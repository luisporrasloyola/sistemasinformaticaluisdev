<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.control_asistencia');
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = is_personal_role() ? (int) current_user_worker_id() : (int) ($_POST['worker_id'] ?? 0);
$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$programId = (int) ($_POST['program_id'] ?? 0);
$activity = trim((string) ($_POST['activity'] ?? ''));
$observations = trim((string) ($_POST['observations'] ?? ''));
$latitude = (float) ($_POST['latitude'] ?? 0);
$longitude = (float) ($_POST['longitude'] ?? 0);
$today = date('Y-m-d');

if ($workerId <= 0 || $assignmentId <= 0 || $activity === '') {
    json_response(['ok'=>false,'message'=>'Indique el trabajo realizado antes de finalizarlo.'],422);
}
if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || ($latitude == 0.0 && $longitude == 0.0)) {
    json_response(['ok'=>false,'message'=>'No se obtuvo una ubicación GPS válida.'],422);
}

$pdo = db();
$context = $pdo->prepare("SELECT aa.id, w.full_name, l.id AS base_location_id, l.name AS base_location_name,
        l.latitude AS base_latitude, l.longitude AS base_longitude, l.radius_meters AS base_radius
    FROM attendance_assignments aa
    JOIN workers w ON w.id=aa.worker_id
    JOIN attendance_locations l ON l.id=aa.location_id
    WHERE aa.id=:assignment_id AND aa.worker_id=:worker_id
      AND ((aa.status=1 AND aa.valid_from<=:today_from AND (aa.valid_until IS NULL OR aa.valid_until>=:today_until))
        OR EXISTS(SELECT 1 FROM attendance_programs ap
            WHERE ap.id=:program_id AND ap.assignment_id=aa.id AND ap.worker_id=aa.worker_id
              AND ap.program_date=:program_date AND ap.status='programada')
        OR EXISTS(SELECT 1 FROM attendance_marks entrada
            WHERE entrada.assignment_id=aa.id AND entrada.worker_id=aa.worker_id
              AND entrada.mark_date=:open_date AND entrada.mark_type='entrada'
              AND NOT EXISTS(SELECT 1 FROM attendance_marks salida
                  WHERE salida.assignment_id=entrada.assignment_id AND salida.worker_id=entrada.worker_id
                    AND salida.mark_date=entrada.mark_date AND salida.mark_type='salida'
                    AND (salida.program_id=entrada.program_id OR (salida.program_id IS NULL AND entrada.program_id IS NULL)))))
    LIMIT 1");
$context->execute([
    'assignment_id'=>$assignmentId,'worker_id'=>$workerId,'today_from'=>$today,'today_until'=>$today,
    'program_id'=>$programId,'program_date'=>$today,'open_date'=>$today,
]);
$assignment = $context->fetch();
if (!$assignment) json_response(['ok'=>false,'message'=>'No se pudo validar el recorrido asignado al trabajador. Actualice la página e inténtelo nuevamente.'],409);

$marks = $pdo->prepare("SELECT mark_type FROM attendance_marks WHERE worker_id=:worker_id AND assignment_id=:assignment_id
    AND mark_date=:today AND ((:program_selected>0 AND program_id=:program_match) OR (:program_none=0 AND program_id IS NULL))");
$marks->execute(['worker_id'=>$workerId,'assignment_id'=>$assignmentId,'today'=>$today,'program_selected'=>$programId,'program_match'=>$programId,'program_none'=>$programId]);
$markTypes = $marks->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('entrada',$markTypes,true) || in_array('salida',$markTypes,true)) {
    json_response(['ok'=>false,'message'=>'Solo puede finalizar un trabajo durante una jornada activa.'],409);
}
$activeTrip = $pdo->prepare("SELECT id FROM attendance_trips WHERE worker_id=:worker_id AND trip_date=:today AND status='en_ruta' LIMIT 1");
$activeTrip->execute(['worker_id'=>$workerId,'today'=>$today]);
if ($activeTrip->fetchColumn()) json_response(['ok'=>false,'message'=>'Primero confirme la llegada al destino actual.'],409);

$locationStmt = $pdo->prepare("SELECT l.id,l.name,l.latitude,l.longitude,l.radius_meters
    FROM attendance_trips t JOIN attendance_locations l ON l.id=t.last_location_id
    WHERE t.worker_id=:worker_id AND t.trip_date=:today AND t.status='finalizado'
    ORDER BY t.ended_at DESC,t.id DESC LIMIT 1");
$locationStmt->execute(['worker_id'=>$workerId,'today'=>$today]);
$location = $locationStmt->fetch() ?: [
    'id'=>$assignment['base_location_id'], 'name'=>$assignment['base_location_name'],
    'latitude'=>$assignment['base_latitude'], 'longitude'=>$assignment['base_longitude'], 'radius_meters'=>$assignment['base_radius'],
];

$earth = 6371000;
$dLat = deg2rad((float)$location['latitude']-$latitude);
$dLon = deg2rad((float)$location['longitude']-$longitude);
$a = sin($dLat/2)**2 + cos(deg2rad($latitude))*cos(deg2rad((float)$location['latitude']))*sin($dLon/2)**2;
$distance = $earth*2*atan2(sqrt($a),sqrt(1-$a));
if ($distance > (float)$location['radius_meters']) {
    json_response(['ok'=>false,'message'=>'Debe estar dentro del área de '.$location['name'].' para finalizar este trabajo.'],409);
}

$lastCompletion = $pdo->prepare('SELECT completed_at FROM attendance_work_completions WHERE worker_id=:worker_id AND work_date=:today ORDER BY completed_at DESC,id DESC LIMIT 1');
$lastCompletion->execute(['worker_id'=>$workerId,'today'=>$today]);
$lastCompletedAt = $lastCompletion->fetchColumn();
$lastTrip = $pdo->prepare("SELECT started_at FROM attendance_trips WHERE worker_id=:worker_id AND trip_date=:today AND status='finalizado' ORDER BY ended_at DESC,id DESC LIMIT 1");
$lastTrip->execute(['worker_id'=>$workerId,'today'=>$today]);
$lastTripStartedAt = $lastTrip->fetchColumn();
if ($lastCompletedAt && (!$lastTripStartedAt || strtotime((string)$lastCompletedAt) >= strtotime((string)$lastTripStartedAt))) {
    json_response(['ok'=>false,'message'=>'Este trabajo ya fue finalizado. El trabajador está esperando su siguiente destino.'],409);
}

try {
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO attendance_work_completions
        (worker_id,assignment_id,program_id,location_id,work_date,activity,observations,completed_at,latitude,longitude,created_by_user_id)
        VALUES (:worker_id,:assignment_id,:program_id,:location_id,:work_date,:activity,:observations,NOW(),:latitude,:longitude,:user_id)');
    $insert->execute([
        'worker_id'=>$workerId,'assignment_id'=>$assignmentId,'program_id'=>$programId ?: null,'location_id'=>(int)$location['id'],'work_date'=>$today,
        'activity'=>mb_substr($activity,0,255),'observations'=>$observations !== '' ? mb_substr($observations,0,500) : null,
        'latitude'=>$latitude,'longitude'=>$longitude,'user_id'=>(int)(current_user()['id'] ?? 0) ?: null,
    ]);
    $nextDestination = null;
    if ($programId > 0) {
        $nextStmt = $pdo->prepare("SELECT aps.destination,al.name AS location_name
            FROM attendance_program_stops aps LEFT JOIN attendance_locations al ON al.id=aps.location_id
            WHERE aps.program_id=:program_id AND NOT EXISTS (
                SELECT 1 FROM attendance_trips t
                WHERE t.program_id=aps.program_id AND t.status='finalizado'
                  AND t.first_destination_location_id=aps.location_id
            ) AND NOT EXISTS (
                SELECT 1 FROM attendance_trip_stops ats JOIN attendance_trips t2 ON t2.id=ats.trip_id
                WHERE t2.program_id=aps.program_id AND t2.status='finalizado' AND ats.location_id=aps.location_id
            ) ORDER BY aps.stop_order LIMIT 1");
        $nextStmt->execute(['program_id'=>$programId]);
        $nextDestination = $nextStmt->fetch();
    }
    $nextDestinationName = trim((string)($nextDestination['location_name'] ?? $nextDestination['destination'] ?? ''));
    $availabilityMessage = $nextDestinationName !== ''
        ? 'Su siguiente destino programado es '.$nextDestinationName.'.'
        : 'Está esperando que se le indique un nuevo destino.';
    $body = $assignment['full_name'].' terminó “'.mb_substr($activity,0,120).'” en '.$location['name'].' a las '.date('H:i').'. Su jornada continúa activa. '.$availabilityMessage;
    $notification = $pdo->prepare("INSERT INTO notifications (type,title,body,worker_id,is_read,created_at)
        VALUES ('work_location_completed','Trabajo finalizado en un lugar',:body,:worker_id,0,NOW())");
    $notification->execute(['body'=>$body,'worker_id'=>$workerId]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok'=>false,'message'=>'No se pudo registrar la finalización del trabajo.'],500);
}

$finalMessage = isset($nextDestinationName) && $nextDestinationName !== ''
    ? 'Trabajo finalizado en '.$location['name'].'. Tu jornada continúa activa. Tu siguiente destino es '.$nextDestinationName.'.'
    : 'Trabajo finalizado en '.$location['name'].'. Tu jornada continúa activa; espera la indicación de tu siguiente destino.';
json_response(['ok'=>true,'message'=>$finalMessage]);
