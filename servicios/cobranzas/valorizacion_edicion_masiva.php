<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function cv_bulk_reply(bool $ok, string $message, int $status): never
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function cv_bulk_decimal(mixed $value): string|false|null
{
    $value = trim((string) $value);
    if ($value === '') return null;
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) return false;
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    return $whole . '.' . str_pad($fraction, 2, '0');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cv_bulk_reply(false, 'Metodo no permitido.', 405);
}
if (empty($_SESSION['user']) || !current_user_can_module('cobranzas.valorizacion')) {
    cv_bulk_reply(false, 'No tiene permiso para editar valorizaciones.', 403);
}
if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
    cv_bulk_reply(false, 'La sesion vencio. Actualice la pagina.', 419);
}

$valuationId = filter_var($_POST['valorizacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$rows = json_decode((string) ($_POST['rows'] ?? ''), true);
if (!$valuationId || !is_array($rows) || !$rows || count($rows) > 25) {
    cv_bulk_reply(false, 'Los registros enviados no son validos.', 422);
}
$activeStmt = db()->prepare('SELECT movilizacion_activa FROM cobranzas_valorizaciones WHERE id = ?');
$activeStmt->execute([$valuationId]);
$mobilityFlag = $activeStmt->fetchColumn();
if ($mobilityFlag === false) cv_bulk_reply(false, 'La valorización ya no existe.', 404);
$mobilityActive = (bool) $mobilityFlag;

$validated = [];
foreach ($rows as $row) {
    $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $equipment = trim((string) ($row['equipo'] ?? ''));
    $operator = trim((string) ($row['operador'] ?? ''));
    $date = (string) ($row['fecha_servicio'] ?? '');
    $mobilization = $mobilityActive ? cv_bulk_decimal($row['movilizacion'] ?? '') : null;
    $demobilization = $mobilityActive ? cv_bulk_decimal($row['desmovilizacion'] ?? '') : null;
    $start = (string) ($row['hora_inicio'] ?? '');
    $end = (string) ($row['hora_final'] ?? '');
    $total = trim((string) ($row['horas_total'] ?? ''));
    $hoursReason = trim((string) ($row['motivo_cambio_horas'] ?? ''));
    $notes = trim((string) ($row['observaciones'] ?? ''));

    if (!$id || $equipment === '' || mb_strlen($equipment, 'UTF-8') > 120 || mb_strlen($operator, 'UTF-8') > 150 || mb_strlen($hoursReason, 'UTF-8') > 1000 || mb_strlen($notes, 'UTF-8') > 2000) {
        cv_bulk_reply(false, 'Revise el equipo, operador u observaciones.', 422);
    }
    if ($mobilization === false || $demobilization === false) cv_bulk_reply(false, 'Ingrese importes decimales válidos para movilización y desmovilización.', 422);

    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date
        || !preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/D', $start)
        || !preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/D', $end)
        || !preg_match('/^([01]?\\d|2[0-3])(?::([0-5]\\d))?$/D', $total, $parts)) {
        cv_bulk_reply(false, 'Revise las fechas y horas ingresadas.', 422);
    }

    [$startHour, $startMinute] = array_map('intval', explode(':', $start));
    [$endHour, $endMinute] = array_map('intval', explode(':', $end));
    $elapsed = ($endHour * 60 + $endMinute - $startHour * 60 - $startMinute + 1440) % 1440;
    $minutes = (int) $parts[1] * 60 + (int) ($parts[2] ?? 0);
    if ($elapsed <= 60 || $minutes < 1) {
        cv_bulk_reply(false, 'Cada turno debe durar mas de una hora y tener un total valido.', 422);
    }

    $validated[] = [$id, $equipment, $operator ?: null, $date, $mobilization, $demobilization, $start, $end, $minutes, $hoursReason ?: null, $notes ?: null];
}

try {
    $ids = array_column($validated, 0);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $owned = db()->prepare("SELECT COUNT(*) FROM cobranzas_valorizacion_registros WHERE valorizacion_id = ? AND id IN ($placeholders)");
    $owned->execute([$valuationId, ...$ids]);
    if ((int) $owned->fetchColumn() !== count($ids)) {
        cv_bulk_reply(false, 'Uno de los registros ya no pertenece a esta valorizacion.', 422);
    }

    db()->beginTransaction();
    $statement = db()->prepare(
        'UPDATE cobranzas_valorizacion_registros
         SET equipo = ?, operador = ?, fecha_servicio = ?, movilizacion = ?, desmovilizacion = ?, hora_inicio = ?, hora_final = ?, horas_total_minutos = ?, motivo_cambio_horas = ?, observaciones = ?
         WHERE id = ? AND valorizacion_id = ?'
    );
    foreach ($validated as [$id, $equipment, $operator, $date, $mobilization, $demobilization, $start, $end, $minutes, $hoursReason, $notes]) {
        $statement->execute([$equipment, $operator, $date, $mobilization, $demobilization, $start, $end, $minutes, $hoursReason, $notes, $id, $valuationId]);
    }
    db()->commit();
    cv_bulk_reply(true, count($validated) === 1 ? 'Registro actualizado.' : 'Registros actualizados.', 200);
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    error_log('Error edicion masiva valorizacion: ' . $exception->getMessage());
    cv_bulk_reply(false, 'No se pudieron guardar los cambios.', 500);
}
