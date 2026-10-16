<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/attendance_report_data.php';

function expect_same(mixed $expected, mixed $actual, string $case): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($case . ': esperado ' . json_encode($expected) . ', obtenido ' . json_encode($actual));
    }
}

expect_same(164, attendance_report_diff_minutes('17:31:37', '20:15:16'), '17:31 a 20:15 son 2 h 44 min');
expect_same(44, attendance_report_diff_minutes('01:10:24', '01:54:08'), '01:10 a 01:54 son 44 min');

expect_same(480, attendance_report_holiday_worked_minutes(240, true), 'plantilla nocturna 20:00-00:00');
expect_same(0, attendance_report_holiday_worked_minutes(240, false), 'sin salida no se acredita la jornada');
expect_same(1140, attendance_report_holiday_worked_minutes(570, true), 'plantilla diurna');

foreach ([['00:15:00', 0], ['00:16:00', 16], ['02:00:00', 120], ['21:54:00', 0]] as [$exit, $expected]) {
    $components = attendance_report_overtime_components('20:00:00', '00:00:00', '20:00:00', $exit, false);
    expect_same($expected, $components['exit_minutes'], 'salida ' . $exit);
}
expect_same(true, attendance_report_exit_is_early('20:00:00', '00:00:00', '21:54:00'), 'salida anticipada');
$lateArrival = attendance_report_overtime_components('21:00:00', '22:00:00', '22:11:55', '22:18:33', false);
expect_same(18, $lateArrival['exit_minutes'], 'salida 22:18 tras horario 22:00');
expect_same(36, $lateArrival['exit_minutes'] * 2, 'salida feriada sin límite por entrada tardía');

$early = attendance_report_overtime_components('20:00:00', '00:00:00', '19:30:00', '00:00:00', true);
expect_same(true, $early['early_eligible'], 'casilla de entrada anticipada');
expect_same(60, attendance_report_early_credit($early['early_minutes'], true), '30 min autorizados en feriado');
expect_same(30, attendance_report_early_credit($early['early_minutes'], false), '30 min autorizados en día normal');
expect_same(0, attendance_report_early_credit(0, true), 'entrada sin autorización');
$seconds = attendance_report_overtime_components('22:15:00', '22:17:00', '22:11:55', '22:18:33', true);
expect_same(4, $seconds['early_minutes'], '22:11 mostrado frente a 22:15 programado');
expect_same(8, attendance_report_early_credit($seconds['early_minutes'], true), 'entrada anticipada de 4 min en feriado');

$doubleHoliday = attendance_holiday_apply_multiplier(600, 600, 30, 120, true, true);
$plainHoliday = attendance_holiday_apply_multiplier(600, 600, 30, 120, false, true);
expect_same(['worked_minutes' => 600, 'holiday_overtime_minutes' => 1080, 'early_overtime_minutes' => 60, 'exit_overtime_minutes' => 240], $doubleHoliday, 'feriado con interruptor activo');
expect_same(['worked_minutes' => 600, 'holiday_overtime_minutes' => 0, 'early_overtime_minutes' => 30, 'exit_overtime_minutes' => 120], $plainHoliday, 'feriado con interruptor desactivado');
$plainLedger = attendance_report_hours_ledger([
    ['date' => '2026-10-13'] + $plainHoliday,
], '2026-10', '2026-11', 0, '2026-11-01');
expect_same(600, $plainLedger['2026-10']['worked_minutes'], 'total mensual sin duplicar');
expect_same(150, $plainLedger['2026-10']['overtime_minutes'], 'extra mensual sin duplicar');
expect_same(150, $plainLedger['2026-11']['opening_minutes'], 'saldo siguiente sin duplicar');
expect_same(540, attendance_report_worked_after_lunch($doubleHoliday['worked_minutes'], true), 'marcación real descuenta solo una hora de almuerzo');
expect_same(540, attendance_report_worked_after_lunch($plainHoliday['worked_minutes'], true), 'feriado sin doble descuenta solo una hora de almuerzo');
$markedHoliday = attendance_holiday_apply_multiplier(attendance_report_diff_minutes('07:07:00', '16:14:00'), attendance_report_diff_minutes('08:00:00', '17:30:00'), 0, 0, true, true);
expect_same(487, attendance_report_worked_after_lunch($markedHoliday['worked_minutes'], true), 'marcación neta de 07:07 a 16:14: 8 h 07 min');
expect_same(1020, $markedHoliday['holiday_overtime_minutes'], 'horario neto de 08:00 a 17:30 duplicado: 17 h');
$holidayLedger = attendance_report_hours_ledger([['date' => '2026-10-08', 'worked_minutes' => 487, 'holiday_overtime_minutes' => $markedHoliday['holiday_overtime_minutes']]], '2026-10', '2026-10', 0, '2026-10-16');
expect_same(487, $holidayLedger['2026-10']['worked_minutes'], 'resumen conserva la marcación neta');
expect_same(1020, $holidayLedger['2026-10']['overtime_minutes'], 'resumen incorpora el feriado a horas extras');
expect_same(660, attendance_report_worked_after_lunch(720, true), 'jornada ordinaria descuenta una hora');
expect_same(0, attendance_report_worked_after_lunch(45, true), 'jornada corta nunca produce horas negativas');
expect_same(0, attendance_report_worked_after_lunch(0, false), 'jornada incompleta sin descuento');
expect_same(['worked_minutes' => 1140, 'lunch_deduction_minutes' => 60], attendance_report_holiday_calendar_credit(600, true), 'FER sin marcaciones: horario doble y un almuerzo');
expect_same(['worked_minutes' => 540, 'lunch_deduction_minutes' => 60], attendance_report_holiday_calendar_credit(600, false), 'FER sin marcaciones con duplicación desactivada');
expect_same(['worked_minutes' => 0, 'lunch_deduction_minutes' => 0], attendance_report_holiday_calendar_credit(0, true), 'FER sin horario no inventa horas');
$holidayWithoutMarks = attendance_report_classify_day(['worker_id' => 1, 'date' => '2026-10-14', 'entry' => '-', 'exit' => '-', 'state_key' => 'holiday', 'calendar_event_type' => 'holiday', 'worked_minutes' => 1140], '2026-10-15');
expect_same('holiday', $holidayWithoutMarks['calendar_type'], 'FER conserva la clasificación de calendario');
expect_same(false, $holidayWithoutMarks['worked'], 'FER sin marcaciones no se cuenta como asistencia');
expect_same(1140, $holidayWithoutMarks['worked_minutes'], 'FER acredita horas en el libro mensual');

