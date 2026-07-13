<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);
$nombre = trim((string) ($_POST['nombre'] ?? ''));

if ($nombre === '') {
    json_response(['ok' => false, 'message' => 'Ingrese un documento.'], 400);
}

$stmt = db()->prepare('INSERT INTO empresa_documentos_catalogo (nombre, estado) VALUES (:nombre, 1) ON DUPLICATE KEY UPDATE estado = 1, id = LAST_INSERT_ID(id)');
$stmt->execute(['nombre' => $nombre]);
$id = (int) db()->lastInsertId();

json_response(['ok' => true, 'id' => $id, 'text' => $nombre]);
