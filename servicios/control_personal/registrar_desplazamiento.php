<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.control_asistencia');
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = is_personal_role() ? (int) current_user_worker_id() : (int) ($_POST['worker_id'] ?? 0);
$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$programId = (int) ($_POST['program_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
$destination = trim((string) ($_POST['destination'] ?? ''));
$reason = trim((string) ($_POST['reason'] ?? ''));
$activity = trim((string) ($_POST['activity'] ?? ''));
$latitude = (float) ($_POST['latitude'] ?? 0);
$longitude = (float) ($_POST['longitude'] ?? 0);
$today = date('Y-m-d');

if ($workerId <= 0 || $assignmentId <= 0 || !in_array($action,['iniciar','parada','finalizar'],true)) {
    json_response(['ok'=>false,'message'=>'Datos del desplazamiento incompletos.'],422);
}
if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || ($latitude == 0.0 && $longitude == 0.0)) {
    json_response(['ok'=>false,'message'=>'No se obtuvo una ubicación GPS válida.'],422);
}
$asg = db()->prepare('SELECT id FROM attendance_assignments
    WHERE id=:id AND worker_id=:worker_id AND status=1
      AND valid_from <= :today_from AND (valid_until IS NULL OR valid_until >= :today_until)
    LIMIT 1');
$asg->execute(['id'=>$assignmentId,'worker_id'=>$workerId,'today_from'=>date('Y-m-d'),'today_until'=>date('Y-m-d')]);
if (!$asg->fetchColumn()) json_response(['ok'=>false,'message'=>'La asignación no corresponde al trabajador.'],403);
$marks = db()->prepare("SELECT mark_type FROM attendance_marks WHERE worker_id=:worker_id AND mark_date=:today
    AND assignment_id=:assignment_id AND ((:program_selected>0 AND program_id=:program_match) OR (:program_none=0 AND program_id IS NULL))");
$marks->execute([
    'worker_id'=>$workerId,'today'=>$today,'assignment_id'=>$assignmentId,
    'program_selected'=>$programId,'program_match'=>$programId,'program_none'=>$programId,
]);
$types = $marks->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('entrada',$types,true) || in_array('salida',$types,true)) {
    json_response(['ok'=>false,'message'=>'Los desplazamientos solo pueden registrarse durante una jornada iniciada y no finalizada.'],409);
}
$active = db()->prepare("SELECT * FROM attendance_trips WHERE worker_id=:worker_id AND trip_date=:today AND status='en_ruta' ORDER BY id DESC LIMIT 1");
$active->execute(['worker_id'=>$workerId,'today'=>$today]);
$trip = $active->fetch();

if ($action === 'iniciar') {
    if ($trip) json_response(['ok'=>false,'message'=>'Ya existe un desplazamiento laboral en curso.'],409);
    if ($destination === '' || $reason === '') json_response(['ok'=>false,'message'=>'Indique el primer destino y el motivo del desplazamiento.'],422);
    $stmt = db()->prepare("INSERT INTO attendance_trips
        (worker_id,assignment_id,program_id,trip_date,reason,first_destination,status,started_at,start_latitude,start_longitude,created_by_user_id)
        VALUES (:worker_id,:assignment_id,:program_id,:today,:reason,:destination,'en_ruta',NOW(),:latitude,:longitude,:user_id)");
    $stmt->execute(['worker_id'=>$workerId,'assignment_id'=>$assignmentId,'program_id'=>$programId ?: null,'today'=>$today,
        'reason'=>mb_substr($reason,0,255),'destination'=>mb_substr($destination,0,180),'latitude'=>$latitude,'longitude'=>$longitude,
        'user_id'=>(int)(current_user()['id'] ?? 0) ?: null]);
    json_response(['ok'=>true,'message'=>'Desplazamiento iniciado. Tu jornada laboral continúa activa.']);
}
if (!$trip) json_response(['ok'=>false,'message'=>'No existe un desplazamiento laboral en curso.'],409);
if ($action === 'parada') {
    if ($destination === '') json_response(['ok'=>false,'message'=>'Indique el punto visitado.'],422);
    if ($activity === '') json_response(['ok'=>false,'message'=>'Describa la actividad realizada en el punto visitado.'],422);
    $orderStmt = db()->prepare('SELECT COALESCE(MAX(stop_order),0)+1 FROM attendance_trip_stops WHERE trip_id=:trip_id');
    $orderStmt->execute(['trip_id'=>$trip['id']]);
    $stmt = db()->prepare('INSERT INTO attendance_trip_stops (trip_id,stop_order,destination,activity,registered_at,latitude,longitude)
        VALUES (:trip_id,:stop_order,:destination,:activity,NOW(),:latitude,:longitude)');
    $stmt->execute(['trip_id'=>$trip['id'],'stop_order'=>(int)$orderStmt->fetchColumn(),'destination'=>mb_substr($destination,0,180),
        'activity'=>$activity !== '' ? mb_substr($activity,0,255) : null,'latitude'=>$latitude,'longitude'=>$longitude]);
    json_response(['ok'=>true,'message'=>'Punto del recorrido registrado correctamente.']);
}
$stmt = db()->prepare("UPDATE attendance_trips SET status='finalizado',ended_at=NOW(),end_latitude=:latitude,end_longitude=:longitude WHERE id=:id AND status='en_ruta'");
$stmt->execute(['latitude'=>$latitude,'longitude'=>$longitude,'id'=>$trip['id']]);
json_response(['ok'=>true,'message'=>'Desplazamiento finalizado. La jornada sigue activa hasta que registres tu salida.']);
