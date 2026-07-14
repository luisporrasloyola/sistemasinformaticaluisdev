<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa.seguridad');

verify_csrf($_POST['csrf_token'] ?? null);
$nombre = trim((string) ($_POST['nombre'] ?? ''));
if ($nombre === '') json_response(['ok' => false, 'message' => 'Ingrese un documento.'], 400);

if (!current_user_can_manage_scope('empresa.seguridad')) {
    json_response(['ok' => false, 'message' => 'No tiene permisos para agregar documentos.'], 403);
}

$exists = db()->prepare('SELECT id FROM empresa_seguridad_catalogo WHERE LOWER(nombre) = LOWER(:nombre) LIMIT 1');
$exists->execute(['nombre' => $nombre]);
if ($exists->fetch()) {
    json_response(['ok' => false, 'message' => 'Este documento ya existe.'], 409);
}

$stmt = db()->prepare('INSERT INTO empresa_seguridad_catalogo (nombre, estado) VALUES (:nombre, 1) ON DUPLICATE KEY UPDATE estado = 1, id = LAST_INSERT_ID(id)');
$stmt->execute(['nombre' => $nombre]);
json_response(['ok' => true, 'id' => (int) db()->lastInsertId(), 'text' => $nombre]);
