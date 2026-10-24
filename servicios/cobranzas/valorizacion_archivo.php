<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('cobranzas.valorizacion');

$recordId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$recordId) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}
$stmt = db()->prepare('SELECT archivo_path, archivo_nombre_original FROM cobranzas_valorizacion_registros WHERE id = ?');
$stmt->execute([$recordId]);
$record = $stmt->fetch();
$root = realpath(UPLOAD_PATH . DIRECTORY_SEPARATOR . 'cobranzas_valorizacion');
$path = $record ? realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $record['archivo_path']) : false;
if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}
$mime = mime_content_type($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
    http_response_code(404);
    exit('Archivo no disponible.');
}
$original = basename((string) $record['archivo_nombre_original']);
$safeName = str_replace(['"', "\r", "\n"], '_', $original);
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . (($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline') . '; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
