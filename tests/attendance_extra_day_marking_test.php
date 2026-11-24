<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/attendance_extra_day_dates.php';
require_once __DIR__ . '/../includes/attendance_report_data.php';

$pdo = db();
$today = date('Y-m-d');
$tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
$candidates = $pdo->prepare("SELECT DISTINCT a.id AS assignment_id, a.worker_id, a.valid_from, m.mark_date
    FROM attendance_assignments a
    JOIN attendance_marks m ON m.worker_id = a.worker_id
        AND m.mark_date BETWEEN a.valid_from AND COALESCE(a.valid_until, '9999-12-31')
        AND m.mark_type IN ('entrada', 'salida')
    LEFT JOIN attendance_extra_days e ON e.worker_id = m.worker_id AND e.work_date = m.mark_date
    WHERE a.status = 1 AND a.deactivated_at IS NULL
      AND (a.valid_until IS NULL OR a.valid_until >= ?)
      AND m.mark_date BETWEEN DATE_SUB(?, INTERVAL 30 DAY) AND DATE_SUB(?, INTERVAL 1 DAY)
      AND (e.day_type IS NULL OR e.day_type = 'extra')
    ORDER BY m.mark_date DESC LIMIT 30");
$candidates->execute([$tomorrow, $today, $today]);

$verified = false;
foreach ($candidates->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
    $workerId = (int) $candidate['worker_id'];
    $markedDate = (string) $candidate['mark_date'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE attendance_assignments SET day_type = 'extra' WHERE id = ?")
            ->execute([(int) $candidate['assignment_id']]);
        $scanFrom = max((string) $candidate['valid_from'],
            (new DateTimeImmutable($markedDate))->modify('-7 days')->format('Y-m-d'));
        $days = attendance_extra_day_dates($pdo, [$workerId], $scanFrom, $tomorrow, true);
        if (!isset($days['dates'][$workerId][$markedDate])) continue;
        if (!isset($days['programmed'][$workerId][$tomorrow])) continue;
        if (isset($days['dates'][$workerId][$tomorrow])) {
            throw new RuntimeException('Una fecha futura sin marcación aparece como DE.');
        }
        $numbers = array_values($days['dates'][$workerId]);
        for ($index = 1; $index < count($numbers); $index++) {
            if ($numbers[$index] !== $numbers[$index - 1] + 1) {
                throw new RuntimeException('Las fechas sin marcación dejaron huecos en la numeración DE.');
            }
        }

        $unmarkedDate = null;
        for ($cursor = new DateTimeImmutable($scanFrom); $cursor < new DateTimeImmutable($today);
             $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');
            if (isset($days['programmed'][$workerId][$date])
                && !isset($days['dates'][$workerId][$date])) {
                $unmarkedDate = $date;
                break;
            }
        }
        if ($unmarkedDate === null) continue;

        $report = attendance_report_build($markedDate, $markedDate, $workerId);
        $expectedMinutes = (int) (new DateTimeImmutable($markedDate))->format('N') === 6 ? 360 : 510;
        $credited = array_sum(array_map(
            static fn(array $row): int => (int) ($row['extra_day_minutes'] ?? 0),
            array_filter($report['rows'], static fn(array $row): bool => $row['date'] === $markedDate)
        ));
        if ($credited !== $expectedMinutes) {
            throw new RuntimeException('El reporte no acreditó exactamente el DE con marcación.');
        }
        $unmarkedReport = attendance_report_build($unmarkedDate, $unmarkedDate, $workerId);
        foreach ($unmarkedReport['rows'] as $row) {
            if ($row['date'] === $unmarkedDate && (int) ($row['extra_day_minutes'] ?? 0) !== 0) {
                throw new RuntimeException('El reporte acreditó un DE pasado sin marcación.');
            }
        }
        $verified = true;
        echo "Asignación extra: fecha marcada acreditada; fechas sin marcación pasada y futura sin DE.\n";
        break;
    } finally {
        $pdo->rollBack();
    }
}
if (!$verified) {
    throw new RuntimeException('No se encontró una asignación y marcación aptas para esta prueba.');
}
