<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);

$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$dayOfWeek = (int) ($_POST['day_of_week'] ?? 0);
$entryTime = (string) ($_POST['entry_time'] ?? '');
$entryStart = (string) ($_POST['entry_start'] ?? '');
$entryEnd = (string) ($_POST['entry_end'] ?? '');
$exitTime = (string) ($_POST['exit_time'] ?? '');
$exitStart = (string) ($_POST['exit_start'] ?? '');
$exitEnd = (string) ($_POST['exit_end'] ?? '');

function valid_time_or_null(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $value)) {
        return null;
    }
    return $value . ':00';
}

if ($scheduleId <= 0 || $dayOfWeek < 1 || $dayOfWeek > 7) {
    json_response(['ok' => false, 'message' => 'Horario o dia no valido.'], 400);
}

$entryTime = valid_time_or_null($entryTime);
$entryStart = valid_time_or_null($entryStart);
$entryEnd = valid_time_or_null($entryEnd);
$breakStart = null;
$breakEnd = null;
$exitTime = valid_time_or_null($exitTime);
$exitStart = valid_time_or_null($exitStart);
$exitEnd = valid_time_or_null($exitEnd);

if (!$entryTime || !$entryStart || !$entryEnd || !$exitTime || !$exitStart || !$exitEnd) {
    json_response(['ok' => false, 'message' => 'Complete los horarios obligatorios.'], 400);
}

$entryTimeMinutes = ((int) substr($entryTime, 0, 2) * 60) + (int) substr($entryTime, 3, 2);
$entryStartMinutes = ((int) substr($entryStart, 0, 2) * 60) + (int) substr($entryStart, 3, 2);
$entryEndMinutes = ((int) substr($entryEnd, 0, 2) * 60) + (int) substr($entryEnd, 3, 2);
$exitTimeMinutes = ((int) substr($exitTime, 0, 2) * 60) + (int) substr($exitTime, 3, 2);
$exitStartMinutes = ((int) substr($exitStart, 0, 2) * 60) + (int) substr($exitStart, 3, 2);
$exitEndMinutes = ((int) substr($exitEnd, 0, 2) * 60) + (int) substr($exitEnd, 3, 2);
$tolerance = $entryEndMinutes - $entryTimeMinutes;

if ($entryStartMinutes > $entryTimeMinutes || $entryTimeMinutes > $entryEndMinutes || $tolerance > 180) {
    json_response(['ok' => false, 'message' => 'La hora de entrada debe estar dentro del rango de marcación y la tolerancia no puede superar 180 minutos.'], 400);
}

if ($exitStartMinutes > $exitTimeMinutes || $exitTimeMinutes > $exitEndMinutes) {
    json_response(['ok' => false, 'message' => 'La hora de salida debe estar dentro del rango de marcación.'], 400);
}

$stmt = db()->prepare('SELECT id FROM attendance_schedules WHERE id = :id AND status = 1');
$stmt->execute(['id' => $scheduleId]);
if (!$stmt->fetch()) {
    json_response(['ok' => false, 'message' => 'El horario no existe.'], 404);
}

$stmt = db()->prepare('INSERT INTO attendance_schedule_days
    (schedule_id, day_of_week, entry_time, entry_start, entry_end, break_start, break_end, exit_time, exit_start, exit_end, tolerance_minutes, status)
    VALUES (:schedule_id, :day_of_week, :entry_time, :entry_start, :entry_end, :break_start, :break_end, :exit_time, :exit_start, :exit_end, :tolerance_minutes, 1)
    ON DUPLICATE KEY UPDATE
        entry_time = VALUES(entry_time),
        entry_start = VALUES(entry_start),
        entry_end = VALUES(entry_end),
        break_start = VALUES(break_start),
        break_end = VALUES(break_end),
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
    'break_start' => $breakStart,
    'break_end' => $breakEnd,
    'exit_time' => $exitTime,
    'exit_start' => $exitStart,
    'exit_end' => $exitEnd,
    'tolerance_minutes' => $tolerance,
]);

json_response(['ok' => true]);
