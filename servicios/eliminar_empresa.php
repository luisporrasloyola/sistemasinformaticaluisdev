<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Empresa no válida.'], 400);
}

$stmt = db()->prepare('SELECT name FROM companies WHERE id = :id');
$stmt->execute(['id' => $id]);
$company = $stmt->fetch();
if (!$company) {
    json_response(['ok' => false, 'message' => 'No se encontró la empresa.'], 404);
}

$stmt = db()->prepare('SELECT COUNT(*) FROM workers WHERE company_id = :id');
$stmt->execute(['id' => $id]);
if ((int) $stmt->fetchColumn() > 0) {
    json_response(['ok' => false, 'message' => 'No se puede eliminar la empresa porque ya está asignada a personal.'], 409);
}

$stmt = db()->prepare('SELECT COUNT(*) FROM maquinarias WHERE company_id = :id');
$stmt->execute(['id' => $id]);
if ((int) $stmt->fetchColumn() > 0) {
    json_response(['ok' => false, 'message' => 'No se puede eliminar la empresa porque ya esta asignada a maquinaria.'], 409);
}

db()->prepare('UPDATE companies SET status = 0 WHERE id = :id')->execute(['id' => $id]);
json_response(['ok' => true]);