$months = attendance_report_hours_ledger([
    ['date' => '2027-08-31', 'worked_minutes' => 480, 'early_overtime_minutes' => 60, 'exit_overtime_minutes' => 32],
], '2027-08', '2027-09', 0, '2027-09-02');
expect_same(480, $months['2027-08']['worked_minutes'], 'horas trabajadas de agosto');
expect_same(92, $months['2027-08']['overtime_minutes'], 'solo entrada y salida en horas extras');
expect_same(0, $months['2027-09']['overtime_minutes'], 'sin crédito nuevo en septiembre');
expect_same(92, $months['2027-09']['opening_minutes'], 'saldo arrastrado a septiembre');

$restLedger = attendance_report_hours_ledger([
    ['date' => '2026-10-09', 'calendar_type' => 'rest', 'rest_kind' => 'hours'],
    ['date' => '2026-10-10', 'calendar_type' => 'rest', 'rest_kind' => 'hours'],
    ['date' => '2026-10-11', 'calendar_type' => 'rest', 'rest_kind' => 'hours'],
    ['date' => '2026-10-12', 'calendar_type' => 'rest', 'rest_kind' => 'regime'],
    ['date' => '2026-10-17', 'calendar_type' => 'rest', 'rest_kind' => 'hours'],
], '2026-10', '2026-11', 0, '2026-10-15');
expect_same(3, $restLedger['2026-10']['redeemed_days'], 'solo descansos a cuenta de horas vencidos');
expect_same(1, $restLedger['2026-10']['redeemed_saturday_days'], 'solo el sábado canjea seis horas');
expect_same(1380, $restLedger['2026-10']['redeemed_minutes'], 'viernes y domingo 8 h 30 min; sábado 6 h');
expect_same(1, $restLedger['2026-10']['planned_redemption_days'], 'sábado futuro aún no se descuenta');
expect_same(-1380, $restLedger['2026-11']['opening_minutes'], 'saldo del mes siguiente respeta canje de sábado');

$adjusted = attendance_report_hours_ledger([
    ['date' => '2026-09-30', 'early_overtime_minutes' => 418],
    ['date' => '2026-10-13', 'exit_overtime_minutes' => 120],
], '2026-09', '2026-11', 0, '2026-11-01', ['2026-10' => 260]);
expect_same(418, $adjusted['2026-09']['closing_minutes'], 'septiembre conserva 6 h 58 min');
expect_same(260, $adjusted['2026-10']['opening_minutes'], 'HEMP de octubre ajustado a 4 h 20 min');
expect_same(380, $adjusted['2026-10']['closing_minutes'], 'octubre cierra con 6 h 20 min');
expect_same(380, $adjusted['2026-11']['opening_minutes'], 'noviembre arrastra el saldo ajustado');

echo "OK: jornada feriada según plantilla, extras, autorización y cambio de mes\n";
