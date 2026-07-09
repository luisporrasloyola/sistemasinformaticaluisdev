<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/config/database.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$type = (string) ($_GET['tipo'] ?? '');
$column = match ($type) {
    'photo' => 'photo_path',
    'signature' => 'signature_path',
    'requirement' => 'file_path',
    default => '',
};

if (!$id || !$column) {
    http_response_code(404);
    exit;
}

if ($type === 'requirement') {
    $stmt = db()->prepare('SELECT file_path AS path, original_file_name AS original FROM worker_requirements WHERE id = :id');
} else {
    $stmt = db()->prepare("SELECT {$column} AS path, {$column} AS original FROM workers WHERE id = :id");
}
$stmt->execute(['id' => $id]);
$file = $stmt->fetch();

$fullPath = $file ? realpath(__DIR__ . DIRECTORY_SEPARATOR . $file['path']) : false;
$archivosRoot = realpath(UPLOAD_PATH);

if (!$fullPath || !$archivosRoot || !str_starts_with($fullPath, $archivosRoot) || !is_file($fullPath)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename((string) $file['original']) . '"');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
