<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_calendar.php';
require_once __DIR__ . '/../../includes/attendance_programming.php';
require_module_access('control_personal.control_asistencia');

$requestedWorkerId = (int) ($_GET['worker_id'] ?? 0);
$requestedProgramId = (int) ($_GET['program_id'] ?? 0);
$workerId = is_personal_role() ? (int) current_user_worker_id() : $requestedWorkerId;
$today = date('Y-m-d');

if ($workerId <= 0) {
    json_response(['ok' => false, 'message' => is_personal_role() ? 'Su usuario no tiene trabajador vinculado.' : 'Seleccione un trabajador.'], 400);
}

$stmt = db()->prepare("SELECT aa.id AS assignment_id, aa.activity, aa.instructions,
        w.id AS worker_id, w.company_id, w.full_name, w.document_number,
        l.id AS location_id, l.name AS location_name, l.latitude, l.longitude, l.address, l.reference, l.radius_meters,
        s.id AS schedule_id, s.name AS schedule_name
    FROM attendance_assignments aa
    JOIN workers w ON w.id = aa.worker_id
    JOIN attendance_locations l ON l.id = aa.location_id
    JOIN attendance_schedules s ON s.id = aa.schedule_id
    WHERE aa.worker_id = :worker_id AND aa.status = 1
      AND aa.valid_from <= :today_from AND (aa.valid_until IS NULL OR aa.valid_until >= :today_until)
    ORDER BY aa.id DESC");
$stmt->execute(['worker_id' => $workerId, 'today_from' => $today, 'today_until' => $today]);
$assignments = $stmt->fetchAll();

if (!$assignments) {
    json_response(['ok' => false, 'message' => 'El trabajador no tiene una asignacion activa.'], 404);
}

$dayOfWeek = (int) date('N');

$selectedAssignment = null;
$selectedScheduleDay = null;
$selectedCalendarEvent = null;
$selectedProgram = null;
$programs = attendance_programs_for_worker_date($workerId, $today);
$priorityCalendarEvent = attendance_calendar_event_for_worker(
    $today,
    $workerId,
    (int) ($assignments[0]['company_id'] ?? 0)
);

if ($programs && !$priorityCalendarEvent) {
    $selectedProgram = attendance_select_program($programs, $requestedProgramId);
    if ($selectedProgram) {
        $selectedAssignment = [
            'assignment_id' => (int) $selectedProgram['assignment_id'],
            'activity' => !empty($selectedProgram['has_route'])
                ? (($selectedProgram['current_assignment_activity'] ?? '') ?: (($selectedProgram['assignment_activity'] ?? '') ?: ($selectedProgram['activity'] ?? '')))
                : (($selectedProgram['activity'] ?? '') ?: ($selectedProgram['assignment_activity'] ?? '')),
            'instructions' => $selectedProgram['notes'] ?: ($selectedProgram['assignment_instructions'] ?? ''),
            'worker_id' => (int) $selectedProgram['worker_id'], 'company_id' => (int) $selectedProgram['company_id'],
            'full_name' => $selectedProgram['full_name'], 'document_number' => $selectedProgram['document_number'],
            'location_id' => (int) $selectedProgram['location_id'], 'location_name' => $selectedProgram['location_name'],
            'latitude' => $selectedProgram['latitude'], 'longitude' => $selectedProgram['longitude'],
            'address' => $selectedProgram['address'], 'reference' => $selectedProgram['reference'] ?? null,
            'radius_meters' => $selectedProgram['radius_meters'],
            'schedule_id' => (int) $selectedProgram['schedule_id'], 'schedule_name' => $selectedProgram['schedule_name'],
        ];
        $selectedScheduleDay = attendance_program_schedule_day($selectedProgram);
    }
}

foreach ($selectedAssignment ? [] : $assignments as $asg) {
    $stmt = db()->prepare('SELECT * FROM attendance_schedule_days
        WHERE schedule_id = :schedule_id AND day_of_week = :day_of_week AND status = 1
        LIMIT 1');
    $stmt->execute([
        'schedule_id' => (int) $asg['schedule_id'],
        'day_of_week' => $dayOfWeek,
    ]);
    $weeklyScheduleDay = $stmt->fetch() ?: null;
    $calendarEvent = attendance_calendar_event_for_worker(
        $today,
        (int) $asg['worker_id'],
        (int) ($asg['company_id'] ?? 0)
    );
    $scheduleDay = attendance_calendar_effective_schedule($weeklyScheduleDay, $calendarEvent);

    if ($scheduleDay) {
        $selectedAssignment = $asg;
        $selectedScheduleDay = $scheduleDay;
        $selectedCalendarEvent = $calendarEvent;
        break;
    }
}

if (!$selectedAssignment) {
    $selectedAssignment = $assignments[0];
    $stmt = db()->prepare('SELECT * FROM attendance_schedule_days
        WHERE schedule_id = :schedule_id AND day_of_week = :day_of_week AND status = 1
        LIMIT 1');
    $stmt->execute([
        'schedule_id' => (int) $selectedAssignment['schedule_id'],
        'day_of_week' => $dayOfWeek,
    ]);
    $weeklyScheduleDay = $stmt->fetch() ?: null;
    $selectedCalendarEvent = attendance_calendar_event_for_worker(
        $today,
        (int) $selectedAssignment['worker_id'],
        (int) ($selectedAssignment['company_id'] ?? 0)
    );
    $selectedScheduleDay = attendance_calendar_effective_schedule($weeklyScheduleDay, $selectedCalendarEvent);
}

$assignment = $selectedAssignment;
$scheduleDay = $selectedScheduleDay;
$calendarEvent = $selectedCalendarEvent;

$overrideStmt = db()->prepare('SELECT activity, instructions FROM attendance_journey_overrides
    WHERE assignment_id=:assignment_id AND journey_date=:journey_date LIMIT 1');
$overrideStmt->execute(['assignment_id' => (int) $assignment['assignment_id'], 'journey_date' => $today]);
$journeyOverride = $overrideStmt->fetch() ?: null;
// Las programaciones especiales almacenan directamente sus propios datos.
// Los recorridos, en cambio, admiten una personalización independiente por fecha.
if ($journeyOverride && (!$selectedProgram || !empty($selectedProgram['has_route']))) {
    $assignment['activity'] = (string) $journeyOverride['activity'];
    $assignment['instructions'] = (string) $journeyOverride['instructions'];
}

$stmt = db()->prepare('SELECT mark_type, mark_time, final_status, photo_path FROM attendance_marks
    WHERE assignment_id = :assignment_id AND mark_date = :mark_date
      AND ((:program_selected > 0 AND program_id = :program_match) OR (:program_none = 0 AND program_id IS NULL))
    ORDER BY marked_at ASC');
$contextProgramId = (int) ($selectedProgram['id'] ?? 0);
$stmt->execute([
    'assignment_id' => (int) $assignment['assignment_id'],
    'mark_date' => $today,
    'program_selected' => $contextProgramId,
    'program_match' => $contextProgramId,
    'program_none' => $contextProgramId,
]);
$marks = $stmt->fetchAll();

$tripStmt = db()->prepare("SELECT id, reason, first_destination, first_destination_location_id, started_at FROM attendance_trips
    WHERE worker_id=:worker_id AND trip_date=:trip_date AND status='en_ruta' ORDER BY id DESC LIMIT 1");
$tripStmt->execute(['worker_id'=>$workerId,'trip_date'=>$today]);
$activeTrip = $tripStmt->fetch() ?: null;
$completionStmt = db()->prepare('SELECT awc.id,awc.location_id,awc.activity,awc.observations,awc.completed_at,l.name AS location_name
    FROM attendance_work_completions awc JOIN attendance_locations l ON l.id=awc.location_id
    WHERE awc.worker_id=:worker_id AND awc.work_date=:trip_date ORDER BY awc.completed_at DESC,awc.id DESC LIMIT 1');
$completionStmt->execute(['worker_id'=>$workerId,'trip_date'=>$today]);
$lastWorkCompletion = $completionStmt->fetch() ?: null;
$lastTripStmt = db()->prepare("SELECT started_at FROM attendance_trips WHERE worker_id=:worker_id AND trip_date=:trip_date AND status='finalizado' ORDER BY ended_at DESC,id DESC LIMIT 1");
$lastTripStmt->execute(['worker_id'=>$workerId,'trip_date'=>$today]);
$lastFinishedTripStartedAt = $lastTripStmt->fetchColumn();
$waitingNextDestination = $lastWorkCompletion
    && (!$lastFinishedTripStartedAt || strtotime((string)$lastWorkCompletion['completed_at']) >= strtotime((string)$lastFinishedTripStartedAt));
$exitLocationStmt = db()->prepare("SELECT l.id,COALESCE(l.name,t.first_destination) AS name,
        COALESCE(l.latitude,t.end_latitude) AS latitude,COALESCE(l.longitude,t.end_longitude) AS longitude,
        l.address,l.reference,l.radius_meters,(t.last_location_id IS NULL) AS is_temporary_location
    FROM attendance_trips t LEFT JOIN attendance_locations l ON l.id=t.last_location_id
    WHERE t.worker_id=:worker_id AND t.trip_date=:trip_date AND t.status='finalizado'
    ORDER BY t.ended_at DESC,t.id DESC LIMIT 1");
$exitLocationStmt->execute(['worker_id'=>$workerId,'trip_date'=>$today]);
$exitLocation = $exitLocationStmt->fetch() ?: null;
$plannedStops = [];
$nextPlannedStop = null;
$finalPlannedStop = null;
if ($selectedProgram) {
    $stopStmt = db()->prepare('SELECT location_id,destination,activity,estimated_time FROM attendance_program_stops WHERE program_id=:program_id ORDER BY stop_order');
    $stopStmt->execute(['program_id'=>$selectedProgram['id']]);
    $plannedStops = $stopStmt->fetchAll();
    if ($plannedStops) $finalPlannedStop = $plannedStops[array_key_last($plannedStops)];
    $visitedStmt = db()->prepare("SELECT location_id FROM (
            SELECT first_destination_location_id AS location_id FROM attendance_trips
            WHERE program_id=:program_first AND status='finalizado' AND first_destination_location_id IS NOT NULL
            UNION ALL
            SELECT ats.location_id FROM attendance_trip_stops ats JOIN attendance_trips atr ON atr.id=ats.trip_id
            WHERE atr.program_id=:program_stops AND atr.status='finalizado' AND ats.location_id IS NOT NULL
        ) visited");
    $visitedStmt->execute(['program_first'=>$selectedProgram['id'],'program_stops'=>$selectedProgram['id']]);
    $visitedLocationIds = array_map('intval', $visitedStmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($plannedStops as $plannedStop) {
        if ((int)($plannedStop['location_id'] ?? 0) > 0 && !in_array((int)$plannedStop['location_id'],$visitedLocationIds,true)) {
            $nextPlannedStop = $plannedStop;
            break;
        }
    }
}

$entryAvailableFrom = $scheduleDay['entry_start'] ?? null;
$entryAvailable = true;
$entrySecondsRemaining = 0;
if ($scheduleDay && $entryAvailableFrom) {
    $entryAvailableAt = strtotime($today . ' ' . $entryAvailableFrom);
    
    // Si el inicio de la ventana es mayor que la hora oficial de entrada (o fin de ventana),
    // significa que la ventana de marcado se abre el día anterior.
    $officialTime = $scheduleDay['entry_time'] ?? $scheduleDay['entry_end'] ?? $entryAvailableFrom;
    if (strtotime($entryAvailableFrom) > strtotime($officialTime)) {
        $entryAvailableAt = strtotime($today . ' ' . $entryAvailableFrom . ' -1 day');
    }
    
    $serverNow = time();
    $entryAvailable = $entryAvailableAt === false || $serverNow >= $entryAvailableAt;
    $entrySecondsRemaining = $entryAvailable ? 0 : max(0, $entryAvailableAt - $serverNow);
}

json_response([
    'ok' => true,
    'today' => $today,
    'work_date' => $selectedProgram['program_date'] ?? $today,
    'work_date_formatted' => date('d/m/Y', strtotime((string) ($selectedProgram['program_date'] ?? $today))),
    'now' => date('H:i:s'),
    'assignment' => $assignment,
    'schedule_day' => $scheduleDay,
    'calendar_event' => $calendarEvent,
    'program' => $selectedProgram,
    'programs' => array_map(static fn(array $program): array => [
        'id'=>(int)$program['id'], 'time'=>substr((string)$program['entry_time'],0,5) . ' - ' . substr((string)$program['exit_time'],0,5),
        'location'=>$program['location_name'], 'schedule'=>$program['schedule_name'],
    ], $programs),
    'marks' => $marks,
    'active_trip' => $activeTrip,
    'last_work_completion' => $lastWorkCompletion,
    'waiting_next_destination' => (bool) $waitingNextDestination,
    'exit_location' => $exitLocation,
    'planned_stops' => $plannedStops,
    'next_planned_stop' => $nextPlannedStop,
    'final_planned_stop' => $finalPlannedStop,
    'entry_availability' => [
        'available' => $entryAvailable,
        'available_from' => $entryAvailableFrom ? substr((string) $entryAvailableFrom, 0, 5) : null,
        'seconds_remaining' => $entrySecondsRemaining,
    ],
    'is_personal' => is_personal_role(),
]);
