<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa_maquirenta.datos_generales');
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$razonSocial = trim((string) ($_POST['razon_social'] ?? ''));
$ruc = trim((string) ($_POST['ruc'] ?? ''));
$direccion = trim((string) ($_POST['direccion'] ?? ''));

if ($razonSocial === '' || $ruc === '') {
    json_response(['ok' => false, 'message' => 'Complete razón social y RUC.'], 422);
}

$newPhotoPath = null;
try {
    $foto = upload_file($_FILES['foto'] ?? [], 'empresas_maquirenta', ['image/jpeg', 'image/png', 'image/webp']);
    $newPhotoPath = $foto['path'];

    if ($id > 0) {
        $currentStmt = db()->prepare('SELECT foto_path FROM empresas_maquirenta WHERE id = :id AND status = 1');
        $currentStmt->execute(['id' => $id]);
        $current = $currentStmt->fetch();
        if (!$current) {
            if ($newPhotoPath) delete_uploaded_file($newPhotoPath);
            json_response(['ok' => false, 'message' => 'No se encontró la empresa Maquirenta.'], 404);
        }

        $sql = 'UPDATE empresas_maquirenta SET razon_social = :razon_social, ruc = :ruc, direccion = :direccion';
        $params = ['razon_social' => $razonSocial, 'ruc' => $ruc, 'direccion' => $direccion ?: null, 'id' => $id];
        if ($newPhotoPath) {
            $sql .= ', foto_path = :foto_path';
            $params['foto_path'] = $newPhotoPath;
        }
        $sql .= ' WHERE id = :id AND status = 1';
        db()->prepare($sql)->execute($params);
        if ($newPhotoPath) delete_uploaded_file($current['foto_path'] ?? null);
    } else {
        $stmt = db()->prepare('INSERT INTO empresas_maquirenta (razon_social, ruc, direccion, foto_path) VALUES (:razon_social, :ruc, :direccion, :foto_path)');
        $stmt->execute(['razon_social' => $razonSocial, 'ruc' => $ruc, 'direccion' => $direccion ?: null, 'foto_path' => $newPhotoPath]);
    }
    json_response(['ok' => true, 'message' => 'Empresa Maquirenta guardada correctamente.']);
} catch (PDOException $e) {
    if ($newPhotoPath) delete_uploaded_file($newPhotoPath);
    if ($e->getCode() === '23000') {
        json_response(['ok' => false, 'message' => 'Ya existe una empresa Maquirenta con ese RUC.'], 409);
    }
    json_response(['ok' => false, 'message' => 'No se pudo guardar la empresa Maquirenta.'], 400);
} catch (Throwable $e) {
    if ($newPhotoPath) delete_uploaded_file($newPhotoPath);
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
