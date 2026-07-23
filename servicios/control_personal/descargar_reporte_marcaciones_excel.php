<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/marking_report_data.php';
require_once __DIR__ . '/../../includes/simple_xlsx.php';
require_module_access('control_personal.reportes');

$today = date('Y-m-d'); $dateFrom = trim((string) ($_GET['desde'] ?? date('Y-m-01'))); $dateTo = trim((string) ($_GET['hasta'] ?? $today));
$workerId = (int) ($_GET['trabajador_id'] ?? 0); $companyId = (int) ($_GET['empresa_id'] ?? 0); $locationId = (int) ($_GET['punto_id'] ?? 0); $status = trim((string) ($_GET['estado'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) || $dateFrom > $dateTo) { http_response_code(422); exit('Periodo no válido.'); }
$rows = marking_report_build($dateFrom, $dateTo, $workerId, $companyId, $locationId, $status);

$sheetRows = [];
$sheetRows[] = xlsx_row(1, [xlsx_cell(1, 1, 'REPORTE DE MARCACIONES', 2)], 28);
$sheetRows[] = xlsx_row(2, [xlsx_cell(1, 2, 'Periodo', 3), xlsx_cell(2, 2, date('d/m/Y', strtotime($dateFrom)) . ' al ' . date('d/m/Y', strtotime($dateTo)), 9), xlsx_cell(4, 2, 'Generado', 3), xlsx_cell(5, 2, date('d/m/Y H:i'), 9)]);
$sheetRows[] = xlsx_row(4, [xlsx_cell(1, 4, 'TOTAL DE MARCACIONES', 1)], 23);
$sheetRows[] = xlsx_row(5, [xlsx_cell(1, 5, count($rows), 9, true)], 24);
$headers = ['Fecha', 'Hora', 'Tipo', 'Trabajador', 'Documento', 'Empresa', 'Lugar', 'Horario', 'Distancia (m)', 'Radio permitido (m)', 'Estado', 'Latitud', 'Longitud', 'Dirección detectada', 'Observaciones'];
$headerCells = []; foreach ($headers as $index => $header) $headerCells[] = xlsx_cell($index + 1, 7, $header, 1);
$sheetRows[] = xlsx_row(7, $headerCells, 28);
$excelRow = 8;
foreach ($rows as $row) {
    $display = $row['display_status'] ?? $row['final_status'] ?? '';
    $values = [date('d/m/Y', strtotime($row['mark_date'])), marking_report_time($row['mark_time']), ucfirst($row['mark_type']), $row['full_name'], $row['document_number'], $row['company'] ?? '-', $row['location_name'], $row['schedule_name'], number_format((float) $row['distance_meters'], 2, '.', ''), number_format((float) $row['radius_meters'], 2, '.', ''), marking_report_status_label($display), $row['latitude'], $row['longitude'], $row['address'] ?: '-', $row['observations'] ?: '-'];
    $cells = []; foreach ($values as $index => $value) $cells[] = xlsx_cell($index + 1, $excelRow, $value, 9);
    $height = (mb_strlen((string) $values[13]) > 40 || mb_strlen((string) $values[14]) > 40) ? 38 : 25;
    $sheetRows[] = xlsx_row($excelRow, $cells, $height); $excelRow++;
}
if (!$rows) $sheetRows[] = xlsx_row(8, [xlsx_cell(1, 8, 'No hay marcaciones para los filtros seleccionados.', 9)]);
$lastRow = max(8, $excelRow - 1);
$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:O' . $lastRow . '"/><sheetViews><sheetView workbookViewId="0" showGridLines="0" zoomScale="85"/></sheetViews><cols><col min="1" max="3" width="14" customWidth="1"/><col min="4" max="4" width="28" customWidth="1"/><col min="5" max="5" width="17" customWidth="1"/><col min="6" max="8" width="25" customWidth="1"/><col min="9" max="13" width="19" customWidth="1"/><col min="14" max="15" width="38" customWidth="1"/></cols><sheetData>' . implode('', $sheetRows) . '</sheetData><mergeCells count="2"><mergeCell ref="A1:O1"/><mergeCell ref="A4:O4"/></mergeCells></worksheet>';
$content = xlsx_package($sheet, 'Reporte de marcaciones');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="reporte_marcaciones_' . $dateFrom . '_' . $dateTo . '.xlsx"');
header('Content-Length: ' . strlen($content)); header('Cache-Control: private, max-age=0, must-revalidate'); echo $content;
