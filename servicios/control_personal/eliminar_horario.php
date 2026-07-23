<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Horario no valido.'], 400);
}

$marked = db()->prepare('SELECT COUNT(*) AS marks_count, COUNT(DISTINCT worker_id) AS workers_count
    FROM attendance_marks
    WHERE schedule_id = :id');
$marked->execute(['id' => $id]);
$usage = $marked->fetch() ?: [];
$marksCount = (int) ($usage['marks_count'] ?? 0);
$workersCount = (int) ($usage['workers_count'] ?? 0);

if ($marksCount > 0) {
    $workerText = $workersCount === 1 ? '1 trabajador' : $workersCount . ' trabajadores';
    $workerVerb = $workersCount === 1 ? 'tiene' : 'tienen';
    $markText = $marksCount === 1 ? '1 marcación registrada' : $marksCount . ' marcaciones registradas';
    json_response([
        'ok' => false,
        'message' => 'No se puede eliminar este horario porque ' . $workerText . ' ya ' . $workerVerb . ' '
            . $markText . '. Debe conservarse para no alterar el historial de asistencia.',
    ], 409);
}

$used = db()->prepare('SELECT COUNT(*) FROM attendance_assignments WHERE schedule_id = :id AND status = 1');
$used->execute(['id' => $id]);
if ((int) $used->fetchColumn() > 0) {
    json_response(['ok' => false, 'message' => 'No se puede eliminar el horario porque tiene asignaciones activas.'], 409);
}

db()->prepare('UPDATE attendance_schedules SET status = 0 WHERE id = :id')->execute(['id' => $id]);
json_response(['ok' => true]);
