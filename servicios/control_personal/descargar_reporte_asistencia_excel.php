<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_once __DIR__ . '/../../includes/simple_xlsx.php';
require_module_access('control_personal.reporte_asistencias');

$workerId = (int) ($_GET['trabajador_id'] ?? 0);
require_personal_own_worker($workerId);
$dateFrom = trim((string) ($_GET['desde'] ?? ''));
$dateTo = trim((string) ($_GET['hasta'] ?? ''));
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

$summaryRows = [];
$summaryRows[] = xlsx_row(1, [xlsx_cell(1, 1, 'REPORTE INDIVIDUAL DE ASISTENCIA', 2)], 28);
$summaryRows[] = xlsx_row(2, [xlsx_cell(1, 2, 'Periodo'), xlsx_cell(2, 2, date('d/m/Y', strtotime($dateFrom)) . ' al ' . date('d/m/Y', strtotime($dateTo))), xlsx_cell(5, 2, 'Generado'), xlsx_cell(6, 2, date('d/m/Y H:i'))]);
$summaryRows[] = xlsx_row(4, [xlsx_cell(1, 4, 'DATOS DEL TRABAJADOR', 1)], 22);
$summaryRows[] = xlsx_row(5, [xlsx_cell(1, 5, 'Trabajador', 3), xlsx_cell(2, 5, $worker['full_name'], 9), xlsx_cell(3, 5, 'Documento', 3), xlsx_cell(4, 5, $worker['document_number'], 9), xlsx_cell(5, 5, 'Empresa', 3), xlsx_cell(6, 5, $worker['company'] ?: '-', 9)], 30);
$summaryRows[] = xlsx_row(6, [xlsx_cell(1, 6, 'Cargo', 3), xlsx_cell(2, 6, $worker['positions'] ?: '-', 9), xlsx_cell(3, 6, 'Horario', 3), xlsx_cell(4, 6, $assignment['schedule_name'] ?? '-', 9), xlsx_cell(5, 6, 'Lugar asignado', 3), xlsx_cell(6, 6, $assignment['location_name'] ?? '-', 9)], 30);
$summaryRows[] = xlsx_row(8, [xlsx_cell(1, 8, 'RESUMEN DEL PERIODO', 1)], 22);
$summaryRows[] = xlsx_row(9, [xlsx_cell(1, 9, 'Días laborables', 3), xlsx_cell(2, 9, $summary['workdays'], 3, true), xlsx_cell(3, 9, 'Asistencias', 4), xlsx_cell(4, 9, $summary['attendances'], 4, true), xlsx_cell(5, 9, 'Tardanzas', 5), xlsx_cell(6, 9, $summary['late'], 5, true)], 28);
$summaryRows[] = xlsx_row(10, [xlsx_cell(1, 10, 'Faltas', 6), xlsx_cell(2, 10, $summary['absent'], 6, true), xlsx_cell(3, 10, 'Vacaciones', 7), xlsx_cell(4, 10, $summary['vacations'], 7, true), xlsx_cell(5, 10, 'Horas trabajadas', 3), xlsx_cell(6, 10, attendance_report_minutes_label((int) $summary['worked_minutes']), 3)], 28);
$summaryRows[] = xlsx_row(12, [xlsx_cell(1, 12, 'INDICADORES', 1)], 22);
$summaryRows[] = xlsx_row(13, [xlsx_cell(1, 13, 'Puntualidad', 8), xlsx_cell(2, 13, $summary['punctuality'] . '%', 8), xlsx_cell(3, 13, 'Jornadas finalizadas', 8), xlsx_cell(4, 13, $summary['compliance'] . '%', 8), xlsx_cell(5, 13, 'Minutos de tardanza', 8), xlsx_cell(6, 13, $summary['late_minutes'] . ' min', 8)], 30);
$summaryRows[] = xlsx_row(14, [xlsx_cell(1, 14, 'Horas extras totales', 8), xlsx_cell(2, 14, attendance_report_minutes_label((int) $summary['overtime_minutes']), 8), xlsx_cell(3, 14, 'Entrada autorizada', 8), xlsx_cell(4, 14, attendance_report_minutes_label((int) $summary['early_overtime_minutes']), 8), xlsx_cell(5, 14, 'Extra por salida (+15 min)', 8), xlsx_cell(6, 14, attendance_report_minutes_label((int) $summary['exit_overtime_minutes']), 8)], 28);
$summaryRows[] = xlsx_row(16, [xlsx_cell(1, 16, 'OBSERVACIÓN GENERAL DEL RESPONSABLE', 1)], 22);
$summaryRows[] = xlsx_row(17, [xlsx_cell(1, 17, $note['observation'] ?? 'Sin observaciones.', 9)], 45);
$headers = ['Fecha', 'Día', 'Horario', 'Tolerancia', 'Proyecto', 'Lugar de entrada', 'Lugar de salida', 'Entrada', 'Salida', 'Tardanza', 'Horas extras (total / entrada / salida)', 'Horas trabajadas', 'Estado de asistencia', 'Estado de jornada'];
$summaryRows[] = xlsx_row(19, [xlsx_cell(1, 19, 'DETALLE DIARIO DE ASISTENCIA', 1)], 24);
$headerCells = [];
foreach ($headers as $index => $header) $headerCells[] = xlsx_cell($index + 1, 20, $header, 1);
$summaryRows[] = xlsx_row(20, $headerCells, 25);
$excelRow = 21;
foreach ($rows as $row) {
    $stateStyle = match ($row['state_key']) { 'attended' => 4, 'late' => 5, 'absent', 'incomplete' => 6, 'vacation' => 7, default => 9 };
    $dayTrips = $tripsByDate[(string) $row['date']] ?? [];
    $entryRoute = (string) $row['entry_location'];
    foreach ($dayTrips as $trip) $entryRoute .= "\n→ " . (string) $trip['first_destination'];
    $entryLabel = $row['entry'] . ($row['entry_administrative'] ? ' | Administrativa; por ' . ($row['entry_administrative_actor'] ?: 'Administrador') . '; motivo: ' . ($row['entry_administrative_reason'] ?: '-') : '');
    $exitLabel = $row['exit'] . ($row['exit_administrative'] ? ' | Administrativa; por ' . ($row['exit_administrative_actor'] ?: 'Administrador') . '; motivo: ' . ($row['exit_administrative_reason'] ?: '-') : '');
    $values = [date('d/m/Y', strtotime($row['date'])), $row['weekday'], $row['schedule'], $row['tolerance_minutes'] !== null ? $row['tolerance_minutes'].' min' : '-', $row['project'] ?? '-', $entryRoute, $row['exit_location'], $entryLabel, $exitLabel,
        $row['late_minutes'] ? attendance_report_minutes_label((int) $row['late_minutes']) : '-',
        $row['overtime_minutes'] ? 'Total: ' . attendance_report_minutes_label((int) $row['overtime_minutes']) . "\nEntrada: " . attendance_report_minutes_label((int) $row['early_overtime_minutes']) . "\nSalida: " . attendance_report_minutes_label((int) $row['exit_overtime_minutes']) : '-',
        $row['entry'] !== '-' && $row['exit'] !== '-' ? attendance_report_minutes_label((int) $row['worked_minutes']) : '-',
        $row['state_code'], $row['journey_label']];
    $cells = [];
    foreach ($values as $index => $value) $cells[] = xlsx_cell($index + 1, $excelRow, $value, $index === 12 ? $stateStyle : 9);
    $detailHeight = max((mb_strlen((string) $values[4]) > 42 || $row['entry_administrative'] || $row['exit_administrative']) ? 38 : 25, $row['overtime_minutes'] > 0 ? 54 : 25, 17 * (count($dayTrips) + 1));
    $summaryRows[] = xlsx_row($excelRow, $cells, $detailHeight);
    $excelRow++;
}
if (!$rows) $summaryRows[] = xlsx_row(21, [xlsx_cell(1, 21, 'No hay jornadas en el periodo seleccionado.', 9)]);
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
$lastRow = max(21, $excelRow - 1);
$mergeCount = $trips ? 8 : 7;
$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:N' . $lastRow . '"/><sheetViews><sheetView workbookViewId="0" showGridLines="0" zoomScale="90"/></sheetViews><cols><col min="1" max="1" width="21" customWidth="1"/><col min="2" max="2" width="16" customWidth="1"/><col min="3" max="3" width="24" customWidth="1"/><col min="4" max="4" width="14" customWidth="1"/><col min="5" max="5" width="42" customWidth="1"/><col min="6" max="7" width="25" customWidth="1"/><col min="8" max="9" width="46" customWidth="1"/><col min="10" max="10" width="18" customWidth="1"/><col min="11" max="11" width="30" customWidth="1"/><col min="12" max="12" width="19" customWidth="1"/><col min="13" max="13" width="25" customWidth="1"/><col min="14" max="14" width="25" customWidth="1"/></cols><sheetData>' . implode('', $summaryRows) . '</sheetData><mergeCells count="'.$mergeCount.'"><mergeCell ref="A1:F1"/><mergeCell ref="A4:F4"/><mergeCell ref="A8:F8"/><mergeCell ref="A12:F12"/><mergeCell ref="A16:F16"/><mergeCell ref="A17:F17"/><mergeCell ref="A19:N19"/>'.$extraMerge.'</mergeCells></worksheet>';

$content = xlsx_package($sheet);
$safeName = trim((string) preg_replace('/[^a-z0-9_-]+/i', '_', (string) $worker['full_name']), '_');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="reporte_asistencia_' . $safeName . '_' . $dateFrom . '_' . $dateTo . '.xlsx"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $content;
