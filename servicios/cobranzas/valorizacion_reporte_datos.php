<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/cobranzas_valorizacion_periodo.php';
require_module_access('cobranzas.valorizacion');

header('Content-Type: application/json; charset=utf-8');
$clientId = filter_var($_GET['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$clientId) json_response(['ok' => false, 'message' => 'Seleccione un cliente válido.'], 422);
try {
    $defaultMonth = null;
    if (!isset($_GET['mes']) && ($_GET['periodo'] ?? 'mes') !== 'fechas') {
        $latestStmt = db()->prepare('SELECT MAX(fecha_servicio) FROM cobranzas_valorizacion_registros WHERE client_id = ?');
        $latestStmt->execute([$clientId]);
        $latestDate = $latestStmt->fetchColumn();
        if ($latestDate) $defaultMonth = substr((string) $latestDate, 0, 7);
    }
    $period = valorizacion_periodo($_GET, $defaultMonth);
} catch (InvalidArgumentException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
}
$periodWhere = ' AND fecha_servicio BETWEEN ? AND ?';
$periodParams = [$period['start'], $period['end']];
$currency = ($_GET['moneda'] ?? 'PEN') === 'USD' ? 'USD' : 'PEN';

function reporte_equipo_completo(string $equipment, string $plate): string
{
    $equipment = trim($equipment);
    $plate = trim($plate);
    return $plate !== '' && !str_ends_with(mb_strtolower($equipment, 'UTF-8'), mb_strtolower($plate, 'UTF-8'))
        ? $equipment . ' ' . $plate : $equipment;
}

function reporte_tipo_equipo(string $equipment): string
{
    return trim((string) preg_replace('/\s+[A-Z0-9]{1,8}-[A-Z0-9-]{2,}\s*$/iu', '', $equipment));
}

function reporte_clave_equipo(string $equipment): string
{
    return strtr(mb_strtolower(reporte_tipo_equipo($equipment), 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
}

try {
    $stmt = db()->prepare('SELECT business_name, ruc FROM clients WHERE id = ?');
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();
    if (!$client) json_response(['ok' => false, 'message' => 'Cliente no encontrado.'], 404);

    $stmt = db()->prepare("SELECT name, phone FROM client_contacts WHERE client_id = ? AND LOWER(position) LIKE '%comercial%' ORDER BY sort_order, id LIMIT 1");
    $stmt->execute([$clientId]);
    $contact = $stmt->fetch() ?: null;

    $stmt = db()->prepare('SELECT lugar_servicio, equipo, periodo FROM cobranzas_valorizacion_datos_cliente WHERE client_id = ?');
    $stmt->execute([$clientId]);
    $headerData = $stmt->fetch() ?: [];
    $location = (string) ($headerData['lugar_servicio'] ?? '');
    $headerEquipment = (string) ($headerData['equipo'] ?? '');
    $headerPeriod = (string) ($headerData['periodo'] ?? '');
    $periodDates = preg_match('/^(\d{4}-\d{2}-\d{2})\|(\d{4}-\d{2}-\d{2})$/D', $headerPeriod, $periodMatch)
        ? ['from' => $periodMatch[1], 'to' => $periodMatch[2]] : null;

    $stmt = db()->prepare('SELECT id, equipo, placa_serie, operador, fecha_servicio, hora_inicio, hora_final, horas_total_minutos, observaciones FROM cobranzas_valorizacion_registros WHERE client_id = ?' . $periodWhere . ' ORDER BY fecha_servicio DESC, id DESC');
    $stmt->execute(array_merge([$clientId], $periodParams));
    $records = [];
    $groups = [];
    $totalMinutes = 0;
    foreach ($stmt as $row) {
        $equipment = reporte_equipo_completo((string) $row['equipo'], (string) $row['placa_serie']);
        $key = reporte_clave_equipo($equipment);
        $minutes = (int) $row['horas_total_minutos'];
        $totalMinutes += $minutes;
        if ($key !== '') {
            if (!isset($groups[$key])) $groups[$key] = ['name' => $key === 'camion grua' ? 'Camión Grúa' : reporte_tipo_equipo($equipment), 'minutes' => 0, 'price' => '37.50'];
            $groups[$key]['minutes'] += $minutes;
        }
        $records[] = [
            'equipment' => $equipment,
            'operator' => (string) ($row['operador'] ?? ''),
            'date' => (string) $row['fecha_servicio'],
            'start' => substr((string) $row['hora_inicio'], 0, 5),
            'end' => substr((string) $row['hora_final'], 0, 5),
            'minutes' => $minutes,
            'notes' => (string) ($row['observaciones'] ?? ''),
            'file_url' => APP_URL . '/servicios/cobranzas/valorizacion_archivo.php?id=' . (int) $row['id'],
        ];
    }
    if (!$records) json_response(['ok' => false, 'message' => 'No hay registros para exportar en el periodo seleccionado.'], 422);

    $stmt = db()->prepare('SELECT equipo_clave, precio_hora FROM cobranzas_valorizacion_precios_hora WHERE client_id = ?');
    $stmt->execute([$clientId]);
    foreach ($stmt as $rate) {
        if (isset($groups[$rate['equipo_clave']])) $groups[$rate['equipo_clave']]['price'] = number_format((float) $rate['precio_hora'], 2, '.', '');
    }
    $subtotalCents = 0;
    foreach ($groups as $group) $subtotalCents += (int) round((float) $group['price'] * 100 * $group['minutes'] / 60);
    $igvCents = (int) round($subtotalCents * 18 / 100);

    json_response([
        'ok' => true,
        'client' => ['name' => $client['business_name'], 'ruc' => $client['ruc'], 'location' => $location, 'contact' => $contact, 'equipment' => $headerEquipment, 'period' => $periodDates],
        'supplier' => ['name' => 'LIFE EQUIPOS INDUSTRIALES SAC', 'ruc' => '20603633441'],
        'records' => $records,
        'rates' => array_values($groups),
        'first_date' => $records[count($records) - 1]['date'],
        'last_date' => $records[0]['date'],
        'total_minutes' => $totalMinutes,
        'subtotal_cents' => $subtotalCents,
        'igv_cents' => $igvCents,
        'total_cents' => $subtotalCents + $igvCents,
        'currency' => $currency,
    ]);
} catch (PDOException $exception) {
    error_log('Error al preparar PDF de valorización: ' . $exception->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudieron cargar los datos de la valorización.'], 500);
}
