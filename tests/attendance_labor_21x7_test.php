<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/attendance_labor_cycle.php';
require_once __DIR__ . '/../includes/attendance_report_daily.php';
require_once __DIR__ . '/../includes/attendance_labor_regime_correction.php';
require_once __DIR__ . '/../includes/attendance_report_data.php';

function check21(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$start = new DateTimeImmutable('2026-09-01');
$history = [101 => [['work_date' => '2026-09-01', 'regime' => '21x7']]];
$range = attendance_labor_cycle_range($history, '2026-09-01', '2026-10-29');
for ($day = 0; $day < 59; $day++) {
    $date = $start->modify('+' . $day . ' days')->format('Y-m-d');
    $position = $day % 29;
    $expected = $position < 21 ? 'A' . ($position + 1) : ($position === 21 ? 'M' : 'D' . ($position - 21));
    check21(($range[101][$date]['cycle']['code'] ?? '') === $expected, "Código incorrecto en día {$day}: {$date}");
}
check21($range[101]['2026-09-22']['cycle']['transition'], 'El día 22 debe ser M');
check21($range[101]['2026-09-23']['cycle']['rest'], 'El día 23 debe ser D1');
check21($range[101]['2026-09-29']['cycle']['code'] === 'D7', 'El día 29 debe ser D7');
check21($range[101]['2026-09-30']['cycle']['code'] === 'A1', 'El día 30 debe reiniciar A1');
check21(attendance_labor_cycle_day('2028-02-20', '2028-03-12')['code'] === 'M',
    'El ciclo debe respetar el 29 de febrero de un año bisiesto');
check21(attendance_labor_cycle_day('2028-12-11', '2029-01-01')['code'] === 'M',
    'El ciclo debe continuar correctamente al cambiar de año');

$changed = [101 => [
    ['work_date' => '2026-09-01', 'regime' => '21x7'],
    ['work_date' => '2026-09-10', 'regime' => 'regular'],
    ['work_date' => '2026-09-12', 'regime' => '21x7'],
]];
$edited = attendance_labor_cycle_range($changed, '2026-09-01', '2026-09-14');
check21($edited[101]['2026-09-09']['cycle']['code'] === 'A9', 'La edición regular no debe alterar días anteriores');
check21($edited[101]['2026-09-10']['regime'] === 'regular', 'Regular debe interrumpir el ciclo');
check21(!isset($edited[101]['2026-09-11']), 'El día siguiente a Regular debe permanecer regular');
check21($edited[101]['2026-09-12']['cycle']['code'] === 'A1', '21x7 debe iniciar un ciclo nuevo');

$base = ['worker_id' => 101, 'entry' => '-', 'exit' => '-', 'state_key' => 'rest',
    'journey_key' => null, 'calendar_event_type' => '', 'labor_regime' => '21x7'];
$days = [
    attendance_report_classify_day($base + ['date' => '2026-09-22', 'labor_cycle_code' => 'M'], '2026-10-01'),
    attendance_report_classify_day($base + ['date' => '2026-09-23', 'labor_cycle_code' => 'D1'], '2026-10-01'),
    attendance_report_classify_day(array_merge($base, ['date' => '2026-09-24', 'labor_cycle_code' => 'D2',
        'entry' => '08:00', 'exit' => '17:00', 'state_key' => 'attended', 'journey_key' => 'completed']), '2026-10-01'),
];
$summary = attendance_report_summarize_monthly_days($days)['2026-09'];
check21($summary['rest_regime'] === 2, 'D1 y D2 deben contarse como descanso de régimen');
check21($summary['rest_regime_worked'] === 1 && $summary['worked'] === 1,
    'Una marcación en D2 debe contarse como descanso trabajado y asistencia');
check21($summary['regular'] === 0, 'Una asistencia 21x7 no debe sumarse como regular');

$fullCycleDays = [];
for ($day = 0; $day < 29; $day++) {
    $date = $start->modify('+' . $day . ' days')->format('Y-m-d');
    $code = $range[101][$date]['cycle']['code'];
    $hasMark = str_starts_with($code, 'A') || $code === 'D1';
    $fullCycleDays[] = attendance_report_classify_day(array_merge($base, [
        'date' => $date, 'labor_cycle_code' => $code,
        'entry' => $hasMark ? '08:00' : '-', 'exit' => $hasMark ? '17:00' : '-',
        'state_key' => $hasMark ? 'attended' : 'rest', 'journey_key' => $hasMark ? 'completed' : null,
    ]), '2026-10-01');
}
$fullSummary = attendance_report_summarize_monthly_days($fullCycleDays)['2026-09'];
check21($fullSummary['rest_regime'] === 7, 'El ciclo completo debe contar exactamente siete descansos');
check21($fullSummary['rest_regime_worked'] === 1 && $fullSummary['worked'] === 22,
    'D1 trabajado debe sumar asistencia sin dejar de contar como descanso');
check21($fullSummary['regular'] === 0, 'Los 21 días A no deben mezclarse con el régimen regular');
$markedTransition = attendance_report_classify_day(array_merge($base, [
    'date' => '2026-09-22', 'labor_cycle_code' => 'M', 'entry' => '08:00', 'exit' => '17:00',
    'state_key' => 'attended', 'journey_key' => 'completed',
]), '2026-10-01');
check21($markedTransition['worked'] && !$markedTransition['labor_cycle_rest'],
    'M marcado debe contar asistencia sin convertirse en D');

$pdo = db();
$worker = $pdo->query("SELECT worker_id FROM attendance_assignments
    WHERE status = 1 AND valid_from <= '2026-09-01'
      AND (valid_until IS NULL OR valid_until >= '2026-10-29')
      AND (deactivated_at IS NULL OR DATE(deactivated_at) >= '2026-10-29')
      AND worker_id NOT IN (SELECT worker_id FROM attendance_labor_regimes)
    ORDER BY worker_id LIMIT 1")->fetchColumn();
if ($worker) {
    $worker = (int) $worker;
    $pdo->beginTransaction();
    try {
        attendance_labor_regime_correct($pdo, $worker, '2026-09-01', '21x7', 'Prueba de ciclo completo', null);
        $report = attendance_report_build('2026-09-01', '2026-10-29', $worker);
        $rows = [];
        foreach ($report['rows'] as $row) $rows[$row['date']] = $row;
        foreach (['2026-09-01' => 'A1', '2026-09-21' => 'A21', '2026-09-22' => 'M',
                  '2026-09-23' => 'D1', '2026-09-29' => 'D7', '2026-09-30' => 'A1',
                  '2026-10-21' => 'M', '2026-10-28' => 'D7', '2026-10-29' => 'A1'] as $date => $code) {
            check21(($rows[$date]['labor_cycle_code'] ?? '') === $code, "Reporte no refleja {$code} en {$date}");
        }
        foreach ($rows as $row) {
            if (($row['labor_regime'] ?? '') !== '21x7') continue;
            check21((int) $row['early_overtime_minutes'] === 0 && (int) $row['exit_overtime_minutes'] === 0,
                '21x7 no debe sumar extras sin autorización');
        }
        attendance_labor_regime_correct($pdo, $worker, '2026-09-10', 'regular', 'Prueba de interrupción', null);
        $afterRegular = attendance_report_build('2026-09-09', '2026-09-12', $worker);
        $editedRows = [];
        foreach ($afterRegular['rows'] as $row) $editedRows[$row['date']] = $row;
        check21(($editedRows['2026-09-09']['labor_cycle_code'] ?? '') === 'A9', 'La edición no debe alterar el día anterior');
        check21(($editedRows['2026-09-10']['labor_regime'] ?? '') === 'regular'
            && ($editedRows['2026-09-11']['labor_regime'] ?? '') === 'regular', 'Regular debe detener los cálculos 21x7 del reporte');
        attendance_labor_regime_correct($pdo, $worker, '2026-09-12', '21x7', 'Prueba de reinicio', null);
        $afterRestart = attendance_report_build('2026-09-12', '2026-09-13', $worker);
        $restartRows = [];
        foreach ($afterRestart['rows'] as $row) $restartRows[$row['date']] = $row;
        check21(($restartRows['2026-09-12']['labor_cycle_code'] ?? '') === 'A1'
            && ($restartRows['2026-09-13']['labor_cycle_code'] ?? '') === 'A2',
            'La edición a 21x7 debe reiniciar los cálculos del reporte');
        $audit = $pdo->prepare('SELECT COUNT(*) FROM attendance_labor_regime_audit
            WHERE worker_id = ? AND work_date BETWEEN ? AND ? AND reason LIKE ?');
        $audit->execute([$worker, '2026-09-01', '2026-09-12', 'Prueba de %']);
        check21((int) $audit->fetchColumn() === 3, 'Cada edición del régimen debe quedar auditada');
    } finally {
        $pdo->rollBack();
    }
    echo "Reporte real y edición de celda 21x7 verificados; cambios revertidos.\n";
} else {
    echo "Sin trabajador con asignación continua para la prueba de reporte real.\n";
}

$closed = $pdo->query("SELECT worker_id, report_month FROM attendance_report_month_closures
    WHERE admin_closed_at IS NOT NULL AND staff_closed_at IS NOT NULL
    ORDER BY worker_id, report_month LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($closed) {
    $closedWorker = (int) $closed['worker_id'];
    $closedMonth = (string) $closed['report_month'];
    $closedDate = $closedMonth . '-01';
    $pdo->beginTransaction();
    try {
        attendance_labor_regime_correct($pdo, $closedWorker, $closedDate, '21x7', 'Prueba de celda cerrada', null);
        $status = attendance_report_closure_status($closedWorker, $closedMonth, $pdo);
        check21(!$status['fully_closed'], 'La corrección debe reabrir el reporte firmado');
        $audit = $pdo->prepare('SELECT COUNT(*) FROM attendance_report_revisions
            WHERE worker_id = ? AND report_month = ? AND correction_reason = ?');
        $audit->execute([$closedWorker, $closedMonth, 'Prueba de celda cerrada']);
        check21((int) $audit->fetchColumn() > 0, 'El cierre anterior debe quedar auditado');
        $report = attendance_report_build($closedDate, $closedDate, $closedWorker);
        check21(($report['rows'][0]['labor_regime'] ?? '') === '21x7', 'El reporte cerrado no reflejó la corrección');
    } finally {
        $pdo->rollBack();
    }
    $status = attendance_report_closure_status($closedWorker, $closedMonth, $pdo);
    check21($status['fully_closed'], 'La transacción de prueba debe restaurar el cierre');
    $audit->execute([$closedWorker, $closedMonth, 'Prueba de celda cerrada']);
    check21((int) $audit->fetchColumn() === 0, 'La prueba no debe conservar auditorías');
    echo "Celda de reporte cerrado, auditoría y reversión verificados.\n";
}

$overtimeMark = $pdo->query("SELECT worker_id, mark_date FROM attendance_marks
    WHERE mark_date = '2026-09-01' AND worker_id = 1
    GROUP BY worker_id, mark_date HAVING COUNT(DISTINCT mark_type) = 2 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($overtimeMark) {
    $pdo->beginTransaction();
    try {
        attendance_labor_regime_correct($pdo, 1, '2026-09-01', '21x7', 'Prueba de horas extra', null);
        $pdo->prepare("UPDATE attendance_marks SET mark_time = '23:00:00'
            WHERE worker_id = 1 AND mark_date = '2026-09-01' AND mark_type = 'salida'")->execute();
        foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$entryAllowed, $exitAllowed]) {
            $pdo->prepare('UPDATE attendance_labor_regimes SET allow_entry_overtime = ?, allow_exit_overtime = ?
                WHERE worker_id = 1 AND work_date = ?')->execute([$entryAllowed, $exitAllowed, '2026-09-01']);
            $report = attendance_report_build('2026-09-01', '2026-09-01', 1);
            $row = $report['rows'][0] ?? null;
            check21($row !== null, 'Falta la jornada de la prueba de horas extra');
            check21(($entryAllowed === 1) === ((int) $row['early_overtime_minutes'] > 0), 'Autorización de entrada incorrecta');
            check21(($exitAllowed === 1) === ((int) $row['exit_overtime_minutes'] > 0), 'Autorización de salida incorrecta');
            check21((int) $row['overtime_minutes'] === (int) $row['early_overtime_minutes'] + (int) $row['exit_overtime_minutes'],
                'Total de horas extra incoherente');
        }
    } finally {
        $pdo->rollBack();
    }
    echo "Horas extra bloqueadas y autorizaciones independientes verificadas; cambios revertidos.\n";
}

echo "Ciclo A1-A21, M, D1-D7, repetición, interrupción y descanso trabajado verificados.\n";
