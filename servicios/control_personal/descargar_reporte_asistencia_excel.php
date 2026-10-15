<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_once __DIR__ . '/../../includes/simple_xlsx.php';
require_module_access('control_personal.reporte_asistencias');

$workerId = (int) ($_GET['trabajador_id'] ?? 0);
require_personal_own_worker($workerId);
$selectedMonth = trim((string) ($_GET['mes'] ?? ''));
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth)) {
    $dateFrom = $selectedMonth . '-01';
    $dateTo = (new DateTimeImmutable($dateFrom))->format('Y-m-t');
} else {
    $dateFrom = trim((string) ($_GET['desde'] ?? ''));
    $dateTo = trim((string) ($_GET['hasta'] ?? ''));
}
if ($workerId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) || $dateFrom > $dateTo) {
    http_response_code(422); exit('Parámetros del reporte no válidos.');
}
if ((new DateTimeImmutable($dateFrom))->diff(new DateTimeImmutable($dateTo))->days > 366) {
    http_response_code(422); exit('El periodo máximo permitido es de 366 días.');
}

$report = attendance_report_build($dateFrom, $dateTo, $workerId);
$worker = $report['worker'];
if (!$worker) { http_response_code(404); exit('Trabajador no encontrado.'); }
$assignment = $report['assignment'];
$summary = $report['summary'];
$rows = $report['individual_rows'];
$note = $report['note'];
$trips = $report['trips'] ?? [];
$tripsByDate = attendance_report_trips_by_date($trips);
$month = substr($dateFrom, 0, 7);
$monthNames = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$monthLabel = $monthNames[(int) substr($month, 5, 2)] . ' ' . substr($month, 0, 4);
$previousDate = (new DateTimeImmutable($dateFrom))->modify('-1 month');
$previousMonthLabel = $monthNames[(int) $previousDate->format('n')] . ' ' . $previousDate->format('Y');
$days = $report['monthly_day_summary'][$month] ?? [];
$hoursBook = attendance_report_build_hours_ledger($workerId, $month, $report['daily_classification']);
$hours = $hoursBook['current'];
$workedDays = (int) ($days['worked'] ?? 0);
$completedDays = 0;
foreach ($report['daily_classification'] as $classifiedDay) {
    if (str_starts_with((string) $classifiedDay['date'], $month) && !empty($classifiedDay['worked']) && !empty($classifiedDay['has_exit'])) $completedDays++;
}
$punctuality = $workedDays > 0 ? round(100 * ($workedDays - (int) ($days['late'] ?? 0)) / $workedDays) : 0;
$completion = $workedDays > 0 ? round(100 * $completedDays / $workedDays) : 0;
$hoursLabel = static fn(string $key): string => attendance_report_hours_label((int) ($hours[$key] ?? 0));

