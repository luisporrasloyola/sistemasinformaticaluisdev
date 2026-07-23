<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_once __DIR__ . '/../../includes/simple_xlsx.php';
require_module_access('control_personal.reporte_asistencias');

$workerId = (int) ($_GET['trabajador_id'] ?? 0);
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

$summaryRows = [];
$summaryRows[] = xlsx_row(1, [xlsx_cell(1, 1, 'REPORTE INDIVIDUAL DE ASISTENCIA', 2)], 28);
$summaryRows[] = xlsx_row(2, [xlsx_cell(1, 2, 'Periodo'), xlsx_cell(2, 2, date('d/m/Y', strtotime($dateFrom)) . ' al ' . date('d/m/Y', strtotime($dateTo))), xlsx_cell(5, 2, 'Generado'), xlsx_cell(6, 2, date('d/m/Y H:i'))]);
$summaryRows[] = xlsx_row(4, [xlsx_cell(1, 4, 'DATOS DEL TRABAJADOR', 1)], 22);
$summaryRows[] = xlsx_row(5, [xlsx_cell(1, 5, 'Trabajador', 3), xlsx_cell(2, 5, $worker['full_name'], 9), xlsx_cell(3, 5, 'Documento', 3), xlsx_cell(4, 5, $worker['document_number'], 9), xlsx_cell(5, 5, 'Empresa', 3), xlsx_cell(6, 5, $worker['company'] ?: '-', 9)], 30);
$summaryRows[] = xlsx_row(6, [xlsx_cell(1, 6, 'Cargo', 3), xlsx_cell(2, 6, $worker['positions'] ?: '-', 9), xlsx_cell(3, 6, 'Horario', 3), xlsx_cell(4, 6, $assignment['schedule_name'] ?? '-', 9), xlsx_cell(5, 6, 'Lugar', 3), xlsx_cell(6, 6, $assignment['location_name'] ?? '-', 9)], 30);
$summaryRows[] = xlsx_row(8, [xlsx_cell(1, 8, 'RESUMEN DEL PERIODO', 1)], 22);
$summaryRows[] = xlsx_row(9, [xlsx_cell(1, 9, 'Días laborables', 3), xlsx_cell(2, 9, $summary['workdays'], 3, true), xlsx_cell(3, 9, 'Asistencias', 4), xlsx_cell(4, 9, $summary['attendances'], 4, true), xlsx_cell(5, 9, 'Tardanzas', 5), xlsx_cell(6, 9, $summary['late'], 5, true)], 28);
$summaryRows[] = xlsx_row(10, [xlsx_cell(1, 10, 'Faltas', 6), xlsx_cell(2, 10, $summary['absent'], 6, true), xlsx_cell(3, 10, 'Vacaciones', 7), xlsx_cell(4, 10, $summary['vacations'], 7, true), xlsx_cell(5, 10, 'Horas trabajadas', 3), xlsx_cell(6, 10, attendance_report_minutes_label((int) $summary['worked_minutes']), 3)], 28);
$summaryRows[] = xlsx_row(12, [xlsx_cell(1, 12, 'INDICADORES', 1)], 22);
$summaryRows[] = xlsx_row(13, [xlsx_cell(1, 13, 'Puntualidad', 8), xlsx_cell(2, 13, $summary['punctuality'] . '%', 8), xlsx_cell(3, 13, 'Jornadas finalizadas', 8), xlsx_cell(4, 13, $summary['compliance'] . '%', 8), xlsx_cell(5, 13, 'Minutos de tardanza', 8), xlsx_cell(6, 13, $summary['late_minutes'] . ' min', 8)], 30);
$summaryRows[] = xlsx_row(14, [xlsx_cell(1, 14, 'Horas extras estimadas', 8), xlsx_cell(2, 14, attendance_report_minutes_label((int) $summary['overtime_minutes']), 8)], 28);
$summaryRows[] = xlsx_row(16, [xlsx_cell(1, 16, 'OBSERVACIÓN GENERAL DEL RESPONSABLE', 1)], 22);
$summaryRows[] = xlsx_row(17, [xlsx_cell(1, 17, $note['observation'] ?? 'Sin observaciones.', 9)], 45);
$headers = ['Fecha', 'Día', 'Horario', 'Lugar', 'Entrada', 'Salida', 'Tardanza', 'H. extras', 'Estado de asistencia', 'Estado de jornada', 'Observación'];
$summaryRows[] = xlsx_row(19, [xlsx_cell(1, 19, 'DETALLE DIARIO DE ASISTENCIA', 1)], 24);
$headerCells = [];
foreach ($headers as $index => $header) $headerCells[] = xlsx_cell($index + 1, 20, $header, 1);
$summaryRows[] = xlsx_row(20, $headerCells, 25);
$excelRow = 21;
foreach ($rows as $row) {
    $stateStyle = match ($row['state_key']) { 'attended' => 4, 'late' => 5, 'absent', 'incomplete' => 6, 'vacation' => 7, default => 9 };
    $values = [date('d/m/Y', strtotime($row['date'])), $row['weekday'], $row['schedule'], $row['location'], $row['entry'], $row['exit'],
        $row['late_minutes'] ? attendance_report_minutes_label((int) $row['late_minutes']) : '-',
        $row['overtime_minutes'] ? attendance_report_minutes_label((int) $row['overtime_minutes']) : '-',
        $row['state_code'] . ' - ' . $row['state_label'], $row['journey_label'], $row['observation']];
    $cells = [];
    foreach ($values as $index => $value) $cells[] = xlsx_cell($index + 1, $excelRow, $value, $index === 8 ? $stateStyle : 9);
    $detailHeight = (mb_strlen((string) $values[8]) > 28 || mb_strlen((string) $values[10]) > 42) ? 38 : 25;
    $summaryRows[] = xlsx_row($excelRow, $cells, $detailHeight);
    $excelRow++;
}
if (!$rows) $summaryRows[] = xlsx_row(21, [xlsx_cell(1, 21, 'No hay jornadas en el periodo seleccionado.', 9)]);
$lastRow = max(21, $excelRow - 1);
$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:K' . $lastRow . '"/><sheetViews><sheetView workbookViewId="0" showGridLines="0" zoomScale="90"/></sheetViews><cols><col min="1" max="1" width="21" customWidth="1"/><col min="2" max="2" width="16" customWidth="1"/><col min="3" max="3" width="24" customWidth="1"/><col min="4" max="4" width="18" customWidth="1"/><col min="5" max="5" width="25" customWidth="1"/><col min="6" max="6" width="16" customWidth="1"/><col min="7" max="8" width="15" customWidth="1"/><col min="9" max="9" width="36" customWidth="1"/><col min="10" max="10" width="25" customWidth="1"/><col min="11" max="11" width="42" customWidth="1"/></cols><sheetData>' . implode('', $summaryRows) . '</sheetData><mergeCells count="7"><mergeCell ref="A1:F1"/><mergeCell ref="A4:F4"/><mergeCell ref="A8:F8"/><mergeCell ref="A12:F12"/><mergeCell ref="A16:F16"/><mergeCell ref="A17:F17"/><mergeCell ref="A19:K19"/></mergeCells></worksheet>';

$content = xlsx_package($sheet);
$safeName = trim((string) preg_replace('/[^a-z0-9_-]+/i', '_', (string) $worker['full_name']), '_');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="reporte_asistencia_' . $safeName . '_' . $dateFrom . '_' . $dateTo . '.xlsx"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $content;
