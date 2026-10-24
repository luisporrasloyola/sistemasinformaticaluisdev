<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('cobranzas.parte_diario');

$recordId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($recordId === false || $recordId === null) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$stmt = db()->prepare('SELECT archivo_path, archivo_nombre_original FROM cobranzas_parte_diario_registros WHERE id = ?');
$stmt->execute([$recordId]);
$record = $stmt->fetch();
$root = realpath(UPLOAD_PATH . DIRECTORY_SEPARATOR . 'cobranzas_parte_diario');
$pathValue = $record ? $record['archivo_path'] : null;
$path = $pathValue ? realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $pathValue) : false;
if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$mime = mime_content_type($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
    http_response_code(404);
    exit('Archivo no disponible.');
}
$original = basename((string) ($record['archivo_nombre_original'] ?: basename($path)));
$safeName = str_replace(['"', "\r", "\n"], '_', $original);
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
