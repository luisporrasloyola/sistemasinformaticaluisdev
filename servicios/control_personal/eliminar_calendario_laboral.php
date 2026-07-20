<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.calendario_laboral');
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Registro no valido.'], 400);
}

$stmt = db()->prepare('UPDATE attendance_calendar_days SET status = 0 WHERE id = :id');
$stmt->execute(['id' => $id]);
json_response(['ok' => true]);

