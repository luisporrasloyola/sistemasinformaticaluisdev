<?php
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../../../includes/upload.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/file_safety.php';
require_login();
if (is_personal_role()) json_response(['ok' => false, 'message' => 'El rol Personal solo puede visualizar su información.'], 403);

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Personal no válido.'], 400);
}

$stmt = db()->prepare('SELECT photo_path, signature_path FROM empresa_maquirenta_formato_personal WHERE id = :id');
$stmt->execute(['id' => $id]);
$worker = $stmt->fetch();

if (!$worker) {
    json_response(['ok' => false, 'message' => 'No se encontró el personal.'], 404);
}

try {
    db()->beginTransaction();

    $files = [];
    if (!empty($worker['photo_path'])) {
        $files[] = $worker['photo_path'];
    }
    if (!empty($worker['signature_path'])) {
        $files[] = $worker['signature_path'];
    }

    $stmt = db()->prepare("SELECT file_path FROM empresa_maquirenta_formato_requisitos WHERE worker_id = :id AND file_path IS NOT NULL AND file_path <> ''");
    $stmt->execute(['id' => $id]);
    foreach ($stmt->fetchAll() as $row) {
        $files[] = $row['file_path'];
    }

    db()->prepare('DELETE FROM empresa_maquirenta_formato_personal WHERE id = :id')->execute(['id' => $id]);
    db()->commit();

    foreach (array_unique(array_filter($files)) as $file) {
        delete_empresa_maquirenta_formato_file($file);
    }

    json_response(['ok' => true]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    json_response(['ok' => false, 'message' => 'No se pudo eliminar el personal.'], 400);
}