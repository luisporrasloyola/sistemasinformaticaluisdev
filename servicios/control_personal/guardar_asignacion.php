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
$assignedCount = 1;

if (!in_array($scopeType, ['all', 'worker', 'selected'], true)) {
    json_response(['ok' => false, 'message' => 'Seleccione a quien se aplicara la asignacion.'], 400);
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
    $stmt = db()->prepare('UPDATE attendance_assignments
        SET worker_id = :worker_id, location_id = :location_id, schedule_id = :schedule_id, activity = :activity
        WHERE id = :id');
    $stmt->execute([
        'worker_id' => $workerId,
        'location_id' => $locationId,
        'schedule_id' => $scheduleId,
        'activity' => $activity ?: null,
        'id' => $id,
    ]);
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
        $assignedCount = count($workerIds);

        $disable = $pdo->prepare('UPDATE attendance_assignments SET status = 0 WHERE worker_id = :worker_id AND status = 1');
        $insert = $pdo->prepare('INSERT INTO attendance_assignments (worker_id, location_id, schedule_id, activity, status)
            VALUES (:worker_id, :location_id, :schedule_id, :activity, 1)');

        foreach ($workerIds as $targetWorkerId) {
            $disable->execute(['worker_id' => (int) $targetWorkerId]);
            $insert->execute([
                'worker_id' => (int) $targetWorkerId,
                'location_id' => $locationId,
                'schedule_id' => $scheduleId,
                'activity' => $activity ?: null,
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

json_response(['ok' => true, 'scope' => $scopeType, 'assigned_count' => $assignedCount]);
