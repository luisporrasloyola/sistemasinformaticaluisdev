<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa_maquirenta.pms');

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
if (in_array($action, ['save', 'delete', 'delete_file'], true)) {
    verify_csrf($_POST['csrf_token'] ?? null);
}

if ($action === 'list') {
    $q = db()->query("SELECT i.*, COALESCE(u.name,'') AS registered_by FROM empresa_maquirenta_cventanilla_pms i LEFT JOIN users u ON u.id=i.registered_by_user_id ORDER BY i.nro_pms DESC");
    json_response(['ok' => true, 'rows' => $q->fetchAll()]);
}

if ($action === 'get') {
    $q = db()->prepare('SELECT * FROM empresa_maquirenta_cventanilla_pms WHERE id=:id');
    $q->execute(['id' => (int) ($_GET['id'] ?? 0)]);
    $row = $q->fetch();
    if (!$row) {
        json_response(['ok' => false, 'message' => 'No se encontró el registro PMS.'], 404);
    }
    json_response(['ok' => true, 'row' => $row]);
}

if ($action === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $start = (string) ($_POST['rango_inicio'] ?? '');
    $end = (string) ($_POST['rango_fin'] ?? '');
    $pms = (int) ($_POST['nro_pms'] ?? 0);
    
    if (!preg_match('/^2026-\d{2}-\d{2}$/', $start) || !preg_match('/^202[67]-\d{2}-\d{2}$/', $end) || $pms < 10 || $pms > 52) {
        json_response(['ok' => false, 'message' => 'Seleccione un rango semanal y Nro. PMS válidos.'], 422);
    }
    
    $allowedMimes = array_merge(
        document_attachment_mimes(),
        [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/octet-stream' // safe fallback for some browser-submitted excel files
        ]
    );
    
    $newPath = null;
    $fileName = null;
    try {
        if (isset($_FILES['adjunto']) && $_FILES['adjunto']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = upload_file($_FILES['adjunto'], 'empresa_maquirenta_pms', $allowedMimes);
            $newPath = $file['path'];
            $fileName = $file['name'];
        }
        
        if ($id > 0) {
            $q = db()->prepare('SELECT archivo_path FROM empresa_maquirenta_cventanilla_pms WHERE id=:id');
            $q->execute(['id' => $id]);
            $old = $q->fetch();
            if (!$old) {
                if ($newPath) {
                    delete_uploaded_file($newPath);
                }
                json_response(['ok' => false, 'message' => 'No se encontró el registro PMS.'], 404);
            }
            
            $sql = 'UPDATE empresa_maquirenta_cventanilla_pms SET rango_inicio=:start, rango_fin=:end, nro_pms=:pms, observaciones=:obs';
            $params = [
                'start' => $start,
                'end' => $end,
                'pms' => $pms,
                'obs' => trim((string) ($_POST['observaciones'] ?? '')),
                'id' => $id
            ];
            if ($newPath) {
                $sql .= ', archivo_path=:path, archivo_nombre_original=:name';
                $params['path'] = $newPath;
                $params['name'] = $fileName;
            }
            $sql .= ' WHERE id=:id';
            db()->prepare($sql)->execute($params);
            
            if ($newPath) {
                delete_uploaded_file($old['archivo_path'] ?? null);
            }
        } else {
            db()->prepare('INSERT INTO empresa_maquirenta_cventanilla_pms (rango_inicio, rango_fin, nro_pms, observaciones, archivo_path, archivo_nombre_original, registered_by_user_id) VALUES (:start, :end, :pms, :obs, :path, :name, :user)')
              ->execute([
                  'start' => $start,
                  'end' => $end,
                  'pms' => $pms,
                  'obs' => trim((string) ($_POST['observaciones'] ?? '')),
                  'path' => $newPath,
                  'name' => $fileName,
                  'user' => (int) (current_user()['id'] ?? 0) ?: null
              ]);
        }
        json_response(['ok' => true]);
    } catch (PDOException $e) {
        if ($newPath) {
            delete_uploaded_file($newPath);
        }
        if ($e->getCode() === '23000') {
            json_response(['ok' => false, 'message' => 'Ya existe un registro con este número de PMS.'], 409);
        }
        json_response(['ok' => false, 'message' => 'No se pudo guardar el registro PMS.'], 400);
    } catch (Throwable $e) {
        if ($newPath) {
            delete_uploaded_file($newPath);
        }
        json_response(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

if ($action === 'delete' || $action === 'delete_file') {
    $id = (int) ($_POST['id'] ?? 0);
    $q = db()->prepare('SELECT archivo_path FROM empresa_maquirenta_cventanilla_pms WHERE id=:id');
    $q->execute(['id' => $id]);
    $row = $q->fetch();
    if (!$row) {
        json_response(['ok' => false, 'message' => 'No se encontró el registro PMS.'], 404);
    }
    if ($action === 'delete') {
        db()->prepare('DELETE FROM empresa_maquirenta_cventanilla_pms WHERE id=:id')->execute(['id' => $id]);
    } else {
        db()->prepare('UPDATE empresa_maquirenta_cventanilla_pms SET archivo_path=NULL, archivo_nombre_original=NULL WHERE id=:id')->execute(['id' => $id]);
    }
    delete_uploaded_file($row['archivo_path'] ?? null);
    json_response(['ok' => true]);
}

if ($action === 'download') {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))))));
    $sql = "SELECT i.id, i.nro_pms, i.rango_inicio, i.rango_fin, i.archivo_path, i.archivo_nombre_original FROM empresa_maquirenta_cventanilla_pms i WHERE i.archivo_path IS NOT NULL AND i.archivo_path<>''";
    $params = [];
    if ($ids) {
        $holders = [];
        foreach ($ids as $index => $id) {
            $key = 'id' . $index;
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }
        $sql .= ' AND i.id IN (' . implode(',', $holders) . ')';
    }
    $q = db()->prepare($sql . ' ORDER BY i.nro_pms DESC');
    $q->execute($params);
    $records = $q->fetchAll();
    if (!$records) {
        json_response(['ok' => false, 'message' => 'Los registros elegidos no tienen archivos adjuntos para descargar.'], 404);
    }
    $root = realpath(UPLOAD_PATH);
    $files = [];
    foreach ($records as $row) {
        $path = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $row['archivo_path']);
        if (!$path || !$root || !str_starts_with($path, $root) || !is_file($path)) {
            continue;
        }
        $original = (string) ($row['archivo_nombre_original'] ?: basename($path));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION)) ?: 'pdf';
        $files[] = [
            'path' => $path,
            'name' => 'PMS_' . (int) $row['nro_pms'] . '_' . str_replace('-', '', $row['rango_inicio']) . '_' . str_replace('-', '', $row['rango_fin']) . '.' . $ext
        ];
    }
    if (!$files) {
        json_response(['ok' => false, 'message' => 'No se encontraron archivos disponibles en el servidor.'], 404);
    }
    $content = pms_zip($files);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="PMS_Ventanilla.zip"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

