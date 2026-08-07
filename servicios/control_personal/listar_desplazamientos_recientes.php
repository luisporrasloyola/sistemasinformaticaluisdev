<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.control_asistencia');

$workerId = (int) ($_GET['worker_id'] ?? 0);
if (is_personal_role()) $workerId = (int) current_user_worker_id();
if ($workerId <= 0) json_response(['ok' => false, 'message' => 'Seleccione un trabajador.'], 400);

    $stmt = db()->prepare("SELECT t.id, t.worker_id, t.program_id, t.trip_date, t.reason, t.first_destination, t.status,
        t.completion_type,t.exception_reason,t.started_at,t.ended_at,
        t.last_location_id,t.end_latitude,t.end_longitude,
        EXISTS(SELECT 1 FROM attendance_program_stops aps WHERE aps.program_id=t.program_id) AS is_planned_route,
        TIMESTAMPDIFF(SECOND, t.started_at, COALESCE(t.ended_at, NOW())) AS duration_seconds,
        COALESCE(
            (SELECT origin_completion_location.name
             FROM attendance_work_completions origin_completion
             JOIN attendance_locations origin_completion_location ON origin_completion_location.id=origin_completion.location_id
             WHERE origin_completion.worker_id=t.worker_id
               AND origin_completion.work_date=t.trip_date
               AND origin_completion.completed_at<=t.started_at
               AND origin_completion.completed_at>=COALESCE(
                    (SELECT MAX(previous_trip.ended_at)
                     FROM attendance_trips previous_trip
                     WHERE previous_trip.worker_id=t.worker_id
                       AND previous_trip.trip_date=t.trip_date
                       AND previous_trip.status='finalizado'
                       AND previous_trip.ended_at<=t.started_at),
                    '1000-01-01 00:00:00')
             ORDER BY origin_completion.completed_at DESC,origin_completion.id DESC LIMIT 1),
            (SELECT origin_trip_location.name
             FROM attendance_trips origin_trip
             JOIN attendance_locations origin_trip_location ON origin_trip_location.id=origin_trip.last_location_id
             WHERE origin_trip.worker_id=t.worker_id
               AND origin_trip.trip_date=t.trip_date
               AND origin_trip.status='finalizado'
               AND origin_trip.ended_at<=t.started_at
             ORDER BY origin_trip.ended_at DESC,origin_trip.id DESC LIMIT 1),
            l.name
        ) AS origin_name,
        dl.name AS arrival_location,dl.latitude AS location_latitude,dl.longitude AS location_longitude,
        dl.radius_meters AS location_radius,dl.address AS location_address
    FROM attendance_trips t
    JOIN attendance_assignments a ON a.id = t.assignment_id
    JOIN attendance_locations l ON l.id = a.location_id
    LEFT JOIN attendance_locations dl ON dl.id=t.last_location_id
    WHERE t.worker_id = :worker_id
    ORDER BY CASE WHEN t.status = 'en_ruta' THEN 0 ELSE 1 END, t.started_at DESC, t.id DESC LIMIT 40");
$stmt->execute(['worker_id' => $workerId]);
$stopsStmt = db()->prepare("SELECT stop_order, destination, activity, registered_at, latitude, longitude FROM attendance_trip_stops WHERE trip_id = :trip_id ORDER BY stop_order, id");
$completionStmt = db()->prepare("SELECT activity,observations,completed_at,latitude,longitude
    FROM attendance_work_completions
    WHERE worker_id=:worker_id AND location_id=:location_id AND work_date=:work_date
      AND (:ended_at IS NULL OR completed_at>=:ended_match)
    ORDER BY completed_at,id LIMIT 1");
$rows = [];
foreach ($stmt->fetchAll() as $trip) {
    $stopsStmt->execute(['trip_id' => (int) $trip['id']]);
    $completion = null;
    if ((int)($trip['last_location_id'] ?? 0) > 0) {
        $completionStmt->execute([
            'worker_id'=>(int)$trip['worker_id'],'location_id'=>(int)$trip['last_location_id'],'work_date'=>$trip['trip_date'],
            'ended_at'=>$trip['ended_at'],'ended_match'=>$trip['ended_at'],
        ]);
        $completion = $completionStmt->fetch() ?: null;
    }
    $arrivalDistance = null;
    if ($trip['end_latitude'] !== null && $trip['end_longitude'] !== null && $trip['location_latitude'] !== null && $trip['location_longitude'] !== null) {
        $earth=6371000; $lat1=(float)$trip['end_latitude']; $lon1=(float)$trip['end_longitude'];
        $lat2=(float)$trip['location_latitude']; $lon2=(float)$trip['location_longitude'];
        $dLat=deg2rad($lat2-$lat1); $dLon=deg2rad($lon2-$lon1);
        $a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
        $arrivalDistance=round($earth*2*atan2(sqrt($a),sqrt(1-$a)),1);
    }
    $rows[] = [
        'id' => (int) $trip['id'], 'date' => date('d/m/Y', strtotime((string) $trip['trip_date'])),
        'started_at' => (string) $trip['started_at'], 'ended_at' => $trip['ended_at'] ? (string) $trip['ended_at'] : null,
        'duration_seconds' => max(0, (int) $trip['duration_seconds']), 'origin' => (string) $trip['origin_name'],
        'first_destination' => (string) $trip['first_destination'], 'reason' => (string) $trip['reason'], 'status' => (string) $trip['status'],
        'completion_type'=>(string)$trip['completion_type'],'exception_reason'=>$trip['exception_reason'] ? (string)$trip['exception_reason'] : null,
        'is_planned_route'=>(bool)$trip['is_planned_route'],
        'arrival' => $trip['ended_at'] ? [
            'destination'=>(string)($trip['arrival_location'] ?: $trip['first_destination']),
            'registered_at'=>(string)$trip['ended_at'],
            'registered_date'=>date('d/m/Y',strtotime((string)$trip['ended_at'])),
            'registered_time'=>date('H:i',strtotime((string)$trip['ended_at'])),
            'latitude'=>$trip['end_latitude'] !== null ? (float)$trip['end_latitude'] : null,
            'longitude'=>$trip['end_longitude'] !== null ? (float)$trip['end_longitude'] : null,
            'distance_meters'=>$arrivalDistance,
            'radius_meters'=>$trip['location_radius'] !== null ? (int)$trip['location_radius'] : null,
            'location_latitude'=>$trip['location_latitude'] !== null ? (float)$trip['location_latitude'] : null,
            'location_longitude'=>$trip['location_longitude'] !== null ? (float)$trip['location_longitude'] : null,
            'address'=>$trip['location_address'] ? (string)$trip['location_address'] : null,
            'activity'=>$completion['activity'] ?? null,
            'work_completed_at'=>$completion['completed_at'] ?? null,
            'work_completed_date'=>!empty($completion['completed_at']) ? date('d/m/Y',strtotime((string)$completion['completed_at'])) : null,
            'work_completed_time'=>!empty($completion['completed_at']) ? date('H:i',strtotime((string)$completion['completed_at'])) : null,
            'observations'=>$completion['observations'] ?? null,
        ] : null,
        'stops' => array_map(static fn(array $stop): array => [
            'order' => (int) $stop['stop_order'], 'destination' => (string) $stop['destination'],
            'activity' => $stop['activity'] ? (string) $stop['activity'] : null, 'registered_at' => (string) $stop['registered_at'],
            'registered_date' => date('d/m/Y', strtotime((string) $stop['registered_at'])),
            'registered_time' => date('H:i', strtotime((string) $stop['registered_at'])),
            'latitude' => $stop['latitude'] !== null ? (float) $stop['latitude'] : null,
            'longitude' => $stop['longitude'] !== null ? (float) $stop['longitude'] : null,
        ], $stopsStmt->fetchAll()),
    ];
}
json_response(['ok' => true, 'rows' => $rows]);
