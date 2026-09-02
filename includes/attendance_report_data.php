<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/attendance_calendar.php';

function attendance_report_time(?string $time): string
{
    return $time ? substr($time, 0, 5) : '-';
}

function attendance_report_minutes_label(int $minutes): string
{
    $minutes = max(0, $minutes);
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;
    return $hours > 0 ? $hours . ' h ' . str_pad((string) $remaining, 2, '0', STR_PAD_LEFT) . ' m' : $remaining . ' min';
}

function attendance_report_state(string $key): array
{
    return match ($key) {
        'attended' => ['code' => 'A', 'label' => 'Asistió', 'class' => 'legend-attended'],
        'late' => ['code' => 'T', 'label' => 'Tarde', 'class' => 'legend-attendance-warning'],
        'early_exit' => ['code' => 'ASA', 'label' => 'Asistió con salida anticipada', 'class' => 'legend-early-exit'],
        'late_early_exit' => ['code' => 'ATSA', 'label' => 'Asistió con tardanza y salida anticipada', 'class' => 'legend-attendance-critical'],
        'absent' => ['code' => 'F', 'label' => 'Falta', 'class' => 'legend-absent'],
        'vacation' => ['code' => 'VAC', 'label' => 'Vacaciones', 'class' => 'legend-vacation'],
        'permission' => ['code' => 'PER', 'label' => 'Permiso', 'class' => 'legend-permission'],
        'rest' => ['code' => 'D', 'label' => 'Descanso', 'class' => 'legend-rest'],
        'unscheduled' => ['code' => 'SHC', 'label' => 'Sin horario configurado', 'class' => 'report-state-unscheduled'],
        'holiday' => ['code' => 'FER', 'label' => 'Feriado', 'class' => 'legend-holiday'],
        'non_working' => ['code' => 'NL', 'label' => 'No laborable', 'class' => 'legend-non-working'],
        'incomplete' => ['code' => 'MI', 'label' => 'Marcación incompleta', 'class' => 'report-state-incomplete'],
        default => ['code' => 'PEN', 'label' => 'Pendiente', 'class' => 'report-state-pending'],
    };
}

function attendance_report_journey_state(?string $key): array
{
    return match ($key) {
        'pending' => ['label' => 'Pendiente', 'class' => 'journey-state-pending'],
        'active' => ['label' => 'En jornada', 'class' => 'journey-state-active'],
        'completed' => ['label' => 'Finalizada', 'class' => 'journey-state-completed'],
        'exit_pending' => ['label' => 'Salida pendiente', 'class' => 'journey-state-exit-pending'],
        'exit_incomplete' => ['label' => 'Salida incompleta', 'class' => 'journey-state-exit-incomplete'],
        default => ['label' => '—', 'class' => 'journey-state-na'],
    };
}

function attendance_report_diff_minutes(string $start, string $end): int
{
    $startTimestamp = strtotime('2000-01-01 ' . $start);
    $endTimestamp = strtotime('2000-01-01 ' . $end);
    if ($startTimestamp === false || $endTimestamp === false) return 0;
    if ($endTimestamp < $startTimestamp) $endTimestamp += 86400;
    return max(0, (int) floor(($endTimestamp - $startTimestamp) / 60));
}

function attendance_report_signed_minutes(string $start, string $end): int
{
    $startTimestamp = strtotime('2000-01-01 ' . $start);
    $endTimestamp = strtotime('2000-01-01 ' . $end);
    if ($startTimestamp === false || $endTimestamp === false) return 0;
    return (int) floor(($endTimestamp - $startTimestamp) / 60);
}

