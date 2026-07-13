<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role(['Administrador', 'Personal']);

$requestedWorkerId = (int) ($_GET['worker_id'] ?? 0);
$workerId = is_personal_role() ? (int) current_user_worker_id() : $requestedWorkerId;

if ($workerId <= 0) {
    json_response(['ok' => false, 'message' => is_personal_role() ? 'Su usuario no tiene trabajador vinculado.' : 'Seleccione un trabajador.'], 400);
}

$stmt = db()->prepare("SELECT aa.id AS assignment_id, aa.activity,
        w.id AS worker_id, w.full_name, w.document_number,
        l.id AS location_id, l.name AS location_name, l.latitude, l.longitude, l.address, l.radius_meters,
        s.id AS schedule_id, s.name AS schedule_name
    FROM attendance_assignments aa
    JOIN workers w ON w.id = aa.worker_id
    JOIN attendance_locations l ON l.id = aa.location_id
    JOIN attendance_schedules s ON s.id = aa.schedule_id
    WHERE aa.worker_id = :worker_id AND aa.status = 1
    ORDER BY aa.id DESC
    LIMIT 1");
$stmt->execute(['worker_id' => $workerId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    json_response(['ok' => false, 'message' => 'El trabajador no tiene una asignacion activa.'], 404);
}

$today = date('Y-m-d');
$dayOfWeek = (int) date('N');

$stmt = db()->prepare('SELECT * FROM attendance_schedule_days
    WHERE schedule_id = :schedule_id AND day_of_week = :day_of_week AND status = 1
    LIMIT 1');
$stmt->execute([
    'schedule_id' => (int) $assignment['schedule_id'],
    'day_of_week' => $dayOfWeek,
]);
$scheduleDay = $stmt->fetch();

$stmt = db()->prepare('SELECT mark_type, mark_time, final_status, photo_path FROM attendance_marks
    WHERE worker_id = :worker_id AND mark_date = :mark_date
    ORDER BY marked_at ASC');
$stmt->execute(['worker_id' => $workerId, 'mark_date' => $today]);
$marks = $stmt->fetchAll();

json_response([
    'ok' => true,
    'today' => $today,
    'now' => date('H:i:s'),
    'assignment' => $assignment,
    'schedule_day' => $scheduleDay,
    'marks' => $marks,
    'is_personal' => is_personal_role(),
]);
