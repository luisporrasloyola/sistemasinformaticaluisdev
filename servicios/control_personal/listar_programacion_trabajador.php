<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_calendar.php';
require_module_access('control_personal.control_asistencia');

$workerId = is_personal_role() ? (int) current_user_worker_id() : (int) ($_GET['worker_id'] ?? 0);
if ($workerId <= 0) json_response(['ok'=>false,'message'=>'Seleccione un trabajador.'],422);
$workerStmt = db()->prepare('SELECT id, company_id FROM workers WHERE id = :id LIMIT 1');
$workerStmt->execute(['id' => $workerId]);
$worker = $workerStmt->fetch();
if (!$worker) json_response(['ok'=>false,'message'=>'El trabajador seleccionado no existe.'],404);
$stmt = db()->prepare("SELECT ap.id, ap.assignment_id, ap.program_date, ap.entry_time, ap.exit_time, ap.schedule_source,
        ap.activity, COALESCE(NULLIF(ap.notes, ''),
            (SELECT GROUP_CONCAT(aps.destination ORDER BY aps.stop_order SEPARATOR '\\n')
             FROM attendance_program_stops aps WHERE aps.program_id=ap.id)) AS notes,
        l.name AS location_name, l.address, l.reference, s.name AS schedule_name,
        EXISTS(SELECT 1 FROM attendance_marks ame WHERE ame.program_id = ap.id AND ame.mark_type = 'entrada') AS has_entry,
        EXISTS(SELECT 1 FROM attendance_marks ams WHERE ams.program_id = ap.id AND ams.mark_type = 'salida') AS has_exit
    FROM attendance_programs ap
    JOIN attendance_assignments aa ON aa.id=ap.assignment_id
    JOIN attendance_locations l ON l.id=COALESCE(ap.location_id, aa.location_id)
    JOIN attendance_schedules s ON s.id=COALESCE(ap.schedule_id, aa.schedule_id)
    WHERE ap.worker_id=:worker_id AND ap.status='programada'
      AND ap.program_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 MONTH) AND DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
    ORDER BY ap.program_date, ap.entry_time");
$stmt->execute(['worker_id'=>$workerId]);
$programs = $stmt->fetchAll();
$stopsStmt = db()->prepare('SELECT destination, activity FROM attendance_program_stops WHERE program_id=:program_id ORDER BY stop_order');
foreach ($programs as &$program) {
    $stopsStmt->execute(['program_id'=>$program['id']]);
    $program['stops'] = $stopsStmt->fetchAll();
    $program['has_entry'] = (bool) $program['has_entry'];
    $program['has_exit'] = (bool) $program['has_exit'];
}
unset($program);

$calendarStmt = db()->prepare("SELECT id, calendar_date, COALESCE(end_date, calendar_date) AS end_date,
        event_type, name, scope_type
    FROM attendance_calendar_days
    WHERE status = 1
      AND event_type IN ('holiday', 'non_working', 'vacation', 'permission', 'rest')
      AND calendar_date <= DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
      AND COALESCE(end_date, calendar_date) >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
      AND (scope_type = 'all'
        OR (scope_type = 'company' AND company_id = :company_id)
        OR (scope_type = 'worker' AND worker_id = :worker_id))
    ORDER BY calendar_date, id");
$calendarStmt->execute(['company_id' => (int) $worker['company_id'], 'worker_id' => $workerId]);
$calendarEvents = array_map(static fn(array $event): array => [
    'id' => (int) $event['id'],
    'start_date' => (string) $event['calendar_date'],
    'end_date' => (string) $event['end_date'],
    'type' => (string) $event['event_type'],
    'code' => attendance_calendar_event_abbreviation((string) $event['event_type']),
    'label' => attendance_calendar_event_label((string) $event['event_type']),
    'name' => (string) $event['name'],
    'scope' => (string) $event['scope_type'],
], $calendarStmt->fetchAll());

$regularStmt = db()->prepare("SELECT aa.id AS assignment_id, aa.activity, aa.instructions, aa.valid_from, aa.valid_until, l.name AS location_name,
        l.address, l.reference, s.name AS schedule_name, sd.day_of_week,
        COALESCE(sd.entry_time, sd.entry_start) AS entry_time,
        COALESCE(sd.exit_time, sd.exit_start) AS exit_time
    FROM attendance_assignments aa
    JOIN attendance_locations l ON l.id = aa.location_id
    JOIN attendance_schedules s ON s.id = aa.schedule_id
    JOIN attendance_schedule_days sd ON sd.schedule_id = s.id AND sd.status = 1
    WHERE aa.worker_id = :worker_id AND aa.status = 1
      AND aa.valid_from <= DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
      AND (aa.valid_until IS NULL OR aa.valid_until >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH))
    ORDER BY sd.day_of_week, sd.entry_time, aa.id");
$regularStmt->execute(['worker_id' => $workerId]);
$regularSchedules = array_map(static fn(array $row): array => [
    'assignment_id' => (int) $row['assignment_id'],
    'valid_from' => (string) $row['valid_from'],
    'valid_until' => $row['valid_until'] ? (string) $row['valid_until'] : null,
    'day_of_week' => (int) $row['day_of_week'],
    'entry_time' => (string) $row['entry_time'],
    'exit_time' => (string) $row['exit_time'],
    'location_name' => (string) $row['location_name'],
    'schedule_name' => (string) $row['schedule_name'],
    'activity' => $row['activity'] ? (string) $row['activity'] : null,
    'instructions' => $row['instructions'] ? (string) $row['instructions'] : null,
    'address' => $row['address'] ? (string) $row['address'] : null,
    'reference' => $row['reference'] ? (string) $row['reference'] : null,
], $regularStmt->fetchAll());

$overrideStmt = db()->prepare("SELECT ajo.assignment_id, ajo.journey_date, ajo.activity, ajo.instructions
    FROM attendance_journey_overrides ajo
    JOIN attendance_assignments aa ON aa.id=ajo.assignment_id
    WHERE aa.worker_id=:worker_id
      AND ajo.journey_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 MONTH) AND DATE_ADD(CURDATE(), INTERVAL 12 MONTH)");
$overrideStmt->execute(['worker_id' => $workerId]);
$overrides = $overrideStmt->fetchAll();

json_response(['ok'=>true,'programs'=>$programs,'calendar_events'=>$calendarEvents,'regular_schedules'=>$regularSchedules,'journey_overrides'=>$overrides]);
