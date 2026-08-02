<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.programacion');
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'La jornada seleccionada no es valida.'], 422);
}

$stmt = db()->prepare("SELECT id, worker_id, calendar_date
    FROM attendance_calendar_days
    WHERE id = :id AND status = 1 AND scope_type = 'worker'
      AND event_type = 'rest' AND name = 'Jornada excluida'
    LIMIT 1");
$stmt->execute(['id' => $id]);
$event = $stmt->fetch();
if (!$event) {
    json_response(['ok' => false, 'message' => 'La exclusion ya no existe o no puede restaurarse desde esta vista.'], 404);
}
if ((string) $event['calendar_date'] < date('Y-m-d')) {
    json_response(['ok' => false, 'message' => 'No se puede modificar una jornada de una fecha pasada.'], 409);
}

$marks = db()->prepare('SELECT 1 FROM attendance_marks WHERE worker_id = :worker_id AND mark_date = :mark_date LIMIT 1');
$marks->execute([
    'worker_id' => (int) $event['worker_id'],
    'mark_date' => (string) $event['calendar_date'],
]);
if ($marks->fetchColumn()) {
    json_response(['ok' => false, 'message' => 'No se puede restaurar porque la fecha ya tiene marcaciones registradas.'], 409);
}

$update = db()->prepare('UPDATE attendance_calendar_days SET status = 0 WHERE id = :id AND status = 1');
$update->execute(['id' => $id]);
json_response(['ok' => true, 'message' => 'La jornada habitual fue restaurada para esta fecha.']);
