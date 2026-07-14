<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_any_module_access(['empresa.datos_generales', 'empresa.documentos', 'empresa.seguridad', 'empresa.calidad', 'empresa.medio_ambiente']);

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM empresas WHERE id = :id AND status = 1');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false, 'message' => 'No se encontro la empresa.'], 404);
}
json_response(['ok' => true, 'empresa' => $row]);
