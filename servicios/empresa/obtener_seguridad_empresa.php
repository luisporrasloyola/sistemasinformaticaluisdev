<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT ed.*, edc.nombre AS documento
    FROM empresa_seguridad_documentos ed
    JOIN empresa_seguridad_catalogo edc ON edc.id = ed.documento_id
    WHERE ed.id = :id");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false], 404);
}
json_response(['ok' => true, 'row' => $row]);