$summaryRows = [];
$summaryRows[] = xlsx_row(1, [xlsx_cell(1, 1, 'REPORTE INDIVIDUAL DE ASISTENCIA', 2)], 28);
$summaryRows[] = xlsx_row(2, [xlsx_cell(1, 2, 'Periodo'), xlsx_cell(2, 2, date('d/m/Y', strtotime($dateFrom)) . ' al ' . date('d/m/Y', strtotime($dateTo))), xlsx_cell(5, 2, 'Generado'), xlsx_cell(6, 2, date('d/m/Y H:i'))]);
$summaryRows[] = xlsx_row(4, [xlsx_cell(1, 4, 'DATOS DEL TRABAJADOR', 1)], 22);
$summaryRows[] = xlsx_row(5, [xlsx_cell(1, 5, 'Trabajador', 3), xlsx_cell(2, 5, $worker['full_name'], 9), xlsx_cell(3, 5, 'Documento', 3), xlsx_cell(4, 5, $worker['document_number'], 9), xlsx_cell(5, 5, 'Empresa', 3), xlsx_cell(6, 5, $worker['company'] ?: '-', 9)], 30);
$summaryRows[] = xlsx_row(6, [xlsx_cell(1, 6, 'Cargo', 3), xlsx_cell(2, 6, $worker['positions'] ?: '-', 9), xlsx_cell(3, 6, 'Horario', 3), xlsx_cell(4, 6, $assignment['schedule_name'] ?? '-', 9), xlsx_cell(5, 6, 'Días registrados', 3), xlsx_cell(6, 6, count($report['daily_classification']), 9, true)], 30);
$summaryRows[] = xlsx_row(8, [xlsx_cell(1, 8, 'RESUMEN MENSUAL DE DÍAS · ' . mb_strtoupper($monthLabel, 'UTF-8'), 1)], 22);
$summaryRows[] = xlsx_row(9, [xlsx_cell(1, 9, 'Puntualidad', 3), xlsx_cell(2, 9, $punctuality . '%', 9), xlsx_cell(3, 9, 'Jornadas finalizadas', 3), xlsx_cell(4, 9, $completion . '%', 9), xlsx_cell(5, 9, 'Días trabajados', 4), xlsx_cell(6, 9, $workedDays, 4, true)], 28);
$summaryRows[] = xlsx_row(10, [xlsx_cell(1, 10, 'Asistencia regular', 3), xlsx_cell(2, 10, $days['regular'] ?? 0, 9, true), xlsx_cell(3, 10, 'No concluidos', 3), xlsx_cell(4, 10, $days['incomplete'] ?? 0, 9, true), xlsx_cell(5, 10, 'Tardanzas', 5), xlsx_cell(6, 10, $days['late'] ?? 0, 5, true)], 28);
$summaryRows[] = xlsx_row(11, [xlsx_cell(1, 11, 'Feriados', 3), xlsx_cell(2, 11, $days['holiday'] ?? 0, 9, true), xlsx_cell(3, 11, 'No laborables', 3), xlsx_cell(4, 11, $days['non_working'] ?? 0, 9, true), xlsx_cell(5, 11, 'Vacaciones', 7), xlsx_cell(6, 11, $days['vacation'] ?? 0, 7, true)], 28);
$summaryRows[] = xlsx_row(12, [xlsx_cell(1, 12, 'Faltas', 6), xlsx_cell(2, 12, $days['absent'] ?? 0, 6, true), xlsx_cell(3, 12, 'Descansos', 3), xlsx_cell(4, 12, $days['rest'] ?? 0, 9, true), xlsx_cell(5, 12, 'Pendientes', 3), xlsx_cell(6, 12, $days['pending'] ?? 0, 9, true)], 28);
$summaryRows[] = xlsx_row(13, [xlsx_cell(1, 13, 'A cuenta de horas', 3), xlsx_cell(2, 13, $days['rest_hours'] ?? 0, 9, true), xlsx_cell(3, 13, 'Régimen laboral', 3), xlsx_cell(4, 13, $days['rest_regime'] ?? 0, 9, true), xlsx_cell(5, 13, 'Sin clasificar', 3), xlsx_cell(6, 13, $days['rest_unclassified'] ?? 0, 9, true)], 28);
$summaryRows[] = xlsx_row(15, [xlsx_cell(1, 15, 'LIBRO MENSUAL DE HORAS', 1)], 22);
$summaryRows[] = xlsx_row(16, [xlsx_cell(1, 16, 'Horas trabajadas', 3), xlsx_cell(2, 16, $hoursLabel('worked_minutes'), 9), xlsx_cell(3, 16, 'Extra por entrada', 3), xlsx_cell(4, 16, $hoursLabel('early_overtime_minutes'), 9), xlsx_cell(5, 16, 'Extra por salida', 3), xlsx_cell(6, 16, $hoursLabel('exit_overtime_minutes'), 9)], 28);
$summaryRows[] = xlsx_row(17, [xlsx_cell(1, 17, 'HEMP · ' . $previousMonthLabel, 3), xlsx_cell(2, 17, $hoursLabel('opening_minutes'), 9), xlsx_cell(3, 17, 'Extras · ' . $monthLabel, 4), xlsx_cell(4, 17, $hoursLabel('overtime_minutes'), 4), xlsx_cell(5, 17, 'H. canjeadas · ' . $monthLabel, 5), xlsx_cell(6, 17, $hoursLabel('redeemed_minutes'), 5)], 32);
$summaryRows[] = xlsx_row(18, [xlsx_cell(1, 18, 'Horas extras vigentes', 4), xlsx_cell(2, 18, $hoursLabel('closing_minutes'), 4), xlsx_cell(3, 18, 'Días canjeados (sábados 6 h; otros 8 h 30 min)', 3), xlsx_cell(4, 18, $hours['redeemed_days'], 9, true), xlsx_cell(5, 18, 'Canjes futuros sin descontar', 3), xlsx_cell(6, 18, $hours['planned_redemption_days'], 9, true)], 34);
if ($month === '2026-10' && !empty($hoursBook['opening_adjustment'])) {
    $summaryRows[] = xlsx_row(19, [xlsx_cell(1, 19, 'HEMP ajustado para octubre; septiembre no cambia.', 9)], 24);
}
$summaryRows[] = xlsx_row(20, [xlsx_cell(1, 20, 'OBSERVACIÓN GENERAL DEL RESPONSABLE', 1)], 22);
$summaryRows[] = xlsx_row(21, [xlsx_cell(1, 21, $note['observation'] ?? 'Sin observaciones.', 9)], 45);
$headers = ['Fecha', 'Día', 'Horario', 'Tolerancia', 'Proyecto', 'Lugar de entrada', 'Lugar de salida', 'Entrada', 'Salida', 'Tardanza', 'Horas extras (total / feriado / entrada / salida)', 'Horas trabajadas', 'Estado de asistencia', 'Estado de jornada'];
$summaryRows[] = xlsx_row(23, [xlsx_cell(1, 23, 'DETALLE DIARIO DE ASISTENCIA', 1)], 24);
$headerCells = [];
foreach ($headers as $index => $header) $headerCells[] = xlsx_cell($index + 1, 24, $header, 1);
$summaryRows[] = xlsx_row(24, $headerCells, 25);
$excelRow = 25;
foreach ($rows as $row) {
    $stateStyle = match ($row['state_key']) { 'attended' => 4, 'late' => 5, 'absent', 'incomplete' => 6, 'vacation' => 7, default => 9 };
    $dayTrips = $tripsByDate[(string) $row['date']] ?? [];
    $entryRoute = (string) $row['entry_location'];
    foreach ($dayTrips as $trip) $entryRoute .= "\n→ " . (string) $trip['first_destination'];
    $entryLabel = $row['entry'] . ($row['entry_administrative'] ? ' | Administrativa; por ' . ($row['entry_administrative_actor'] ?: 'Administrador') . '; motivo: ' . ($row['entry_administrative_reason'] ?: '-') : '');
    $exitLabel = $row['exit'] . (!empty($row['exit_next_day']) ? ' | Día siguiente' : '') . ($row['exit_administrative'] ? ' | Administrativa; por ' . ($row['exit_administrative_actor'] ?: 'Administrador') . '; motivo: ' . ($row['exit_administrative_reason'] ?: '-') : '');
    $values = [date('d/m/Y', strtotime($row['date'])), $row['weekday'], $row['schedule'], $row['tolerance_minutes'] !== null ? $row['tolerance_minutes'].' min' : '-', $row['project'] ?? '-', $entryRoute, $row['exit_location'], $entryLabel, $exitLabel,
        $row['late_minutes'] ? attendance_report_minutes_label((int) $row['late_minutes']) : '-',
        $row['overtime_minutes'] ? 'Total: ' . attendance_report_minutes_label((int) $row['overtime_minutes']) . "\nEntrada" . (!empty($row['holiday_double_applied']) && $row['early_overtime_minutes'] > 0 ? ' x2' : '') . ': ' . attendance_report_minutes_label((int) $row['early_overtime_minutes']) . "\nSalida" . (!empty($row['holiday_double_applied']) && $row['exit_overtime_minutes'] > 0 ? ' x2' : '') . ': ' . attendance_report_minutes_label((int) $row['exit_overtime_minutes']) : '-',
        (($row['entry'] !== '-' && $row['exit'] !== '-') || !empty($row['holiday_schedule_credit'])) ? attendance_report_minutes_label((int) $row['worked_minutes']) . (!empty($row['holiday_double_applied']) ? "\nHorario x2 por feriado" : '') . (!empty($row['holiday_schedule_credit']) ? "\nCrédito de feriado según horario" : '') . (!empty($row['lunch_deduction_minutes']) ? "\nAlmuerzo -" . attendance_report_minutes_label((int) $row['lunch_deduction_minutes']) : '') : '-',
        $row['state_code'], $row['journey_label']];
    $cells = [];
    foreach ($values as $index => $value) $cells[] = xlsx_cell($index + 1, $excelRow, $value, $index === 12 ? $stateStyle : 9);
    $detailHeight = max((mb_strlen((string) $values[4]) > 42 || $row['entry_administrative'] || $row['exit_administrative']) ? 38 : 25, $row['overtime_minutes'] > 0 ? 54 : 25, 17 * (count($dayTrips) + 1));
    $summaryRows[] = xlsx_row($excelRow, $cells, $detailHeight);
    $excelRow++;
}
if (!$rows) $summaryRows[] = xlsx_row(25, [xlsx_cell(1, 25, 'No hay jornadas en el periodo seleccionado.', 9)]);
$extraMerge = '';
if ($trips) {
    $sectionRow = $excelRow + 1;
    $summaryRows[] = xlsx_row($sectionRow, [xlsx_cell(1,$sectionRow,'DESPLAZAMIENTOS LABORALES',1)],24);
    $extraMerge = '<mergeCell ref="A'.$sectionRow.':N'.$sectionRow.'"/>';
    $excelRow = $sectionRow + 1;
    $tripHeaders=['Fecha','Horario','Inicio','Fin','Duración','Origen','Destino','Proyecto','Estado'];
    $cells=[]; foreach($tripHeaders as $index=>$header)$cells[]=xlsx_cell($index+1,$excelRow,$header,1);
    $summaryRows[]=xlsx_row($excelRow,$cells,25); $excelRow++;
    foreach($trips as $trip){
        $incident=($trip['completion_type']??'')==='returned_without_arrival';
        $registered=($trip['status']??'')==='registrado';
        $project=($trip['project_name']?:($registered?'-':(($trip['status']??'')!=='finalizado'?'Pendiente':'-'))).($incident?' | Llegada no confirmada: '.($trip['exception_reason']?:'Sin detalle'):'');
        $status=$registered?'Registrado':(($trip['status']??'')!=='finalizado'?'En curso':($incident?'Regreso con incidencia':'Finalizado'));
        $values=[date('d/m/Y',strtotime($trip['trip_date'])),$trip['schedule_label'],date('H:i',strtotime($trip['started_at'])),$trip['ended_at']?date('H:i',strtotime($trip['ended_at'])):'-',$trip['duration_label'],
            $trip['location_name'],$trip['first_destination'],$project,$status];
        $cells=[];foreach($values as $index=>$value)$cells[]=xlsx_cell($index+1,$excelRow,$value,9);
        $summaryRows[]=xlsx_row($excelRow,$cells,mb_strlen(implode(' ',$values))>100?42:28);$excelRow++;
    }
}
$lastRow = max(25, $excelRow - 1);
$mergeCount = $trips ? 8 : 7;
$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:N' . $lastRow . '"/><sheetViews><sheetView workbookViewId="0" showGridLines="0" zoomScale="90"/></sheetViews><cols><col min="1" max="1" width="25" customWidth="1"/><col min="2" max="2" width="20" customWidth="1"/><col min="3" max="3" width="29" customWidth="1"/><col min="4" max="4" width="20" customWidth="1"/><col min="5" max="5" width="42" customWidth="1"/><col min="6" max="7" width="25" customWidth="1"/><col min="8" max="9" width="46" customWidth="1"/><col min="10" max="10" width="18" customWidth="1"/><col min="11" max="11" width="30" customWidth="1"/><col min="12" max="12" width="19" customWidth="1"/><col min="13" max="13" width="25" customWidth="1"/><col min="14" max="14" width="25" customWidth="1"/></cols><sheetData>' . implode('', $summaryRows) . '</sheetData><mergeCells count="'.$mergeCount.'"><mergeCell ref="A1:F1"/><mergeCell ref="A4:F4"/><mergeCell ref="A8:F8"/><mergeCell ref="A15:F15"/><mergeCell ref="A20:F20"/><mergeCell ref="A21:F21"/><mergeCell ref="A23:N23"/>'.$extraMerge.'</mergeCells></worksheet>';

$content = xlsx_package($sheet);
$safeName = trim((string) preg_replace('/[^a-z0-9_-]+/i', '_', (string) $worker['full_name']), '_');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="reporte_asistencia_' . $safeName . '_' . $dateFrom . '_' . $dateTo . '.xlsx"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $content;
