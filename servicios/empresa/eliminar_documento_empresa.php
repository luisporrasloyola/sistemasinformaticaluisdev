<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT archivo_path FROM empresa_documentos WHERE id = :id');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false], 404);
}
delete_uploaded_file($row['archivo_path'] ?? null);
db()->prepare('DELETE FROM empresa_documentos WHERE id = :id')->execute(['id' => $id]);
json_response(['ok' => true]);
