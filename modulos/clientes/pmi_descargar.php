<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');
$clientId = (int) ($_GET['cliente_id'] ?? 0);
$type = (string) ($_GET['tipo'] ?? 'base');
if (!in_array($type, ['base', 'formatos'], true)) { http_response_code(400); exit('Tipo de descarga inválido.'); }
$ids = $_GET['ids'] ?? [];
if (!is_array($ids)) { $ids = []; }
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
if ($clientId < 1 || count($ids) > 500) { http_response_code(400); exit('Solicitud inválida.'); }
$clientStmt = db()->prepare('SELECT business_name FROM clients WHERE id = :id');
$clientStmt->execute(['id' => $clientId]);
$businessName = $clientStmt->fetchColumn();
if ($businessName === false) { http_response_code(404); exit('Cliente no encontrado.'); }
$safePart = static function (string $value, string $fallback): string {
    $clean = trim((string) preg_replace('/[^\pL\pN _-]+/u', '', $value));
    return $clean !== '' ? mb_substr($clean, 0, 100) : $fallback;
};
$clientName = $safePart((string) $businessName, 'Cliente');
$sql = $type === 'formatos'
    ? 'SELECT d.id, c.name, d.file_path FROM client_pmi_format_documents d JOIN client_pmi_format_catalog c ON c.id = d.catalog_id WHERE d.client_id = :id AND d.file_path IS NOT NULL ORDER BY c.name, d.id'
    : 'SELECT id, name, file_path FROM client_pmi_base_documents WHERE client_id = :id AND file_path IS NOT NULL ORDER BY name, id';
$stmt = db()->prepare($sql);
$stmt->execute(['id' => $clientId]);
$files = [];
$root = realpath(UPLOAD_PATH);
foreach ($stmt->fetchAll() as $row) {
    if ($ids && !in_array((int) $row['id'], $ids, true)) { continue; }
    $path = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $row['file_path']);
    if (!$path || !$root || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) { continue; }
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $requirementName = $safePart((string) $row['name'], 'Requisito');
    $files[] = ['path' => $path, 'name' => $clientName . ' - ' . $requirementName . '.' . $extension, 'id' => (int) $row['id']];
}
if (!$files) { redirect('modulos/clientes/pmi.php?cliente_id=' . $clientId . ($type === 'formatos' ? '&tab=formatos' : '') . '&error=' . urlencode('No hay archivos adjuntos disponibles para descargar.')); }
if (count($files) === 1) {
    $file = $files[0];
    header('Content-Type: application/octet-stream');
    $asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '', $file['name']) ?: 'documento.' . pathinfo($file['name'], PATHINFO_EXTENSION);
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
    header('Content-Length: ' . filesize($file['path']));
    readfile($file['path']);
    exit;
}
$temporary = tempnam(sys_get_temp_dir(), 'client_pmi_');
if ($temporary === false) { http_response_code(500); exit('No se pudo preparar la descarga.'); }
unlink($temporary);
$archivePath = $temporary . (class_exists('ZipArchive') ? '.zip' : '.tar');
try {
    $usedNames = [];
    foreach ($files as &$file) {
        $originalName = $file['name'];
        if (isset($usedNames[$originalName])) {
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            $baseName = substr($originalName, 0, -strlen($extension) - 1);
            $file['name'] = $baseName . ' - ' . $file['id'] . '.' . $extension;
        }
        $usedNames[$file['name']] = true;
    }
    unset($file);
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('No se pudo crear el archivo ZIP.'); }
        foreach ($files as $file) { $zip->addFile($file['path'], $file['name']); }
        $zip->close();
        $downloadName = $clientName . ($type === 'formatos' ? ' - Formatos PMI.zip' : ' - Documentos PMI.zip');
        $mime = 'application/zip';
    } else {
        $tar = new PharData($archivePath);
        foreach ($files as $file) { $tar->addFile($file['path'], $file['name']); }
        unset($tar);
        $downloadName = $clientName . ($type === 'formatos' ? ' - Formatos PMI.tar' : ' - Documentos PMI.tar');
        $mime = 'application/x-tar';
    }
    header('Content-Type: ' . $mime);
    $asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '', $downloadName) ?: 'Documentos PMI.' . pathinfo($downloadName, PATHINFO_EXTENSION);
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Content-Length: ' . filesize($archivePath));
    readfile($archivePath);
} catch (Throwable $error) {
    if (!headers_sent()) { http_response_code(500); echo 'No se pudo preparar la descarga.'; }
} finally {
    if (is_file($archivePath)) { unlink($archivePath); }
}
