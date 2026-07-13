<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['empresa_id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Seleccione una empresa.'], 400);
}

try {
    $currentStmt = db()->prepare('SELECT foto_path FROM empresas WHERE id = :id AND status = 1');
    $currentStmt->execute(['id' => $id]);
    $current = $currentStmt->fetch();

    if (!$current) {
        json_response(['ok' => false, 'message' => 'No se encontro la empresa.'], 404);
    }

    $photo = upload_file($_FILES['foto'] ?? [], 'empresas', ['image/jpeg', 'image/png', 'image/webp']);
    if (!$photo['path']) {
        json_response(['ok' => false, 'message' => 'Seleccione una imagen.'], 400);
    }

    delete_uploaded_file($current['foto_path'] ?? null);
    db()->prepare('UPDATE empresas SET foto_path = :foto_path WHERE id = :id')->execute([
        'foto_path' => $photo['path'],
        'id' => $id,
    ]);

    json_response(['ok' => true, 'path' => APP_URL . '/' . $photo['path']]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
