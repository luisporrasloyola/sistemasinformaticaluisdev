<?php
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../../../includes/upload.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/file_safety.php';
require_login();
if (is_personal_role()) json_response(['ok' => false, 'message' => 'El rol Personal solo puede visualizar sus requisitos.'], 403);

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT file_path FROM empresa_maquirenta_formato_requisitos WHERE id = :id');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();
if ($row) {
    delete_empresa_maquirenta_formato_file($row['file_path']);
    db()->prepare('DELETE FROM empresa_maquirenta_formato_requisitos WHERE id = :id')->execute(['id' => $id]);
}
json_response(['ok' => true]);

