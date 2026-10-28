<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/pmi_project_scope.php';
require_module_access('clientes.pmi');

$clientId = max(0, (int) ($_GET['cliente_id'] ?? 0));
$habilitationId = max(0, (int) ($_GET['habilitacion_id'] ?? 0));
try { $projectId = pmi_project_id($clientId, $_GET); }
catch (DomainException $error) { http_response_code(404); exit('Pase no encontrado.'); }
$stmt = db()->prepare("SELECT h.pass_file_path, h.habilitation_type, h.group_title, w.full_name FROM client_pmi_habilitations h
    JOIN workers w ON w.id = h.subject_id
    WHERE h.id = ? AND h.client_id = ? AND h.project_id = ? AND h.area = 'personal' AND h.has_pass = 1");
$stmt->execute([$habilitationId, $clientId, $projectId]);
$row = $stmt->fetch();
$relative = (string) ($row['pass_file_path'] ?? '');
if (!preg_match('~^archivos/clientes_pmi_pases/[a-f0-9]{32}\.(pdf|docx?|xlsx?|jpe?g|png|webp)$~i', $relative)) {
    http_response_code(404); exit('Pase no encontrado.');
}
$root = realpath(UPLOAD_PATH . DIRECTORY_SEPARATOR . 'clientes_pmi_pases');
$path = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relative);
if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404); exit('Archivo no encontrado.');
}
$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$name = $row['habilitation_type'] === 'grupal' && trim((string) $row['group_title']) !== '' ? $row['group_title'] : $row['full_name'];
$safeName = trim((string) preg_replace('/[^\pL\pN _-]+/u', '', (string) $name));
$safeName = ($safeName !== '' ? $safeName : 'Personal') . ' - Pase.' . $extension;
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
$disposition = in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true) ? 'inline' : 'attachment';
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="Pase.' . $extension . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
header('Content-Length: ' . filesize($path));
readfile($path);
