<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT foto_path FROM maquinarias WHERE id = :id');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['ok' => false, 'message' => 'No se encontro la maquinaria.'], 404);
}

$pdfs = db()->prepare('SELECT archivo_path FROM maquinaria_documentos WHERE maquinaria_id = :id');
$pdfs->execute(['id' => $id]);
foreach ($pdfs->fetchAll() as $pdf) {
    delete_uploaded_file($pdf['archivo_path'] ?? null);
}
delete_uploaded_file($row['foto_path'] ?? null);

db()->prepare('DELETE FROM maquinarias WHERE id = :id')->execute(['id' => $id]);
json_response(['ok' => true]);
