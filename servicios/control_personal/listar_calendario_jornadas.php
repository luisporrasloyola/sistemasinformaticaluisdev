<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_calendar.php';
require_module_access('control_personal.programacion');

$scheduleId = (int) ($_GET['schedule_id'] ?? 0);
$workerId = (int) ($_GET['worker_id'] ?? 0);
$start = substr((string) ($_GET['start'] ?? ''), 0, 10);
$endExclusive = substr((string) ($_GET['end'] ?? ''), 0, 10);
$startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
$endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $endExclusive);
if (!$startDate || !$endDate || $endDate <= $startDate) {
    json_response(['ok' => false, 'message' => 'No se pudo determinar el periodo del calendario.'], 422);
}
$lastDate = $endDate->modify('-1 day');
if ($startDate->diff($lastDate)->days > 100) {
    json_response(['ok' => false, 'message' => 'El periodo consultado es demasiado amplio.'], 422);
}

$params = ['range_start' => $start, 'range_end' => $lastDate->format('Y-m-d')];
$scheduleFilter = '';
if ($scheduleId > 0) {
    $scheduleFilter = ' AND aa.schedule_id = :schedule_id';
    $params['schedule_id'] = $scheduleId;
}
$workerFilter = '';
if ($workerId > 0) {
    $workerFilter = ' AND aa.worker_id = :worker_id';
    $params['worker_id'] = $workerId;
}
$stmt = db()->prepare("SELECT aa.id AS assignment_id, aa.worker_id, aa.schedule_id, aa.activity, aa.instructions, aa.valid_from, aa.valid_until,
        w.full_name, w.document_number, w.company_id,
        l.name AS location_name, s.name AS schedule_name
    FROM attendance_assignments aa
    JOIN workers w ON w.id = aa.worker_id
    JOIN attendance_locations l ON l.id = aa.location_id
    JOIN attendance_schedules s ON s.id = aa.schedule_id
    WHERE aa.status = 1
      AND aa.valid_from <= :range_end AND (aa.valid_until IS NULL OR aa.valid_until >= :range_start)
      {$scheduleFilter} {$workerFilter}
    ORDER BY w.full_name, aa.id");
$stmt->execute($params);
$assignments = $stmt->fetchAll();

$overrideStmt = db()->prepare("SELECT assignment_id, journey_date, activity, instructions
    FROM attendance_journey_overrides WHERE journey_date BETWEEN :range_start AND :range_end");
$overrideStmt->execute(['range_start' => $start, 'range_end' => $lastDate->format('Y-m-d')]);
$overrides = [];
foreach ($overrideStmt->fetchAll() as $override) {
    $overrides[(int) $override['assignment_id']][(string) $override['journey_date']] = $override;
}

$daysScheduleFilter = $scheduleId > 0 ? ' AND schedule_id = :schedule_id' : '';
$daysStmt = db()->prepare("SELECT schedule_id, day_of_week,
        COALESCE(entry_time, entry_start) AS entry_time,
        COALESCE(exit_time, exit_start) AS exit_time
    FROM attendance_schedule_days
    WHERE status = 1 {$daysScheduleFilter}");
$daysStmt->execute($scheduleId > 0 ? ['schedule_id' => $scheduleId] : []);
$scheduleDays = [];
foreach ($daysStmt->fetchAll() as $day) $scheduleDays[(int) $day['schedule_id']][(int) $day['day_of_week']] = $day;

$programFilters = [];
$programParams = ['program_start' => $start, 'program_end' => $lastDate->format('Y-m-d')];
if ($workerId > 0) {
    $programFilters[] = 'ap.worker_id = :program_worker_id';
    $programParams['program_worker_id'] = $workerId;
}
if ($scheduleId > 0) {
    $programFilters[] = 'COALESCE(ap.schedule_id, aa.schedule_id) = :program_schedule_id';
    $programParams['program_schedule_id'] = $scheduleId;
}
$programWhere = $programFilters ? ' AND ' . implode(' AND ', $programFilters) : '';
$programStmt = db()->prepare("SELECT ap.id, ap.assignment_id, ap.worker_id, ap.program_date, ap.entry_time, ap.exit_time,
        ap.activity, COALESCE(NULLIF(ap.notes, ''),
            (SELECT GROUP_CONCAT(aps.destination ORDER BY aps.stop_order SEPARATOR '\\n')
             FROM attendance_program_stops aps WHERE aps.program_id=ap.id)) AS notes,
        (SELECT COUNT(*) FROM attendance_program_stops aps WHERE aps.program_id=ap.id) AS route_stop_count,
        l.name AS location_name, w.full_name, w.company_id,
        s.name AS schedule_name, aa.activity AS assignment_activity, aa.instructions AS assignment_instructions,
        (SELECT aa2.activity FROM attendance_assignments aa2
            WHERE aa2.worker_id=ap.worker_id AND aa2.status=1
              AND aa2.location_id=COALESCE(ap.location_id, aa.location_id)
              AND aa2.valid_from<=ap.program_date AND (aa2.valid_until IS NULL OR aa2.valid_until>=ap.program_date)
            ORDER BY aa2.id DESC LIMIT 1) AS current_assignment_activity
    FROM attendance_programs ap
    JOIN attendance_assignments aa ON aa.id = ap.assignment_id
    JOIN attendance_locations l ON l.id = COALESCE(ap.location_id, aa.location_id)
    JOIN attendance_schedules s ON s.id = COALESCE(ap.schedule_id, aa.schedule_id)
    JOIN workers w ON w.id = ap.worker_id
    WHERE ap.status = 'programada'
      AND ap.program_date BETWEEN :program_start AND :program_end {$programWhere}
    ORDER BY ap.program_date, ap.entry_time, ap.id");
$programStmt->execute($programParams);
$programRows = $programStmt->fetchAll();
$programStopsById = [];
if ($programRows) {
    $programIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['id'], $programRows)));
    $placeholders = implode(',', array_fill(0, count($programIds), '?'));
    $stopsStmt = db()->prepare("SELECT aps.program_id, aps.stop_order,
            COALESCE(NULLIF(al.name,''), aps.destination) AS destination,
            aps.activity, aps.estimated_time
        FROM attendance_program_stops aps
        LEFT JOIN attendance_locations al ON al.id=aps.location_id
        WHERE aps.program_id IN ({$placeholders}) ORDER BY aps.program_id, aps.stop_order");
    $stopsStmt->execute($programIds);
    foreach ($stopsStmt->fetchAll() as $stop) {
        $programStopsById[(int) $stop['program_id']][] = [
            'order' => (int) $stop['stop_order'],
            'destination' => (string) $stop['destination'],
            'activity' => (string) ($stop['activity'] ?? ''),
            'estimatedTime' => $stop['estimated_time'] ? substr((string) $stop['estimated_time'], 0, 5) : '',
        ];
    }
}
$programsByDate = [];
$programmedWorkerDates = [];
foreach ($programRows as $program) {
    $programsByDate[(string) $program['program_date']][] = $program;
    $programmedWorkerDates[(int) $program['worker_id']][(string) $program['program_date']] = true;
}

$eventsByDate = attendance_calendar_events_between($start, $lastDate->format('Y-m-d'));
$selectedWorkerCompanyId = 0;
if ($workerId > 0) {
    $companyStmt = db()->prepare('SELECT company_id FROM workers WHERE id = :id LIMIT 1');
    $companyStmt->execute(['id' => $workerId]);
    $selectedWorkerCompanyId = (int) $companyStmt->fetchColumn();
}
$specialColors = [
    'vacation' => ['#2563eb', '#1d4ed8'], 'permission' => ['#7c3aed', '#6d28d9'],
    'rest' => ['#334155', '#1e293b'], 'holiday' => ['#0891b2', '#0e7490'],
    'non_working' => ['#78716c', '#57534e'],
];
$events = [];
for ($cursor = $startDate; $cursor <= $lastDate; $cursor = $cursor->modify('+1 day')) {
    $date = $cursor->format('Y-m-d');
    $dayOfWeek = (int) $cursor->format('N');

    // El Calendario laboral es una fuente independiente de las asignaciones.
    // Debe visualizarse incluso cuando todavía no existe una jornada habitual
    // activa para esa fecha (por ejemplo, un feriado anterior a la vigencia).
    foreach ($eventsByDate[$date] ?? [] as $special) {
        $scope = (string) ($special['scope_type'] ?? 'all');
        $visibleForWorker = $workerId <= 0
            || $scope === 'all'
            || ($scope === 'company' && (int) $special['company_id'] === $selectedWorkerCompanyId)
            || ($scope === 'worker' && (int) $special['worker_id'] === $workerId);
        if (!$visibleForWorker) continue;

        $colors = $specialColors[(string) $special['event_type']] ?? ['#64748b', '#475569'];
        $scopeLabel = match ($scope) {
            'worker' => (string) ($special['worker_name'] ?: 'Trabajador'),
            'company' => (string) ($special['company_name'] ?: 'Empresa'),
            default => 'Todo el personal',
        };
        $events[] = [
            'id' => 'special-' . (int) $special['id'] . '-' . $date,
            'title' => attendance_calendar_event_abbreviation((string) $special['event_type']) . ' · ' . $special['name'] . ' · ' . $scopeLabel,
            'start' => $date, 'allDay' => true, 'backgroundColor' => $colors[0], 'borderColor' => $colors[1], 'textColor' => '#fff',
            'extendedProps' => [
                'kind' => 'special', 'calendarId' => (int) $special['id'], 'worker' => $scopeLabel,
                'workerId' => $scope === 'worker' ? (int) $special['worker_id'] : 0,
                'eventType' => $special['event_type'], 'name' => $special['name'], 'scope' => $scope,
                'canRestore' => $scope === 'worker'
                    && (string) $special['name'] === 'Jornada excluida' && $date >= date('Y-m-d'),
            ],
        ];
    }

    foreach ($programsByDate[$date] ?? [] as $program) {
        $programSpecial = attendance_calendar_resolve_event(
            $eventsByDate,
            $date,
            (int) $program['worker_id'],
            (int) $program['company_id']
        );
        if ($programSpecial) continue;
        $routeStopCount = (int) ($program['route_stop_count'] ?? 0);
        $isRoute = $routeStopCount > 0;
        $routePlaceCount = $routeStopCount + 1;
        $events[] = [
            'id' => 'program-' . (int) $program['id'],
            'title' => substr((string) $program['entry_time'], 0, 5) . ' - ' . substr((string) $program['exit_time'], 0, 5) . ' · ' . $program['full_name'],
            'start' => $date, 'allDay' => true,
            'backgroundColor' => $isRoute ? '#0f766e' : '#f97316',
            'borderColor' => $isRoute ? '#115e59' : '#c2410c', 'textColor' => '#fff',
            'extendedProps' => [
                'kind' => $isRoute ? 'route' : 'program', 'assignmentId' => (int) $program['assignment_id'], 'programId' => (int) $program['id'], 'date' => $date,
                'worker' => $program['full_name'], 'workerId' => (int) $program['worker_id'],
                'location' => $program['location_name'], 'schedule' => $program['schedule_name'],
                'entry' => substr((string) $program['entry_time'], 0, 5), 'exit' => substr((string) $program['exit_time'], 0, 5),
                'activity' => $isRoute
                    ? (isset($overrides[(int) $program['assignment_id']][$date])
                        ? (string) $overrides[(int) $program['assignment_id']][$date]['activity']
                        : ($program['current_assignment_activity'] ?: ($program['assignment_activity'] ?: ($program['activity'] ?: ''))))
                    : ($program['activity'] ?: ($program['assignment_activity'] ?: '')),
                'instructions' => $isRoute
                    ? (isset($overrides[(int) $program['assignment_id']][$date])
                        ? (string) $overrides[(int) $program['assignment_id']][$date]['instructions']
                        : ($program['notes'] ?: ($program['assignment_instructions'] ?: '')))
                    : ($program['notes'] ?: ($program['assignment_instructions'] ?: '')),
                'customized' => $isRoute && isset($overrides[(int) $program['assignment_id']][$date]),
                'routePlaceCount' => $routePlaceCount,
                'stops' => $programStopsById[(int) $program['id']] ?? [],
            ],
        ];
    }
    foreach ($assignments as $assignment) {
        if ($date < (string) $assignment['valid_from']
            || (!empty($assignment['valid_until']) && $date > (string) $assignment['valid_until'])) continue;
        $assignmentId = (int) $assignment['assignment_id'];
        $worker = (int) $assignment['worker_id'];
        // Las programaciones especiales ya se agregaron directamente por trabajador.
        $programs = [];
        if ($programs) {
            foreach ($programs as $program) {
                $events[] = [
                    'id' => 'program-' . (int) $program['id'],
                    'title' => substr((string) $program['entry_time'], 0, 5) . ' - ' . substr((string) $program['exit_time'], 0, 5) . ' · ' . $assignment['full_name'],
                    'start' => $date, 'allDay' => true, 'backgroundColor' => '#f97316', 'borderColor' => '#c2410c', 'textColor' => '#fff',
                    'extendedProps' => [
                        'kind' => 'program', 'worker' => $assignment['full_name'], 'workerId' => $worker,
                        'location' => $program['location_name'], 'schedule' => $assignment['schedule_name'],
                        'entry' => substr((string) $program['entry_time'], 0, 5), 'exit' => substr((string) $program['exit_time'], 0, 5),
                        'activity' => $program['activity'] ?: ($assignment['activity'] ?: '-'), 'notes' => $program['notes'] ?: '-',
                    ],
                ];
            }
            continue;
        }
        // Una programación especial reemplaza el horario habitual del trabajador
        // durante esa fecha, incluso cuando posee más de una asignación activa.
        $special = attendance_calendar_resolve_event($eventsByDate, $date, $worker, (int) $assignment['company_id']);
        if ($special) {
            continue;
        }

        if (!empty($programmedWorkerDates[$worker][$date])) continue;

        $assignmentScheduleId = (int) $assignment['schedule_id'];
        if (!isset($scheduleDays[$assignmentScheduleId][$dayOfWeek])) continue;
        $day = $scheduleDays[$assignmentScheduleId][$dayOfWeek];
        $override = $overrides[$assignmentId][$date] ?? null;
        $events[] = [
            'id' => 'regular-' . $assignmentId . '-' . $date,
            'title' => substr((string) $day['entry_time'], 0, 5) . ' - ' . substr((string) $day['exit_time'], 0, 5) . ' · ' . $assignment['full_name'],
            'start' => $date, 'allDay' => true, 'backgroundColor' => '#16a34a', 'borderColor' => '#15803d', 'textColor' => '#fff',
            'extendedProps' => [
                'kind' => 'regular', 'assignmentId' => $assignmentId, 'worker' => $assignment['full_name'], 'workerId' => $worker,
                'location' => $assignment['location_name'], 'schedule' => $assignment['schedule_name'],
                'entry' => substr((string) $day['entry_time'], 0, 5), 'exit' => substr((string) $day['exit_time'], 0, 5),
                'activity' => $override['activity'] ?? ($assignment['activity'] ?: ''),
                'instructions' => $override['instructions'] ?? ($assignment['instructions'] ?: ''),
                'customized' => $override !== null, 'date' => $date, 'canExclude' => $date >= date('Y-m-d'),
            ],
        ];
    }
}

json_response(['ok' => true, 'events' => $events]);
