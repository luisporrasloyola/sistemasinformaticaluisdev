<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa_maquirenta.valorizaciones');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
}
verify_csrf($_POST['csrf_token'] ?? null);

$site = (string) ($_POST['sede'] ?? '');
$sourceTables = [
    'ventanilla' => 'empresa_maquirenta_charla_preoperacional',
    'santa_rosa' => 'empresa_maquirenta_santa_rosa_charla_preoperacional',
];
$recordId = filter_var($_POST['charla_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$field = (string) ($_POST['campo'] ?? '');
$rawValue = trim((string) ($_POST['valor'] ?? ''));
if (!isset($sourceTables[$site]) || !is_int($recordId) || !in_array($field, ['horas_trabajo', 'tarifa_equipo', 'tarifa_supervisor'], true)
    || !preg_match('/^\d+(?:\.\d{1,2})?$/', $rawValue)) {
    json_response(['ok' => false, 'message' => 'Datos de valorización inválidos.'], 422);
}
$value = (float) $rawValue;
if (($field === 'horas_trabajo' && $value > 24) || ($field !== 'horas_trabajo' && $value > 999999.99)) {
    json_response(['ok' => false, 'message' => 'El valor supera el máximo permitido.'], 422);
}

$find = db()->prepare('SELECT fecha FROM ' . $sourceTables[$site] . ' WHERE id = ?');
$find->execute([$recordId]);
$record = $find->fetch();
if (!$record) json_response(['ok' => false, 'message' => 'La charla ya no existe.'], 404);

try {
    if ($field === 'tarifa_supervisor') {
        $stmt = db()->prepare('INSERT INTO empresa_maquirenta_valorizacion_supervisor
            (central, fecha, tarifa_supervisor) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE tarifa_supervisor = VALUES(tarifa_supervisor)');
        $stmt->execute([$site, $record['fecha'], $rawValue]);
    } else {
        $stmt = db()->prepare("INSERT INTO empresa_maquirenta_valorizacion_equipos
            (central, charla_id, {$field}) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE {$field} = VALUES({$field})");
        $stmt->execute([$site, $recordId, $rawValue]);
    }
} catch (PDOException $exception) {
    error_log('Error al guardar valorización: ' . $exception->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudo guardar el cambio.'], 500);
}

json_response(['ok' => true, 'value' => $rawValue]);
