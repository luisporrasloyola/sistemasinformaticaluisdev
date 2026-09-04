<?php
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../../../includes/upload.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/file_safety.php';
require_login();
if (is_personal_role()) json_response(['ok' => false, 'message' => 'El rol Personal solo puede visualizar sus requisitos.'], 403);

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['worker_id'] ?? 0);

try {
    $currentStmt = db()->prepare('SELECT photo_path FROM empresa_maquirenta_formato_personal WHERE id = :id');
    $currentStmt->execute(['id' => $id]);
    $current = $currentStmt->fetch();
    $photo = upload_file($_FILES['photo'] ?? [], 'empresa_maquirenta/personal/fotos', ['image/jpeg','image/png','image/webp']);
    if (!$photo['path']) {
        json_response(['ok' => false, 'message' => 'Seleccione una imagen.'], 400);
    }
    delete_empresa_maquirenta_formato_file($current['photo_path'] ?? null);
    db()->prepare('UPDATE empresa_maquirenta_formato_personal SET photo_path = :photo_path WHERE id = :id')->execute(['photo_path' => $photo['path'], 'id' => $id]);
    json_response(['ok' => true, 'path' => APP_URL . '/' . $photo['path']]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
