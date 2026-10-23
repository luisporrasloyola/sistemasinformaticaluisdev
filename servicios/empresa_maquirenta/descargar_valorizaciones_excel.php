<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/simple_xlsx.php';
require_module_access('empresa_maquirenta.valorizaciones');

$site = (string) ($_GET['sede'] ?? 'ventanilla');
$sources = [
    'ventanilla' => ['charla' => 'empresa_maquirenta_charla_preoperacional', 'equipment' => 'empresa_maquirenta_charla_preoperacional_maquinarias', 'name' => 'Ventanilla'],
    'santa_rosa' => ['charla' => 'empresa_maquirenta_santa_rosa_charla_preoperacional', 'equipment' => 'empresa_maquirenta_santa_rosa_charla_preoperacional_maquinarias', 'name' => 'Santa Rosa'],
];
if (!isset($sources[$site]) && $site !== 'consolidado') {
    http_response_code(422);
    exit('Central no válida.');
}

$month = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    http_response_code(422);
    exit('Mes no válido.');
}
$start = $month . '-01';
$end = (new DateTimeImmutable($start))->format('Y-m-t');
$dateFrom = trim((string) ($_GET['desde'] ?? ''));
$dateTo = trim((string) ($_GET['hasta'] ?? ''));
if ($dateFrom !== '' || $dateTo !== '') {
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', $dateFrom);
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', $dateTo);
    if (!$from || !$to || $from->format('Y-m-d') !== $dateFrom || $to->format('Y-m-d') !== $dateTo
        || $from > $to || $from->diff($to)->days > 366) {
        http_response_code(422);
        exit('Período no válido.');
    }
    $start = $dateFrom;
    $end = $dateTo;
}

$queries = [];
$parameters = [];
foreach (($site === 'consolidado' ? $sources : [$site => $sources[$site]]) as $key => $source) {
    $queries[] = "SELECT c.id, c.fecha, m.nombre AS equipo, COALESCE(u.name, 'Sin usuario') AS registrado_por,
        v.horas_trabajo, v.tarifa_equipo, s.tarifa_supervisor, d.descuento, d.observaciones,
        COALESCE(b.bloqueado, 0) AS bloqueado, '{$source['name']}' AS central
        FROM {$source['charla']} c
        JOIN {$source['equipment']} m ON m.id = c.maquinaria_tipo_id
        LEFT JOIN users u ON u.id = c.registered_by_user_id
        LEFT JOIN empresa_maquirenta_valorizacion_equipos v ON v.central = '{$key}' AND v.charla_id = c.id
        LEFT JOIN empresa_maquirenta_valorizacion_supervisor s ON s.central = '{$key}' AND s.fecha = c.fecha
        LEFT JOIN empresa_maquirenta_valorizacion_detalles d ON d.central = '{$key}' AND d.fecha = c.fecha
        LEFT JOIN empresa_maquirenta_valorizacion_bloqueos b ON b.central = '{$key}' AND b.charla_id = c.id
        WHERE c.fecha BETWEEN ? AND ?";
    array_push($parameters, $start, $end);
}
$stmt = db()->prepare(implode(' UNION ALL ', $queries) . ' ORDER BY fecha DESC, central DESC, equipo, id');
$stmt->execute($parameters);
$groups = [];
$recordCount = 0;
while ($record = $stmt->fetch()) {
    $groups[$record['fecha'] . '|' . $record['central']][] = $record;
    $recordCount++;
}

$clean = static fn (mixed $value): string => preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $value) ?? '';
$displayDate = static fn (string $date): string => (new DateTimeImmutable($date))->format('d/m/Y');
$rowNumber = 1;
$sheetRows = [];
$merges = ['A1:G1', 'A2:B2', 'C2:D2', 'F2:G2', 'A3:B3', 'C3:D3', 'F3:G3'];
$titleCells = [];
for ($column = 1; $column <= 7; $column++) $titleCells[] = xlsx_cell($column, 1, $column === 1 ? 'VALORIZACIONES' : '', 2);
$sheetRows[] = xlsx_row($rowNumber++, $titleCells, 30);
$siteLabel = $site === 'consolidado' ? 'Ventanilla y Santa Rosa' : $sources[$site]['name'];
$sheetRows[] = xlsx_row($rowNumber, [xlsx_cell(1, $rowNumber, 'Central térmica', 3), xlsx_cell(2, $rowNumber, '', 3), xlsx_cell(3, $rowNumber, $siteLabel, 9), xlsx_cell(4, $rowNumber, '', 9), xlsx_cell(5, $rowNumber, 'Período', 3), xlsx_cell(6, $rowNumber, $displayDate($start) . ' al ' . $displayDate($end), 9), xlsx_cell(7, $rowNumber, '', 9)], 25);
$rowNumber++;
$sheetRows[] = xlsx_row($rowNumber, [xlsx_cell(1, $rowNumber, 'Generado', 3), xlsx_cell(2, $rowNumber, '', 3), xlsx_cell(3, $rowNumber, date('d/m/Y H:i'), 9), xlsx_cell(4, $rowNumber, '', 9), xlsx_cell(5, $rowNumber, 'Registros', 3), xlsx_cell(6, $rowNumber, $recordCount, 9, true), xlsx_cell(7, $rowNumber, '', 9)], 25);
$rowNumber += 2;
$headers = ['Nro.', 'Fecha', 'Central', 'Equipo', 'Registrado por', 'Horas de trabajo', 'Costo por hora del equipo (S/)', 'Costo hora supervisor + RT (S/)', 'Costo por día del equipo (S/)', 'Costo día supervisor + RT (S/)', 'Descuento (S/)', 'Total (S/)', 'Observaciones'];
$cells = [];
foreach ($headers as $index => $header) $cells[] = xlsx_cell($index + 1, $rowNumber, $header, 1);
$sheetRows[] = xlsx_row($rowNumber++, $cells, 52);

