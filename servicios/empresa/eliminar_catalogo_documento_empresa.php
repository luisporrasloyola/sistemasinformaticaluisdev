<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa.documentos');

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Seleccione un documento valido.'], 400);
}

$stmt = db()->prepare('SELECT id, nombre FROM empresa_documentos_catalogo WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$documento = $stmt->fetch();

if (!$documento) {
    json_response(['ok' => false, 'message' => 'El documento no existe.'], 404);
}

if (!current_user_can_document('empresa.documentos', $id, 'manage')) {
    json_response(['ok' => false, 'message' => 'No tiene permisos para eliminar este documento.'], 403);
}

$used = db()->prepare('SELECT COUNT(*) FROM empresa_documentos WHERE documento_id = :id');
$used->execute(['id' => $id]);

if ((int) $used->fetchColumn() > 0) {
    json_response(['ok' => false, 'message' => 'No se puede eliminar porque este documento ya tiene registros asociados.'], 409);
}

db()->prepare('UPDATE empresa_documentos_catalogo SET estado = 0 WHERE id = :id')->execute(['id' => $id]);

json_response(['ok' => true, 'message' => 'Documento eliminado del catalogo.']);
