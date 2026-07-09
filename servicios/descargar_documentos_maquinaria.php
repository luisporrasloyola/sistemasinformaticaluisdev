<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$maquinariaId = (int) ($_GET['maquinaria_id'] ?? 0);
$selectedIds = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))));

if ($maquinariaId <= 0) {
    json_response(['ok' => false, 'message' => 'Seleccione una maquinaria.'], 400);
}

$sql = "SELECT md.id, md.archivo_path, md.archivo_nombre_original, mdc.nombre AS documento, m.equipo, m.serie_placa
    FROM maquinaria_documentos md
    JOIN maquinaria_documentos_catalogo mdc ON mdc.id = md.documento_id
    JOIN maquinarias m ON m.id = md.maquinaria_id
    WHERE md.maquinaria_id = :maquinaria_id
      AND md.archivo_path IS NOT NULL
      AND md.archivo_path <> ''";
$params = ['maquinaria_id' => $maquinariaId];

if ($selectedIds) {
    $placeholders = [];
    foreach ($selectedIds as $index => $id) {
        $key = 'id' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $id;
    }
    $sql .= ' AND md.id IN (' . implode(',', $placeholders) . ')';
}

$sql .= ' ORDER BY mdc.nombre';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$files = [];
$archivosRoot = realpath(UPLOAD_PATH);

foreach ($rows as $row) {
    $fullPath = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . $row['archivo_path']);
    if (!$fullPath || !$archivosRoot || !str_starts_with($fullPath, $archivosRoot) || !is_file($fullPath)) {
        continue;
    }

    $baseName = sanitize_download_name($row['documento']);
    $original = (string) ($row['archivo_nombre_original'] ?: basename($fullPath));
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION)) ?: 'pdf';
    $files[] = [
        'path' => $fullPath,
        'name' => $baseName . '.' . $extension,
        'equipo' => $row['equipo'],
        'serie' => $row['serie_placa'],
    ];
}

if (!$files) {
    json_response(['ok' => false, 'message' => $selectedIds ? 'No hay documentos PDF subidos para los registros seleccionados.' : 'No hay documentos PDF subidos para la maquinaria seleccionada.'], 404);
}

$zipName = sanitize_download_name($files[0]['serie'] . '_' . $files[0]['equipo']) . '_documentos.zip';
$zipContent = build_zip($files);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . strlen($zipContent));
header('Cache-Control: no-store, no-cache, must-revalidate');
echo $zipContent;
exit;

function sanitize_download_name(string $value): string
{
    $value = trim($value);
    if (function_exists('iconv')) {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    }
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value) ?: 'documento';
    return trim($value, '_') ?: 'documento';
}

function build_zip(array $files): string
{
    $data = '';
    $centralDirectory = '';
    $offset = 0;
    $usedNames = [];

    foreach ($files as $file) {
        $name = unique_zip_name($file['name'], $usedNames);
        $content = file_get_contents($file['path']);
        if ($content === false) {
            continue;
        }

        $crc = crc32($content);
        $size = strlen($content);
        [$dosTime, $dosDate] = dos_datetime((int) filemtime($file['path']));

        $localHeader = pack('VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            strlen($name),
            0
        ) . $name;

        $data .= $localHeader . $content;

        $centralDirectory .= pack('VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            strlen($name),
            0,
            0,
            0,
            0,
            32,
            $offset
        ) . $name;

        $offset += strlen($localHeader) + $size;
    }

    $end = pack('VvvvvVVv',
        0x06054b50,
        0,
        0,
        count($usedNames),
        count($usedNames),
        strlen($centralDirectory),
        strlen($data),
        0
    );

    return $data . $centralDirectory . $end;
}

function unique_zip_name(string $name, array &$usedNames): string
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

function dos_datetime(int $timestamp): array
{
    $parts = getdate($timestamp);
    $dosTime = (($parts['hours'] << 11) | ($parts['minutes'] << 5) | ((int) ($parts['seconds'] / 2)));
    $dosDate = ((($parts['year'] - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday']);
    return [$dosTime, $dosDate];
}