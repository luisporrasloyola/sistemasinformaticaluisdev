<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_module_access('requisitos.pmi_individual');

$workerId = filter_input(INPUT_GET, 'worker_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$type = (string) ($_GET['tipo'] ?? '');
if (!$workerId || !in_array($type, ['foto', 'firma'], true)) {
    http_response_code(400);
    exit('Solicitud inválida.');
}
require_personal_own_worker($workerId, false);

$column = $type === 'foto' ? 'photo_path' : 'signature_path';
$folder = $type === 'foto' ? 'fotos' : 'firmas';
$stmt = db()->prepare("SELECT full_name, {$column} AS file_path FROM workers WHERE id = :id");
$stmt->execute(['id' => $workerId]);
$worker = $stmt->fetch();
$relativePath = (string) ($worker['file_path'] ?? '');
if (!$worker || !preg_match('~^archivos/' . $folder . '/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$~i', $relativePath)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$root = realpath(UPLOAD_PATH . DIRECTORY_SEPARATOR . $folder);
$file = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath);
if (!$root || !$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = match ($extension) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
};
$name = trim((string) preg_replace('/[^\pL\pN _-]+/u', '', (string) $worker['full_name']));
$name = ($name !== '' ? $name : 'Trabajador') . ' - ' . ($type === 'foto' ? 'Foto' : 'Firma') . '.' . $extension;
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . (preg_replace('/[^A-Za-z0-9._ -]/', '', $name) ?: ($type . '.' . $extension)) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . filesize($file));
readfile($file);
