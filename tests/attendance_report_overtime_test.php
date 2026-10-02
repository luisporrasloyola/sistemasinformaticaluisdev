<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/attendance_report_data.php';

$cases = [
    ['08:00', '17:00', '06:00', '17:00', true, 120, 0, true],
    ['08:00', '17:00', '06:00', '17:00', false, 0, 0, true],
    ['08:00', '17:00', '07:30', '17:00', false, 0, 0, true],
    ['08:00', '17:00', '08:00', '17:15', false, 0, 0, false],
    ['08:00', '17:00', '08:00', '17:14', false, 0, 0, false],
    ['08:00', '17:00', '08:00', '17:16', false, 0, 16, false],
    ['08:00', '17:00', '08:00', '17:30', false, 0, 30, false],
    ['08:00', '17:00', '08:00', '18:00', false, 0, 60, false],
    ['08:00', '17:00', '06:00', '18:00', true, 120, 60, true],
    ['08:00', '17:00', '06:00', null, true, 120, 0, true],
    ['00:00', '07:00', '02:12', null, true, 0, 0, false],
    ['08:00', '17:00', null, '17:16', false, 0, 16, false],
    ['22:00', '06:00', '21:00', '06:16', true, 60, 16, true],
    ['22:00', '06:00', null, '06:16', false, 0, 16, false],
    ['22:00', '06:00', '21:00', '06:15', true, 60, 0, true],
    ['22:00', '06:00', '00:30', '06:16', true, 0, 16, false],
    ['02:30', '10:00', '02:30', '11:00', false, 0, 60, false],
];

foreach ($cases as $index => [$scheduledEntry, $scheduledExit, $entry, $exit, $authorized, $expectedEarly, $expectedExit, $expectedEligible]) {
    $actual = attendance_report_overtime_components($scheduledEntry, $scheduledExit, $entry, $exit, $authorized);
    if ($actual !== ['early_eligible' => $expectedEligible, 'early_minutes' => $expectedEarly, 'exit_minutes' => $expectedExit]) {
        fwrite(STDERR, 'Caso ' . ($index + 1) . ': ' . json_encode($actual) . PHP_EOL);
        exit(1);
    }
    if ($actual['early_minutes'] + $actual['exit_minutes'] !== $expectedEarly + $expectedExit) {
        fwrite(STDERR, 'Total incoherente en caso ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
}

echo count($cases) . " casos de horas extra correctos.\n";

$scheduleDays = [
    1 => [4 => ['entry_time' => '08:00:00', 'exit_time' => '17:00:00', 'tolerance_minutes' => 5]],
    2 => [4 => ['entry_time' => '02:30:00', 'exit_time' => '10:00:00', 'tolerance_minutes' => 0]],
];
$assignment = ['schedule_id' => 1];
$program = ['schedule_id' => 1, 'entry_time' => '07:30:00', 'entry_start' => '07:30:00', 'entry_end' => '08:00:00', 'exit_time' => '17:30:00', 'tolerance_minutes' => 5];
$markedDay = attendance_report_effective_schedule_day($assignment, $program, ['schedule_id' => 2], null, $scheduleDays, 4);
if ($markedDay['entry_time'] !== '02:30:00' || $markedDay['exit_time'] !== '10:00:00') {
    fwrite(STDERR, "El horario corregido no prevalece sobre la programación.\n");
    exit(1);
}
$recalculated = attendance_report_overtime_components($markedDay['entry_time'], $markedDay['exit_time'], '02:12:00', '10:16:00', true);
if ($recalculated['early_minutes'] !== 18 || $recalculated['exit_minutes'] !== 16) {
    fwrite(STDERR, "Las horas extras no siguen el horario corregido.\n");
    exit(1);
}
$programDay = attendance_report_effective_schedule_day($assignment, $program, ['schedule_id' => 1], null, $scheduleDays, 4);
if ($programDay['entry_time'] !== '07:30:00' || $programDay['exit_time'] !== '17:30:00') {
    fwrite(STDERR, "La programación vigente no se conserva.\n");
    exit(1);
}
$unmarkedDay = attendance_report_effective_schedule_day($assignment, null, null, null, $scheduleDays, 4);
if ($unmarkedDay['entry_time'] !== '08:00:00') {
    fwrite(STDERR, "El horario de días sin marcación no se conserva.\n");
    exit(1);
}
echo "Prioridad de horarios correcta.\n";
