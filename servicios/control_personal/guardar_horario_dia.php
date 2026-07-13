<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);

$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$dayOfWeek = (int) ($_POST['day_of_week'] ?? 0);
$entryStart = (string) ($_POST['entry_start'] ?? '');
$entryEnd = (string) ($_POST['entry_end'] ?? '');
$breakStart = (string) ($_POST['break_start'] ?? '');
$breakEnd = (string) ($_POST['break_end'] ?? '');
$exitStart = (string) ($_POST['exit_start'] ?? '');
$exitEnd = (string) ($_POST['exit_end'] ?? '');
$tolerance = (int) ($_POST['tolerance_minutes'] ?? 0);

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

$entryStart = valid_time_or_null($entryStart);
$entryEnd = valid_time_or_null($entryEnd);
$breakStart = valid_time_or_null($breakStart);
$breakEnd = valid_time_or_null($breakEnd);
$exitStart = valid_time_or_null($exitStart);
$exitEnd = valid_time_or_null($exitEnd);

if (!$entryStart || !$entryEnd || !$exitStart || !$exitEnd || $tolerance < 0 || $tolerance > 180) {
    json_response(['ok' => false, 'message' => 'Complete los horarios obligatorios.'], 400);
}

if (($breakStart && !$breakEnd) || (!$breakStart && $breakEnd)) {
    json_response(['ok' => false, 'message' => 'Complete el rango de refrigerio.'], 400);
}

$stmt = db()->prepare('SELECT id FROM attendance_schedules WHERE id = :id AND status = 1');
$stmt->execute(['id' => $scheduleId]);
if (!$stmt->fetch()) {
    json_response(['ok' => false, 'message' => 'El horario no existe.'], 404);
}

$stmt = db()->prepare('INSERT INTO attendance_schedule_days
    (schedule_id, day_of_week, entry_start, entry_end, break_start, break_end, exit_start, exit_end, tolerance_minutes, status)
    VALUES (:schedule_id, :day_of_week, :entry_start, :entry_end, :break_start, :break_end, :exit_start, :exit_end, :tolerance_minutes, 1)
    ON DUPLICATE KEY UPDATE
        entry_start = VALUES(entry_start),
        entry_end = VALUES(entry_end),
        break_start = VALUES(break_start),
        break_end = VALUES(break_end),
        exit_start = VALUES(exit_start),
        exit_end = VALUES(exit_end),
        tolerance_minutes = VALUES(tolerance_minutes),
        status = 1');
$stmt->execute([
    'schedule_id' => $scheduleId,
    'day_of_week' => $dayOfWeek,
    'entry_start' => $entryStart,
    'entry_end' => $entryEnd,
    'break_start' => $breakStart,
    'break_end' => $breakEnd,
    'exit_start' => $exitStart,
    'exit_end' => $exitEnd,
    'tolerance_minutes' => $tolerance,
]);

json_response(['ok' => true]);
