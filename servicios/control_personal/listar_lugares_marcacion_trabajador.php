<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_location_access.php';
require_module_access('control_personal.control_asistencia');

$requestedWorkerId = max(0, (int) ($_GET['worker_id'] ?? 0));
$linkedWorkerId = (int) (current_user_worker_id() ?? 0);
$workerId = is_personal_role() ? $linkedWorkerId : $requestedWorkerId;

if ($workerId <= 0) {
    json_response(['ok' => false, 'message' => 'Seleccione un trabajador.'], 422);
}

$workerStmt = db()->prepare('SELECT id FROM workers WHERE id=:id LIMIT 1');
$workerStmt->execute(['id' => $workerId]);
if (!$workerStmt->fetchColumn()) {
    json_response(['ok' => false, 'message' => 'El trabajador seleccionado no existe.'], 404);
}

$accessCondition = attendance_location_access_condition('l');
$stmt = db()->prepare("SELECT DISTINCT l.id,l.name,l.latitude,l.longitude,l.radius_meters
    FROM attendance_locations l
    WHERE l.status=1 AND {$accessCondition}
    ORDER BY l.name");
$stmt->execute(attendance_location_access_params($workerId));
$locations = array_map(static fn(array $location): array => [
    'id' => (int) $location['id'],
    'name' => (string) $location['name'],
    'latitude' => (float) $location['latitude'],
    'longitude' => (float) $location['longitude'],
    'radius_meters' => (int) $location['radius_meters'],
], $stmt->fetchAll());

json_response([
    'ok' => true,
    'worker_id' => $workerId,
    'locations' => $locations,
    'count' => count($locations),
]);