function attendance_report_note(int $workerId, string $dateFrom, string $dateTo): ?array
{
    try {
        $stmt = db()->prepare('SELECT observation, responsible_name, updated_at
            FROM attendance_report_notes
            WHERE worker_id = :worker_id AND date_from = :date_from AND date_to = :date_to
            LIMIT 1');
        $stmt->execute(['worker_id' => $workerId, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function attendance_report_build(string $dateFrom, string $dateTo, int $workerId = 0): array
{
    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    $workersSql = "SELECT w.id, w.full_name, w.document_type, w.document_number, w.company_id,
            w.signature_path, c.name AS company,
            GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS positions
        FROM workers w
        LEFT JOIN companies c ON c.id = w.company_id
        LEFT JOIN worker_positions wp ON wp.worker_id = w.id
        LEFT JOIN positions p ON p.id = wp.position_id
        WHERE 1 = 1" . ($workerId > 0 ? ' AND w.id = :worker_id' : '') . "
        GROUP BY w.id
        ORDER BY w.full_name";
    $stmt = db()->prepare($workersSql);
    $stmt->execute($workerId > 0 ? ['worker_id' => $workerId] : []);
    $workers = $stmt->fetchAll();

    $assignmentByWorker = [];
    $assignmentsByWorker = [];
    $assignmentsById = [];
    if ($workers) {
        $ids = array_map(static fn(array $worker): int => (int) $worker['id'], $workers);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT aa.*, aa.valid_from AS assignment_start_date,
                COALESCE(aa.valid_until, DATE(aa.deactivated_at)) AS assignment_end_date,
                s.name AS schedule_name, l.name AS location_name, l.address AS location_address
            FROM attendance_assignments aa
            JOIN attendance_schedules s ON s.id = aa.schedule_id
            JOIN attendance_locations l ON l.id = aa.location_id
            WHERE aa.worker_id IN ({$placeholders})
            ORDER BY aa.worker_id, aa.created_at, aa.id");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $assignment) {
            $assignmentId = (int) $assignment['id'];
            $assignmentWorkerId = (int) $assignment['worker_id'];
            $assignmentsById[$assignmentId] = $assignment;
            $assignmentsByWorker[$assignmentWorkerId][] = $assignment;
            if ((int) $assignment['status'] === 1) {
                $assignmentByWorker[$assignmentWorkerId] = $assignment;
            }
        }
    }

    $scheduleDaysBySchedule = [];
    foreach (db()->query("SELECT schedule_id, day_of_week, entry_time, entry_start, entry_end,
            break_start, break_end, exit_time, exit_start, exit_end, tolerance_minutes
        FROM attendance_schedule_days WHERE status = 1")->fetchAll() as $scheduleDay) {
        $scheduleDaysBySchedule[(int) $scheduleDay['schedule_id']][(int) $scheduleDay['day_of_week']] = $scheduleDay;
    }

    $programsByWorkerDateAssignment = [];
    try {
        $programParams = ['date_from'=>$dateFrom,'date_to'=>$dateTo];
        $programSql = "SELECT * FROM attendance_programs WHERE status='programada' AND program_date BETWEEN :date_from AND :date_to";
        if ($workerId > 0) { $programSql .= ' AND worker_id=:worker_id'; $programParams['worker_id']=$workerId; }
        $stmt = db()->prepare($programSql); $stmt->execute($programParams);
        foreach ($stmt->fetchAll() as $program) {
            $programsByWorkerDateAssignment[(int)$program['worker_id']][(string)$program['program_date']][(int)$program['assignment_id']] = $program;
        }
    } catch (Throwable $error) {
        $programsByWorkerDateAssignment = [];
    }

    $marksByWorkerAndDateAndAssignment = [];
    $markParams = ['date_from' => $dateFrom, 'date_to' => $dateTo];
    $markSql = 'SELECT am.assignment_id, am.worker_id, am.mark_date, am.mark_type, am.mark_time,
        am.schedule_status, am.final_status, am.observations, l.name AS mark_location
        FROM attendance_marks am JOIN attendance_locations l ON l.id=am.location_id
        WHERE am.mark_date BETWEEN :date_from AND :date_to';
    if ($workerId > 0) {
        $markSql .= ' AND am.worker_id = :worker_id';
        $markParams['worker_id'] = $workerId;
    }
    $markSql .= ' ORDER BY am.mark_date, am.mark_time';
    $stmt = db()->prepare($markSql);
    $stmt->execute($markParams);
    foreach ($stmt->fetchAll() as $mark) {
        $reportWorkerId = (int) $mark['worker_id'];
        $reportMarkDate = (string) $mark['mark_date'];
        $reportAssignmentId = (int) $mark['assignment_id'];
        $reportMarkType = (string) $mark['mark_type'];
        $hasExtremeMark = isset($marksByWorkerAndDateAndAssignment[$reportWorkerId][$reportMarkDate][$reportAssignmentId][$reportMarkType]);
        if ($reportMarkType !== 'entrada' || !$hasExtremeMark) {
            $marksByWorkerAndDateAndAssignment[$reportWorkerId][$reportMarkDate][$reportAssignmentId][$reportMarkType] = $mark;
        }
    }

    $latestManualComments = [];
    try {
        $manualParams = ['date_from' => $dateFrom, 'date_to' => $dateTo];
        $manualSql = 'SELECT ama.worker_id, ama.mark_date, am.assignment_id,
                ama.reason, ama.created_at, u.name AS administrator
            FROM attendance_manual_adjustments ama
            JOIN attendance_marks am ON am.id = ama.attendance_mark_id
            LEFT JOIN users u ON u.id = ama.adjusted_by_user_id
            WHERE ama.mark_date BETWEEN :date_from AND :date_to
              AND ama.id = (
                  SELECT MAX(last_ama.id)
                  FROM attendance_manual_adjustments last_ama
                  JOIN attendance_marks last_am ON last_am.id = last_ama.attendance_mark_id
                  WHERE last_ama.worker_id = ama.worker_id
                    AND last_ama.mark_date = ama.mark_date
                    AND last_am.assignment_id = am.assignment_id
              )';
        if ($workerId > 0) {
            $manualSql .= ' AND ama.worker_id = :worker_id';
            $manualParams['worker_id'] = $workerId;
        }
        $manualStmt = db()->prepare($manualSql);
        $manualStmt->execute($manualParams);
        foreach ($manualStmt->fetchAll() as $manualComment) {
            $latestManualComments[(int) $manualComment['worker_id']][(string) $manualComment['mark_date']][(int) $manualComment['assignment_id']] = $manualComment;
        }
    } catch (Throwable $error) {
        // Mantiene compatible el reporte mientras la tabla de auditoría aún no haya sido migrada.
        $latestManualComments = [];
    }

    $manualDayOverrides = [];
    try {
        $overrideParams = ['date_from' => $dateFrom, 'date_to' => $dateTo];
        $overrideSql = 'SELECT worker_id, mark_date, reason, adjusted_by_user_id, updated_at
            FROM attendance_manual_day_overrides
            WHERE mark_date BETWEEN :date_from AND :date_to';
        if ($workerId > 0) {
            $overrideSql .= ' AND worker_id = :worker_id';
            $overrideParams['worker_id'] = $workerId;
        }
        $overrideStmt = db()->prepare($overrideSql);
        $overrideStmt->execute($overrideParams);
        foreach ($overrideStmt->fetchAll() as $overrideRow) {
            $manualDayOverrides[(int) $overrideRow['worker_id']][(string) $overrideRow['mark_date']] = $overrideRow;
        }
    } catch (Throwable $error) {
        $manualDayOverrides = [];
    }
    $calendarEvents = attendance_calendar_events_between($dateFrom, $dateTo);
    $rows = [];
    $periodStart = new DateTimeImmutable($dateFrom);
    $periodEnd = new DateTimeImmutable($dateTo);
    $weekdayLabels = [1 => 'Lun.', 2 => 'Mar.', 3 => 'Mié.', 4 => 'Jue.', 5 => 'Vie.', 6 => 'Sáb.', 7 => 'Dom.'];

    foreach ($workers as $worker) {
        $id = (int) $worker['id'];
        $workerAssignments = $assignmentsByWorker[$id] ?? [];
        if (!$workerAssignments) continue;
        $assignmentStart = new DateTimeImmutable((string) $workerAssignments[0]['assignment_start_date']);
        $cursor = $assignmentStart > $periodStart ? $assignmentStart : $periodStart;

        while ($cursor <= $periodEnd) {
            $date = $cursor->format('Y-m-d');
            $weekday = (int) $cursor->format('N');

            $dateAssignments = [];
            $markedAssignmentsMap = $marksByWorkerAndDateAndAssignment[$id][$date] ?? [];
            $dateProgramsMap = $programsByWorkerDateAssignment[$id][$date] ?? [];
            if ($dateProgramsMap) {
                foreach (array_keys($dateProgramsMap) as $aid) if (isset($assignmentsById[$aid])) $dateAssignments[] = $assignmentsById[$aid];
            }
            if ($markedAssignmentsMap) {
                foreach (array_keys($markedAssignmentsMap) as $aid) {
                    if (isset($assignmentsById[$aid]) && !in_array($assignmentsById[$aid], $dateAssignments, true)) {
                        $dateAssignments[] = $assignmentsById[$aid];
                    }
                }
            }

            if (empty($dateAssignments)) {
                foreach ($workerAssignments as $candidate) {
                    if ((string) $candidate['assignment_start_date'] > $date) break;
                    $candidateEnd = (string) ($candidate['assignment_end_date'] ?? '');
                    if ($candidateEnd === '' || $date <= $candidateEnd) {
                        $dateAssignments[] = $candidate;
                    }
                }
            }

            if (empty($dateAssignments)) {
                $cursor = $cursor->modify('+1 day');
                continue;
            }

            foreach ($dateAssignments as $assignment) {
                $aid = (int) $assignment['id'];
                $marks = $markedAssignmentsMap[$aid] ?? [];
                $entry = $marks['entrada'] ?? null;
                $exit = $marks['salida'] ?? null;
                $manualOverride = $manualDayOverrides[$id][$date] ?? null;
                if ($manualOverride) { $entry = null; $exit = null; }
                $scheduleDay = $scheduleDaysBySchedule[(int) $assignment['schedule_id']][$weekday] ?? null;
                $program = $dateProgramsMap[$aid] ?? null;
                if ($program) {
                    $scheduleDay = [
                        'entry_time'=>$program['entry_time'], 'entry_start'=>$program['entry_start'], 'entry_end'=>$program['entry_end'],
                        'exit_time'=>$program['exit_time'], 'exit_start'=>$program['exit_time'], 'exit_end'=>$program['exit_time'],
                        'tolerance_minutes'=>$program['tolerance_minutes'], 'break_start'=>null, 'break_end'=>null,
                    ];
                }
                $calendarEvent = attendance_calendar_resolve_event($calendarEvents, $date, $id, (int) $worker['company_id']);
                $eventType = (string) ($calendarEvent['event_type'] ?? '');
                $isNonWorking = attendance_calendar_is_non_working_event($eventType);
                $hasSchedule = $scheduleDay !== null;
                $isLate = $entry && (($entry['schedule_status'] ?? '') === 'tardanza' || ($entry['final_status'] ?? '') === 'tardanza');
                $isEarlyExit = $exit && (($exit['schedule_status'] ?? '') === 'salida_anticipada' || ($exit['final_status'] ?? '') === 'salida_anticipada');

            if ($manualOverride) {
                $stateKey = 'absent';
            } elseif ($entry || $exit) {
                $stateKey = !$entry ? 'incomplete'
                    : ($isLate && $isEarlyExit ? 'late_early_exit'
                    : ($isLate ? 'late' : ($isEarlyExit ? 'early_exit' : 'attended')));
            } elseif ($isNonWorking) {
                $stateKey = match ($eventType) {
                    'holiday' => 'holiday', 'vacation' => 'vacation', 'permission' => 'permission',
                    'rest' => 'rest', default => 'non_working',
                };
            } elseif (!$hasSchedule) {
                $stateKey = 'unscheduled';
            } elseif ($date < $today) {
                $stateKey = 'absent';
            } else {
                $stateKey = 'pending';
            }

            $journeyKey = null;
            if ($exit) {
                $journeyKey = 'completed';
            } elseif ($entry && $date < $today) {
                $journeyKey = 'exit_incomplete';
            } elseif ($entry && $date === $today) {
                $scheduledExit = (string) ($scheduleDay['exit_time'] ?? $scheduleDay['exit_start'] ?? $scheduleDay['exit_end'] ?? '');
                $journeyKey = $scheduledExit !== '' && $nowTime > $scheduledExit ? 'exit_pending' : 'active';
            } elseif ($hasSchedule && !$isNonWorking && $date >= $today) {
                $journeyKey = 'pending';
            }

            $workedMinutes = 0;
            $scheduledMinutes = 0;
            $lateMinutes = 0;
            $entryDelayMinutes = 0;
            $entryToleranceMinutes = 0;
            $toleranceObservation = '';
            $overtimeMinutes = 0;
            $scheduleLabel = '-';
            if ($scheduleDay) {
                $officialEntry = (string) ($scheduleDay['entry_time'] ?? $scheduleDay['entry_start'] ?? '');
                $officialExit = (string) ($scheduleDay['exit_time'] ?? $scheduleDay['exit_start'] ?? '');
                if ($officialEntry !== '' && $officialExit !== '') {
                    $scheduleLabel = attendance_report_time($officialEntry) . ' - ' . attendance_report_time($officialExit);
                }
                if ($officialEntry !== '' && $officialExit !== '') {
                    $scheduledMinutes = attendance_report_diff_minutes($officialEntry, $officialExit);
                    if (!empty($scheduleDay['break_start']) && !empty($scheduleDay['break_end'])) {
                        $scheduledMinutes -= attendance_report_diff_minutes((string) $scheduleDay['break_start'], (string) $scheduleDay['break_end']);
                    }
                }
                if ($entry && $officialEntry !== '') {
                    $entryDelayMinutes = max(0, attendance_report_signed_minutes($officialEntry, (string) $entry['mark_time']));
                    $entryToleranceMinutes = max(0,(int)($scheduleDay['tolerance_minutes'] ?? 0));
                    $lateMinutes = $entryDelayMinutes > $entryToleranceMinutes ? $entryDelayMinutes : 0;
                    if ($entryDelayMinutes > 0 && $lateMinutes === 0) {
                        $toleranceObservation = 'Puntual: llegó dentro de los '.$entryToleranceMinutes.' min de tolerancia (utilizó '.$entryDelayMinutes.' min)';
                    }
                }
            }
            if ($entry && $exit) {
                $workedMinutes = attendance_report_diff_minutes((string) $entry['mark_time'], (string) $exit['mark_time']);
                if ($scheduleDay && !empty($scheduleDay['break_start']) && !empty($scheduleDay['break_end'])) {
                    $workedMinutes = max(0, $workedMinutes - attendance_report_diff_minutes((string) $scheduleDay['break_start'], (string) $scheduleDay['break_end']));
                }
                if ($scheduleDay) {
                    $officialEntry = (string) ($scheduleDay['entry_time'] ?? $scheduleDay['entry_start'] ?? '');
                    $officialExit = (string) ($scheduleDay['exit_time'] ?? $scheduleDay['exit_start'] ?? '');
                    $actualEntry = (string) $entry['mark_time'];
                    $actualExit = (string) $exit['mark_time'];
                    $officialEntryTs = strtotime('2000-01-01 ' . $officialEntry);
                    $officialExitTs = strtotime('2000-01-01 ' . $officialExit);
                    $actualEntryTs = strtotime('2000-01-01 ' . $actualEntry);
                    $actualExitTs = strtotime('2000-01-01 ' . $actualExit);
                    if ($officialEntryTs !== false && $officialExitTs !== false && $actualEntryTs !== false && $actualExitTs !== false) {
                        if ($officialExitTs < $officialEntryTs) $officialExitTs += 86400;
                        if ($actualExitTs < $actualEntryTs) $actualExitTs += 86400;
                        $overtimeMinutes = max(0, (int) floor(($actualExitTs - $officialExitTs) / 60));
                    }
                }
            }

            $latestManualComment = $latestManualComments[$id][$date][$aid] ?? null;
            if ($manualOverride) {
                $reason = trim((string) ($manualOverride['reason'] ?? ''));
                $observation = $reason !== '' ? $reason : '-';
            } elseif ($latestManualComment) {
                $reason = trim((string) ($latestManualComment['reason'] ?? ''));
                $observation = $reason !== '' ? $reason : '-';
            } else {
                $observations = array_values(array_filter([
                    trim((string) ($entry['observations'] ?? '')),
                    trim((string) ($exit['observations'] ?? '')),
                    $toleranceObservation,
                    $lateMinutes > 0 ? $lateMinutes . ' min de tardanza' : '',
                    $journeyKey === 'exit_incomplete' ? 'Salida no registrada' : '',
                ]));
                $observation = $observations ? implode(' · ', array_unique($observations)) : '-';
            }
            $state = attendance_report_state($stateKey);
            $journey = attendance_report_journey_state($journeyKey);
            $entryLocation = $manualOverride ? '-' : (string) ($entry['mark_location'] ?? $assignment['location_name'] ?? '-');
            $exitLocation = $manualOverride ? '-' : (string) ($exit['mark_location'] ?? $entryLocation);
            $journeyLocations = $entryLocation === $exitLocation ? $entryLocation : $entryLocation . ' → ' . $exitLocation;
            $rows[] = [
                'worker_id' => $id, 'date' => $date, 'weekday' => $weekdayLabels[$weekday],
                'assignment_id' => (int) $assignment['id'],
                'worker' => (string) $worker['full_name'], 'document' => (string) $worker['document_number'],
                'company' => (string) ($worker['company'] ?? ''),
                'entry' => attendance_report_time($entry['mark_time'] ?? null),
                'exit' => attendance_report_time($exit['mark_time'] ?? null),
                'schedule' => $scheduleLabel,
                'tolerance_minutes' => $hasSchedule ? max(0,(int)($scheduleDay['tolerance_minutes'] ?? 0)) : null,
                'location' => $journeyLocations,
                'entry_location' => $entryLocation, 'exit_location' => $exitLocation,
                'state_key' => $stateKey, 'state_code' => $state['code'], 'state_label' => $state['label'], 'state_class' => $state['class'],
                'journey_key' => $journeyKey, 'journey_label' => $journey['label'], 'journey_class' => $journey['class'],
                'worked_minutes' => $workedMinutes, 'scheduled_minutes' => $scheduledMinutes,
                'late_minutes' => $lateMinutes, 'overtime_minutes' => $overtimeMinutes,
                'observation' => $observation,
                'is_workday' => $hasSchedule && !$isNonWorking,
            ];
            }
            $cursor = $cursor->modify('+1 day');
        }
    }

    usort($rows, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']) ?: strcasecmp($a['worker'], $b['worker']));
    $selectedWorker = $workerId > 0 ? ($workers[0] ?? null) : null;
    $individualRows = $workerId > 0 ? array_values(array_filter($rows, static fn(array $row): bool => $row['worker_id'] === $workerId)) : [];
    $selectedAssignment = $workerId > 0 ? ($assignmentByWorker[$workerId] ?? null) : null;
    if ($workerId > 0 && $individualRows) {
        $periodAssignmentIds = array_values(array_unique(array_column($individualRows, 'assignment_id')));
        if (count($periodAssignmentIds) === 1) {
            $selectedAssignment = $assignmentsById[(int) $periodAssignmentIds[0]] ?? $selectedAssignment;
        } elseif (count($periodAssignmentIds) > 1) {
            $selectedAssignment = [
                'schedule_name' => 'Varios horarios (ver detalle)',
                'location_name' => 'Varios lugares (ver detalle)',
            ];
        }
    }
    $summary = ['workdays' => 0, 'attendances' => 0, 'late' => 0, 'absent' => 0, 'leaves' => 0, 'vacations' => 0, 'worked_minutes' => 0, 'late_minutes' => 0, 'overtime_minutes' => 0, 'completed' => 0];
    foreach ($individualRows as $row) {
        if ($row['is_workday']) $summary['workdays']++;
        if (in_array($row['state_key'], ['attended', 'late', 'early_exit', 'late_early_exit', 'incomplete'], true)) $summary['attendances']++;
        if (in_array($row['state_key'], ['late', 'late_early_exit'], true)) $summary['late']++;
        if ($row['state_key'] === 'absent') $summary['absent']++;
        if (in_array($row['state_key'], ['vacation', 'permission'], true)) $summary['leaves']++;
        if ($row['state_key'] === 'vacation') $summary['vacations']++;
        if ($row['journey_key'] === 'completed') $summary['completed']++;
        $summary['worked_minutes'] += $row['worked_minutes'];
        $summary['late_minutes'] += $row['late_minutes'];
        $summary['overtime_minutes'] += $row['overtime_minutes'];
    }
    $summary['punctuality'] = $summary['attendances'] > 0 ? round((($summary['attendances'] - $summary['late']) / $summary['attendances']) * 100, 1) : 0;
    $summary['compliance'] = $summary['attendances'] > 0 ? round(($summary['completed'] / $summary['attendances']) * 100, 1) : 0;

    $trips = [];
    if ($workerId > 0) {
        try {
            $stmt = db()->prepare("SELECT at.*, COALESCE(
                (SELECT origin_completion_location.name
                 FROM attendance_work_completions origin_completion
                 JOIN attendance_locations origin_completion_location ON origin_completion_location.id=origin_completion.location_id
                 WHERE origin_completion.worker_id=at.worker_id
                   AND origin_completion.work_date=at.trip_date
                   AND origin_completion.completed_at<=at.started_at
                   AND origin_completion.completed_at>=COALESCE(
                        (SELECT MAX(previous_trip.ended_at)
                         FROM attendance_trips previous_trip
                         WHERE previous_trip.worker_id=at.worker_id
                           AND previous_trip.trip_date=at.trip_date
                           AND previous_trip.status='finalizado'
                           AND previous_trip.ended_at<=at.started_at),
                        '1000-01-01 00:00:00')
                 ORDER BY origin_completion.completed_at DESC,origin_completion.id DESC LIMIT 1),
                (SELECT origin_trip_location.name
                 FROM attendance_trips origin_trip
                 JOIN attendance_locations origin_trip_location ON origin_trip_location.id=origin_trip.last_location_id
                 WHERE origin_trip.worker_id=at.worker_id
                   AND origin_trip.trip_date=at.trip_date
                   AND origin_trip.status='finalizado'
                   AND origin_trip.ended_at<=at.started_at
                 ORDER BY origin_trip.ended_at DESC,origin_trip.id DESC LIMIT 1),
                l.name
            ) AS location_name,
                COALESCE(ap.entry_time,sd.entry_time,sd.entry_start) AS schedule_entry_time,
                COALESCE(ap.exit_time,sd.exit_time,sd.exit_start) AS schedule_exit_time
                FROM attendance_trips at
                JOIN attendance_assignments aa ON aa.id=at.assignment_id
                JOIN attendance_locations l ON l.id=aa.location_id
                LEFT JOIN attendance_programs ap ON ap.id=at.program_id
                LEFT JOIN attendance_schedule_days sd ON sd.schedule_id=COALESCE(ap.schedule_id,aa.schedule_id)
                    AND sd.day_of_week=WEEKDAY(at.trip_date)+1 AND sd.status=1
                WHERE at.worker_id=:worker_id AND at.trip_date BETWEEN :date_from AND :date_to ORDER BY at.started_at");
            $stmt->execute(['worker_id'=>$workerId,'date_from'=>$dateFrom,'date_to'=>$dateTo]);
            $trips = $stmt->fetchAll();
            foreach ($trips as &$trip) {
                $trip['schedule_label'] = $trip['schedule_entry_time'] && $trip['schedule_exit_time']
                    ? substr((string)$trip['schedule_entry_time'],0,5).' - '.substr((string)$trip['schedule_exit_time'],0,5)
                    : '-';
                $tripEnd = $trip['ended_at'] ? strtotime((string)$trip['ended_at']) : time();
                $tripStart = strtotime((string)$trip['started_at']);
                $trip['duration_label'] = attendance_report_minutes_label(max(0,(int)floor(($tripEnd-$tripStart)/60)));
            }
            unset($trip);
        } catch (Throwable $error) { $trips=[]; }
    }

    return [
        'workers' => $workers, 'rows' => $rows, 'individual_rows' => $individualRows,
        'worker' => $selectedWorker, 'assignment' => $selectedAssignment, 'summary' => $summary,
        'note' => $workerId > 0 ? attendance_report_note($workerId, $dateFrom, $dateTo) : null,
        'trips' => $trips,
    ];
}
