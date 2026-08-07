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
        COALESCE(ap.location_id, aa.location_id) AS location_id,
        CASE WHEN EXISTS(SELECT 1 FROM attendance_program_stops aps WHERE aps.program_id=ap.id)
            THEN COALESCE(
                (SELECT ajo.activity FROM attendance_journey_overrides ajo
                    WHERE ajo.assignment_id=ap.assignment_id AND ajo.journey_date=ap.program_date LIMIT 1),
                NULLIF((SELECT aa2.activity FROM attendance_assignments aa2
                    WHERE aa2.worker_id=ap.worker_id AND aa2.status=1
                      AND aa2.location_id=COALESCE(ap.location_id, aa.location_id)
                      AND aa2.valid_from<=ap.program_date AND (aa2.valid_until IS NULL OR aa2.valid_until>=ap.program_date)
                    ORDER BY aa2.id DESC LIMIT 1), ''),
                NULLIF(aa.activity, ''), ap.activity)
            ELSE ap.activity END AS activity,
        CASE WHEN EXISTS(SELECT 1 FROM attendance_program_stops aps WHERE aps.program_id=ap.id)
            THEN CASE
                WHEN ajo.id IS NOT NULL AND ajo.updated_at >= ap.updated_at THEN ajo.instructions
                ELSE COALESCE(ap.notes, aa.instructions)
            END
            ELSE ap.notes END AS notes,
        l.name AS location_name, l.address, l.reference, l.latitude AS location_latitude,
        l.longitude AS location_longitude, l.radius_meters AS location_radius, s.name AS schedule_name,
        EXISTS(SELECT 1 FROM attendance_marks ame WHERE ame.program_id = ap.id AND ame.mark_type = 'entrada') AS has_entry,
        EXISTS(SELECT 1 FROM attendance_marks ams WHERE ams.program_id = ap.id AND ams.mark_type = 'salida') AS has_exit
    FROM attendance_programs ap
    JOIN attendance_assignments aa ON aa.id=ap.assignment_id
    LEFT JOIN attendance_journey_overrides ajo
      ON ajo.assignment_id=ap.assignment_id AND ajo.journey_date=ap.program_date
    JOIN attendance_locations l ON l.id=COALESCE(ap.location_id, aa.location_id)
    JOIN attendance_schedules s ON s.id=COALESCE(ap.schedule_id, aa.schedule_id)
    WHERE ap.worker_id=:worker_id AND ap.status='programada'
      AND ap.program_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 MONTH) AND DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
    ORDER BY ap.program_date, ap.entry_time");
