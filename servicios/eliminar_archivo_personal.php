<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);
$type = (string) ($_POST['type'] ?? '');
$column = $type === 'photo' ? 'photo_path' : ($type === 'signature' ? 'signature_path' : '');

if (!$id || !$column) {
    json_response(['ok' => false, 'message' => 'Solicitud inválida.'], 400);
}

$stmt = db()->prepare("SELECT {$column} FROM workers WHERE id = :id");
$stmt->execute(['id' => $id]);
$path = $stmt->fetchColumn();
delete_uploaded_file($path ?: null);

$stmt = db()->prepare("UPDATE workers SET {$column} = NULL WHERE id = :id");
$stmt->execute(['id' => $id]);
json_response(['ok' => true]);

