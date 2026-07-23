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
    if ($workers) {
        $ids = array_map(static fn(array $worker): int => (int) $worker['id'], $workers);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT aa.*, DATE(aa.created_at) AS assignment_start_date,
                s.name AS schedule_name, l.name AS location_name, l.address AS location_address
            FROM attendance_assignments aa
            JOIN attendance_schedules s ON s.id = aa.schedule_id
            JOIN attendance_locations l ON l.id = aa.location_id
            WHERE aa.status = 1 AND aa.worker_id IN ({$placeholders})
            ORDER BY aa.id DESC");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $assignment) {
            $id = (int) $assignment['worker_id'];
            $assignmentByWorker[$id] ??= $assignment;
        }
    }

    $scheduleDaysBySchedule = [];
    foreach (db()->query("SELECT schedule_id, day_of_week, entry_time, entry_start, entry_end,
            break_start, break_end, exit_time, exit_start, exit_end, tolerance_minutes
        FROM attendance_schedule_days WHERE status = 1")->fetchAll() as $scheduleDay) {
        $scheduleDaysBySchedule[(int) $scheduleDay['schedule_id']][(int) $scheduleDay['day_of_week']] = $scheduleDay;
    }

    $marksByWorkerAndDate = [];
    $markParams = ['date_from' => $dateFrom, 'date_to' => $dateTo];
    $markSql = 'SELECT worker_id, mark_date, mark_type, mark_time, schedule_status, final_status, observations
        FROM attendance_marks WHERE mark_date BETWEEN :date_from AND :date_to';
    if ($workerId > 0) {
        $markSql .= ' AND worker_id = :worker_id';
        $markParams['worker_id'] = $workerId;
    }
    $markSql .= ' ORDER BY mark_date, mark_time';
    $stmt = db()->prepare($markSql);
    $stmt->execute($markParams);
    foreach ($stmt->fetchAll() as $mark) {
        $marksByWorkerAndDate[(int) $mark['worker_id']][(string) $mark['mark_date']][(string) $mark['mark_type']] = $mark;
    }

    $calendarEvents = attendance_calendar_events_between($dateFrom, $dateTo);
    $rows = [];
    $periodStart = new DateTimeImmutable($dateFrom);
    $periodEnd = new DateTimeImmutable($dateTo);
    $weekdayLabels = [1 => 'Lun.', 2 => 'Mar.', 3 => 'Mié.', 4 => 'Jue.', 5 => 'Vie.', 6 => 'Sáb.', 7 => 'Dom.'];

    foreach ($workers as $worker) {
        $id = (int) $worker['id'];
        $assignment = $assignmentByWorker[$id] ?? null;
        if (!$assignment) continue;
        $assignmentStart = new DateTimeImmutable((string) $assignment['assignment_start_date']);
        $cursor = $assignmentStart > $periodStart ? $assignmentStart : $periodStart;

        while ($cursor <= $periodEnd) {
            $date = $cursor->format('Y-m-d');
            $weekday = (int) $cursor->format('N');
            $scheduleDay = $scheduleDaysBySchedule[(int) $assignment['schedule_id']][$weekday] ?? null;
            $marks = $marksByWorkerAndDate[$id][$date] ?? [];
            $entry = $marks['entrada'] ?? null;
            $exit = $marks['salida'] ?? null;
            $calendarEvent = attendance_calendar_resolve_event($calendarEvents, $date, $id, (int) $worker['company_id']);
            $eventType = (string) ($calendarEvent['event_type'] ?? '');
            $isNonWorking = attendance_calendar_is_non_working_event($eventType);
            $hasSchedule = $scheduleDay !== null;
            $isLate = $entry && (($entry['schedule_status'] ?? '') === 'tardanza' || ($entry['final_status'] ?? '') === 'tardanza');
            $isEarlyExit = $exit && (($exit['schedule_status'] ?? '') === 'salida_anticipada' || ($exit['final_status'] ?? '') === 'salida_anticipada');

            if ($entry || $exit) {
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
                    $lateMinutes = max(0, attendance_report_signed_minutes($officialEntry, (string) $entry['mark_time']));
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

            $observations = array_values(array_filter([
                trim((string) ($entry['observations'] ?? '')),
                trim((string) ($exit['observations'] ?? '')),
                $lateMinutes > 0 ? $lateMinutes . ' min de tardanza' : '',
                $journeyKey === 'exit_incomplete' ? 'Salida no registrada' : '',
            ]));
            $state = attendance_report_state($stateKey);
            $journey = attendance_report_journey_state($journeyKey);
            $rows[] = [
                'worker_id' => $id, 'date' => $date, 'weekday' => $weekdayLabels[$weekday],
                'worker' => (string) $worker['full_name'], 'document' => (string) $worker['document_number'],
                'company' => (string) ($worker['company'] ?? ''),
                'entry' => attendance_report_time($entry['mark_time'] ?? null),
                'exit' => attendance_report_time($exit['mark_time'] ?? null),
                'schedule' => $scheduleLabel,
                'location' => (string) ($assignment['location_name'] ?? '-'),
                'state_key' => $stateKey, 'state_code' => $state['code'], 'state_label' => $state['label'], 'state_class' => $state['class'],
                'journey_key' => $journeyKey, 'journey_label' => $journey['label'], 'journey_class' => $journey['class'],
                'worked_minutes' => $workedMinutes, 'scheduled_minutes' => $scheduledMinutes,
                'late_minutes' => $lateMinutes, 'overtime_minutes' => $overtimeMinutes,
                'observation' => $observations ? implode(' · ', array_unique($observations)) : '-',
                'is_workday' => $hasSchedule && !$isNonWorking,
            ];
            $cursor = $cursor->modify('+1 day');
        }
    }

    usort($rows, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']) ?: strcasecmp($a['worker'], $b['worker']));
    $selectedWorker = $workerId > 0 ? ($workers[0] ?? null) : null;
    $selectedAssignment = $workerId > 0 ? ($assignmentByWorker[$workerId] ?? null) : null;
    $individualRows = $workerId > 0 ? array_values(array_filter($rows, static fn(array $row): bool => $row['worker_id'] === $workerId)) : [];
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

    return [
        'workers' => $workers, 'rows' => $rows, 'individual_rows' => $individualRows,
        'worker' => $selectedWorker, 'assignment' => $selectedAssignment, 'summary' => $summary,
        'note' => $workerId > 0 ? attendance_report_note($workerId, $dateFrom, $dateTo) : null,
    ];
}
