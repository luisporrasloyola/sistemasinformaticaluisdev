<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';

$module = (string) ($_REQUEST['module'] ?? '');
$action = (string) ($_REQUEST['action'] ?? '');
$config = generic_company_module_config($module);

if (!$config || $action === '') {
    json_response(['ok' => false, 'message' => 'Solicitud no valida.'], 400);
}

require_module_access($config['scope']);

match ($action) {
    'list' => generic_company_list($config),
    'get' => generic_company_get($config),
    'save' => generic_company_save($config, $module),
    'delete' => generic_company_delete($config),
    'delete_pdf' => generic_company_delete_pdf($config),
    'catalog_save' => generic_company_catalog_save($config),
    'catalog_delete' => generic_company_catalog_delete($config),
    'download' => generic_company_download($config, $module),
    default => json_response(['ok' => false, 'message' => 'Accion no valida.'], 400),
};

function generic_company_module_config(string $module): ?array
{
    return match ($module) {
        'calidad' => [
            'documents' => 'empresa_calidad_documentos',
            'catalog' => 'empresa_calidad_catalogo',
            'folder' => 'empresa_calidad',
            'zip_suffix' => 'calidad',
            'scope' => 'empresa.calidad',
        ],
        'medio_ambiente' => [
            'documents' => 'empresa_medio_ambiente_documentos',
            'catalog' => 'empresa_medio_ambiente_catalogo',
            'folder' => 'empresa_medio_ambiente',
            'zip_suffix' => 'medio_ambiente',
            'scope' => 'empresa.medio_ambiente',
        ],
        default => null,
    };
}

function generic_company_status(string $endDate): array
{
    $today = new DateTimeImmutable('today');
    $end = new DateTimeImmutable($endDate);
    $warningLimit = $today->modify('+30 days');
    if ($end < $today) return ['label' => 'NO APTO', 'class' => 'text-bg-danger'];
    if ($end <= $warningLimit) return ['label' => 'POR VENCER', 'class' => 'text-bg-warning'];
    return ['label' => 'APTO', 'class' => 'text-bg-success'];
}

