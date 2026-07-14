<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa.seguridad');

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
if (!current_user_can_document('empresa.seguridad', $documentoId, 'upload')) {
    json_response(['ok' => false, 'message' => 'No tiene permisos para guardar este documento.'], 403);
}
if (strtotime($fechaFin) < strtotime($fechaInicio)) {
    json_response(['ok' => false, 'message' => 'La fecha fin no puede ser menor a la fecha inicio.'], 400);
}

try {
    $pdf = upload_file($_FILES['pdf'] ?? [], 'empresa_seguridad', ['application/pdf']);
    if ($id > 0) {
        $currentStmt = db()->prepare('SELECT archivo_path FROM empresa_seguridad_documentos WHERE id = :id');
        $currentStmt->execute(['id' => $id]);
        $current = $currentStmt->fetch();
        if (!$current) json_response(['ok' => false, 'message' => 'No se encontro el documento.'], 404);

        $sql = 'UPDATE empresa_seguridad_documentos SET documento_id = :documento_id, fecha_registro = :fecha_registro, fecha_inicio = :fecha_inicio, fecha_fin = :fecha_fin, observaciones = :observaciones';
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
        $stmt = db()->prepare('INSERT INTO empresa_seguridad_documentos (empresa_id, documento_id, fecha_registro, fecha_inicio, fecha_fin, observaciones, archivo_path, archivo_nombre_original, registered_by_user_id)
            VALUES (:empresa_id, :documento_id, :fecha_registro, :fecha_inicio, :fecha_fin, :observaciones, :archivo_path, :archivo_nombre_original, :registered_by_user_id)');
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
