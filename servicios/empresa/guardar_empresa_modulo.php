<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$razonSocial = trim((string) ($_POST['razon_social'] ?? ''));
$ruc = trim((string) ($_POST['ruc'] ?? ''));
$direccion = trim((string) ($_POST['direccion'] ?? ''));

if ($razonSocial === '' || $ruc === '') {
    json_response(['ok' => false, 'message' => 'Complete razon social y RUC.'], 400);
}

try {
    $foto = upload_file($_FILES['foto'] ?? [], 'empresas', ['image/jpeg', 'image/png', 'image/webp']);

    if ($id > 0) {
        $currentStmt = db()->prepare('SELECT foto_path FROM empresas WHERE id = :id');
        $currentStmt->execute(['id' => $id]);
        $current = $currentStmt->fetch();
        if (!$current) {
            json_response(['ok' => false, 'message' => 'No se encontro la empresa.'], 404);
        }

        $sql = 'UPDATE empresas SET razon_social = :razon_social, ruc = :ruc, direccion = :direccion';
        $params = [
            'razon_social' => $razonSocial,
            'ruc' => $ruc,
            'direccion' => $direccion ?: null,
            'id' => $id,
        ];
        if ($foto['path']) {
            delete_uploaded_file($current['foto_path'] ?? null);
            $sql .= ', foto_path = :foto_path';
            $params['foto_path'] = $foto['path'];
        }
        $sql .= ' WHERE id = :id';
        db()->prepare($sql)->execute($params);
    } else {
        $stmt = db()->prepare('INSERT INTO empresas (razon_social, ruc, direccion, foto_path) VALUES (:razon_social, :ruc, :direccion, :foto_path)');
        $stmt->execute([
            'razon_social' => $razonSocial,
            'ruc' => $ruc,
            'direccion' => $direccion ?: null,
            'foto_path' => $foto['path'],
        ]);
    }
    json_response(['ok' => true]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_response(['ok' => false, 'message' => 'Ya existe una empresa con ese RUC.'], 409);
    }
    json_response(['ok' => false, 'message' => 'No se pudo guardar la empresa.'], 400);
}
