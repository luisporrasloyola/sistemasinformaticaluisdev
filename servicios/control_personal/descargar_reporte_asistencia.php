<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_module_access('control_personal.reporte_asistencias');

use Dompdf\Dompdf;
use Dompdf\Options;

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
$assignment = $report['assignment']; $summary = $report['summary']; $rows = $report['individual_rows']; $note = $report['note'];
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$responsible = (string) ($note['responsible_name'] ?? (current_user()['name'] ?? 'Responsable'));
$signatureHtml = '';
$signatureRelative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($worker['signature_path'] ?? '')), DIRECTORY_SEPARATOR);
$signatureFile = $signatureRelative !== '' ? realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $signatureRelative) : false;
$appRoot = realpath(dirname(__DIR__, 2));
if ($signatureFile && $appRoot && str_starts_with($signatureFile, $appRoot . DIRECTORY_SEPARATOR) && is_file($signatureFile)) {
    $mime = mime_content_type($signatureFile) ?: '';
    if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        $signatureHtml = '<img class="signature-image" src="data:' . $h($mime) . ';base64,' . base64_encode((string) file_get_contents($signatureFile)) . '" alt="Firma">';
    }
}
$bodyRows = '';
foreach ($rows as $row) {
    $stateClass = 'state-' . preg_replace('/[^a-z_]/', '', (string) $row['state_key']);
    $bodyRows .= '<tr><td>' . date('d/m/Y', strtotime($row['date'])) . '</td><td>' . $h($row['weekday']) . '</td><td class="schedule">' . $h($row['schedule']) . '</td><td>' . $h($row['location']) . '</td><td>' . $h($row['entry']) . '</td><td>' . $h($row['exit']) . '</td><td>' . ($row['late_minutes'] ? $h(attendance_report_minutes_label((int) $row['late_minutes'])) : '-') . '</td><td>' . ($row['overtime_minutes'] ? $h(attendance_report_minutes_label((int) $row['overtime_minutes'])) : '-') . '</td><td><span class="state ' . $stateClass . '"><b>' . $h($row['state_code']) . '</b> ' . $h($row['state_label']) . '</span></td><td><span class="journey">' . $h($row['journey_label']) . '</span></td><td>' . $h($row['observation']) . '</td></tr>';
}
if ($bodyRows === '') $bodyRows = '<tr><td colspan="11" class="center">No hay jornadas en el periodo seleccionado.</td></tr>';
$html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
@page{size:A4 portrait;margin:22px 22px 32px}body{font-family:DejaVu Sans,sans-serif;color:#17233b;font-size:8px}h1{font-size:17px;margin:0;color:#10203d}.header{border-bottom:3px solid #2468e8;padding:4px}.muted{color:#61708a}.period{float:right;text-align:right}.profile,.metrics,.indicators{width:100%;border-collapse:separate;border-spacing:3px;margin:8px 0}.profile td,.metrics td,.indicators td{border:1px solid #d7dfeb;border-radius:4px;padding:6px;background:#f7f9fc}.profile td:first-child{border-left:3px solid #2468e8}.profile span,.metrics span,.indicators span{display:block;color:#65738a;font-size:6px;text-transform:uppercase}.profile strong,.metrics strong,.indicators strong{font-size:8px}.metrics strong{font-size:13px}.metrics td{border-top:3px solid #2468e8;background:#f1f6ff}.metrics td:nth-child(2){border-top-color:#13a561;background:#effbf5}.metrics td:nth-child(3){border-top-color:#e3a008;background:#fff9e8}.metrics td:nth-child(4){border-top-color:#e3343f;background:#fff1f2}.metrics td:nth-child(5){border-top-color:#7547e8;background:#f6f1ff}.metrics td:nth-child(6){border-top-color:#089bb7;background:#effbfc}.indicators td{background:#f4f7fc;border-left:3px solid #2468e8}.indicators strong{color:#174fae}.section{font-size:11px;margin:11px 0 5px;color:#10203d}table.detail{width:100%;border-collapse:collapse;table-layout:fixed;border:1px solid #d4dfec}table.detail th{background:#10203d;color:#fff;padding:5px 2px;font-size:5.7px;text-transform:uppercase;text-align:left}table.detail td{border-bottom:1px solid #dce3ed;padding:4px 2px;vertical-align:top;font-size:6.6px;word-wrap:break-word;text-align:left}table.detail tbody tr:nth-child(even) td{background:#f5f8fc}.schedule{white-space:nowrap}.state{display:inline-block;border-radius:3px;padding:2px 3px;background:#edf2f7;color:#334155}.state-attended{background:#dcfce7;color:#08783e}.state-late{background:#fff1b8;color:#8a5900}.state-early_exit{background:#ffedd5;color:#b74708}.state-late_early_exit{background:#ffe1ee;color:#c20c55}.state-absent{background:#ffe1e3;color:#c51e2b}.state-vacation{background:#ddebff;color:#1256c5}.state-permission{background:#eee3ff;color:#6530c7}.state-rest{background:#e3eaf2;color:#263b57}.state-holiday{background:#d8f7fb;color:#05758d}.state-non_working,.state-unscheduled{background:#eceae8;color:#57534e}.state-incomplete{background:#fee2e2;color:#b91c1c}.journey{color:#1d4f91;font-weight:bold}.center{text-align:center}.note{margin-top:9px;border:1px solid #cbd9ea;border-left:4px solid #2468e8;padding:7px;min-height:34px;background:#f7faff}.signatures{margin-top:25px;width:100%;text-align:center}.signatures td{width:50%;padding:0 32px}.line{border-top:1px solid #26354f;padding-top:5px}.signature-image{display:block;max-width:110px;max-height:38px;margin:0 auto 4px}.footer{position:fixed;bottom:-20px;left:0;right:0;text-align:center;color:#7b8799;font-size:7px}</style></head><body>
<div class="header"><div class="period"><b>Periodo</b><br>' . date('d/m/Y', strtotime($dateFrom)) . ' - ' . date('d/m/Y', strtotime($dateTo)) . '<br><span class="muted">Generado: ' . date('d/m/Y H:i') . '</span></div><h1>REPORTE INDIVIDUAL DE ASISTENCIA</h1><div class="muted">Life Maquinarias · Control de personal</div></div>
<table class="profile"><tr><td style="border-left:1px solid #d7dfeb"><span>Trabajador</span><strong>' . $h($worker['full_name']) . '</strong></td><td><span>Documento</span><strong>' . $h($worker['document_number']) . '</strong></td><td><span>Empresa</span><strong>' . $h($worker['company'] ?: '-') . '</strong></td><td><span>Cargo</span><strong>' . $h($worker['positions'] ?: '-') . '</strong></td></tr></table>
<table class="metrics"><tr><td><span>Días laborables</span><strong>' . (int)$summary['workdays'] . '</strong></td><td><span>Asistencias</span><strong>' . (int)$summary['attendances'] . '</strong></td><td><span>Tardanzas</span><strong>' . (int)$summary['late'] . '</strong></td><td><span>Faltas</span><strong>' . (int)$summary['absent'] . '</strong></td><td style="border-top-color:#276be8;background:#eef5ff"><span>Vacaciones</span><strong style="color:#111827">' . (int)$summary['vacations'] . '</strong></td><td><span>Horas trabajadas</span><strong>' . $h(attendance_report_minutes_label((int)$summary['worked_minutes'])) . '</strong></td></tr></table>
<table class="indicators"><tr><td style="border-left:1px solid #d7dfeb"><span>Puntualidad</span><strong>' . $h($summary['punctuality']) . '%</strong></td><td style="border-left:1px solid #d7dfeb"><span>Jornadas finalizadas</span><strong>' . $h($summary['compliance']) . '%</strong></td><td style="border-left:1px solid #d7dfeb"><span>Minutos de tardanza</span><strong>' . (int)$summary['late_minutes'] . ' min</strong></td><td style="border-left:1px solid #d7dfeb"><span>Horas extras estimadas</span><strong>' . $h(attendance_report_minutes_label((int)$summary['overtime_minutes'])) . '</strong></td></tr></table>
<h2 class="section">Detalle diario</h2><table class="detail"><thead><tr><th>Fecha</th><th>Día</th><th>Horario</th><th>Lugar</th><th>Entrada</th><th>Salida</th><th>Tardanza</th><th>H. extras</th><th>Asistencia</th><th>Jornada</th><th style="width:15%">Observación</th></tr></thead><tbody>' . $bodyRows . '</tbody></table>
<div class="note" style="border-left:1px solid #cbd9ea"><b>Observación general del responsable</b><br><br>' . $h($note['observation'] ?? 'Sin observaciones.') . '</div>
<table class="signatures" style="margin-top:65px"><tr><td><div class="line"><b>' . $h($responsible) . '</b><br><span class="muted">Responsable del reporte</span></div></td><td>' . $signatureHtml . '<div class="line"><b>' . $h($worker['full_name']) . '</b><br><span class="muted">Trabajador</span></div></td></tr></table>
<div class="footer">Documento generado por Life Maquinarias. La información corresponde al periodo seleccionado.</div></body></html>';
$options = new Options(); $options->set('defaultFont', 'DejaVu Sans'); $options->set('isRemoteEnabled', false);
$pdf = new Dompdf($options); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4', 'portrait'); $pdf->render();
$safeName = preg_replace('/[^a-z0-9_-]+/i', '_', (string) $worker['full_name']);
$pdf->stream('reporte_asistencia_' . trim((string)$safeName, '_') . '_' . $dateFrom . '_' . $dateTo . '.pdf', ['Attachment' => true]);
