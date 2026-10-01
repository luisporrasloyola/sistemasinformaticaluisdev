<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/attendance_report_data.php';

$cases = [
    ['08:00', '17:00', '06:00', '17:00', true, 120, 0, true],
    ['08:00', '17:00', '06:00', '17:00', false, 0, 0, true],
    ['08:00', '17:00', '07:30', '17:00', false, 0, 0, true],
    ['08:00', '17:00', '08:00', '17:15', false, 0, 0, false],
    ['08:00', '17:00', '08:00', '17:14', false, 0, 0, false],
    ['08:00', '17:00', '08:00', '17:16', false, 0, 1, false],
    ['08:00', '17:00', '08:00', '17:30', false, 0, 15, false],
    ['08:00', '17:00', '08:00', '18:00', false, 0, 45, false],
    ['08:00', '17:00', '06:00', '18:00', true, 120, 45, true],
    ['08:00', '17:00', '06:00', null, true, 120, 0, true],
    ['00:00', '07:00', '02:12', null, true, 0, 0, false],
    ['08:00', '17:00', null, '17:16', false, 0, 1, false],
    ['22:00', '06:00', '21:00', '06:16', true, 60, 1, true],
    ['22:00', '06:00', null, '06:16', false, 0, 1, false],
    ['22:00', '06:00', '21:00', '06:15', true, 60, 0, true],
    ['22:00', '06:00', '00:30', '06:16', true, 0, 1, false],
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
