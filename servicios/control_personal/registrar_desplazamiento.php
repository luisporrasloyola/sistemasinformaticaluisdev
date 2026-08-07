<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.control_asistencia');
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = is_personal_role() ? (int) current_user_worker_id() : (int) ($_POST['worker_id'] ?? 0);
$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$programId = (int) ($_POST['program_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
$destinationLocationId = (int) ($_POST['destination_location_id'] ?? 0);
$destination = trim((string) ($_POST['destination'] ?? ''));
$reason = trim((string) ($_POST['reason'] ?? ''));
$activity = trim((string) ($_POST['activity'] ?? ''));
$latitude = (float) ($_POST['latitude'] ?? 0);
$longitude = (float) ($_POST['longitude'] ?? 0);
$accuracy = (float) ($_POST['accuracy'] ?? 0);
$today = date('Y-m-d');

if ($workerId <= 0 || $assignmentId <= 0 || !in_array($action,['iniciar','parada','finalizar','regresar_lugar_trabajo','regresar_sin_llegada'],true)) {
    json_response(['ok'=>false,'message'=>'Datos del desplazamiento incompletos.'],422);
}
if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180
    || ($latitude == 0.0 && $longitude == 0.0) || $accuracy <= 0) {
    json_response(['ok'=>false,'message'=>'No se obtuvo una ubicación GPS válida.'],422);
}
$asg = db()->prepare("SELECT aa.id,aa.location_id,l.name AS location_name,l.latitude,l.longitude,l.radius_meters
    FROM attendance_assignments aa JOIN attendance_locations l ON l.id=aa.location_id
    WHERE aa.id=:id AND aa.worker_id=:worker_id AND l.status=1
      AND ((aa.status=1 AND valid_from <= :today_from AND (valid_until IS NULL OR valid_until >= :today_until))
        OR EXISTS(SELECT 1 FROM attendance_programs ap
            WHERE ap.id=:program_id AND ap.assignment_id=aa.id AND ap.worker_id=aa.worker_id
              AND ap.program_date=:program_date AND ap.status='programada'))
    LIMIT 1");
$asg->execute([
    'id'=>$assignmentId,'worker_id'=>$workerId,'today_from'=>$today,'today_until'=>$today,
    'program_id'=>$programId,'program_date'=>$today,
]);
$assignment = $asg->fetch();
if (!$assignment) json_response(['ok'=>false,'message'=>'No se pudo validar el recorrido asignado al trabajador. Actualice la página e inténtelo nuevamente.'],409);
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

function trip_location(PDO $pdo, int $locationId): ?array {
    if ($locationId <= 0) return null;
    $stmt = $pdo->prepare('SELECT id,name,latitude,longitude,radius_meters FROM attendance_locations WHERE id=:id AND status=1 LIMIT 1');
    $stmt->execute(['id'=>$locationId]);
    return $stmt->fetch() ?: null;
}
function trip_distance(float $lat1,float $lon1,float $lat2,float $lon2): float {
    $earth=6371000; $dLat=deg2rad($lat2-$lat1); $dLon=deg2rad($lon2-$lon1);
    $a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
    return $earth*2*atan2(sqrt($a),sqrt(1-$a));
}
function require_trip_location_radius(array $location,float $latitude,float $longitude,float $accuracy,string $purpose='arrival'): void {
    $distance=trip_distance($latitude,$longitude,(float)$location['latitude'],(float)$location['longitude']);
    $allowedRadius = (float) $location['radius_meters'];
    $locationName = (string) ($location['name'] ?? $location['location_name'] ?? 'el lugar indicado');
    // La precisión es un margen de incertidumbre informado por el dispositivo,
    // no la distancia al lugar. En equipos de escritorio puede ser amplia aun
    // cuando la coordenada obtenida esté dentro del punto configurado.
    if ($distance > $allowedRadius) {
        if ($purpose === 'departure') {
            json_response(['ok'=>false,'message'=>'Para iniciar el traslado debes encontrarte en '.$locationName.'. Verifique que el GPS esté activo e inténtelo nuevamente.'],409);
        }
        json_response(['ok'=>false,'message'=>'Aún estás fuera del área de '.$locationName.'. Acércate al lugar para confirmar tu llegada.'],409);
    }
}

if ($action === 'iniciar') {
    if ($trip) json_response(['ok'=>false,'message'=>'Ya existe un desplazamiento laboral en curso.'],409);
    $hasPlannedRoute = false;
    if ($programId > 0) {
        $plannedRouteStmt = db()->prepare('SELECT EXISTS(SELECT 1 FROM attendance_program_stops WHERE program_id=:program_id)');
        $plannedRouteStmt->execute(['program_id'=>$programId]);
        $hasPlannedRoute = (bool)$plannedRouteStmt->fetchColumn();
    }
    if ($hasPlannedRoute) {
        $readyStmt = db()->prepare("SELECT awc.completed_at,
            (SELECT MAX(t.started_at) FROM attendance_trips t WHERE t.worker_id=awc.worker_id AND t.trip_date=awc.work_date) AS last_trip_started_at
            FROM attendance_work_completions awc
            WHERE awc.worker_id=:worker_id AND awc.work_date=:today
            ORDER BY awc.completed_at DESC,awc.id DESC LIMIT 1");
        $readyStmt->execute(['worker_id'=>$workerId,'today'=>$today]);
        $ready = $readyStmt->fetch();
        if (!$ready || ($ready['last_trip_started_at'] && strtotime((string)$ready['completed_at']) < strtotime((string)$ready['last_trip_started_at']))) {
            json_response(['ok'=>false,'message'=>'Primero finaliza el trabajo en tu lugar actual. Esto avisará al administrador que estás listo para el siguiente destino del recorrido.'],409);
        }
    }
    $originLocation = $assignment;
    if ($programId > 0) {
        $originStmt = db()->prepare("SELECT l.id AS location_id,l.name AS location_name,l.latitude,l.longitude,l.radius_meters
            FROM attendance_work_completions awc
            JOIN attendance_locations l ON l.id=awc.location_id
            WHERE awc.program_id=:program_id AND awc.worker_id=:worker_id AND awc.work_date=:today
            ORDER BY awc.completed_at DESC,awc.id DESC LIMIT 1");
        $originStmt->execute(['program_id'=>$programId,'worker_id'=>$workerId,'today'=>$today]);
        $lastCompletedLocation = $originStmt->fetch();
        if ($lastCompletedLocation) {
            $originLocation = [
                'name'=>$lastCompletedLocation['location_name'],
                'latitude'=>$lastCompletedLocation['latitude'],
                'longitude'=>$lastCompletedLocation['longitude'],
                'radius_meters'=>$lastCompletedLocation['radius_meters'],
            ];
        }
    }
    // Al iniciar se valida exclusivamente el punto de partida. El destino se
    // comprobará con GPS cuando el trabajador confirme su llegada.
    require_trip_location_radius($originLocation,$latitude,$longitude,$accuracy,'departure');
    $destinationLocation = trip_location(db(), $destinationLocationId);
    if ($hasPlannedRoute && !$destinationLocation) json_response(['ok'=>false,'message'=>'Seleccione el destino programado.'],422);
    $destinationName = $destinationLocation ? (string)$destinationLocation['name'] : mb_substr($destination,0,180);
    if ($destinationName === '' || $reason === '') json_response(['ok'=>false,'message'=>'Escriba el destino y el motivo del desplazamiento.'],422);
    $stmt = db()->prepare("INSERT INTO attendance_trips
        (worker_id,assignment_id,program_id,trip_date,reason,first_destination,first_destination_location_id,status,started_at,start_latitude,start_longitude,created_by_user_id)
        VALUES (:worker_id,:assignment_id,:program_id,:today,:reason,:destination,:destination_location_id,'en_ruta',NOW(),:latitude,:longitude,:user_id)");
    $stmt->execute(['worker_id'=>$workerId,'assignment_id'=>$assignmentId,'program_id'=>$programId ?: null,'today'=>$today,
        'reason'=>mb_substr($reason,0,255),'destination'=>$destinationName,'destination_location_id'=>$destinationLocation ? $destinationLocationId : null,'latitude'=>$latitude,'longitude'=>$longitude,
        'user_id'=>(int)(current_user()['id'] ?? 0) ?: null]);
    json_response(['ok'=>true,'message'=>'Desplazamiento hacia '.$destinationName.' iniciado. Tu jornada laboral continúa activa.']);
}
if (!$trip) json_response(['ok'=>false,'message'=>'No existe un desplazamiento laboral en curso.'],409);
if (in_array($action,['regresar_lugar_trabajo','regresar_sin_llegada'],true)) {
    if ((int)($trip['first_destination_location_id'] ?? 0) > 0 || (int)($trip['last_location_id'] ?? 0) > 0) {
        json_response(['ok'=>false,'message'=>'Esta opción solo está disponible para una salida temporal cuya llegada no fue confirmada.'],409);
    }
    if ($action === 'regresar_sin_llegada' && $reason === '') json_response(['ok'=>false,'message'=>'Indique brevemente por qué no registró la llegada al destino.'],422);
    require_trip_location_radius($assignment,$latitude,$longitude,$accuracy);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE attendance_trips
            SET status='finalizado',completion_type=:completion_type,exception_reason=:reason,
                last_location_id=:location_id,ended_at=NOW(),end_latitude=:latitude,end_longitude=:longitude
            WHERE id=:id AND status='en_ruta'");
        $stmt->execute([
            'completion_type'=>$action === 'regresar_sin_llegada' ? 'returned_without_arrival' : 'temporary_return_confirmed',
            'reason'=>$action === 'regresar_sin_llegada' ? mb_substr($reason,0,500) : null,'location_id'=>(int)$assignment['location_id'],
            'latitude'=>$latitude,'longitude'=>$longitude,'id'=>$trip['id'],
        ]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('El desplazamiento ya no se encuentra en curso.');
        if ($action === 'regresar_sin_llegada') {
            $workerStmt = $pdo->prepare('SELECT full_name FROM workers WHERE id=:id LIMIT 1');
            $workerStmt->execute(['id'=>$workerId]);
            $workerName = (string)($workerStmt->fetchColumn() ?: 'El trabajador');
            $body = $workerName.' regresó a '.$assignment['location_name'].' sin confirmar su llegada a '.$trip['first_destination'].'. Motivo: '.mb_substr($reason,0,500);
            $notification = $pdo->prepare("INSERT INTO notifications (type,title,body,worker_id,is_read,created_at)
                VALUES ('temporary_trip_exception','Regreso sin llegada confirmada',:body,:worker_id,0,NOW())");
            $notification->execute(['body'=>$body,'worker_id'=>$workerId]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['ok'=>false,'message'=>$error instanceof RuntimeException ? $error->getMessage() : 'No se pudo registrar el regreso.'],409);
    }
    json_response(['ok'=>true,'message'=>'Regreso a '.$assignment['location_name'].' confirmado. La salida temporal a '.$trip['first_destination'].' finalizó y tu jornada laboral continúa activa.']);
}
if ($action === 'parada') {
    $destinationLocation = trip_location(db(), $destinationLocationId);
    if (!$destinationLocation) json_response(['ok'=>false,'message'=>'Seleccione el lugar visitado.'],422);
    if ($activity === '') json_response(['ok'=>false,'message'=>'Describa la actividad realizada en el punto visitado.'],422);
    require_trip_location_radius($destinationLocation,$latitude,$longitude,$accuracy);
    $orderStmt = db()->prepare('SELECT COALESCE(MAX(stop_order),0)+1 FROM attendance_trip_stops WHERE trip_id=:trip_id');
    $orderStmt->execute(['trip_id'=>$trip['id']]);
    $stmt = db()->prepare('INSERT INTO attendance_trip_stops (trip_id,location_id,stop_order,destination,activity,registered_at,latitude,longitude)
        VALUES (:trip_id,:location_id,:stop_order,:destination,:activity,NOW(),:latitude,:longitude)');
    $stmt->execute(['trip_id'=>$trip['id'],'location_id'=>$destinationLocationId,'stop_order'=>(int)$orderStmt->fetchColumn(),'destination'=>$destinationLocation['name'],
        'activity'=>$activity !== '' ? mb_substr($activity,0,255) : null,'latitude'=>$latitude,'longitude'=>$longitude]);
    db()->prepare('UPDATE attendance_trips SET last_location_id=:location_id WHERE id=:id')->execute(['location_id'=>$destinationLocationId,'id'=>$trip['id']]);
    json_response(['ok'=>true,'message'=>'Llegada a '.$destinationLocation['name'].' confirmada. Este será el lugar habilitado para tu salida.']);
}
$arrivalLocationId = (int) ($trip['last_location_id'] ?: $trip['first_destination_location_id']);
$arrivalLocation = trip_location(db(), $arrivalLocationId);
$isPlannedRoute = false;
if ($programId > 0) {
    $plannedRouteStmt = db()->prepare('SELECT EXISTS(SELECT 1 FROM attendance_program_stops WHERE program_id=:program_id)');
    $plannedRouteStmt->execute(['program_id'=>$programId]);
    $isPlannedRoute = (bool) $plannedRouteStmt->fetchColumn();
}
if ($isPlannedRoute && !$arrivalLocation) {
    json_response(['ok'=>false,'message'=>'El destino programado no tiene coordenadas activas para validar la llegada. Comuníquese con el administrador.'],409);
}
if ($arrivalLocation) require_trip_location_radius($arrivalLocation,$latitude,$longitude,$accuracy);
$arrivalName = $arrivalLocation ? (string)$arrivalLocation['name'] : (string)$trip['first_destination'];
$arrivalLocationValue = $arrivalLocation ? $arrivalLocationId : null;
$stmt = db()->prepare("UPDATE attendance_trips SET status='finalizado',last_location_id=:location_id,ended_at=NOW(),end_latitude=:latitude,end_longitude=:longitude WHERE id=:id AND status='en_ruta'");
$stmt->execute(['location_id'=>$arrivalLocationValue,'latitude'=>$latitude,'longitude'=>$longitude,'id'=>$trip['id']]);
json_response(['ok'=>true,'message'=>'Llegada a '.$arrivalName.' confirmada. Tu jornada laboral continúa activa.']);
