<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Asignacion no valida.'], 400);
}

db()->prepare('UPDATE attendance_assignments SET status = 0 WHERE id = :id')->execute(['id' => $id]);
json_response(['ok' => true]);
