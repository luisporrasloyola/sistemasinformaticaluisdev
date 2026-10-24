<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function lugar_response(bool $ok, string $message, int $status): never
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') lugar_response(false, 'Método no permitido.', 405);
if (empty($_SESSION['user'])) lugar_response(false, 'La sesión venció. Actualice la página e ingrese de nuevo.', 401);
if (!current_user_can_module('cobranzas.valorizacion')) lugar_response(false, 'No tiene permiso para editar valorizaciones.', 403);
if (!csrf_is_valid($_POST['csrf_token'] ?? null)) lugar_response(false, 'La sesión del formulario venció. Actualice la página.', 419);

$clientId = filter_var($_POST['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$location = trim((string) ($_POST['lugar_servicio'] ?? ''));
if (!$clientId || mb_strlen($location, 'UTF-8') > 200) lugar_response(false, 'Ingrese un lugar de servicio válido (máximo 200 caracteres).', 422);

try {
    db()->prepare('INSERT INTO cobranzas_valorizacion_datos_cliente (client_id, lugar_servicio) VALUES (?, ?) ON DUPLICATE KEY UPDATE lugar_servicio = VALUES(lugar_servicio)')
        ->execute([$clientId, $location]);
    lugar_response(true, 'Lugar de servicio actualizado.', 200);
} catch (PDOException $exception) {
    error_log('Error al guardar lugar de servicio: ' . $exception->getMessage());
    lugar_response(false, 'No se pudo guardar el lugar de servicio. Verifique la tabla de datos del cliente.', 500);
}
