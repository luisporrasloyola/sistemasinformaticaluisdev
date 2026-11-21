<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function operador_masivo_response(bool $ok, string $message, int $status, int $updated = 0): never
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message, 'updated' => $updated], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') operador_masivo_response(false, 'Método no permitido.', 405);
if (empty($_SESSION['user'])) operador_masivo_response(false, 'La sesión venció. Actualice la página e ingrese nuevamente.', 401);
if (!current_user_can_module('cobranzas.valorizacion')) operador_masivo_response(false, 'No tiene permiso para editar valorizaciones.', 403);
if (!csrf_is_valid($_POST['csrf_token'] ?? null)) operador_masivo_response(false, 'La sesión del formulario venció. Actualice la página.', 419);

$valuationId = filter_var($_POST['valorizacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$newOperator = trim((string) ($_POST['operador_nuevo'] ?? ''));

if (!$valuationId || $newOperator === '') {
    operador_masivo_response(false, 'Ingrese el nombre correcto del operador.', 422);
}
if (mb_strlen($newOperator, 'UTF-8') > 150) {
    operador_masivo_response(false, 'El nombre del operador no puede superar 150 caracteres.', 422);
}

try {
    $valuationStmt = db()->prepare('SELECT id FROM cobranzas_valorizaciones WHERE id = ? LIMIT 1');
    $valuationStmt->execute([$valuationId]);
    if (!$valuationStmt->fetchColumn()) operador_masivo_response(false, 'No se encontró la valorización.', 404);

    $countStmt = db()->prepare('SELECT COUNT(*) FROM cobranzas_valorizacion_registros WHERE valorizacion_id = ?');
    $countStmt->execute([$valuationId]);
    $recordsCount = (int) $countStmt->fetchColumn();
    if ($recordsCount < 1) operador_masivo_response(false, 'No hay registros para actualizar en esta valorización.', 404);

    $updateStmt = db()->prepare('UPDATE cobranzas_valorizacion_registros
        SET operador = ?
        WHERE valorizacion_id = ?');
    $updateStmt->execute([$newOperator, $valuationId]);
    operador_masivo_response(true, $recordsCount === 1 ? 'Se aplicó el nombre a 1 registro.' : "Se aplicó el nombre a {$recordsCount} registros.", 200, $recordsCount);
} catch (PDOException $exception) {
    error_log('Error al corregir operador masivamente en valorización: ' . $exception->getMessage());
    operador_masivo_response(false, 'No se pudo actualizar el operador. Intente nuevamente.', 500);
}
