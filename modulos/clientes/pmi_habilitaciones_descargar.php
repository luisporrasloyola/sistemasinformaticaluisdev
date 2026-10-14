<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');

$clientId = (int) ($_GET['cliente_id'] ?? 0);
$habilitationId = (int) ($_GET['habilitacion_id'] ?? 0);
$positionId = (int) ($_GET['puesto_id'] ?? 0);
$mode = (string) ($_GET['modo'] ?? '');
$ids = $_GET['ids'] ?? [];
if (!is_array($ids)) { $ids = []; }
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
$formatIds = $_GET['format_ids'] ?? [];
if (!is_array($formatIds)) { $formatIds = []; }
$formatIds = array_values(array_unique(array_filter(array_map('intval', $formatIds), static fn(int $id): bool => $id > 0)));
if ($clientId < 1 || $habilitationId < 1 || !in_array($mode, ['all', 'selected'], true) || count($ids) + count($formatIds) > 100 || ($mode === 'selected' && !$ids && !$formatIds)) {
    http_response_code(400); exit('Solicitud inválida.');
}
$stmt = db()->prepare('SELECT h.area, h.subject_id, c.business_name, COALESCE(w.full_name, m.equipo) AS subject_name FROM client_pmi_habilitations h JOIN clients c ON c.id = h.client_id LEFT JOIN workers w ON h.area = \'personal\' AND w.id = h.subject_id LEFT JOIN maquinarias m ON h.area = \'equipo\' AND m.id = h.subject_id WHERE h.id = ? AND h.client_id = ?');
$stmt->execute([$habilitationId, $clientId]);
$habilitation = $stmt->fetch();
if (!$habilitation) { http_response_code(404); exit('Habilitación no encontrada.'); }
$positionName = '';
if ($habilitation['area'] === 'personal') {
    $check = db()->prepare('SELECT p.name FROM client_pmi_habilitation_positions hp JOIN worker_positions wp ON wp.worker_id = ? AND wp.position_id = hp.position_id JOIN positions p ON p.id = hp.position_id WHERE hp.habilitation_id = ? AND hp.position_id = ?');
    $check->execute([$habilitation['subject_id'], $habilitationId, $positionId]);
    $positionName = (string) $check->fetchColumn();
    if ($positionName === '' && ($positionId > 0 || $ids)) { http_response_code(400); exit('Puesto inválido.'); }
    $stmt = db()->prepare("SELECT wr.id, rc.name, wr.file_path FROM client_pmi_general_items gi JOIN requirements_catalog rc ON rc.id = gi.source_id
        JOIN worker_requirements wr ON wr.id = (
            SELECT latest.id FROM worker_requirements latest WHERE latest.worker_id = :subject_id AND latest.position_id = :position_id AND latest.requirement_id = gi.source_id
            ORDER BY latest.registration_date DESC, latest.id DESC LIMIT 1
        ) WHERE gi.client_id = :client_id AND gi.category = 'personal' AND wr.file_path IS NOT NULL ORDER BY rc.name LIMIT 101");
    $stmt->execute(['subject_id' => $habilitation['subject_id'], 'position_id' => $positionId, 'client_id' => $clientId]);
} else {
    $stmt = db()->prepare("SELECT md.id, dc.nombre AS name, md.archivo_path AS file_path
        FROM client_pmi_general_items gi JOIN maquinaria_documentos_catalogo dc ON dc.id = gi.source_id
        JOIN maquinaria_documentos md ON md.id = (
            SELECT latest.id FROM maquinaria_documentos latest WHERE latest.maquinaria_id = :subject_id AND latest.documento_id = gi.source_id
            ORDER BY latest.fecha_registro DESC, latest.id DESC LIMIT 1
        ) WHERE gi.client_id = :client_id AND gi.category = 'equipos' AND md.archivo_path IS NOT NULL ORDER BY dc.nombre LIMIT 101");
    $stmt->execute(['client_id' => $clientId, 'subject_id' => $habilitation['subject_id']]);
}
$safe = static function (string $value): string {
    $value = trim((string) preg_replace('/[^\pL\pN _-]+/u', '', $value));
    return mb_substr($value !== '' ? $value : 'Documento', 0, 100);
};
$root = realpath(UPLOAD_PATH);
$files = [];
foreach ($stmt->fetchAll() as $row) {
    if ($mode === 'selected' && !in_array((int) $row['id'], $ids, true)) { continue; }
    if (!preg_match('~^archivos/[A-Za-z0-9_/-]+\.(pdf|jpe?g|png|webp)$~i', (string) $row['file_path'])) { continue; }
    $path = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $row['file_path']);
    if (!$path || !$root || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) { continue; }
    $prefix = $habilitation['area'] === 'equipo'
        ? $safe($habilitation['subject_name'] ?? 'Equipo')
        : $safe($habilitation['subject_name'] ?? 'Personal') . ' - ' . $safe($positionName);
    $files[] = ['id' => (int) $row['id'], 'path' => $path, 'name' => $prefix . ' - ' . $safe($row['name']) . '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION))];
}
if ($habilitation['area'] === 'personal') {
    $formatStmt = db()->prepare("SELECT f.id, fc.name, f.file_path FROM client_pmi_habilitation_formats f
        JOIN client_pmi_format_catalog fc ON fc.id = f.catalog_id AND fc.client_id = :client_id
        WHERE f.habilitation_id = :habilitation_id AND f.file_path IS NOT NULL
        AND EXISTS (SELECT 1 FROM client_pmi_format_documents fd WHERE fd.catalog_id = fc.id AND fd.client_id = fc.client_id)
        ORDER BY fc.name LIMIT 101");
    $formatStmt->execute(['client_id' => $clientId, 'habilitation_id' => $habilitationId]);
    foreach ($formatStmt->fetchAll() as $row) {
        if ($mode === 'selected' && !in_array((int) $row['id'], $formatIds, true)) { continue; }
        if (!preg_match('~^archivos/[A-Za-z0-9_/-]+\.(pdf|jpe?g|png|webp)$~i', (string) $row['file_path'])) { continue; }
        $path = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $row['file_path']);
        if (!$path || !$root || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) { continue; }
        $files[] = ['id' => (int) $row['id'], 'path' => $path, 'name' => $safe($habilitation['subject_name'] ?? 'Personal') . ' - ' . $safe($row['name']) . '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION))];
    }
}
if (!$files) { http_response_code(404); exit('No hay archivos disponibles para descargar.'); }
$totalBytes = 0;
foreach ($files as $file) { $totalBytes += filesize($file['path']) ?: 0; }
if (count($files) > 100 || $totalBytes > 100 * 1024 * 1024) {
    http_response_code(413); exit('La descarga supera el límite de 100 archivos o 100 MB. Seleccione menos archivos.');
}
$sendName = static function (string $name): void {
    $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '', $name) ?: 'descarga';
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
};
if (count($files) === 1) {
    header('Content-Type: application/octet-stream');
    $sendName($files[0]['name']);
    header('Content-Length: ' . filesize($files[0]['path']));
    readfile($files[0]['path']);
    exit;
}
$temporary = tempnam(sys_get_temp_dir(), 'pmi_hab_');
if ($temporary === false) { http_response_code(500); exit('No se pudo preparar la descarga.'); }
unlink($temporary);
$archivePath = $temporary . (class_exists('ZipArchive') ? '.zip' : '.tar');
try {
    $used = [];
    foreach ($files as &$file) {
        if (isset($used[$file['name']])) {
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $file['name'] = substr($file['name'], 0, -strlen($extension) - 1) . ' - ' . $file['id'] . '.' . $extension;
        }
        $used[$file['name']] = true;
    }
    unset($file);
    if (class_exists('ZipArchive')) {
        $archive = new ZipArchive();
        if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('No se pudo crear el ZIP.'); }
        foreach ($files as $file) { $archive->addFile($file['path'], $file['name']); }
        $archive->close();
        header('Content-Type: application/zip');
    } else {
        $archive = new PharData($archivePath);
        foreach ($files as $file) { $archive->addFile($file['path'], $file['name']); }
        unset($archive);
        header('Content-Type: application/x-tar');
    }
    $archivePrefix = $habilitation['area'] === 'equipo'
        ? $safe($habilitation['subject_name'] ?? 'Equipo')
        : $safe($habilitation['subject_name'] ?? 'Personal') . ' - ' . $safe($positionName);
    $sendName($archivePrefix . ' - Documentos' . (class_exists('ZipArchive') ? '.zip' : '.tar'));
    header('Content-Length: ' . filesize($archivePath));
    readfile($archivePath);
} catch (Throwable $error) {
    if (!headers_sent()) { http_response_code(500); echo 'No se pudo preparar la descarga.'; }
} finally {
    if (is_file($archivePath)) { unlink($archivePath); }
}
