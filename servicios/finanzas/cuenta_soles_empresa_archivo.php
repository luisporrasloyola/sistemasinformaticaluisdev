<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('finanzas.gastos_personal');
if (is_personal_role()) { http_response_code(403); exit('Sin acceso.'); }
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(400); exit('Archivo no válido.'); }
$stmt = db()->prepare('SELECT c.proof_path FROM finance_company_sole_deposit_details c JOIN finance_personal_deposits p ON p.id = c.source_deposit_id WHERE c.source_deposit_id = ? AND p.depositor = ?');
$stmt->execute([$id, 'Cuenta soles empresa']);
$relative = $stmt->fetchColumn();
if (!$relative || !str_starts_with((string) $relative, 'archivos/finanzas_cuenta_soles/')) { http_response_code(404); exit('Archivo no encontrado.'); }
$root = realpath(UPLOAD_PATH . DIRECTORY_SEPARATOR . 'finanzas_cuenta_soles');
$path = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relative);
if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) { http_response_code(404); exit('Archivo no encontrado.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(404); exit('Archivo no válido.'); }
$extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="cuenta-soles-' . $id . '.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($path));
readfile($path);