$stmt->execute(['worker_id'=>$workerId]);
$programs = $stmt->fetchAll();
$stopsStmt = db()->prepare("SELECT aps.stop_order, aps.location_id,
        COALESCE(NULLIF(al.name, ''), aps.destination) AS destination,
        al.address, al.reference, aps.activity, aps.estimated_time
    FROM attendance_program_stops aps
    LEFT JOIN attendance_locations al ON al.id = aps.location_id
    WHERE aps.program_id=:program_id
    ORDER BY aps.stop_order");
$completionsByProgram = [];
$arrivalsByProgram = [];
if ($programs) {
    $programIds = array_values(array_unique(array_map(static fn(array $program): int => (int) $program['id'], $programs)));
    $placeholders = implode(',', array_fill(0, count($programIds), '?'));
    $completionStmt = db()->prepare("SELECT program_id, location_id, activity, observations, completed_at
        FROM attendance_work_completions
        WHERE program_id IN ({$placeholders})
        ORDER BY completed_at DESC, id DESC");
    $completionStmt->execute($programIds);
    foreach ($completionStmt->fetchAll() as $completion) {
        $programId = (int) $completion['program_id'];
        $locationId = (int) $completion['location_id'];
        // Si se finalizó más de una actividad en el mismo lugar, se muestra la última.
        if (isset($completionsByProgram[$programId][$locationId])) continue;
        $completionsByProgram[$programId][$locationId] = [
            'location_id' => $locationId,
            'activity' => (string) $completion['activity'],
            'observations' => (string) ($completion['observations'] ?? ''),
            'completed_at' => (string) $completion['completed_at'],
            'completed_time' => substr((string) $completion['completed_at'], 11, 5),
        ];
    }

    $entryArrivalStmt = db()->prepare("SELECT am.program_id, am.location_id, am.marked_at,
            am.latitude, am.longitude, am.distance_meters,
            l.latitude AS location_latitude, l.longitude AS location_longitude,
            l.radius_meters, l.address
        FROM attendance_marks am
        JOIN attendance_locations l ON l.id=am.location_id
        WHERE am.program_id IN ({$placeholders}) AND am.mark_type='entrada'
        ORDER BY am.marked_at ASC, am.id ASC");
    $entryArrivalStmt->execute($programIds);
    foreach ($entryArrivalStmt->fetchAll() as $arrival) {
        $programId = (int) $arrival['program_id'];
        $locationId = (int) $arrival['location_id'];
        if (isset($arrivalsByProgram[$programId][$locationId])) continue;
        $arrivalsByProgram[$programId][$locationId] = [
            'location_id' => $locationId,
            'arrived_at' => (string) $arrival['marked_at'],
            'arrived_time' => substr((string) $arrival['marked_at'], 11, 5),
            'latitude' => (float) $arrival['latitude'],
            'longitude' => (float) $arrival['longitude'],
            'location_latitude' => (float) $arrival['location_latitude'],
            'location_longitude' => (float) $arrival['location_longitude'],
            'radius_meters' => (float) $arrival['radius_meters'],
            'distance_meters' => (float) $arrival['distance_meters'],
            'address' => (string) ($arrival['address'] ?? ''),
        ];
    }

    $arrivalStmt = db()->prepare("SELECT t.program_id,
            COALESCE(t.last_location_id, t.first_destination_location_id) AS location_id,
            t.ended_at, t.end_latitude AS latitude, t.end_longitude AS longitude,
            l.latitude AS location_latitude, l.longitude AS location_longitude,
            l.radius_meters, l.address
        FROM attendance_trips t
        LEFT JOIN attendance_locations l ON l.id=COALESCE(t.last_location_id, t.first_destination_location_id)
        WHERE t.program_id IN ({$placeholders}) AND t.status='finalizado'
          AND t.ended_at IS NOT NULL
          AND COALESCE(last_location_id, first_destination_location_id) IS NOT NULL
        ORDER BY t.ended_at ASC, t.id ASC");
    $arrivalStmt->execute($programIds);
    foreach ($arrivalStmt->fetchAll() as $arrival) {
        $programId = (int) $arrival['program_id'];
        $locationId = (int) $arrival['location_id'];
        // La primera confirmación representa la llegada real al lugar.
        if (isset($arrivalsByProgram[$programId][$locationId])) continue;
        $arrivalsByProgram[$programId][$locationId] = [
            'location_id' => $locationId,
            'arrived_at' => (string) $arrival['ended_at'],
            'arrived_time' => substr((string) $arrival['ended_at'], 11, 5),
            'latitude' => $arrival['latitude'] !== null ? (float) $arrival['latitude'] : null,
            'longitude' => $arrival['longitude'] !== null ? (float) $arrival['longitude'] : null,
            'location_latitude' => $arrival['location_latitude'] !== null ? (float) $arrival['location_latitude'] : null,
            'location_longitude' => $arrival['location_longitude'] !== null ? (float) $arrival['location_longitude'] : null,
            'radius_meters' => $arrival['radius_meters'] !== null ? (float) $arrival['radius_meters'] : null,
            'address' => (string) ($arrival['address'] ?? ''),
        ];
    }
}
foreach ($programs as &$program) {
    $stopsStmt->execute(['program_id'=>$program['id']]);
    $program['stops'] = $stopsStmt->fetchAll();
    $program['work_completions'] = array_values($completionsByProgram[(int) $program['id']] ?? []);
    $program['route_arrivals'] = array_values($arrivalsByProgram[(int) $program['id']] ?? []);
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
