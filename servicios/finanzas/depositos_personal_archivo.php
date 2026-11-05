<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_any_module_access(['finanzas.depositos_personal', 'finanzas.gastos_personal']);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(400); exit('Archivo no válido.'); }
$stmt = db()->prepare('SELECT image_path, creditor FROM finance_personal_deposits WHERE id = ?');
$stmt->execute([$id]);
$deposit = $stmt->fetch(PDO::FETCH_ASSOC);
$relative = $deposit['image_path'] ?? null;
if (is_personal_role()) {
    require_once __DIR__ . '/../../includes/finanzas_depositos_personal_match.php';
    if (!$deposit || !personal_deposit_is_own((string) $deposit['creditor'])) {
        http_response_code(403);
        exit('Solo puede consultar sus propios comprobantes.');
    }
}
if (!$relative || !str_starts_with((string) $relative, 'archivos/finanzas_depositos_personal/')) { http_response_code(404); exit('Archivo no encontrado.'); }
$root = realpath(UPLOAD_PATH . DIRECTORY_SEPARATOR . 'finanzas_depositos_personal');
$path = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relative);
if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) { http_response_code(404); exit('Archivo no encontrado.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(404); exit('Archivo no válido.'); }
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="deposito-' . $id . '.' . ($mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/png' ? 'png' : 'webp')) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($path));
readfile($path);
