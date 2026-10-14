<?php
declare(strict_types=1);

/** Jornada nocturna iniciada ayer que sigue abierta. */
function attendance_overnight_context(PDO $pdo, int $workerId, DateTimeImmutable $now): ?array
{
    $workDate = $now->modify('-1 day')->format('Y-m-d');
    $stmt = $pdo->prepare("SELECT entry.mark_date, entry.schedule_id, day.exit_time, day.exit_start,
            (SELECT MAX(exit_mark.marked_at) FROM attendance_marks exit_mark
             WHERE exit_mark.worker_id = entry.worker_id AND exit_mark.mark_date = entry.mark_date
               AND exit_mark.mark_type = 'salida') AS exit_marked_at
        FROM attendance_marks entry
        JOIN attendance_schedule_days day ON day.schedule_id = entry.schedule_id
          AND day.day_of_week = WEEKDAY(entry.mark_date) + 1 AND day.status = 1
        WHERE entry.worker_id = :worker_id AND entry.mark_date = :work_date
          AND entry.mark_type = 'entrada'
          AND COALESCE(day.exit_time, day.exit_start) <= COALESCE(day.entry_time, day.entry_start)
        ORDER BY entry.marked_at DESC, entry.id DESC LIMIT 1");
    $stmt->execute(['worker_id' => $workerId, 'work_date' => $workDate]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $exitTime = (string) ($row['exit_time'] ?: $row['exit_start']);
    $scheduledExit = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $exitTime, $now->getTimezone());
    if ($now > $scheduledExit->modify('+12 hours')) return null;

    if (!empty($row['exit_marked_at'])) return null;
    return ['work_date' => $workDate, 'schedule_id' => (int) $row['schedule_id']];
}
