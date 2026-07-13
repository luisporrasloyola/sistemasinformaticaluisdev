<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);
$currentUser = current_user();

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Usuario no válido.'], 400);
}

if ($currentUser && (int) $currentUser['id'] === $id) {
    json_response(['ok' => false, 'message' => 'No puede eliminar su propio usuario.'], 400);
}

db()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
json_response(['ok' => true]);
