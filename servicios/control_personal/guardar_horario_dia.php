<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);

$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$dayOfWeek = (int) ($_POST['day_of_week'] ?? 0);
$entryTime = (string) ($_POST['entry_time'] ?? '');
$entryAdvance = filter_var($_POST['entry_advance_minutes'] ?? null, FILTER_VALIDATE_INT);
$tolerance = filter_var($_POST['tolerance_minutes'] ?? null, FILTER_VALIDATE_INT);
$exitTime = (string) ($_POST['exit_time'] ?? '');

function valid_time_or_null(string $value): ?string
{
    $value = trim($value);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) return null;
    return $value . ':00';
}

function time_to_minutes(string $value): int
{
    return ((int) substr($value, 0, 2) * 60) + (int) substr($value, 3, 2);
}

function minutes_to_time(int $minutes): string
{
    $minutes = (($minutes % 1440) + 1440) % 1440;
    return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
}

if ($scheduleId <= 0 || $dayOfWeek < 1 || $dayOfWeek > 7) {
    json_response(['ok' => false, 'message' => 'Horario o día no válido.'], 400);
}

$entryTime = valid_time_or_null($entryTime);
$exitTime = valid_time_or_null($exitTime);
if (!$entryTime || !$exitTime) {
    json_response(['ok' => false, 'message' => 'Complete la hora de entrada y la hora de salida.'], 400);
}

if ($entryAdvance === false || $tolerance === false || $entryAdvance < 0 || $entryAdvance > 180 || $tolerance < 0 || $tolerance > 180) {
    json_response(['ok' => false, 'message' => 'Los márgenes de entrada deben estar entre 0 y 180 minutos.'], 400);
}

$entryTimeMinutes = time_to_minutes($entryTime);
$entryStart = minutes_to_time($entryTimeMinutes - $entryAdvance);
$entryEnd = minutes_to_time($entryTimeMinutes + $tolerance);

// Campos internos conservados para compatibilidad con Control de asistencia y reportes.
// La salida anterior a la hora oficial se clasifica como salida anticipada.
$exitStart = $exitTime;
$exitEnd = $exitTime;

$stmt = db()->prepare('SELECT id FROM attendance_schedules WHERE id = :id AND status = 1');
$stmt->execute(['id' => $scheduleId]);
if (!$stmt->fetch()) {
    json_response(['ok' => false, 'message' => 'El horario no existe.'], 404);
}

$stmt = db()->prepare('INSERT INTO attendance_schedule_days
    (schedule_id, day_of_week, entry_time, entry_start, entry_end, break_start, break_end, exit_time, exit_start, exit_end, tolerance_minutes, status)
    VALUES (:schedule_id, :day_of_week, :entry_time, :entry_start, :entry_end, NULL, NULL, :exit_time, :exit_start, :exit_end, :tolerance_minutes, 1)
    ON DUPLICATE KEY UPDATE
        entry_time = VALUES(entry_time),
        entry_start = VALUES(entry_start),
        entry_end = VALUES(entry_end),
        break_start = NULL,
        break_end = NULL,
        exit_time = VALUES(exit_time),
        exit_start = VALUES(exit_start),
        exit_end = VALUES(exit_end),
        tolerance_minutes = VALUES(tolerance_minutes),
        status = 1');
$stmt->execute([
    'schedule_id' => $scheduleId,
    'day_of_week' => $dayOfWeek,
    'entry_time' => $entryTime,
    'entry_start' => $entryStart,
    'entry_end' => $entryEnd,
    'exit_time' => $exitTime,
    'exit_start' => $exitStart,
    'exit_end' => $exitEnd,
    'tolerance_minutes' => $tolerance,
]);

json_response(['ok' => true]);
