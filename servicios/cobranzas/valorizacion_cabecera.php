<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function cabecera_response(bool $ok, string $message, int $status): never
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cabecera_response(false, 'Método no permitido.', 405);
if (empty($_SESSION['user'])) cabecera_response(false, 'La sesión venció. Actualice la página.', 401);
if (!current_user_can_module('cobranzas.valorizacion')) cabecera_response(false, 'No tiene permiso para editar valorizaciones.', 403);
if (!csrf_is_valid($_POST['csrf_token'] ?? null)) cabecera_response(false, 'La sesión del formulario venció. Actualice la página.', 419);

$clientId = filter_var($_POST['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$field = (string) ($_POST['campo'] ?? '');
$limits = ['equipo' => 150, 'periodo' => 120];
$value = trim((string) ($_POST['valor'] ?? ''));
if (!$clientId || !isset($limits[$field]) || mb_strlen($value, 'UTF-8') > $limits[$field]) {
    cabecera_response(false, 'Ingrese un dato válido.', 422);
}
if ($field === 'periodo' && $value !== '') {
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})\|(\d{4}-\d{2}-\d{2})$/D', $value, $dates)) {
        cabecera_response(false, 'Seleccione las fechas Desde y Hasta.', 422);
    }
    foreach ([$dates[1], $dates[2]] as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) cabecera_response(false, 'Seleccione fechas válidas.', 422);
    }
    if ($dates[1] > $dates[2]) cabecera_response(false, 'La fecha Desde debe ser anterior o igual a Hasta.', 422);
}

try {
    $clientExists = db()->prepare('SELECT 1 FROM clients WHERE id = ?');
    $clientExists->execute([$clientId]);
    if (!$clientExists->fetchColumn()) cabecera_response(false, 'Cliente no encontrado.', 404);
    $sql = "INSERT INTO cobranzas_valorizacion_datos_cliente (client_id, $field) VALUES (?, ?) ON DUPLICATE KEY UPDATE $field = VALUES($field)";
    db()->prepare($sql)->execute([$clientId, $value]);
    cabecera_response(true, 'Dato guardado.', 200);
} catch (PDOException $exception) {
    error_log('Error al guardar cabecera de valorización: ' . $exception->getMessage());
    cabecera_response(false, 'No se pudo guardar el dato. Verifique la tabla de datos del cliente.', 500);
}
