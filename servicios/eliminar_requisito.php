<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT file_path FROM worker_requirements WHERE id = :id');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();
if ($row) {
    delete_uploaded_file($row['file_path']);
    db()->prepare('DELETE FROM worker_requirements WHERE id = :id')->execute(['id' => $id]);
}
json_response(['ok' => true]);

