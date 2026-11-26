<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/attendance_report_data.php';

function verify_overtime_doubling(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = db();
$candidates = $pdo->query("SELECT worker_id, mark_date
    FROM attendance_marks
    WHERE mark_type IN ('entrada', 'salida') AND mark_date < CURDATE()
    GROUP BY worker_id, mark_date
    HAVING COUNT(DISTINCT mark_type) = 2
    ORDER BY mark_date DESC LIMIT 100")->fetchAll();
$verified = 0;
foreach ($candidates as $candidate) {
    $workerId = (int) $candidate['worker_id'];
    $date = (string) $candidate['mark_date'];
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM attendance_worked_hour_doublings WHERE worker_id = ? AND work_date = ?');
        $delete->execute([$workerId, $date]);
        $original = attendance_report_build($date, $date, $workerId);
        $eligible = array_filter($original['individual_rows'], static fn(array $row): bool =>
            $row['entry'] !== '-' && $row['exit'] !== '-' && ($row['base_overtime_minutes'] ?? 0) > 0);
        if (!$eligible) continue;

        $pdo->prepare('INSERT INTO attendance_worked_hour_doublings (worker_id, work_date) VALUES (?, ?)')
            ->execute([$workerId, $date]);
        $doubled = attendance_report_build($date, $date, $workerId);
        verify_overtime_doubling(count($original['individual_rows']) === count($doubled['individual_rows']), 'Row count changed.');
        $extraIncrease = 0;
        foreach ($original['individual_rows'] as $index => $before) {
            $after = $doubled['individual_rows'][$index];
            $applies = $before['entry'] !== '-' && $before['exit'] !== '-' && ($before['base_overtime_minutes'] ?? 0) > 0;
            verify_overtime_doubling($before['worked_minutes'] === $after['worked_minutes'], 'Worked hours changed.');
            verify_overtime_doubling($before['lunch_deduction_minutes'] === $after['lunch_deduction_minutes'], 'Lunch changed.');
            verify_overtime_doubling($before['late_minutes'] === $after['late_minutes'], 'Lateness changed.');
            verify_overtime_doubling((bool) ($after['overtime_double_applied'] ?? false) === $applies, 'Invalid application.');
            verify_overtime_doubling($after['overtime_minutes'] === $before['overtime_minutes'] * ($applies ? 2 : 1), 'Invalid overtime total.');
            foreach (['holiday_overtime_minutes', 'early_overtime_minutes', 'exit_overtime_minutes', 'extra_day_minutes'] as $component) {
                verify_overtime_doubling($after[$component] === $before[$component] * ($applies ? 2 : 1), 'Invalid component: ' . $component);
            }
            verify_overtime_doubling($after['overtime_minutes'] === $after['holiday_overtime_minutes'] + $after['early_overtime_minutes'] + $after['exit_overtime_minutes'] + $after['extra_day_minutes'], 'Breakdown does not match total.');
            $extraIncrease += $after['overtime_minutes'] - $before['overtime_minutes'];
        }
        verify_overtime_doubling($original['summary']['worked_minutes'] === $doubled['summary']['worked_minutes'], 'Worked summary changed.');
        verify_overtime_doubling($doubled['summary']['overtime_minutes'] - $original['summary']['overtime_minutes'] === $extraIncrease, 'Overtime summary mismatch.');
        $month = substr($date, 0, 7);
        $monthlyBefore = attendance_report_build($month . '-01', (new DateTimeImmutable($date))->format('Y-m-t'), $workerId);
        $delete->execute([$workerId, $date]);
        $monthlyAfter = attendance_report_build($month . '-01', (new DateTimeImmutable($date))->format('Y-m-t'), $workerId);
        verify_overtime_doubling($monthlyBefore['summary']['overtime_minutes'] - $monthlyAfter['summary']['overtime_minutes'] === $extraIncrease, 'Monthly overtime mismatch.');
        verify_overtime_doubling($monthlyBefore['summary']['worked_minutes'] === $monthlyAfter['summary']['worked_minutes'], 'Monthly worked hours changed.');
        $ledgerBefore = attendance_report_hours_ledger($monthlyBefore['daily_classification'], $month, $month)[$month];
        $ledgerAfter = attendance_report_hours_ledger($monthlyAfter['daily_classification'], $month, $month)[$month];
        verify_overtime_doubling($ledgerBefore['closing_minutes'] - $ledgerAfter['closing_minutes'] === $extraIncrease, 'Monthly balance mismatch.');
        verify_overtime_doubling($ledgerBefore['worked_minutes'] === $ledgerAfter['worked_minutes'], 'Ledger worked hours changed.');
        $restored = attendance_report_build($date, $date, $workerId);
        verify_overtime_doubling($restored['summary'] === $original['summary'], 'Disabling did not restore totals.');
        $verified++;
        if ($verified >= 3) break;
    } finally {
        $pdo->rollBack();
    }
}
verify_overtime_doubling($verified > 0, 'No eligible marked day found to verify.');
echo "OK: {$verified} days; overtime, breakdown, monthly totals, worked hours and reversal verified. All database changes rolled back.\n";
