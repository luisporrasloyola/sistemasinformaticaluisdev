<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/marking_report_data.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_module_access('control_personal.reportes');

use Dompdf\Dompdf;
use Dompdf\Options;

$today = date('Y-m-d'); $dateFrom = trim((string) ($_GET['desde'] ?? date('Y-m-01'))); $dateTo = trim((string) ($_GET['hasta'] ?? $today));
$workerId = (int) ($_GET['trabajador_id'] ?? 0); $companyId = (int) ($_GET['empresa_id'] ?? 0); $locationId = (int) ($_GET['punto_id'] ?? 0); $status = trim((string) ($_GET['estado'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) || $dateFrom > $dateTo) { http_response_code(422); exit('Periodo no válido.'); }
$rows = marking_report_build($dateFrom, $dateTo, $workerId, $companyId, $locationId, $status);
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$body = '';
foreach ($rows as $row) {
    $display = $row['display_status'] ?? $row['final_status'] ?? '';
    $class = match ($display) { 'puntual' => 'ok', 'tardanza' => 'warning', 'salida_valida' => 'exit', 'salida_anticipada' => 'early', 'tardanza_salida_anticipada' => 'critical', default => 'neutral' };
    $body .= '<tr><td>' . date('d/m/Y', strtotime($row['mark_date'])) . '</td><td>' . $h(marking_report_time($row['mark_time'])) . '</td><td>' . $h(ucfirst($row['mark_type'])) . '</td><td><b>' . $h($row['full_name']) . '</b><br><span>' . $h($row['document_number']) . '</span></td><td>' . $h($row['company'] ?? '-') . '</td><td>' . $h($row['location_name']) . '</td><td>' . $h(number_format((float) $row['distance_meters'], 2)) . ' m</td><td><span class="badge ' . $class . '">' . $h(marking_report_status_label($display)) . '</span></td><td>' . ($row['photo_path'] ? 'Sí' : 'No') . '</td></tr>';
}
if ($body === '') $body = '<tr><td colspan="9" class="empty">No hay marcaciones para los filtros seleccionados.</td></tr>';
$html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>@page{size:A4 landscape;margin:24px 26px 32px}body{font-family:DejaVu Sans,sans-serif;color:#17233b;font-size:8px}h1{font-size:19px;margin:0}.header{border-bottom:3px solid #2468e8;padding-bottom:8px}.period{float:right;text-align:right}.muted{color:#66758b}.summary{margin:10px 0;padding:8px;border:1px solid #d7e0ec;background:#f7f9fc}.summary b{font-size:12px}.detail{width:100%;border-collapse:collapse;table-layout:fixed}.detail th{background:#10203d;color:#fff;text-align:left;text-transform:uppercase;padding:6px 4px;font-size:6.5px}.detail td{border-bottom:1px solid #d9e2ed;padding:6px 4px;vertical-align:top}.detail tbody tr:nth-child(even){background:#f7f9fc}.detail span{color:#68768a}.badge{display:inline-block;padding:3px 5px;border-radius:3px;font-weight:bold}.ok{background:#dcfce7;color:#08783e}.warning{background:#fff1b8;color:#8a5900}.exit{background:#ddebff;color:#1256c5}.early{background:#ffedd5;color:#b74708}.critical{background:#ffe1ee;color:#c20c55}.neutral{background:#edf2f7;color:#334155}.empty{text-align:center;padding:20px}.footer{position:fixed;bottom:-20px;left:0;right:0;text-align:center;color:#7b8799;font-size:7px}</style></head><body><div class="header"><div class="period"><b>Periodo</b><br>' . date('d/m/Y', strtotime($dateFrom)) . ' - ' . date('d/m/Y', strtotime($dateTo)) . '<br><span class="muted">Generado: ' . date('d/m/Y H:i') . '</span></div><h1>REPORTE DE MARCACIONES</h1><div class="muted">Life Maquinarias · Control de personal</div></div><div class="summary"><b>' . count($rows) . ' marcaciones encontradas</b><br><span class="muted">El documento corresponde a los filtros seleccionados en el sistema.</span></div><table class="detail"><thead><tr><th>Fecha</th><th>Hora</th><th>Tipo</th><th>Personal</th><th>Empresa</th><th>Lugar</th><th>Distancia</th><th>Estado</th><th>Foto</th></tr></thead><tbody>' . $body . '</tbody></table><div class="footer">Documento generado por Life Maquinarias.</div></body></html>';
$options = new Options(); $options->set('defaultFont', 'DejaVu Sans'); $options->set('isRemoteEnabled', false);
$pdf = new Dompdf($options); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4', 'landscape'); $pdf->render();
$pdf->stream('reporte_marcaciones_' . $dateFrom . '_' . $dateTo . '.pdf', ['Attachment' => true]);
