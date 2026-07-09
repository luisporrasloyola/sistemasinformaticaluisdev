<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT md.*, mdc.nombre AS documento
    FROM maquinaria_documentos md
    JOIN maquinaria_documentos_catalogo mdc ON mdc.id = md.documento_id
    WHERE md.id = :id");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false], 404);
}
json_response(['ok' => true, 'row' => $row]);
