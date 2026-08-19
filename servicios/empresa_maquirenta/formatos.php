<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../includes/status_alerts.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa_maquirenta.formatos');

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
if (in_array($action, ['save', 'delete', 'delete_file', 'catalog_save', 'catalog_delete', 'upload_photo'], true)) {
    verify_csrf($_POST['csrf_token'] ?? null);
}

if ($action === 'profile') {
    $stmt = db()->prepare('SELECT * FROM empresas_maquirenta WHERE id=:id AND status=1');
    $stmt->execute(['id' => (int) ($_GET['id'] ?? 0)]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'message' => 'No se encontró la empresa Maquirenta.'], 404);
    }
    json_response(['ok' => true, 'empresa' => $row]);
}

if ($action === 'catalog') {
    $stmt = db()->prepare('SELECT id, nombre AS text FROM empresa_maquirenta_formatos_catalogo WHERE estado=1 AND nombre LIKE :q ORDER BY nombre');
    $stmt->execute(['q' => '%' . trim((string) ($_GET['q'] ?? '')) . '%']);
    json_response(['results' => filter_allowed_documents('empresa_maquirenta.formatos', $stmt->fetchAll(), 'id', 'upload')]);
}

if ($action === 'list') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $stmt = db()->prepare("SELECT d.*, c.nombre AS documento, COALESCE(u.name,'') AS registered_by FROM empresa_maquirenta_formatos_documentos d JOIN empresa_maquirenta_formatos_catalogo c ON c.id=d.documento_id LEFT JOIN users u ON u.id=d.registered_by_user_id WHERE d.empresa_maquirenta_id=:company_id ORDER BY c.nombre");
    $stmt->execute(['company_id' => (int) ($_GET['empresa_maquirenta_id'] ?? 0)]);
    $rows = array_values(array_filter($stmt->fetchAll(), static fn(array $row): bool => current_user_can_document('empresa_maquirenta.formatos', (int) $row['documento_id'], 'view')));
    foreach ($rows as &$row) {
        $row['status'] = status_alert_document_status((string) $row['fecha_fin'], 'empresa_maquirenta.formatos', (int) $row['documento_id']);
    }
    unset($row);
    json_response(['ok' => true, 'rows' => $rows]);
}

if ($action === 'get') {
    $stmt = db()->prepare('SELECT d.*, c.nombre AS documento FROM empresa_maquirenta_formatos_documentos d JOIN empresa_maquirenta_formatos_catalogo c ON c.id=d.documento_id WHERE d.id=:id');
    $stmt->execute(['id' => (int) ($_GET['id'] ?? 0)]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'message' => 'No se encontró el documento.'], 404);
    }
    if (!current_user_can_document('empresa_maquirenta.formatos', (int) $row['documento_id'], 'view')) {
        json_response(['ok' => false, 'message' => 'No tiene permisos para consultar este documento.'], 403);
    }
    json_response(['ok' => true, 'row' => $row]);
}

