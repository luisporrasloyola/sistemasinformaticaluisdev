<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../config/database.php';
require_login();
if (is_personal_role()) json_response(['ok' => false, 'message' => 'El rol Personal solo puede visualizar su información.'], 403);

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Personal no válido.'], 400);
}

$stmt = db()->prepare('SELECT photo_path, signature_path FROM workers WHERE id = :id');
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

    $stmt = db()->prepare('SELECT file_path, word_file_path, image_file_path FROM worker_requirements WHERE worker_id = :id');
    $stmt->execute(['id' => $id]);
    foreach ($stmt->fetchAll() as $row) {
        foreach (['file_path', 'word_file_path', 'image_file_path'] as $column) if (!empty($row[$column])) $files[] = $row[$column];
    }

    db()->prepare('DELETE FROM workers WHERE id = :id')->execute(['id' => $id]);
    db()->commit();

    foreach (array_unique(array_filter($files)) as $file) {
        delete_uploaded_file($file);
    }

    json_response(['ok' => true]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    json_response(['ok' => false, 'message' => 'No se pudo eliminar el personal.'], 400);
}
