<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function precio_response(bool $ok, string $message, int $status, ?string $price = null): never
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message, 'price' => $price], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') precio_response(false, 'Método no permitido.', 405);
if (empty($_SESSION['user'])) precio_response(false, 'La sesión venció. Actualice la página e ingrese de nuevo.', 401);
if (!current_user_can_module('cobranzas.valorizacion')) precio_response(false, 'No tiene permiso para editar valorizaciones.', 403);
if (!csrf_is_valid($_POST['csrf_token'] ?? null)) precio_response(false, 'La sesión del formulario venció. Actualice la página.', 419);

$clientId = filter_var($_POST['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$rateKey = trim((string) ($_POST['equipo_clave'] ?? ''));
$rateRaw = trim((string) ($_POST['precio_hora'] ?? ''));
if (!$clientId || $rateKey === '' || mb_strlen($rateKey, 'UTF-8') > 120) precio_response(false, 'Seleccione un equipo válido.', 422);
if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $rateRaw) || (float) $rateRaw > 9999999999.99) precio_response(false, 'Ingrese un precio por hora válido.', 422);

function precio_equipo_clave(string $equipment, string $plate): string
{
    $equipment = trim($equipment);
    $plate = trim($plate);
    if ($plate !== '' && !str_ends_with(mb_strtolower($equipment, 'UTF-8'), mb_strtolower($plate, 'UTF-8'))) {
        $equipment .= ' ' . $plate;
    }
    $name = trim((string) preg_replace('/\s+[A-Z0-9]{1,8}-[A-Z0-9-]{2,}\s*$/iu', '', $equipment));
    return strtr(mb_strtolower($name, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
}

try {
    $stmt = db()->prepare('SELECT equipo, placa_serie FROM cobranzas_valorizacion_registros WHERE client_id = ?');
    $stmt->execute([$clientId]);
    $knownKey = false;
    foreach ($stmt as $record) {
        if (precio_equipo_clave((string) $record['equipo'], (string) $record['placa_serie']) === $rateKey) {
            $knownKey = true;
            break;
        }
    }
    if (!$knownKey) precio_response(false, 'El equipo no pertenece a este cliente.', 422);

    $price = number_format((float) $rateRaw, 2, '.', '');
    db()->prepare('INSERT INTO cobranzas_valorizacion_precios_hora (client_id, equipo_clave, precio_hora) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE precio_hora = VALUES(precio_hora)')
        ->execute([$clientId, $rateKey, $price]);
    precio_response(true, 'Precio guardado.', 200, $price);
} catch (PDOException $exception) {
    error_log('Error al guardar precio por hora: ' . $exception->getMessage());
    precio_response(false, 'No se pudo guardar el precio por hora. Verifique la tabla de precios.', 500);
}
