<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.programacion');
verify_csrf($_POST['csrf_token'] ?? null);

$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$date = trim((string) ($_POST['date'] ?? ''));
$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($assignmentId <= 0 || !$parsed || $parsed->format('Y-m-d') !== $date) {
    json_response(['ok' => false, 'message' => 'La jornada seleccionada no es válida.'], 422);
}
if ($date < date('Y-m-d')) {
    json_response(['ok' => false, 'message' => 'No se puede modificar una jornada de una fecha pasada.'], 409);
}
$stmt = db()->prepare('SELECT aa.worker_id, w.company_id, w.full_name
    FROM attendance_assignments aa JOIN workers w ON w.id = aa.worker_id
    WHERE aa.id = :id AND aa.status = 1 LIMIT 1');
$stmt->execute(['id' => $assignmentId]);
$assignment = $stmt->fetch();
if (!$assignment) json_response(['ok' => false, 'message' => 'La asignación ya no está activa.'], 404);

$workerId = (int) $assignment['worker_id'];
$used = db()->prepare("SELECT
    EXISTS(SELECT 1 FROM attendance_marks WHERE worker_id=:worker_marks AND mark_date=:date_marks) AS has_marks,
    EXISTS(SELECT 1 FROM attendance_programs WHERE worker_id=:worker_program AND program_date=:date_program AND status='programada') AS has_program,
    EXISTS(SELECT 1 FROM attendance_trips WHERE worker_id=:worker_trip AND trip_date=:date_trip) AS has_trips");
$used->execute([
    'worker_marks'=>$workerId, 'date_marks'=>$date, 'worker_program'=>$workerId, 'date_program'=>$date,
    'worker_trip'=>$workerId, 'date_trip'=>$date,
]);
$usage = $used->fetch();
if (!empty($usage['has_marks']) || !empty($usage['has_program']) || !empty($usage['has_trips'])) {
    json_response(['ok'=>false,'message'=>'No se puede excluir esta fecha porque ya tiene marcaciones, una jornada extraordinaria o desplazamientos registrados.'],409);
}

$existing = db()->prepare("SELECT id FROM attendance_calendar_days
    WHERE status=1 AND calendar_date<=:date_end AND COALESCE(end_date,calendar_date)>=:date_start
      AND (scope_type='all' OR (scope_type='company' AND company_id=:company_id) OR (scope_type='worker' AND worker_id=:worker_id))
    LIMIT 1");
$existing->execute(['date_end'=>$date,'date_start'=>$date,'company_id'=>(int)$assignment['company_id'],'worker_id'=>$workerId]);
if ($existing->fetch()) json_response(['ok'=>false,'message'=>'La fecha ya tiene una configuración en Calendario laboral.'],409);

$insert = db()->prepare("INSERT INTO attendance_calendar_days
    (calendar_date,end_date,event_type,name,scope_type,company_id,worker_id,status,created_by_user_id)
    VALUES (:date_start,:date_end,'rest','Jornada excluida','worker',NULL,:worker_id,1,:user_id)");
$insert->execute(['date_start'=>$date,'date_end'=>$date,'worker_id'=>$workerId,'user_id'=>(int)($_SESSION['user']['id']??0)?:null]);
json_response(['ok'=>true,'message'=>'La jornada se excluyó únicamente para esta fecha.']);
