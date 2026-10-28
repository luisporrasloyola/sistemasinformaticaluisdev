<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$workerId = (int) ($_GET['trabajador_id'] ?? 0);
$positionId = (int) ($_GET['puesto_id'] ?? 0);
$selectedIds = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))));
require_personal_own_worker($workerId, false);

if (!$workerId || !$positionId) {
    json_response(['ok' => false, 'message' => 'Seleccione un trabajador y puesto.'], 400);
}

$assigned = db()->prepare('SELECT COUNT(*) FROM worker_positions WHERE worker_id = :worker_id AND position_id = :position_id');
$assigned->execute(['worker_id' => $workerId, 'position_id' => $positionId]);
if ((int) $assigned->fetchColumn() === 0) {
    json_response(['ok' => false, 'message' => 'El puesto seleccionado no pertenece al trabajador.'], 403);
}

$sql = "SELECT wr.id, wr.requirement_id, wr.file_path, wr.original_file_name, wr.word_file_path, wr.word_original_file_name, wr.image_file_path, wr.image_original_file_name, rc.name AS requirement, w.full_name, w.document_number, p.name AS position_name
    FROM worker_requirements wr
    JOIN requirements_catalog rc ON rc.id = wr.requirement_id
    JOIN workers w ON w.id = wr.worker_id
    JOIN positions p ON p.id = wr.position_id
    WHERE wr.worker_id = :worker_id
      AND wr.position_id = :position_id
      AND (wr.file_path IS NOT NULL OR wr.word_file_path IS NOT NULL OR wr.image_file_path IS NOT NULL)";
$params = ['worker_id' => $workerId, 'position_id' => $positionId];

if ($selectedIds) {
    $placeholders = [];
    foreach ($selectedIds as $index => $id) {
        $key = 'id' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $id;
    }
    $sql .= ' AND wr.id IN (' . implode(',', $placeholders) . ')';
}

$sql .= ' ORDER BY rc.name';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = filter_allowed_documents('requisitos.pmi_individual', $stmt->fetchAll(), 'requirement_id', 'view');

$files = [];
$archivosRoot = realpath(UPLOAD_PATH);

foreach ($rows as $row) {
    $baseName = sanitize_download_name($row['full_name'] . ' - ' . $row['requirement']);
    foreach ([['file_path', 'original_file_name', 'Adjunto'], ['word_file_path', 'word_original_file_name', 'Word'], ['image_file_path', 'image_original_file_name', 'Imagen']] as [$pathColumn, $nameColumn, $label]) {
        if (empty($row[$pathColumn])) continue;
        $fullPath = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . $row[$pathColumn]);
        if (!$fullPath || !$archivosRoot || !str_starts_with($fullPath, $archivosRoot . DIRECTORY_SEPARATOR) || !is_file($fullPath)) continue;
        $original = (string) ($row[$nameColumn] ?: basename($fullPath));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION)) ?: strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $files[] = [
            'path' => $fullPath,
            'name' => $baseName . '_' . $row['id'] . '_' . $label . '.' . $extension,
            'worker' => $row['full_name'],
            'document' => $row['document_number'],
            'position' => $row['position_name'],
        ];
    }
}

if (!$files) {
    json_response(['ok' => false, 'message' => $selectedIds ? 'No hay archivos subidos para los registros seleccionados.' : 'No hay archivos subidos para el puesto seleccionado.'], 404);
}

$zipName = sanitize_download_name($files[0]['document'] . '_' . $files[0]['position']) . '_documentos.zip';
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


