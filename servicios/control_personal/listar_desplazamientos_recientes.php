<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.control_asistencia');

$workerId = (int) ($_GET['worker_id'] ?? 0);
if (is_personal_role()) $workerId = (int) current_user_worker_id();
if ($workerId <= 0) json_response(['ok' => false, 'message' => 'Seleccione un trabajador.'], 400);

$stmt = db()->prepare("SELECT t.id, t.trip_date, t.reason, t.first_destination, t.status, t.started_at, t.ended_at,
        TIMESTAMPDIFF(SECOND, t.started_at, COALESCE(t.ended_at, NOW())) AS duration_seconds, l.name AS origin_name
    FROM attendance_trips t
    JOIN attendance_assignments a ON a.id = t.assignment_id
    JOIN attendance_locations l ON l.id = a.location_id
    WHERE t.worker_id = :worker_id
    ORDER BY CASE WHEN t.status = 'en_ruta' THEN 0 ELSE 1 END, t.started_at DESC, t.id DESC LIMIT 40");
$stmt->execute(['worker_id' => $workerId]);
$stopsStmt = db()->prepare("SELECT stop_order, destination, activity, registered_at, latitude, longitude FROM attendance_trip_stops WHERE trip_id = :trip_id ORDER BY stop_order, id");
$rows = [];
foreach ($stmt->fetchAll() as $trip) {
    $stopsStmt->execute(['trip_id' => (int) $trip['id']]);
    $rows[] = [
        'id' => (int) $trip['id'], 'date' => date('d/m/Y', strtotime((string) $trip['trip_date'])),
        'started_at' => (string) $trip['started_at'], 'ended_at' => $trip['ended_at'] ? (string) $trip['ended_at'] : null,
        'duration_seconds' => max(0, (int) $trip['duration_seconds']), 'origin' => (string) $trip['origin_name'],
        'first_destination' => (string) $trip['first_destination'], 'reason' => (string) $trip['reason'], 'status' => (string) $trip['status'],
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
