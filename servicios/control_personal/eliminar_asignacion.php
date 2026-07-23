<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Asignacion no valida.'], 400);
}

$userId = (int) (current_user()['id'] ?? 0) ?: null;
$stmt = db()->prepare('UPDATE attendance_assignments
    SET status = 0, deactivated_at = NOW(), deactivated_by_user_id = :user_id
    WHERE id = :id AND status = 1');
$stmt->execute(['id' => $id, 'user_id' => $userId]);
if ($stmt->rowCount() === 0) {
    json_response(['ok' => false, 'message' => 'La asignación ya estaba desactivada o no existe.'], 409);
}
json_response(['ok' => true, 'message' => 'Asignación desactivada. El historial de marcaciones se conservó.']);