if ($action === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $companyId = (int) ($_POST['empresa_maquirenta_id'] ?? 0);
    $documentId = (int) ($_POST['documento_id'] ?? 0);
    $registration = (string) ($_POST['fecha_registro'] ?? '');
    $start = (string) ($_POST['fecha_inicio'] ?? '');
    $end = (string) ($_POST['fecha_fin'] ?? '');
    
    if (!$companyId || !$documentId || !$registration || !$start || !$end) {
        json_response(['ok' => false, 'message' => 'Complete todos los campos obligatorios.'], 422);
    }
    if (!current_user_can_document('empresa_maquirenta.formatos', $documentId, 'upload')) {
        json_response(['ok' => false, 'message' => 'No tiene permisos para guardar este documento.'], 403);
    }
    if (strtotime($end) < strtotime($start)) {
        json_response(['ok' => false, 'message' => 'La fecha fin no puede ser menor a la fecha inicio.'], 422);
    }
    
    $newPath = null;
    try {
        $file = upload_file($_FILES['pdf'] ?? [], 'empresa_maquirenta_formatos', document_attachment_mimes());
        $newPath = $file['path'];
        if ($id > 0) {
            $currentStmt = db()->prepare('SELECT archivo_path FROM empresa_maquirenta_formatos_documentos WHERE id=:id AND empresa_maquirenta_id=:company');
            $currentStmt->execute(['id' => $id, 'company' => $companyId]);
            $current = $currentStmt->fetch();
            if (!$current) {
                if ($newPath) {
                    delete_uploaded_file($newPath);
                }
                json_response(['ok' => false, 'message' => 'No se encontró el documento.'], 404);
            }
            $sql = 'UPDATE empresa_maquirenta_formatos_documentos SET documento_id=:documento,fecha_registro=:registration,fecha_inicio=:start,fecha_fin=:end,observaciones=:observations';
            $params = [
                'documento' => $documentId,
                'registration' => $registration,
                'start' => $start,
                'end' => $end,
                'observations' => trim((string) ($_POST['observaciones'] ?? '')),
                'id' => $id,
                'company' => $companyId
            ];
            if ($newPath) {
                $sql .= ',archivo_path=:path,archivo_nombre_original=:name';
                $params['path'] = $newPath;
                $params['name'] = $file['name'];
            }
            $sql .= ' WHERE id=:id AND empresa_maquirenta_id=:company';
            db()->prepare($sql)->execute($params);
            if ($newPath) {
                delete_uploaded_file($current['archivo_path'] ?? null);
            }
        } else {
            $stmt = db()->prepare('INSERT INTO empresa_maquirenta_formatos_documentos (empresa_maquirenta_id,documento_id,fecha_registro,fecha_inicio,fecha_fin,observaciones,archivo_path,archivo_nombre_original,registered_by_user_id) VALUES (:company,:documento,:registration,:start,:end,:observations,:path,:name,:user)');
            $stmt->execute([
                'company' => $companyId,
                'documento' => $documentId,
                'registration' => $registration,
                'start' => $start,
                'end' => $end,
                'observations' => trim((string) ($_POST['observaciones'] ?? '')),
                'path' => $newPath,
                'name' => $file['name'],
                'user' => (int) (current_user()['id'] ?? 0) ?: null
            ]);
        }
        json_response(['ok' => true]);
    } catch (PDOException $e) {
        if ($newPath) {
            delete_uploaded_file($newPath);
        }
        if ($e->getCode() === '23000') {
            json_response(['ok' => false, 'message' => 'Este documento ya existe para la empresa seleccionada.'], 409);
        }
        json_response(['ok' => false, 'message' => 'No se pudo guardar el documento.'], 400);
    } catch (Throwable $e) {
        if ($newPath) {
            delete_uploaded_file($newPath);
        }
        json_response(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

if ($action === 'delete' || $action === 'delete_file') {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT documento_id, archivo_path FROM empresa_maquirenta_formatos_documentos WHERE id=:id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'message' => 'No se encontró el documento.'], 404);
    }
    if (!current_user_can_document('empresa_maquirenta.formatos', (int) $row['documento_id'], 'upload')) {
        json_response(['ok' => false, 'message' => 'No tiene permisos para modificar este documento.'], 403);
    }
    if ($action === 'delete') {
        db()->prepare('DELETE FROM empresa_maquirenta_formatos_documentos WHERE id=:id')->execute(['id' => $id]);
    } else {
        db()->prepare('UPDATE empresa_maquirenta_formatos_documentos SET archivo_path=NULL, archivo_nombre_original=NULL WHERE id=:id')->execute(['id' => $id]);
    }
    delete_uploaded_file($row['archivo_path'] ?? null);
    json_response(['ok' => true]);
}

if ($action === 'catalog_save') {
    if (!current_user_can_manage_scope('empresa_maquirenta.formatos')) {
        json_response(['ok' => false, 'message' => 'No tiene permisos para agregar documentos.'], 403);
    }
    $name = trim((string) ($_POST['nombre'] ?? ''));
    if ($name === '') {
        json_response(['ok' => false, 'message' => 'Ingrese un documento.'], 422);
    }
    $exists = db()->prepare('SELECT id FROM empresa_maquirenta_formatos_catalogo WHERE LOWER(nombre)=LOWER(:name)');
    $exists->execute(['name' => $name]);
    if ($exists->fetch()) {
        json_response(['ok' => false, 'message' => 'Este documento ya existe.'], 409);
    }
    db()->prepare('INSERT INTO empresa_maquirenta_formatos_catalogo (nombre,estado) VALUES (:name,1)')->execute(['name' => $name]);
    json_response(['ok' => true, 'id' => (int) db()->lastInsertId(), 'text' => $name]);
}

if ($action === 'catalog_delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if (!current_user_can_document('empresa_maquirenta.formatos', $id, 'manage')) {
        json_response(['ok' => false, 'message' => 'No tiene permisos para eliminar este documento.'], 403);
    }
    $used = db()->prepare('SELECT COUNT(*) FROM empresa_maquirenta_formatos_documentos WHERE documento_id=:id');
    $used->execute(['id' => $id]);
    if ((int) $used->fetchColumn() > 0) {
        json_response(['ok' => false, 'message' => 'No se puede eliminar porque ya tiene registros asociados.'], 409);
    }
    db()->prepare('UPDATE empresa_maquirenta_formatos_catalogo SET estado=0 WHERE id=:id')->execute(['id' => $id]);
    json_response(['ok' => true, 'message' => 'Documento eliminado del catálogo.']);
}