$dayTones = [3, 4, 5, 6, 7, 8];
$dayIndex = -1;
$previousDate = null;
$number = 0;
$periodTotal = 0.0;
foreach ($groups as $records) {
    $date = (string) $records[0]['fecha'];
    $central = (string) $records[0]['central'];
    if ($date !== $previousDate) {
        $dayIndex++;
        $previousDate = $date;
    }
    $style = $dayTones[$dayIndex % count($dayTones)];
    $label = $displayDate($date) . ' · ' . $central . ' · ' . count($records) . (count($records) === 1 ? ' equipo' : ' equipos');
    $groupCells = [];
    for ($column = 1; $column <= 13; $column++) $groupCells[] = xlsx_cell($column, $rowNumber, $column === 1 ? $label : '', $style);
    $sheetRows[] = xlsx_row($rowNumber, $groupCells, 24);
    $merges[] = 'A' . $rowNumber . ':M' . $rowNumber;
    $rowNumber++;
    $firstEquipmentRow = $rowNumber;

    $included = array_values(array_filter($records, static fn (array $record): bool => (int) $record['bloqueado'] !== 1));
    $supervisorHours = 0.0;
    foreach ($included as $record) $supervisorHours = max($supervisorHours, (float) ($record['horas_trabajo'] ?? 8));
    $supervisorCost = $included ? $supervisorHours * (float) ($included[0]['tarifa_supervisor'] ?? 50) : 0.0;
    $equipmentCosts = [];
    $equipmentTotal = 0.0;
    foreach ($records as $index => $record) {
        $equipmentName = mb_strtolower(trim((string) $record['equipo']), 'UTF-8');
        $defaultRate = str_contains($equipmentName, 'montacarga') ? 95 : (str_contains($equipmentName, 'tijeral') ? 80 : 125);
        $equipmentCosts[$index] = (int) $record['bloqueado'] === 1 ? 0.0
            : (float) ($record['horas_trabajo'] ?? 8) * (float) ($record['tarifa_equipo'] ?? $defaultRate);
        $equipmentTotal += $equipmentCosts[$index];
    }
    $discount = (float) ($records[0]['descuento'] ?? 0);
    $dayTotal = $included ? $equipmentTotal + $supervisorCost - $discount : 0.0;
    $periodTotal += $dayTotal;

    foreach ($records as $index => $record) {
        $equipmentName = mb_strtolower(trim((string) $record['equipo']), 'UTF-8');
        $defaultRate = str_contains($equipmentName, 'montacarga') ? 95 : (str_contains($equipmentName, 'tijeral') ? 80 : 125);
        $values = [
            ++$number, $displayDate($date), $central,
            $clean($record['equipo']), $clean($record['registrado_por']),
            (float) ($record['horas_trabajo'] ?? 8), (float) ($record['tarifa_equipo'] ?? $defaultRate),
            (float) ($record['tarifa_supervisor'] ?? 50), round($equipmentCosts[$index], 2),
            $index === 0 ? round($supervisorCost, 2) : null,
            $index === 0 ? round($discount, 2) : null,
            $index === 0 ? round($dayTotal, 2) : null,
            $index === 0 ? $clean($record['observaciones'] ?? '') : '',
        ];
        $numericColumns = [1, 6, 7, 8, 9, 10, 11, 12];
        $cells = [];
        foreach ($values as $column => $value) {
            $column++;
            if ($value === null) {
                $cells[] = xlsx_cell($column, $rowNumber, '', 9);
                continue;
            }
            $cells[] = xlsx_cell($column, $rowNumber, $value, 9, in_array($column, $numericColumns, true));
        }
        $sheetRows[] = xlsx_row($rowNumber++, $cells, 25);
    }
    if (count($records) > 1) {
        foreach (['J', 'K', 'L', 'M'] as $column) {
            $merges[] = $column . $firstEquipmentRow . ':' . $column . ($rowNumber - 1);
        }
    }
}
if (!$groups) {
    $sheetRows[] = xlsx_row($rowNumber, [xlsx_cell(1, $rowNumber, 'No hay registros para el período seleccionado.', 9)]);
    $rowNumber++;
}
$sheetRows[] = xlsx_row($rowNumber, [xlsx_cell(11, $rowNumber, 'TOTAL DEL PERÍODO', 1), xlsx_cell(12, $rowNumber, round($periodTotal, 2), 1, true)], 30);
$lastRow = $rowNumber;
$mergeXml = implode('', array_map(static fn (string $range): string => '<mergeCell ref="' . $range . '"/>', $merges));
$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:M' . $lastRow . '"/><sheetViews><sheetView workbookViewId="0" showGridLines="0" zoomScale="85"/></sheetViews><cols><col min="1" max="1" width="8" customWidth="1"/><col min="2" max="3" width="16" customWidth="1"/><col min="4" max="4" width="24" customWidth="1"/><col min="5" max="5" width="27" customWidth="1"/><col min="6" max="6" width="17" customWidth="1"/><col min="7" max="12" width="24" customWidth="1"/><col min="13" max="13" width="44" customWidth="1"/></cols><sheetData>' . implode('', $sheetRows) . '</sheetData><mergeCells count="' . count($merges) . '">' . $mergeXml . '</mergeCells></worksheet>';
$content = xlsx_package($sheet, 'Valorizaciones', true, true);
$fileName = 'valorizaciones_' . $site . '_' . $start . '_' . $end . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $content;
