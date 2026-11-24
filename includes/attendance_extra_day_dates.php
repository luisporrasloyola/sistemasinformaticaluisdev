<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_calendar.php';

/** Fechas extra y numeración acumulada por trabajador, incluidas las asignaciones por vigencia. */
function attendance_extra_day_dates(PDO $pdo, array $workerIds, string $from, string $through): array
{
    $workerIds = array_values(array_unique(array_filter(array_map('intval', $workerIds))));
    $result = [];
    if (!$workerIds || $through < $from) return ['dates' => $result, 'available' => true];
    $yesterday = (new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
    $end = min($through, $yesterday);
    if ($end < $from) return ['dates' => $result, 'available' => true];
    $scanFrom = '1900-01-01';
    $available = true;

    foreach (array_chunk($workerIds, 300) as $ids) {
        $places = implode(',', array_fill(0, count($ids), '?'));
        $byWorker = [];
        $activeByWorker = [];
        $historicalByWorker = [];
        try {
            $assignments = $pdo->prepare("SELECT worker_id, status, day_type, valid_from,
                    CASE WHEN deactivated_at IS NOT NULL
                        THEN LEAST(COALESCE(valid_until, DATE(deactivated_at)), DATE(deactivated_at))
                        ELSE valid_until END AS valid_until
                FROM attendance_assignments
                WHERE worker_id IN ($places) AND valid_from <= ?
                  AND (valid_until IS NULL OR valid_until >= ?)
                  AND (status = 1 OR deactivated_at IS NOT NULL)
                ORDER BY id");
            $assignments->execute([...$ids, $end, $scanFrom]);
            foreach ($assignments->fetchAll() as $assignment) {
                $start = max($scanFrom, (string) $assignment['valid_from']);
                $finish = min($end, (string) ($assignment['valid_until'] ?: $end));
                if ($finish < $start) continue;
                $cursor = new DateTimeImmutable($start);
                $last = new DateTimeImmutable($finish);
                while ($cursor <= $last) {
                    $id = (int) $assignment['worker_id'];
                    $date = $cursor->format('Y-m-d');
                    if ((int) $assignment['status'] === 1) {
                        $activeByWorker[$id][$date] = ($activeByWorker[$id][$date] ?? false)
                            || $assignment['day_type'] === 'extra';
                    } else {
                        $historicalByWorker[$id][$date] = $assignment['day_type'] === 'extra';
                    }
                    $cursor = $cursor->modify('+1 day');
                }
            }
            $byWorker = $historicalByWorker;
            foreach ($activeByWorker as $id => $dates) {
                foreach ($dates as $date => $isExtra) $byWorker[$id][$date] = $isExtra;
            }
        } catch (PDOException $error) {
            if (!in_array($error->getCode(), ['42S22', '42S02'], true)) throw $error;
            $available = false;
        }

        try {
            $overrides = $pdo->prepare("SELECT worker_id, work_date, day_type FROM attendance_extra_days
                WHERE worker_id IN ($places) AND work_date BETWEEN ? AND ?");
            $overrides->execute([...$ids, $scanFrom, $end]);
            $rows = $overrides->fetchAll();
        } catch (PDOException $error) {
            if ($error->getCode() === '42S22') {
                $overrides = $pdo->prepare("SELECT worker_id, work_date, 'extra' AS day_type FROM attendance_extra_days
                    WHERE worker_id IN ($places) AND work_date BETWEEN ? AND ?");
                $overrides->execute([...$ids, $scanFrom, $end]);
                $rows = $overrides->fetchAll();
            } elseif ($error->getCode() === '42S02') {
                $available = false;
                $rows = [];
            } else {
                throw $error;
            }
        }
        foreach ($rows as $row) {
            $id = (int) $row['worker_id'];
            $date = (string) $row['work_date'];
            if ($row['day_type'] === 'regular') unset($byWorker[$id][$date]);
            else $byWorker[$id][$date] = true;
        }
        $firstExtraDate = null;
        foreach ($byWorker as $dates) {
            foreach ($dates as $date => $isExtra) {
                if ($isExtra && ($firstExtraDate === null || $date < $firstExtraDate)) {
                    $firstExtraDate = $date;
                }
            }
        }
        if ($firstExtraDate === null) continue;

        $companies = [];
        $workers = $pdo->prepare("SELECT id, company_id FROM workers WHERE id IN ($places)");
        $workers->execute($ids);
        foreach ($workers->fetchAll() as $worker) {
            $companies[(int) $worker['id']] = (int) $worker['company_id'];
        }
        $calendarEvents = attendance_calendar_events_between($firstExtraDate, $end);
        $manualAbsences = [];
        $absences = $pdo->prepare("SELECT worker_id, mark_date FROM attendance_manual_day_overrides
            WHERE worker_id IN ($places) AND mark_date BETWEEN ? AND ? AND attendance_status = 'falta'");
        $absences->execute([...$ids, $firstExtraDate, $end]);
        foreach ($absences->fetchAll() as $absence) {
            $manualAbsences[(int) $absence['worker_id']][(string) $absence['mark_date']] = true;
        }
        foreach ($byWorker as $id => $dates) {
            ksort($dates);
            $number = 0;
            foreach ($dates as $date => $isExtra) {
                if (!$isExtra) continue;
                if (isset($manualAbsences[$id][$date])
                    || attendance_calendar_resolve_event($calendarEvents, $date, (int) $id, $companies[(int) $id] ?? 0)) {
                    continue;
                }
                ++$number;
                if ($date >= $from) $result[$id][$date] = $number;
            }
        }
    }
    return ['dates' => $result, 'available' => $available];
}
