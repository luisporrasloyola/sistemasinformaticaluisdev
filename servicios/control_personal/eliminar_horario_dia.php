<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);

$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$dayOfWeek = (int) ($_POST['day_of_week'] ?? 0);

if ($scheduleId <= 0 || $dayOfWeek < 1 || $dayOfWeek > 7) {
    json_response(['ok' => false, 'message' => 'Horario o dia no valido.'], 400);
}

db()->prepare('UPDATE attendance_schedule_days SET status = 0 WHERE schedule_id = :schedule_id AND day_of_week = :day_of_week')
    ->execute(['schedule_id' => $scheduleId, 'day_of_week' => $dayOfWeek]);

json_response(['ok' => true]);
