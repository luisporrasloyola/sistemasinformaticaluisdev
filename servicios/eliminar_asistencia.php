<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/asistencia.php';
require_login();
ensure_attendance_schema();

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Registro inválido.'], 400);
}

$stmt = db()->prepare('DELETE FROM attendance_control WHERE id = :id');
$stmt->execute(['id' => $id]);
json_response(['ok' => true]);