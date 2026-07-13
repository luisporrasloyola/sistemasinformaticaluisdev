<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$workerId = (int) ($_POST['worker_id'] ?? 0);
$locationId = (int) ($_POST['location_id'] ?? 0);
$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$activity = trim((string) ($_POST['activity'] ?? ''));

if ($workerId <= 0 || $locationId <= 0 || $scheduleId <= 0) {
    json_response(['ok' => false, 'message' => 'Complete trabajador, lugar y horario.'], 400);
}

$checks = [
    ['sql' => 'SELECT id FROM workers WHERE id = :id LIMIT 1', 'id' => $workerId, 'message' => 'El trabajador no existe.'],
    ['sql' => 'SELECT id FROM attendance_locations WHERE id = :id AND status = 1 LIMIT 1', 'id' => $locationId, 'message' => 'El punto de marcacion no existe.'],
    ['sql' => 'SELECT id FROM attendance_schedules WHERE id = :id AND status = 1 LIMIT 1', 'id' => $scheduleId, 'message' => 'El horario no existe.'],
];

foreach ($checks as $check) {
    $stmt = db()->prepare($check['sql']);
    $stmt->execute(['id' => $check['id']]);
    if (!$stmt->fetch()) {
        json_response(['ok' => false, 'message' => $check['message']], 400);
    }
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
    $stmt = db()->prepare('INSERT INTO attendance_assignments (worker_id, location_id, schedule_id, activity, status)
        VALUES (:worker_id, :location_id, :schedule_id, :activity, 1)');
    $stmt->execute([
        'worker_id' => $workerId,
        'location_id' => $locationId,
        'schedule_id' => $scheduleId,
        'activity' => $activity ?: null,
    ]);
}

json_response(['ok' => true]);
