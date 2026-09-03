<?php
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../../../includes/upload.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/file_safety.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT file_path FROM empresa_maquirenta_formato_requisitos WHERE id = :id');
$stmt->execute(['id' => $id]);
$path = $stmt->fetchColumn();
delete_empresa_maquirenta_formato_file($path ?: null);
db()->prepare('UPDATE empresa_maquirenta_formato_requisitos SET file_path = NULL, original_file_name = NULL WHERE id = :id')->execute(['id' => $id]);
json_response(['ok' => true]);

