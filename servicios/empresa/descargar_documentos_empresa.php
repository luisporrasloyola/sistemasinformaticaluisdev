<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

$empresaId = (int) ($_GET['empresa_id'] ?? 0);
$selectedIds = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))));

if ($empresaId <= 0) {
    json_response(['ok' => false, 'message' => 'Seleccione una empresa.'], 400);
}

$sql = "SELECT ed.id, ed.archivo_path, ed.archivo_nombre_original, edc.nombre AS documento, e.razon_social, e.ruc
    FROM empresa_documentos ed
    JOIN empresa_documentos_catalogo edc ON edc.id = ed.documento_id
    JOIN empresas e ON e.id = ed.empresa_id
    WHERE ed.empresa_id = :empresa_id
      AND ed.archivo_path IS NOT NULL
      AND ed.archivo_path <> ''";
$params = ['empresa_id' => $empresaId];

if ($selectedIds) {
    $placeholders = [];
    foreach ($selectedIds as $index => $id) {
        $key = 'id' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $id;
    }
    $sql .= ' AND ed.id IN (' . implode(',', $placeholders) . ')';
}

$sql .= ' ORDER BY edc.id';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$files = [];
$archivosRoot = realpath(UPLOAD_PATH);

foreach ($rows as $row) {
    $fullPath = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $row['archivo_path']);
    if (!$fullPath || !$archivosRoot || !str_starts_with($fullPath, $archivosRoot) || !is_file($fullPath)) {
        continue;
    }

    $baseName = sanitize_company_download_name($row['documento']);
    $original = (string) ($row['archivo_nombre_original'] ?: basename($fullPath));
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION)) ?: 'pdf';
    $files[] = [
        'path' => $fullPath,
        'name' => $baseName . '.' . $extension,
        'razon_social' => $row['razon_social'],
        'ruc' => $row['ruc'],
    ];
}

if (!$files) {
    json_response(['ok' => false, 'message' => $selectedIds ? 'No hay documentos PDF subidos para los registros seleccionados.' : 'No hay documentos PDF subidos para la empresa seleccionada.'], 404);
}

$zipName = sanitize_company_download_name($files[0]['ruc'] . '_' . $files[0]['razon_social']) . '_documentos.zip';
$zipContent = build_company_zip($files);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . strlen($zipContent));
header('Cache-Control: no-store, no-cache, must-revalidate');
echo $zipContent;
exit;

function sanitize_company_download_name(string $value): string
{
    $value = trim($value);
    if (function_exists('iconv')) {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    }
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value) ?: 'documento';
    return trim($value, '_') ?: 'documento';
}

function build_company_zip(array $files): string
{
    $data = '';
    $centralDirectory = '';
    $offset = 0;
    $usedNames = [];

    foreach ($files as $file) {
        $name = unique_company_zip_name($file['name'], $usedNames);
        $content = file_get_contents($file['path']);
        if ($content === false) {
            continue;
        }

        $crc = crc32($content);
        $size = strlen($content);
        [$dosTime, $dosDate] = company_dos_datetime((int) filemtime($file['path']));

        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0) . $name;
        $data .= $localHeader . $content;
        $centralDirectory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 32, $offset) . $name;
        $offset += strlen($localHeader) + $size;
    }

    return $data . $centralDirectory . pack('VvvvvVVv', 0x06054b50, 0, 0, count($usedNames), count($usedNames), strlen($centralDirectory), strlen($data), 0);
}

function unique_company_zip_name(string $name, array &$usedNames): string
{
    $candidate = $name;
    $index = 2;
    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $stem = $extension ? substr($name, 0, -(strlen($extension) + 1)) : $name;

    while (isset($usedNames[$candidate])) {
        $candidate = $stem . '_' . $index . ($extension ? '.' . $extension : '');
        $index++;
    }

    $usedNames[$candidate] = true;
    return $candidate;
}

function company_dos_datetime(int $timestamp): array
{
    $parts = getdate($timestamp);
    return [
        (($parts['hours'] << 11) | ($parts['minutes'] << 5) | ((int) ($parts['seconds'] / 2))),
        ((($parts['year'] - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday']),
    ];
}
