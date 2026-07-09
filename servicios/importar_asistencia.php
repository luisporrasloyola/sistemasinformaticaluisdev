<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/asistencia.php';
require_login();
ensure_attendance_schema();

verify_csrf($_POST['csrf_token'] ?? null);

$file = $_FILES['excel'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'message' => 'Seleccione un archivo Excel válido.'], 400);
}

$extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
if ($extension !== 'xlsx') {
    json_response(['ok' => false, 'message' => 'Solo se permiten archivos .xlsx.'], 400);
}

try {
    $rows = read_attendance_xlsx((string) $file['tmp_name']);
    if (count($rows) <= 1) {
        json_response(['ok' => false, 'message' => 'El Excel no contiene registros para importar.'], 400);
    }

    $inserted = 0;
    $skipped = 0;
    $errors = [];

    $stmt = db()->prepare('INSERT IGNORE INTO attendance_control
        (fecha, nombre_apellido, lugar_actividad, empresa_proyecto, puesto, record_hash)
        VALUES (:fecha, :nombre, :actividad, :empresa, :puesto, :hash)');

    db()->beginTransaction();

    foreach (array_slice($rows, 1) as $index => $row) {
        $line = $index + 2;
        $fecha = excel_serial_to_date($row['A'] ?? '');
        $nombre = trim((string) ($row['B'] ?? ''));
        $actividad = trim((string) ($row['C'] ?? ''));
        $empresa = trim((string) ($row['D'] ?? ''));
        $puesto = trim((string) ($row['E'] ?? ''));

        if (!$fecha && $nombre === '' && $actividad === '' && $empresa === '' && $puesto === '') {
            continue;
        }

        if (!$fecha || $nombre === '' || $actividad === '') {
            $errors[] = 'Fila ' . $line . ': fecha, nombre y actividad son obligatorios.';
            $skipped++;
            continue;
        }

        $hash = attendance_hash($fecha, $nombre, $actividad, $empresa, $puesto);
        $stmt->execute([
            'fecha' => $fecha,
            'nombre' => $nombre,
            'actividad' => $actividad,
            'empresa' => $empresa !== '' ? $empresa : null,
            'puesto' => $puesto !== '' ? $puesto : null,
            'hash' => $hash,
        ]);

        if ($stmt->rowCount() > 0) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    db()->commit();
    json_response(['ok' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}

function read_attendance_xlsx(string $path): array
{
    $sharedXml = xlsx_get_entry($path, 'xl/sharedStrings.xml');
    $sheetXml = xlsx_get_entry($path, 'xl/worksheets/sheet1.xml');

    if ($sheetXml === null) {
        throw new RuntimeException('No se encontró la primera hoja del Excel.');
    }

    $sharedStrings = read_shared_strings($sharedXml);
    $xml = simplexml_load_string($sheetXml);
    if (!$xml) {
        throw new RuntimeException('No se pudo leer la hoja del Excel.');
    }

    $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $result = [];

    foreach ($xml->xpath('//x:sheetData/x:row') as $row) {
        $line = [];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            $column = preg_replace('/\d+/', '', $ref);
            if ($column === null || $column === '') {
                continue;
            }
            $line[$column] = read_cell_value($cell, $sharedStrings);
        }
        $result[] = $line;
    }

    return $result;
}

function read_shared_strings(?string $xmlText): array
{
    if (!$xmlText) {
        return [];
    }

    $xml = simplexml_load_string($xmlText);
    if (!$xml) {
        return [];
    }

    $strings = [];
    foreach ($xml->si as $si) {
        if (isset($si->t)) {
            $strings[] = (string) $si->t;
            continue;
        }

        $value = '';
        foreach ($si->r as $run) {
            $value .= (string) $run->t;
        }
        $strings[] = $value;
    }

    return $strings;
}

function read_cell_value(SimpleXMLElement $cell, array $sharedStrings): string
{
    $type = (string) $cell['t'];
    if ($type === 's') {
        $index = (int) ($cell->v ?? -1);
        return trim((string) ($sharedStrings[$index] ?? ''));
    }

    if ($type === 'inlineStr') {
        return trim((string) ($cell->is->t ?? ''));
    }

    return trim((string) ($cell->v ?? ''));
}

function xlsx_get_entry(string $zipPath, string $entryName): ?string
{
    $data = file_get_contents($zipPath);
    if ($data === false) {
        throw new RuntimeException('No se pudo leer el archivo Excel.');
    }

    $eocdPos = strrpos($data, "PK\x05\x06");
    if ($eocdPos === false) {
        throw new RuntimeException('El archivo Excel no tiene una estructura ZIP válida.');
    }

    $eocd = unpack('vdisk/vdiskStart/ventriesDisk/ventries/Vsize/Voffset/vcomment', substr($data, $eocdPos + 4, 18));
    $offset = (int) $eocd['offset'];
    $entries = (int) $eocd['entries'];

    for ($i = 0; $i < $entries; $i++) {
        if (substr($data, $offset, 4) !== "PK\x01\x02") {
            break;
        }

        $header = unpack(
            'Vsig/vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLen/vextraLen/vcommentLen/vdisk/vinternal/Vexternal/VlocalOffset',
            substr($data, $offset, 46)
        );
        $nameLen = (int) $header['nameLen'];
        $extraLen = (int) $header['extraLen'];
        $commentLen = (int) $header['commentLen'];
        $name = substr($data, $offset + 46, $nameLen);

        if ($name === $entryName) {
            return xlsx_read_local_entry($data, (int) $header['localOffset'], (int) $header['compressed'], (int) $header['method']);
        }

        $offset += 46 + $nameLen + $extraLen + $commentLen;
    }

    return null;
}

function xlsx_read_local_entry(string $zipData, int $offset, int $compressedSize, int $method): string
{
    if (substr($zipData, $offset, 4) !== "PK\x03\x04") {
        throw new RuntimeException('No se pudo leer una entrada interna del Excel.');
    }

    $header = unpack('Vsig/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLen/vextraLen', substr($zipData, $offset, 30));
    $dataOffset = $offset + 30 + (int) $header['nameLen'] + (int) $header['extraLen'];
    $compressed = substr($zipData, $dataOffset, $compressedSize);

    if ($method === 0) {
        return $compressed;
    }

    if ($method === 8) {
        $inflated = gzinflate($compressed);
        if ($inflated === false) {
            throw new RuntimeException('No se pudo descomprimir el Excel.');
        }
        return $inflated;
    }

    throw new RuntimeException('El método de compresión del Excel no es compatible.');
}