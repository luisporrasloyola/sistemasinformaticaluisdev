<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_report_closure.php';
require_module_access('control_personal.calendario_laboral');
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Registro no valido.'], 400);
}

$eventStmt = db()->prepare('SELECT calendar_date, COALESCE(end_date, calendar_date) AS end_date, scope_type, worker_id
    FROM attendance_calendar_days WHERE id = :id AND status = 1 LIMIT 1');
$eventStmt->execute(['id' => $id]);
$event = $eventStmt->fetch();
if (!$event) json_response(['ok' => false, 'message' => 'El día ya no existe.'], 404);
try {
    attendance_report_assert_calendar_editable((string) $event['calendar_date'], (string) $event['end_date'],
        $event['scope_type'] === 'worker' ? [(int) $event['worker_id']] : []);
} catch (DomainException $error) {
    json_response(['ok' => false, 'message' => $error->getMessage()], 409);
}
$stmt = db()->prepare('UPDATE attendance_calendar_days SET status = 0 WHERE id = :id');
$stmt->execute(['id' => $id]);
json_response(['ok' => true]);