if ($action === 'file') {
    $q = db()->prepare('SELECT archivo_path, archivo_nombre_original FROM empresa_maquirenta_cventanilla_pms WHERE id=:id');
    $q->execute(['id' => (int) ($_GET['id'] ?? 0)]);
    $row = $q->fetch();
    $root = realpath(UPLOAD_PATH);
    $path = $row ? realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $row['archivo_path']) : false;
    if (!$path || !$root || !str_starts_with($path, $root) || !is_file($path)) {
        http_response_code(404);
        exit('Archivo no encontrado.');
    }
    $mime = mime_content_type($path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ((int) ($_GET['download'] ?? 0) === 1 ? 'attachment' : 'inline') . '; filename="' . basename((string) ($row['archivo_nombre_original'] ?: $path)) . '"');
    readfile($path);
    exit;
}

json_response(['ok' => false, 'message' => 'Acción no válida.'], 400);

function pms_zip(array $files): string
{
    $data = '';
    $central = '';
    $offset = 0;
    $used = [];
    $count = 0;
    foreach ($files as $file) {
        $name = (string) $file['name'];
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
        $parts = getdate((int) filemtime($file['path']));
        $time = ($parts['hours'] << 11) | ($parts['minutes'] << 5) | (int) ($parts['seconds'] / 2);
        $date = (($parts['year'] - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday'];
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $time, $date, $crc, $size, $size, strlen($name), 0) . $name;
        $data .= $local . $content;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $time, $date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 32, $offset) . $name;
        $offset += strlen($local) + $size;
        $count++;
    }
    return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($data), 0);
}
