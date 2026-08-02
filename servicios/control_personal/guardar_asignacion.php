<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$scopeType = $id > 0 ? 'worker' : trim((string) ($_POST['scope_type'] ?? 'worker'));
$workerId = (int) ($_POST['worker_id'] ?? 0);
$selectedWorkerIds = array_values(array_unique(array_filter(
    array_map('intval', (array) ($_POST['worker_ids'] ?? [])),
    static fn(int $value): bool => $value > 0
)));
$locationId = (int) ($_POST['location_id'] ?? 0);
$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$activity = trim((string) ($_POST['activity'] ?? ''));
$instructions = trim((string) ($_POST['instructions'] ?? ''));
$validFrom = trim((string) ($_POST['valid_from'] ?? ''));
$validUntil = !empty($_POST['no_end']) ? null : trim((string) ($_POST['valid_until'] ?? ''));
$conflictPolicy = trim((string) ($_POST['conflict_policy'] ?? 'skip'));
$currentUserId = (int) (current_user()['id'] ?? 0) ?: null;
$assignedCount = 1;
$skippedCount = 0;
$replacedCount = 0;
$skippedConflicts = [];
$requestedAssignment = [];

/** Convierte los días de una plantilla en intervalos de una semana modelo. */
function assignment_schedule_intervals(PDO $pdo, int $scheduleId): array
{
    static $cache = [];
    if (isset($cache[$scheduleId])) return $cache[$scheduleId];
    $stmt = $pdo->prepare('SELECT day_of_week, entry_time, entry_start, exit_time, exit_start
        FROM attendance_schedule_days WHERE schedule_id=:schedule_id AND status=1');
    $stmt->execute(['schedule_id' => $scheduleId]);
    $intervals = [];
    foreach ($stmt->fetchAll() as $day) {
        $startText = (string) ($day['entry_time'] ?: $day['entry_start']);
        $endText = (string) ($day['exit_time'] ?: $day['exit_start']);
        if ($startText === '' || $endText === '') continue;
        [$sh, $sm, $ss] = array_pad(array_map('intval', explode(':', $startText)), 3, 0);
        [$eh, $em, $es] = array_pad(array_map('intval', explode(':', $endText)), 3, 0);
        $start = (((int) $day['day_of_week']) - 1) * 86400 + $sh * 3600 + $sm * 60 + $ss;
        $end = (((int) $day['day_of_week']) - 1) * 86400 + $eh * 3600 + $em * 60 + $es;
        if ($end <= $start) $end += 86400;
        $intervals[] = [$start, $end, (int) $day['day_of_week'], substr($startText, 0, 5), substr($endText, 0, 5)];
    }
    return $cache[$scheduleId] = $intervals;
}

function assignment_schedules_overlap(PDO $pdo, int $firstScheduleId, int $secondScheduleId): ?array
{
    $week = 7 * 86400;
    foreach (assignment_schedule_intervals($pdo, $firstScheduleId) as $first) {
        foreach (assignment_schedule_intervals($pdo, $secondScheduleId) as $second) {
            foreach ([-$week, 0, $week] as $shift) {
                if ($first[0] < $second[1] + $shift && $first[1] > $second[0] + $shift) {
                    // El mensaje debe mostrar el rango de la asignación que ya existe,
                    // no el de la plantilla que se está intentando agregar.
                    return ['day' => $second[2], 'start' => $second[3], 'end' => $second[4]];
                }
            }
        }
    }
    return null;
}

function assignment_conflict(PDO $pdo, int $workerId, int $scheduleId, string $validFrom, ?string $validUntil, int $excludeId = 0): ?array
{
    $stmt = $pdo->prepare("SELECT aa.id, aa.schedule_id, aa.valid_from, aa.valid_until,
            s.name AS schedule_name, l.name AS location_name, w.full_name AS worker_name
        FROM attendance_assignments aa
        JOIN workers w ON w.id=aa.worker_id
        JOIN attendance_schedules s ON s.id=aa.schedule_id
        JOIN attendance_locations l ON l.id=aa.location_id
        WHERE aa.worker_id=:worker_id AND aa.status=1 AND aa.id<>:exclude_id
          AND aa.valid_from<=:range_end AND (aa.valid_until IS NULL OR aa.valid_until>=:range_start)");
    $stmt->execute([
        'worker_id' => $workerId, 'exclude_id' => $excludeId,
        'range_end' => $validUntil ?: '9999-12-31', 'range_start' => $validFrom,
    ]);
    foreach ($stmt->fetchAll() as $existing) {
        $overlap = assignment_schedules_overlap($pdo, $scheduleId, (int) $existing['schedule_id']);
        if ($overlap) return $existing + ['overlap' => $overlap];
    }
    return null;
}

function assignment_conflict_message(array $conflict): string
{
    $days = [1=>'lunes', 2=>'martes', 3=>'miércoles', 4=>'jueves', 5=>'viernes', 6=>'sábado', 7=>'domingo'];
    $day = $days[(int) $conflict['overlap']['day']] ?? 'uno de los días';
    $worker = trim((string) ($conflict['worker_name'] ?? ''));
    return 'No se puede guardar porque ' . ($worker !== '' ? $worker : 'el trabajador')
        . ' ya tiene una asignación que se superpone. '
        . 'Conflicto con "' . $conflict['schedule_name'] . '" en ' . $conflict['location_name']
        . ' (' . $day . ', ' . $conflict['overlap']['start'] . ' - ' . $conflict['overlap']['end'] . '). '
        . 'Cambie el horario o finalice la asignación anterior.';
}

if (!in_array($scopeType, ['all', 'worker', 'selected'], true)) {
    json_response(['ok' => false, 'message' => 'Seleccione a quien se aplicara la asignacion.'], 400);
}
if (!in_array($conflictPolicy, ['skip', 'replace'], true)) {
    json_response(['ok' => false, 'message' => 'Seleccione cómo tratar las asignaciones existentes.'], 400);
}
if (($scopeType === 'worker' && $workerId <= 0) || $locationId <= 0 || $scheduleId <= 0) {
    json_response(['ok' => false, 'message' => 'Complete el alcance, lugar y horario.'], 400);
}
if ($scopeType === 'selected' && !$selectedWorkerIds) {
    json_response(['ok' => false, 'message' => 'Seleccione al menos un trabajador.'], 400);
}
$validFromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $validFrom);
$validUntilDate = $validUntil ? DateTimeImmutable::createFromFormat('!Y-m-d', $validUntil) : null;
if (!$validFromDate || $validFromDate->format('Y-m-d') !== $validFrom
    || ($validUntil !== null && (!$validUntilDate || $validUntilDate->format('Y-m-d') !== $validUntil))) {
    json_response(['ok' => false, 'message' => 'Defina un periodo de vigencia valido para la asignacion.'], 422);
}
if ($validUntilDate && $validUntilDate < $validFromDate) {
    json_response(['ok' => false, 'message' => 'La fecha de finalizacion no puede ser anterior a la fecha de inicio.'], 422);
}

$checks = [
    ['sql' => 'SELECT id FROM attendance_locations WHERE id = :id AND status = 1 LIMIT 1', 'id' => $locationId, 'message' => 'El punto de marcacion no existe.'],
    ['sql' => 'SELECT id FROM attendance_schedules WHERE id = :id AND status = 1 LIMIT 1', 'id' => $scheduleId, 'message' => 'El horario no existe.'],
];

if ($scopeType === 'worker') {
    array_unshift($checks, ['sql' => 'SELECT id FROM workers WHERE id = :id LIMIT 1', 'id' => $workerId, 'message' => 'El trabajador no existe.']);
}

foreach ($checks as $check) {
    $stmt = db()->prepare($check['sql']);
    $stmt->execute(['id' => $check['id']]);
    if (!$stmt->fetch()) {
        json_response(['ok' => false, 'message' => $check['message']], 400);
    }
}

$requestedStmt = db()->prepare('SELECT l.name AS location_name, s.name AS schedule_name
    FROM attendance_locations l
    CROSS JOIN attendance_schedules s
    WHERE l.id = :location_id AND s.id = :schedule_id
    LIMIT 1');
$requestedStmt->execute(['location_id' => $locationId, 'schedule_id' => $scheduleId]);
$requestedAssignment = $requestedStmt->fetch() ?: [];
$requestedAssignment['activity'] = $activity ?: 'Sin actividad especificada';
$requestedAssignment['valid_from'] = $validFrom;
$requestedAssignment['valid_until'] = $validUntil;

if ($scopeType === 'selected') {
    $placeholders = implode(',', array_fill(0, count($selectedWorkerIds), '?'));
    $stmt = db()->prepare("SELECT id FROM workers WHERE id IN ({$placeholders}) ORDER BY id");
    $stmt->execute($selectedWorkerIds);
    $validWorkerIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($validWorkerIds) !== count($selectedWorkerIds)) {
        json_response(['ok' => false, 'message' => 'Uno o mas trabajadores seleccionados no existen.'], 400);
    }
    $selectedWorkerIds = $validWorkerIds;
}

if ($id > 0) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $currentStmt = $pdo->prepare('SELECT worker_id FROM attendance_assignments WHERE id = :id AND status = 1 LIMIT 1 FOR UPDATE');
        $currentStmt->execute(['id' => $id]);
        $currentWorkerId = (int) $currentStmt->fetchColumn();
        if ($currentWorkerId <= 0) {
            throw new RuntimeException('La asignación ya no está activa.');
        }
        $conflict = assignment_conflict($pdo, $currentWorkerId, $scheduleId, $validFrom, $validUntil, $id);
        if ($conflict) throw new DomainException(assignment_conflict_message($conflict));
        $pdo->prepare('UPDATE attendance_assignments
            SET status = 0, deactivated_at = NOW(), deactivated_by_user_id = :user_id
            WHERE id = :id AND status = 1')
            ->execute(['id' => $id, 'user_id' => $currentUserId]);
        $pdo->prepare('INSERT INTO attendance_assignments
            (worker_id, location_id, schedule_id, activity, instructions, valid_from, valid_until, status, created_by_user_id)
            VALUES (:worker_id, :location_id, :schedule_id, :activity, :instructions, :valid_from, :valid_until, 1, :user_id)')->execute([
                'worker_id' => $currentWorkerId,
                'location_id' => $locationId,
                'schedule_id' => $scheduleId,
                'activity' => $activity ?: null,
                'instructions' => $instructions ?: null,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'user_id' => $currentUserId,
            ]);
        $replacedCount = 1;
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $controlled = $e instanceof RuntimeException || $e instanceof DomainException;
        json_response(
            ['ok' => false, 'message' => $controlled ? $e->getMessage() : 'No se pudo actualizar la asignación.'],
            $e instanceof DomainException ? 409 : 500
        );
    }
} else {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $workerIds = match ($scopeType) {
            'all' => $pdo->query('SELECT id FROM workers ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
            'selected' => $selectedWorkerIds,
            default => [$workerId],
        };

        if (!$workerIds) {
            throw new RuntimeException('No hay trabajadores para asignar.');
        }
        $workerIds = array_values(array_unique(array_map('intval', $workerIds)));
        $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
        $activeStmt = $pdo->prepare("SELECT DISTINCT worker_id FROM attendance_assignments
            WHERE status = 1 AND worker_id IN ({$placeholders})
              AND valid_from <= ? AND (valid_until IS NULL OR valid_until >= ?)");
        $activeStmt->execute(array_merge($workerIds, [$validUntil ?: '9999-12-31', $validFrom]));
        $activeWorkerIds = array_map('intval', $activeStmt->fetchAll(PDO::FETCH_COLUMN));
        $activeLookup = array_fill_keys($activeWorkerIds, true);

        if ($conflictPolicy === 'skip') {
            if ($activeWorkerIds) {
                $conflictPlaceholders = implode(',', array_fill(0, count($activeWorkerIds), '?'));
                $conflictStmt = $pdo->prepare("SELECT
                        w.id AS worker_id, w.full_name, w.document_number,
                        l.name AS location_name, s.name AS schedule_name,
                        aa.activity, aa.valid_from, aa.valid_until
                    FROM attendance_assignments aa
                    JOIN workers w ON w.id = aa.worker_id
                    JOIN attendance_locations l ON l.id = aa.location_id
                    JOIN attendance_schedules s ON s.id = aa.schedule_id
                    WHERE aa.status = 1 AND aa.worker_id IN ({$conflictPlaceholders})
                      AND aa.valid_from <= ? AND (aa.valid_until IS NULL OR aa.valid_until >= ?)
                    ORDER BY w.full_name, aa.valid_from");
                $conflictStmt->execute(array_merge($activeWorkerIds, [$validUntil ?: '9999-12-31', $validFrom]));
                $skippedConflicts = $conflictStmt->fetchAll();
            }
            $workerIds = array_values(array_filter(
                $workerIds,
                static fn(int $targetId): bool => !isset($activeLookup[$targetId])
            ));
            $skippedCount = count($activeWorkerIds);
        } elseif ($conflictPolicy === 'replace') {
            $replacedCount = count($activeWorkerIds);
        }
        $assignedCount = count($workerIds);

        $disable = $pdo->prepare('UPDATE attendance_assignments
            SET status = 0, deactivated_at = NOW(), deactivated_by_user_id = :user_id
            WHERE worker_id = :worker_id AND status = 1
              AND valid_from <= :range_end AND (valid_until IS NULL OR valid_until >= :range_start)');
        $insert = $pdo->prepare('INSERT INTO attendance_assignments
            (worker_id, location_id, schedule_id, activity, instructions, valid_from, valid_until, status, created_by_user_id)
            VALUES (:worker_id, :location_id, :schedule_id, :activity, :instructions, :valid_from, :valid_until, 1, :user_id)');

        foreach ($workerIds as $targetWorkerId) {
            if ($conflictPolicy === 'replace') {
                $disable->execute(['worker_id' => (int) $targetWorkerId, 'user_id' => $currentUserId,
                    'range_end' => $validUntil ?: '9999-12-31', 'range_start' => $validFrom]);
            }
            $insert->execute([
                'worker_id' => (int) $targetWorkerId,
                'location_id' => $locationId,
                'schedule_id' => $scheduleId,
                'activity' => $activity ?: null,
                'instructions' => $instructions ?: null,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'user_id' => $currentUserId,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = ($e instanceof RuntimeException || $e instanceof DomainException)
            ? $e->getMessage()
            : 'No se pudo guardar la asignacion.';
        json_response(['ok' => false, 'message' => $message], $e instanceof DomainException ? 409 : 500);
    }
}

json_response([
    'ok' => true,
    'scope' => $scopeType,
    'assigned_count' => $assignedCount,
    'skipped_count' => $skippedCount,
    'replaced_count' => $replacedCount,
    'skipped_conflicts' => $skippedConflicts,
    'requested_assignment' => $requestedAssignment,
]);
