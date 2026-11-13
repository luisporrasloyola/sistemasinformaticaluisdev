<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('compras');
require_module_access('compras.registro');
if (is_personal_role()) { http_response_code(403); exit; }

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(400); exit('Archivo no válido.'); }
$stmt = db()->prepare('SELECT image_path, batch_id FROM purchase_records WHERE id = ?');
$stmt->execute([$id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$record) { http_response_code(404); exit('Registro no encontrado.'); }
$relative = $record['image_path'] ?? null;
if (!$relative && $record['batch_id'] !== null) {
    $batch = db()->prepare('SELECT image_path FROM purchase_record_batches WHERE id = ?');
    $batch->execute([(int) $record['batch_id']]);
    $relative = $batch->fetchColumn();
}
if (!is_string($relative) || !str_starts_with($relative, 'archivos/compras_registro/')) { http_response_code(404); exit('Archivo no encontrado.'); }
$root = realpath(UPLOAD_PATH . DIRECTORY_SEPARATOR . 'compras_registro');
$path = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relative);
if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) { http_response_code(404); exit('Archivo no encontrado.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) { http_response_code(404); exit('Archivo no válido.'); }
$extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime];
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="compra-' . $id . '.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($path));
readfile($path);
