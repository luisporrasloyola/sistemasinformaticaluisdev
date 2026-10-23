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
if ($field === 'bloqueo_masivo') {
    $records = json_decode((string) ($_POST['registros'] ?? ''), true);
    if (($rawValue !== '0' && $rawValue !== '1') || !is_array($records)
        || array_diff(array_keys($records), array_keys($sourceTables)) !== []) {
        json_response(['ok' => false, 'message' => 'Selección de registros inválida.'], 422);
    }
    $totalRecords = 0;
    foreach ($records as $ids) {
        if (!is_array($ids)) json_response(['ok' => false, 'message' => 'Selección de registros inválida.'], 422);
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) json_response(['ok' => false, 'message' => 'Selección de registros inválida.'], 422);
            $totalRecords++;
        }
    }
    if ($totalRecords < 1 || $totalRecords > 1000) {
        json_response(['ok' => false, 'message' => 'Seleccione entre 1 y 1000 registros.'], 422);
    }
    try {
        db()->beginTransaction();
        foreach ($records as $central => $ids) {
            $ids = array_values(array_unique($ids));
            if (!$ids) continue;
            $marks = implode(',', array_fill(0, count($ids), '?'));
            if ($rawValue === '1') {
                $stmt = db()->prepare("INSERT INTO empresa_maquirenta_valorizacion_bloqueos (central, charla_id, bloqueado)
                    SELECT ?, c.id, 1 FROM {$sourceTables[$central]} c WHERE c.id IN ($marks)
                    ON DUPLICATE KEY UPDATE bloqueado = 1");
                $stmt->execute(array_merge([$central], $ids));
            } else {
                $stmt = db()->prepare("DELETE FROM empresa_maquirenta_valorizacion_bloqueos WHERE central = ? AND charla_id IN ($marks)");
                $stmt->execute(array_merge([$central], $ids));
            }
        }
        db()->commit();
    } catch (PDOException $exception) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Error al actualizar bloqueos de valorización: ' . $exception->getMessage());
        json_response(['ok' => false, 'message' => 'No se pudieron guardar los cambios.'], 500);
    }
    json_response(['ok' => true, 'value' => $rawValue]);
}
if (!isset($sourceTables[$site]) || !is_int($recordId)
    || !in_array($field, ['horas_trabajo', 'tarifa_equipo', 'tarifa_supervisor', 'descuento', 'observaciones', 'bloqueado'], true)) {
    json_response(['ok' => false, 'message' => 'Datos de valorización inválidos.'], 422);
}
if ($field === 'bloqueado') {
    if ($rawValue !== '0' && $rawValue !== '1') {
        json_response(['ok' => false, 'message' => 'Estado de bloqueo inválido.'], 422);
    }
} elseif ($field === 'observaciones') {
    if (mb_strlen($rawValue, 'UTF-8') > 1000) {
        json_response(['ok' => false, 'message' => 'La observación no puede superar 1000 caracteres.'], 422);
    }
} else {
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $rawValue)) {
        json_response(['ok' => false, 'message' => 'Ingrese un importe u horas válidos.'], 422);
    }
    $value = (float) $rawValue;
    if (($field === 'horas_trabajo' && $value > 24) || ($field !== 'horas_trabajo' && $value > 999999.99)) {
        json_response(['ok' => false, 'message' => 'El valor supera el máximo permitido.'], 422);
    }
}

$find = db()->prepare('SELECT fecha FROM ' . $sourceTables[$site] . ' WHERE id = ?');
$find->execute([$recordId]);
$record = $find->fetch();
if (!$record) json_response(['ok' => false, 'message' => 'La charla ya no existe.'], 404);

try {
    if ($field === 'bloqueado') {
        if ($rawValue === '1') {
            $stmt = db()->prepare('INSERT INTO empresa_maquirenta_valorizacion_bloqueos
                (central, charla_id, bloqueado) VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE bloqueado = 1');
            $stmt->execute([$site, $recordId]);
        } else {
            $stmt = db()->prepare('DELETE FROM empresa_maquirenta_valorizacion_bloqueos WHERE central = ? AND charla_id = ?');
            $stmt->execute([$site, $recordId]);
        }
    } elseif ($field === 'descuento' || $field === 'observaciones') {
        $stmt = db()->prepare("INSERT INTO empresa_maquirenta_valorizacion_detalles
            (central, fecha, {$field}) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE {$field} = VALUES({$field})");
        $stmt->execute([$site, $record['fecha'], $rawValue]);
    } elseif ($field === 'tarifa_supervisor') {
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
