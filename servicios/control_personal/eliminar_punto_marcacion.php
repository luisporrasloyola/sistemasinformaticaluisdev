<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Punto de marcacion no valido.'], 400);
}

$used = db()->prepare('SELECT COUNT(*) FROM attendance_assignments WHERE location_id = :id AND status = 1');
$used->execute(['id' => $id]);
if ((int) $used->fetchColumn() > 0) {
    json_response(['ok' => false, 'message' => 'No se puede eliminar el punto porque tiene asignaciones activas.'], 409);
}

db()->prepare('UPDATE attendance_locations SET status = 0 WHERE id = :id')->execute(['id' => $id]);
json_response(['ok' => true]);
