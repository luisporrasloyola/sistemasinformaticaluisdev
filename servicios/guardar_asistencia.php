<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/asistencia.php';
require_login();
ensure_attendance_schema();

verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$fecha = trim((string) ($_POST['fecha'] ?? ''));
$nombre = trim((string) ($_POST['nombre_apellido'] ?? ''));
$actividad = trim((string) ($_POST['lugar_actividad'] ?? ''));
$empresa = trim((string) ($_POST['empresa_proyecto'] ?? ''));
$puesto = trim((string) ($_POST['puesto'] ?? ''));

if ($fecha === '' || $nombre === '' || $actividad === '') {
    json_response(['ok' => false, 'message' => 'Complete los campos obligatorios.'], 400);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    json_response(['ok' => false, 'message' => 'Ingrese una fecha válida.'], 400);
}

$hash = attendance_hash($fecha, $nombre, $actividad, $empresa, $puesto);

try {
    if ($id > 0) {
        $stmt = db()->prepare('UPDATE attendance_control
            SET fecha = :fecha, nombre_apellido = :nombre, lugar_actividad = :actividad,
                empresa_proyecto = :empresa, puesto = :puesto, record_hash = :hash
            WHERE id = :id');
        $stmt->execute([
            'fecha' => $fecha,
            'nombre' => $nombre,
            'actividad' => $actividad,
            'empresa' => $empresa !== '' ? $empresa : null,
            'puesto' => $puesto !== '' ? $puesto : null,
            'hash' => $hash,
            'id' => $id,
        ]);
    } else {
        $stmt = db()->prepare('INSERT INTO attendance_control
            (fecha, nombre_apellido, lugar_actividad, empresa_proyecto, puesto, record_hash)
            VALUES (:fecha, :nombre, :actividad, :empresa, :puesto, :hash)');
        $stmt->execute([
            'fecha' => $fecha,
            'nombre' => $nombre,
            'actividad' => $actividad,
            'empresa' => $empresa !== '' ? $empresa : null,
            'puesto' => $puesto !== '' ? $puesto : null,
            'hash' => $hash,
        ]);
    }

    json_response(['ok' => true]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_response(['ok' => false, 'message' => 'Este registro de asistencia ya existe.'], 409);
    }
    json_response(['ok' => false, 'message' => 'No se pudo guardar el registro.'], 400);
}