function generic_company_list(array $config): never
{
    $empresaId = (int) ($_GET['empresa_id'] ?? 0);
    $stmt = db()->prepare("SELECT d.*, c.nombre AS documento, COALESCE(u.name, '') AS registered_by
        FROM {$config['documents']} d
        JOIN {$config['catalog']} c ON c.id = d.documento_id
        LEFT JOIN users u ON u.id = d.registered_by_user_id
        WHERE d.empresa_id = :empresa_id
        ORDER BY c.id");
    $stmt->execute(['empresa_id' => $empresaId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['status'] = generic_company_status($row['fecha_fin']);
    }
    json_response(['ok' => true, 'rows' => $rows]);
}

function generic_company_get(array $config): never
{
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = db()->prepare("SELECT d.*, c.nombre AS documento
        FROM {$config['documents']} d
        JOIN {$config['catalog']} c ON c.id = d.documento_id
        WHERE d.id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_response(['ok' => false], 404);
    json_response(['ok' => true, 'row' => $row]);
}

function generic_company_save(array $config, string $module): never
{
    verify_csrf($_POST['csrf_token'] ?? null);
    $id = (int) ($_POST['id'] ?? 0);
    $empresaId = (int) ($_POST['empresa_id'] ?? 0);
    $documentoId = (int) ($_POST['documento_id'] ?? 0);
    $fechaRegistro = (string) ($_POST['fecha_registro'] ?? '');
    $fechaInicio = (string) ($_POST['fecha_inicio'] ?? '');
    $fechaFin = (string) ($_POST['fecha_fin'] ?? '');
    $observaciones = trim((string) ($_POST['observaciones'] ?? ''));

    if (!$empresaId || !$documentoId || !$fechaRegistro || !$fechaInicio || !$fechaFin) {
        json_response(['ok' => false, 'message' => 'Complete todos los campos obligatorios.'], 400);
    }
    if (!current_user_can_document($config['scope'], $documentoId, 'upload')) {
        json_response(['ok' => false, 'message' => 'No tiene permisos para guardar este documento.'], 403);
    }
    if (strtotime($fechaFin) < strtotime($fechaInicio)) {
        json_response(['ok' => false, 'message' => 'La fecha fin no puede ser menor a la fecha inicio.'], 400);
    }

    try {
        $pdf = upload_file($_FILES['pdf'] ?? [], $config['folder'], ['application/pdf']);
        if ($id > 0) {
            $currentStmt = db()->prepare("SELECT archivo_path FROM {$config['documents']} WHERE id = :id");
            $currentStmt->execute(['id' => $id]);
            $current = $currentStmt->fetch();
            if (!$current) json_response(['ok' => false, 'message' => 'No se encontro el documento.'], 404);

            $sql = "UPDATE {$config['documents']} SET documento_id = :documento_id, fecha_registro = :fecha_registro, fecha_inicio = :fecha_inicio, fecha_fin = :fecha_fin, observaciones = :observaciones";
            $params = [
                'documento_id' => $documentoId,
                'fecha_registro' => $fechaRegistro,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'observaciones' => $observaciones ?: null,
                'id' => $id,
            ];
            if ($pdf['path']) {
                delete_uploaded_file($current['archivo_path'] ?? null);
                $sql .= ', archivo_path = :archivo_path, archivo_nombre_original = :archivo_nombre_original';
                $params['archivo_path'] = $pdf['path'];
                $params['archivo_nombre_original'] = $pdf['name'];
            }
            $sql .= ' WHERE id = :id';
            db()->prepare($sql)->execute($params);
        } else {
            $stmt = db()->prepare("INSERT INTO {$config['documents']} (empresa_id, documento_id, fecha_registro, fecha_inicio, fecha_fin, observaciones, archivo_path, archivo_nombre_original, registered_by_user_id)
                VALUES (:empresa_id, :documento_id, :fecha_registro, :fecha_inicio, :fecha_fin, :observaciones, :archivo_path, :archivo_nombre_original, :registered_by_user_id)");
            $stmt->execute([
                'empresa_id' => $empresaId,
                'documento_id' => $documentoId,
                'fecha_registro' => $fechaRegistro,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'observaciones' => $observaciones ?: null,
                'archivo_path' => $pdf['path'],
                'archivo_nombre_original' => $pdf['name'],
                'registered_by_user_id' => (int) (current_user()['id'] ?? 0) ?: null,
            ]);
        }
        json_response(['ok' => true]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_response(['ok' => false, 'message' => 'Este documento ya existe para la empresa seleccionada.'], 409);
        }
        json_response(['ok' => false, 'message' => 'No se pudo guardar el documento.'], 400);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

function generic_company_delete(array $config): never
{
    verify_csrf($_POST['csrf_token'] ?? null);
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare("SELECT archivo_path FROM {$config['documents']} WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_response(['ok' => false], 404);
    delete_uploaded_file($row['archivo_path'] ?? null);
    db()->prepare("DELETE FROM {$config['documents']} WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

function generic_company_delete_pdf(array $config): never
{
    verify_csrf($_POST['csrf_token'] ?? null);
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare("SELECT archivo_path FROM {$config['documents']} WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_response(['ok' => false], 404);
    delete_uploaded_file($row['archivo_path'] ?? null);
    db()->prepare("UPDATE {$config['documents']} SET archivo_path = NULL, archivo_nombre_original = NULL WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

function generic_company_catalog_save(array $config): never
{
    verify_csrf($_POST['csrf_token'] ?? null);
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    if ($nombre === '') json_response(['ok' => false, 'message' => 'Ingrese un documento.'], 400);
    if (!current_user_can_manage_scope($config['scope'])) {
        json_response(['ok' => false, 'message' => 'No tiene permisos para agregar documentos.'], 403);
    }
    $exists = db()->prepare("SELECT id FROM {$config['catalog']} WHERE LOWER(nombre) = LOWER(:nombre) LIMIT 1");
    $exists->execute(['nombre' => $nombre]);
    if ($exists->fetch()) {
        json_response(['ok' => false, 'message' => 'Este documento ya existe.'], 409);
    }
    $stmt = db()->prepare("INSERT INTO {$config['catalog']} (nombre, estado) VALUES (:nombre, 1) ON DUPLICATE KEY UPDATE estado = 1, id = LAST_INSERT_ID(id)");
    $stmt->execute(['nombre' => $nombre]);
    json_response(['ok' => true, 'id' => (int) db()->lastInsertId(), 'text' => $nombre]);
}

function generic_company_catalog_delete(array $config): never
{
    verify_csrf($_POST['csrf_token'] ?? null);
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) json_response(['ok' => false, 'message' => 'Seleccione un documento valido.'], 400);
    if (!current_user_can_document($config['scope'], $id, 'manage')) {
        json_response(['ok' => false, 'message' => 'No tiene permisos para eliminar este documento.'], 403);
    }
    $used = db()->prepare("SELECT COUNT(*) FROM {$config['documents']} WHERE documento_id = :id");
    $used->execute(['id' => $id]);
    if ((int) $used->fetchColumn() > 0) {
        json_response(['ok' => false, 'message' => 'No se puede eliminar porque este documento ya tiene registros asociados.'], 409);
    }
    db()->prepare("UPDATE {$config['catalog']} SET estado = 0 WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true, 'message' => 'Documento eliminado del catalogo.']);
}

function generic_company_download(array $config, string $module): never
{
    $empresaId = (int) ($_GET['empresa_id'] ?? 0);
    $selectedIds = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))));
    if ($empresaId <= 0) json_response(['ok' => false, 'message' => 'Seleccione una empresa.'], 400);

    $sql = "SELECT d.id, d.archivo_path, d.archivo_nombre_original, c.nombre AS documento, e.razon_social, e.ruc
        FROM {$config['documents']} d
        JOIN {$config['catalog']} c ON c.id = d.documento_id
        JOIN empresas e ON e.id = d.empresa_id
        WHERE d.empresa_id = :empresa_id AND d.archivo_path IS NOT NULL AND d.archivo_path <> ''";
    $params = ['empresa_id' => $empresaId];
    if ($selectedIds) {
        $placeholders = [];
        foreach ($selectedIds as $index => $id) {
            $key = 'id' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $sql .= ' AND d.id IN (' . implode(',', $placeholders) . ')';
    }
    $sql .= ' ORDER BY c.id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $files = [];
    $archivosRoot = realpath(UPLOAD_PATH);
    foreach ($rows as $row) {
        $fullPath = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $row['archivo_path']);
        if (!$fullPath || !$archivosRoot || !str_starts_with($fullPath, $archivosRoot) || !is_file($fullPath)) continue;
        $original = (string) ($row['archivo_nombre_original'] ?: basename($fullPath));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION)) ?: 'pdf';
        $files[] = [
            'path' => $fullPath,
            'name' => generic_download_name($row['documento']) . '.' . $extension,
            'razon_social' => $row['razon_social'],
            'ruc' => $row['ruc'],
        ];
    }
    if (!$files) {
        json_response(['ok' => false, 'message' => $selectedIds ? 'No hay documentos PDF subidos para los registros seleccionados.' : 'No hay documentos PDF subidos para la empresa seleccionada.'], 404);
    }
    $zipName = generic_download_name($files[0]['ruc'] . '_' . $files[0]['razon_social']) . '_' . $config['zip_suffix'] . '.zip';
    $zipContent = generic_build_zip($files);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . strlen($zipContent));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $zipContent;
    exit;
}

function generic_download_name(string $value): string
{
    $value = trim($value);
    if (function_exists('iconv')) $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value) ?: 'documento';
    return trim($value, '_') ?: 'documento';
}

function generic_build_zip(array $files): string
{
    $data = '';
    $centralDirectory = '';
    $offset = 0;
    $usedNames = [];
    foreach ($files as $file) {
        $name = generic_unique_zip_name($file['name'], $usedNames);
        $content = file_get_contents($file['path']);
        if ($content === false) continue;
        $crc = crc32($content);
        $size = strlen($content);
        [$dosTime, $dosDate] = generic_dos_datetime((int) filemtime($file['path']));
        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0) . $name;
        $data .= $localHeader . $content;
        $centralDirectory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 32, $offset) . $name;
        $offset += strlen($localHeader) + $size;
    }
    return $data . $centralDirectory . pack('VvvvvVVv', 0x06054b50, 0, 0, count($usedNames), count($usedNames), strlen($centralDirectory), strlen($data), 0);
}

function generic_unique_zip_name(string $name, array &$usedNames): string
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

function generic_dos_datetime(int $timestamp): array
{
    $parts = getdate($timestamp);
    return [
        (($parts['hours'] << 11) | ($parts['minutes'] << 5) | ((int) ($parts['seconds'] / 2))),
        ((($parts['year'] - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday']),
    ];
}
