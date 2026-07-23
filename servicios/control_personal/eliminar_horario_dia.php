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

$dayNames = [
    1 => 'lunes',
    2 => 'martes',
    3 => 'miércoles',
    4 => 'jueves',
    5 => 'viernes',
    6 => 'sábado',
    7 => 'domingo',
];

$used = db()->prepare('SELECT COUNT(*) AS marks_count, COUNT(DISTINCT worker_id) AS workers_count
    FROM attendance_marks
    WHERE schedule_id = :schedule_id
      AND WEEKDAY(mark_date) + 1 = :day_of_week');
$used->execute([
    'schedule_id' => $scheduleId,
    'day_of_week' => $dayOfWeek,
]);
$usage = $used->fetch() ?: [];
$marksCount = (int) ($usage['marks_count'] ?? 0);
$workersCount = (int) ($usage['workers_count'] ?? 0);

if ($marksCount > 0) {
    $dayName = $dayNames[$dayOfWeek];
    $workerText = $workersCount === 1 ? '1 trabajador' : $workersCount . ' trabajadores';
    $workerVerb = $workersCount === 1 ? 'tiene' : 'tienen';
    $markText = $marksCount === 1 ? '1 marcación registrada' : $marksCount . ' marcaciones registradas';
    json_response([
        'ok' => false,
        'message' => 'No se puede quitar el ' . $dayName . ' porque ' . $workerText
            . ' ya ' . $workerVerb . ' ' . $markText . ' con este horario. Debe conservarse para no alterar el historial de asistencia.',
    ], 409);
}

db()->prepare('UPDATE attendance_schedule_days SET status = 0 WHERE schedule_id = :schedule_id AND day_of_week = :day_of_week')
    ->execute(['schedule_id' => $scheduleId, 'day_of_week' => $dayOfWeek]);

json_response(['ok' => true]);
