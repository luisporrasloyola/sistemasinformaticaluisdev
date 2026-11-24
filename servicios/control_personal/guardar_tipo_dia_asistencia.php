<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_calendar.php';
require_once __DIR__ . '/../../includes/attendance_labor_cycle.php';

require_role('Administrador');
verify_csrf($_POST['csrf_token'] ?? null);
header('Content-Type: application/json; charset=utf-8');

function day_type_response(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$workerId = (int) ($_POST['worker_id'] ?? 0);
$workDate = trim((string) ($_POST['work_date'] ?? ''));
$dayType = trim((string) ($_POST['day_type'] ?? ''));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $workDate);
if ($workerId < 1 || !$parsedDate || $parsedDate->format('Y-m-d') !== $workDate
    || !in_array($dayType, ['regular', 'extra'], true)) {
    day_type_response(['ok' => false, 'message' => 'Seleccione un trabajador, fecha y tipo de día válidos.'], 422);
}
if ($workDate >= date('Y-m-d')) {
    day_type_response(['ok' => false, 'message' => 'El día actual y los días futuros no se pueden cambiar.'], 409);
}

try {
    $pdo = db();
    $worker = $pdo->prepare('SELECT company_id FROM workers WHERE id = :id LIMIT 1');
    $worker->execute(['id' => $workerId]);
    $companyId = $worker->fetchColumn();
    if ($companyId === false) day_type_response(['ok' => false, 'message' => 'El trabajador no existe.'], 404);

    if ($dayType === 'extra') {
        if (attendance_calendar_event_for_worker($workDate, $workerId, (int) $companyId)) {
            day_type_response(['ok' => false, 'message' => 'No se puede marcar como Día Extra una fecha especial del calendario laboral.'], 409);
        }
        $absence = $pdo->prepare("SELECT 1 FROM attendance_manual_day_overrides WHERE worker_id = :worker AND mark_date = :date AND attendance_status = 'falta' LIMIT 1");
        $absence->execute(['worker' => $workerId, 'date' => $workDate]);
        if ($absence->fetchColumn()) {
            day_type_response(['ok' => false, 'message' => 'No se puede marcar como Día Extra una falta administrativa.'], 409);
        }
        $save = $pdo->prepare("INSERT INTO attendance_extra_days (worker_id, work_date, day_type, changed_by_user_id)
            VALUES (:worker, :date, 'extra', :actor)
            ON DUPLICATE KEY UPDATE day_type = 'extra', changed_by_user_id = VALUES(changed_by_user_id), changed_at = CURRENT_TIMESTAMP");
        $save->execute(['worker' => $workerId, 'date' => $workDate, 'actor' => (int) (current_user()['id'] ?? 0) ?: null]);
    } else {
        $assigned = $pdo->prepare("SELECT 1 FROM attendance_assignments
            WHERE worker_id = :worker AND day_type = 'extra' AND valid_from <= :date_start
              AND (valid_until IS NULL OR valid_until >= :date_end)
              AND (deactivated_at IS NULL OR DATE(deactivated_at) >= :date_deactivated)
              AND (status = 1 OR deactivated_at IS NOT NULL) LIMIT 1");
        $assigned->execute(['worker' => $workerId, 'date_start' => $workDate,
            'date_end' => $workDate, 'date_deactivated' => $workDate]);
        if ($assigned->fetchColumn()) {
            $save = $pdo->prepare("INSERT INTO attendance_extra_days (worker_id, work_date, day_type, changed_by_user_id)
                VALUES (:worker, :date, 'regular', :actor)
                ON DUPLICATE KEY UPDATE day_type = 'regular', changed_by_user_id = VALUES(changed_by_user_id), changed_at = CURRENT_TIMESTAMP");
            $save->execute(['worker' => $workerId, 'date' => $workDate, 'actor' => (int) (current_user()['id'] ?? 0) ?: null]);
        } else {
            $remove = $pdo->prepare('DELETE FROM attendance_extra_days WHERE worker_id = :worker AND work_date = :date');
            $remove->execute(['worker' => $workerId, 'date' => $workDate]);
        }
    }
    day_type_response(['ok' => true, 'message' => $dayType === 'extra' ? 'Día Extra guardado. La matriz mostrará su número según la fecha.' : 'Día Regular guardado.']);
} catch (PDOException $error) {
    if (in_array($error->getCode(), ['42S02', '42S22'], true)) {
        day_type_response(['ok' => false, 'message' => 'Falta ejecutar database/attendance_extra_days.sql o database/attendance_assignments_day_type.sql en esta base de datos.'], 503);
    }
    error_log('Error al guardar tipo de día de asistencia: ' . $error->getCode());
    day_type_response(['ok' => false, 'message' => 'No se pudo guardar el tipo de día.'], 500);
}