if ($action === 'upload_photo') {
    $companyId = (int) ($_POST['empresa_maquirenta_id'] ?? 0);
    $stmt = db()->prepare('SELECT foto_path FROM empresas_maquirenta WHERE id=:id AND status=1');
    $stmt->execute(['id' => $companyId]);
    $current = $stmt->fetch();
    if (!$current) {
        json_response(['ok' => false, 'message' => 'No se encontró la empresa.'], 404);
    }
    $photo = upload_file($_FILES['foto'] ?? [], 'empresas_maquirenta', ['image/jpeg', 'image/png', 'image/webp']);
    if (!$photo['path']) {
        json_response(['ok' => false, 'message' => 'Seleccione una imagen.'], 422);
    }
    db()->prepare('UPDATE empresas_maquirenta SET foto_path=:path WHERE id=:id')->execute(['path' => $photo['path'], 'id' => $companyId]);
    delete_uploaded_file($current['foto_path'] ?? null);
    json_response(['ok' => true, 'path' => APP_URL . '/' . $photo['path']]);
}

if ($action === 'download') {
    $companyId = (int) ($_GET['empresa_maquirenta_id'] ?? 0);
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))));
    $sql = "SELECT d.id, d.documento_id, d.archivo_path, d.archivo_nombre_original, c.nombre AS documento, e.razon_social, e.ruc FROM empresa_maquirenta_formatos_documentos d JOIN empresa_maquirenta_formatos_catalogo c ON c.id=d.documento_id JOIN empresas_maquirenta e ON e.id=d.empresa_maquirenta_id WHERE d.empresa_maquirenta_id=:company AND d.archivo_path IS NOT NULL AND d.archivo_path<>''";
    $params = ['company' => $companyId];
    if ($ids) {
        $holders = [];
        foreach ($ids as $i => $id) {
            $key = 'id' . $i;
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }
        $sql .= ' AND d.id IN (' . implode(',', $holders) . ')';
    }
    $stmt = db()->prepare($sql . ' ORDER BY c.nombre');
    $stmt->execute($params);
    $files = [];
    $root = realpath(UPLOAD_PATH);
    $company = null;
    foreach ($stmt->fetchAll() as $row) {
        if (!current_user_can_document('empresa_maquirenta.formatos', (int) $row['documento_id'], 'view')) {
            continue;
        }
        $path = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $row['archivo_path']);
        if (!$path || !$root || !str_starts_with($path, $root) || !is_file($path)) {
            continue;
        }
        $original = (string) ($row['archivo_nombre_original'] ?: basename($path));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION)) ?: 'pdf';
        $files[] = ['path' => $path, 'name' => emf_name($row['documento']) . '.' . $ext];
        $company = $row;
    }
    if (!$files) {
        json_response(['ok' => false, 'message' => 'No hay archivos subidos para descargar.'], 404);
    }
    $zip = emf_zip($files);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . emf_name($company['ruc'] . '_' . $company['razon_social']) . '_formatos.zip"');
    header('Content-Length: ' . strlen($zip));
    echo $zip;
    exit;
}

json_response(['ok' => false, 'message' => 'Acción no válida.'], 400);

function emf_name(string $value): string
{
    $value = trim($value);
    if (function_exists('iconv')) {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    }
    return trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $value) ?: 'documento', '_') ?: 'documento';
}

function emf_zip(array $files): string
{
    $data = '';
    $central = '';
    $offset = 0;
    $used = [];
    foreach ($files as $file) {
        $name = $file['name'];
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $n = 2;
        while (isset($used[$name])) {
            $name = $base . '_' . $n++ . ($ext ? '.' . $ext : '');
        }
        $used[$name] = true;
        $content = file_get_contents($file['path']);
        if ($content === false) {
            continue;
        }
        $crc = crc32($content);
        $size = strlen($content);
        $p = getdate((int) filemtime($file['path']));
        $time = ($p['hours'] << 11) | ($p['minutes'] << 5) | (int) ($p['seconds'] / 2);
        $date = (($p['year'] - 1980) << 9) | ($p['mon'] << 5) | $p['mday'];
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $time, $date, $crc, $size, $size, strlen($name), 0) . $name;
        $data .= $local . $content;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $time, $date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 32, $offset) . $name;
        $offset += strlen($local) + $size;
    }
    return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($used), count($used), strlen($central), strlen($data), 0);
}
