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
$conflictPolicy = trim((string) ($_POST['conflict_policy'] ?? 'skip'));
$currentUserId = (int) (current_user()['id'] ?? 0) ?: null;
$assignedCount = 1;
$skippedCount = 0;
$replacedCount = 0;

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
        $pdo->prepare('UPDATE attendance_assignments
            SET status = 0, deactivated_at = NOW(), deactivated_by_user_id = :user_id
            WHERE worker_id = :worker_id AND status = 1')
            ->execute(['worker_id' => $currentWorkerId, 'user_id' => $currentUserId]);
        $pdo->prepare('INSERT INTO attendance_assignments
            (worker_id, location_id, schedule_id, activity, status, created_by_user_id)
            VALUES (:worker_id, :location_id, :schedule_id, :activity, 1, :user_id)')->execute([
                'worker_id' => $currentWorkerId,
                'location_id' => $locationId,
                'schedule_id' => $scheduleId,
                'activity' => $activity ?: null,
                'user_id' => $currentUserId,
            ]);
        $replacedCount = 1;
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo actualizar la asignación.'], 500);
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
            WHERE status = 1 AND worker_id IN ({$placeholders})");
        $activeStmt->execute($workerIds);
        $activeWorkerIds = array_map('intval', $activeStmt->fetchAll(PDO::FETCH_COLUMN));
        $activeLookup = array_fill_keys($activeWorkerIds, true);

        if ($conflictPolicy === 'skip') {
            $workerIds = array_values(array_filter(
                $workerIds,
                static fn(int $targetId): bool => !isset($activeLookup[$targetId])
            ));
            $skippedCount = count($activeWorkerIds);
        } else {
            $replacedCount = count($activeWorkerIds);
        }
        $assignedCount = count($workerIds);

        $disable = $pdo->prepare('UPDATE attendance_assignments
            SET status = 0, deactivated_at = NOW(), deactivated_by_user_id = :user_id
            WHERE worker_id = :worker_id AND status = 1');
        $insert = $pdo->prepare('INSERT INTO attendance_assignments
            (worker_id, location_id, schedule_id, activity, status, created_by_user_id)
            VALUES (:worker_id, :location_id, :schedule_id, :activity, 1, :user_id)');

        foreach ($workerIds as $targetWorkerId) {
            if ($conflictPolicy === 'replace') {
                $disable->execute(['worker_id' => (int) $targetWorkerId, 'user_id' => $currentUserId]);
            }
            $insert->execute([
                'worker_id' => (int) $targetWorkerId,
                'location_id' => $locationId,
                'schedule_id' => $scheduleId,
                'activity' => $activity ?: null,
                'user_id' => $currentUserId,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo guardar la asignacion.';
        json_response(['ok' => false, 'message' => $message], 500);
    }
}

json_response([
    'ok' => true,
    'scope' => $scopeType,
    'assigned_count' => $assignedCount,
    'skipped_count' => $skippedCount,
    'replaced_count' => $replacedCount,
]);
