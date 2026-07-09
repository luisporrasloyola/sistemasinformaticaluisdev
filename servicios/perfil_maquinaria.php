<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT m.*, c.name AS empresa
    FROM maquinarias m
    LEFT JOIN companies c ON c.id = m.company_id
    WHERE m.id = :id');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false, 'message' => 'No se encontro la maquinaria.'], 404);
}
json_response(['ok' => true, 'maquinaria' => $row]);
