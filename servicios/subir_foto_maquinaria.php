<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['maquinaria_id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Seleccione una maquinaria.'], 400);
}

try {
    $currentStmt = db()->prepare('SELECT foto_path FROM maquinarias WHERE id = :id');
    $currentStmt->execute(['id' => $id]);
    $current = $currentStmt->fetch();

    if (!$current) {
        json_response(['ok' => false, 'message' => 'No se encontró la maquinaria.'], 404);
    }

    $photo = upload_file($_FILES['foto'] ?? [], 'maquinarias', ['image/jpeg', 'image/png', 'image/webp']);
    if (!$photo['path']) {
        json_response(['ok' => false, 'message' => 'Seleccione una imagen.'], 400);
    }

    delete_uploaded_file($current['foto_path'] ?? null);
    db()->prepare('UPDATE maquinarias SET foto_path = :foto_path WHERE id = :id')->execute([
        'foto_path' => $photo['path'],
        'id' => $id,
    ]);

    json_response(['ok' => true, 'path' => APP_URL . '/' . $photo['path']]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
