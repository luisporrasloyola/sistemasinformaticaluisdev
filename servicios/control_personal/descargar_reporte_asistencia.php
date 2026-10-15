<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_module_access('control_personal.reporte_asistencias');

use Dompdf\Dompdf;
use Dompdf\Options;

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
$assignment = $report['assignment']; $summary = $report['summary']; $rows = $report['individual_rows']; $note = $report['note']; $trips = $report['trips'] ?? [];
$tripsByDate = attendance_report_trips_by_date($trips);
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$month = substr($dateFrom, 0, 7);
$monthNames = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$monthLabel = $monthNames[(int) substr($month, 5, 2)] . ' ' . substr($month, 0, 4);
$previousDate = (new DateTimeImmutable($dateFrom))->modify('-1 month');
$previousMonthLabel = $monthNames[(int) $previousDate->format('n')] . ' ' . $previousDate->format('Y');
$days = $report['monthly_day_summary'][$month] ?? [];
$book = attendance_report_build_hours_ledger($workerId, $month, $report['daily_classification']);
$hours = $book['current'];
$workedDays = (int) ($days['worked'] ?? 0);
$completedDays = 0;
foreach ($report['daily_classification'] as $classifiedDay) {
    if (str_starts_with((string) $classifiedDay['date'], $month) && !empty($classifiedDay['worked']) && !empty($classifiedDay['has_exit'])) $completedDays++;
}
$punctuality = $workedDays > 0 ? round(100 * ($workedDays - (int) ($days['late'] ?? 0)) / $workedDays) : 0;
$completion = $workedDays > 0 ? round(100 * $completedDays / $workedDays) : 0;
$hoursLabel = static fn(string $key): string => attendance_report_hours_label((int) ($hours[$key] ?? 0));
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
$markCell = static function (array $row, string $type) use ($h): string {
    $value = $h($row[$type]);
    if ($type === 'exit' && !empty($row['exit_next_day'])) $value .= '<br><span class="muted">Día siguiente</span>';
    if (!$row[$type . '_administrative']) return $value;
    return $value . '<br><b>Administrativa</b><br><span>Por ' . $h($row[$type . '_administrative_actor'] ?: 'Administrador')
        . '. Motivo: ' . $h($row[$type . '_administrative_reason'] ?: '-') . '</span>';
};
$bodyRows = '';
foreach ($rows as $row) {
    $stateClass = 'state-' . preg_replace('/[^a-z_]/', '', (string) $row['state_key']);
    $entryRoute = $h($row['entry_location']);
    foreach ($tripsByDate[(string) $row['date']] ?? [] as $trip) {
        $entryRoute .= '<br><span class="route-step">→ ' . $h($trip['first_destination']) . '</span>';
    }
    $overtimeCell = $row['overtime_minutes'] > 0
        ? $h(attendance_report_minutes_label((int) $row['overtime_minutes']))
            . '<br><span class="muted">'
            . 'Entrada' . (!empty($row['holiday_double_applied']) && $row['early_overtime_minutes'] > 0 ? ' ×2' : '') . ': ' . $h(attendance_report_minutes_label((int) $row['early_overtime_minutes'])) . '<br>'
            . 'Salida' . (!empty($row['holiday_double_applied']) && $row['exit_overtime_minutes'] > 0 ? ' ×2' : '') . ': ' . $h(attendance_report_minutes_label((int) $row['exit_overtime_minutes'])) . '</span>'
        : '-';
    $workedCell = ($row['entry'] !== '-' && $row['exit'] !== '-') || !empty($row['holiday_schedule_credit']) ? $h(attendance_report_minutes_label((int) $row['worked_minutes'])) : '-';
    if (!empty($row['holiday_double_applied']) && $workedCell !== '-') $workedCell .= '<br><span class="muted">Horario ×2 por feriado</span>';
    if (!empty($row['holiday_schedule_credit'])) $workedCell .= '<br><span class="muted">Crédito de feriado según horario</span>';
    if (!empty($row['lunch_deduction_minutes'])) $workedCell .= '<br><span class="muted">Almuerzo −' . $h(attendance_report_minutes_label((int) $row['lunch_deduction_minutes'])) . '</span>';
    $bodyRows .= '<tr><td>' . date('d/m/Y', strtotime($row['date'])) . '</td><td>' . $h($row['weekday']) . '</td><td class="schedule">' . $h($row['schedule']) . '</td><td>' . ($row['tolerance_minutes'] !== null ? (int)$row['tolerance_minutes'].' min' : '-') . '</td><td>' . $h($row['project'] ?? '-') . '</td><td>' . $entryRoute . '</td><td>' . $h($row['exit_location']) . '</td><td>' . $markCell($row, 'entry') . '</td><td>' . $markCell($row, 'exit') . '</td><td>' . ($row['late_minutes'] ? $h(attendance_report_minutes_label((int) $row['late_minutes'])) : '-') . '</td><td>' . $overtimeCell . '</td><td>' . $workedCell . '</td><td><span class="state ' . $stateClass . '"><b>' . $h($row['state_code']) . '</b></span></td><td><span class="journey">' . $h($row['journey_label']) . '</span></td></tr>';
}
if ($bodyRows === '') $bodyRows = '<tr><td colspan="14" class="center">No hay jornadas en el periodo seleccionado.</td></tr>';
$tripSection = '';
if ($trips) {
    $tripRows = '';
    foreach ($trips as $trip) {
        $incident = ($trip['completion_type'] ?? '') === 'returned_without_arrival';
        $registered = ($trip['status'] ?? '') === 'registrado';
        $project = ($trip['project_name'] ?: ($registered ? '-' : (($trip['status'] ?? '') !== 'finalizado' ? 'Pendiente' : '-'))) . ($incident ? ' | Llegada no confirmada: ' . ($trip['exception_reason'] ?: 'Sin detalle') : '');
        $status = $registered ? 'Registrado' : (($trip['status'] ?? '') !== 'finalizado' ? 'En curso' : ($incident ? 'Regreso con incidencia' : 'Finalizado'));
        $tripRows .= '<tr><td>' . date('d/m/Y', strtotime($trip['trip_date'])) . '</td><td>' . $h($trip['schedule_label']) . '</td><td>' . date('H:i', strtotime($trip['started_at'])) . '</td><td>' . ($trip['ended_at'] ? date('H:i', strtotime($trip['ended_at'])) : '-') . '</td><td>' . $h($trip['duration_label']) . '</td><td>' . $h($trip['location_name']) . '</td><td>' . $h($trip['first_destination']) . '</td><td>' . $h($project) . '</td><td>' . $h($status) . '</td></tr>';
    }
    $tripSection = '<h2 class="section">Desplazamientos laborales</h2><table class="detail"><thead><tr><th>Fecha</th><th>Horario</th><th>Inicio</th><th>Fin</th><th>Duración</th><th>Origen</th><th>Destino</th><th>Proyecto</th><th>Estado</th></tr></thead><tbody>' . $tripRows . '</tbody></table>';
}
ob_start();
?>
<table class="monthly-head"><tr>
    <td class="monthly-worker"><span>REPORTE INDIVIDUAL</span><b><?= $h($worker['full_name']) ?></b><small><?= date('d/m/Y', strtotime($dateFrom)) ?> al <?= date('d/m/Y', strtotime($dateTo)) ?></small></td>
    <td class="monthly-person-data">
        <div>Documento: <?= $h(($worker['document_type'] ?: 'Documento') . ' - ' . $worker['document_number']) ?></div>
        <div>Empresa: <?= $h($worker['company'] ?: 'Sin empresa') ?></div>
        <div>Cargo: <?= $h($worker['positions'] ?: 'Sin cargo registrado') ?></div>
        <div>Horario: <?= $h($assignment['schedule_name'] ?? 'Sin horario asignado') ?></div>
    </td>
    <td class="monthly-count"><?= count($report['daily_classification']) ?> días registrados</td>
</tr></table>
<table class="monthly-rates"><tr><td>Puntualidad: <b><?= $punctuality ?>%</b></td><td>Jornadas finalizadas: <b><?= $completion ?>%</b></td></tr></table>
<table class="monthly-days"><tr>
    <td class="monthly-work-column">
        <div class="monthly-worked">Días trabajados: <b><?= $workedDays ?></b></div>
        <table class="monthly-mini"><tr>
            <td>Asistencia regular<b><?= (int) ($days['regular'] ?? 0) ?></b></td>
            <td>No concluidos<b><?= (int) ($days['incomplete'] ?? 0) ?></b></td>
            <td>Tardanzas<b><?= (int) ($days['late'] ?? 0) ?></b></td>
        </tr></table>
    </td>
    <td class="monthly-other-column">
        <table class="monthly-mini monthly-special"><tr>
            <td>Feriados<b><?= (int) ($days['holiday'] ?? 0) ?> días</b></td>
            <td>No laborables<b><?= (int) ($days['non_working'] ?? 0) ?> días</b></td>
            <td>Vacaciones<b><?= (int) ($days['vacation'] ?? 0) ?> días</b></td>
            <td>Faltas<b><?= (int) ($days['absent'] ?? 0) ?> días</b></td>
        </tr></table>
    </td>
    <td class="monthly-rest-column">
        <div class="monthly-rest-total">Descansos: <b><?= (int) ($days['rest'] ?? 0) ?> días</b></div>
        <table class="monthly-mini"><tr>
            <td>A cuenta de horas<b><?= (int) ($days['rest_hours'] ?? 0) ?> días</b></td>
            <td>Régimen laboral<b><?= (int) ($days['rest_regime'] ?? 0) ?> días</b></td>
        </tr></table>
        <?php if (($days['rest_unclassified'] ?? 0) > 0): ?><small>Sin clasificar: <?= (int) $days['rest_unclassified'] ?></small><?php endif; ?>
    </td>
</tr></table>
<?php if (($days['pending'] ?? 0) > 0): ?><div class="monthly-footnote"><?= (int) $days['pending'] ?> día(s) pendientes no se cuentan como faltas.</div><?php endif; ?>
<div class="monthly-worked-hours">Horas trabajadas: <b><?= $h($hoursLabel('worked_minutes')) ?></b></div>
<table class="monthly-hour-ledger"><tr>
    <td class="monthly-hour-source">
        <div class="monthly-hour-card"><span>H. canjeadas · <?= $h($monthLabel) ?></span><b><?= $h($hoursLabel('redeemed_minutes')) ?></b><small><?= (int) ($hours['redeemed_saturday_days'] ?? 0) ?> sábado(s) × 6 h + <?= (int) ($hours['redeemed_days'] - ($hours['redeemed_saturday_days'] ?? 0)) ?> otro(s) día(s) × 8 h 30 min</small></div>
        <div class="monthly-hour-card"><span>HEMP · <?= $h($previousMonthLabel) ?></span><b><?= $h($hoursLabel('opening_minutes')) ?></b><small><?= $month === '2026-10' && !empty($book['opening_adjustment']) ? 'Saldo ajustado para octubre' : 'Saldo del mes anterior' ?></small></div>
    </td>
    <td class="monthly-hour-extra">
        <table><tr>
            <td><div class="monthly-hour-card">Extra por entrada<b><?= $h($hoursLabel('early_overtime_minutes')) ?></b></div><div class="monthly-hour-card">Extra por salida<b><?= $h($hoursLabel('exit_overtime_minutes')) ?></b></div></td>
            <td><div class="monthly-hour-card monthly-hour-total"><span>Horas extras · <?= $h($monthLabel) ?></span><b><?= $h($hoursLabel('overtime_minutes')) ?></b></div></td>
        </tr></table>
    </td>
    <td class="monthly-hour-balance"><div class="monthly-hour-card"><span>Horas extras vigentes</span><b><?= $h($hoursLabel('closing_minutes')) ?></b><small>HEMP + extras del mes − horas canjeadas</small></div></td>
</tr></table>
<?php if ($hours['planned_redemption_days'] > 0): ?><div class="monthly-footnote"><?= (int) $hours['planned_redemption_days'] ?> día(s) «A cuenta de horas» futuros aún no descontados.</div><?php endif; ?>
<?php
$monthlyHtml = ob_get_clean();
$html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
@page{size:A4 landscape;margin:22px 22px 32px}body{font-family:DejaVu Sans,sans-serif;color:#17233b;font-size:8px}h1{font-size:17px;margin:0;color:#10203d}.header{border-bottom:3px solid #2468e8;padding:4px}.muted{color:#61708a}.period{float:right;text-align:right}.section{font-size:11px;margin:11px 0 5px;color:#10203d}table.detail{width:100%;border-collapse:collapse;table-layout:fixed;border:1px solid #d4dfec}table.detail th{background:#10203d;color:#fff;padding:5px 2px;font-size:5.7px;text-transform:uppercase;text-align:left}table.detail td{border-bottom:1px solid #dce3ed;padding:4px 2px;vertical-align:top;font-size:6.6px;word-wrap:break-word;text-align:left}table.detail tbody tr:nth-child(even) td{background:#f5f8fc}.schedule{white-space:nowrap}.state{display:inline-block;border-radius:3px;padding:2px 3px;background:#edf2f7;color:#334155}.state-attended{background:#dcfce7;color:#08783e}.state-late{background:#fff1b8;color:#8a5900}.state-early_exit{background:#ffedd5;color:#b74708}.state-late_early_exit{background:#ffe1ee;color:#c20c55}.state-absent{background:#ffe1e3;color:#c51e2b}.state-vacation{background:#ddebff;color:#1256c5}.state-permission{background:#eee3ff;color:#6530c7}.state-rest{background:#e3eaf2;color:#263b57}.state-holiday{background:#d8f7fb;color:#05758d}.state-non_working,.state-unscheduled{background:#eceae8;color:#57534e}.state-incomplete{background:#fee2e2;color:#b91c1c}.journey{color:#1d4f91;font-weight:bold}.center{text-align:center}.note{margin-top:9px;border:1px solid #cbd9ea;border-left:4px solid #2468e8;padding:7px;min-height:34px;background:#f7faff}.signatures{margin-top:25px;width:100%;text-align:center}.signatures td{width:50%;padding:0 32px}.line{border-top:1px solid #26354f;padding-top:5px}.signature-image{display:block;max-width:110px;max-height:38px;margin:0 auto 4px}.footer{position:fixed;bottom:-20px;left:0;right:0;text-align:center;color:#7b8799;font-size:7px}
table.monthly-head,table.monthly-rates,table.monthly-days,table.monthly-hour-ledger{width:100%;border-collapse:separate;border-spacing:4px;table-layout:fixed}.monthly-head td{vertical-align:top;padding:5px}.monthly-worker{width:24%;border-right:2px solid #183152}.monthly-worker span,.monthly-worker b,.monthly-worker small{display:block}.monthly-worker span{color:#2468e8;font-size:7px;font-weight:bold}.monthly-worker b{font-size:11px}.monthly-worker small{margin-top:5px;color:#607089}.monthly-person-data{width:60%;line-height:1.5}.monthly-count{width:16%;text-align:right;font-weight:bold;background:#f4f7fc;border:1px solid #d1deed;border-radius:5px}.monthly-rates{margin:4px 0 5px}.monthly-rates td{text-align:center;padding:5px;background:#f3f6fa;border:1px solid #8898ac;border-radius:15px}.monthly-days{margin:2px 0 4px}.monthly-days>tbody>tr>td{vertical-align:top}.monthly-work-column{width:26%}.monthly-other-column{width:44%}.monthly-rest-column{width:30%}.monthly-worked,.monthly-rest-total{text-align:center;padding:6px;border:1px solid #399576;border-radius:15px;background:#f0faf5;font-weight:bold}.monthly-rest-total{border-color:#ed9f61;background:#f7f9fc}.monthly-mini{width:100%;table-layout:fixed;border-spacing:3px;margin-top:3px}.monthly-mini td{text-align:center;vertical-align:middle;height:47px;padding:4px 2px;border:1px solid #a8a3ce;border-radius:12px;background:#f4f7fc;font-size:7px;font-weight:bold}.monthly-mini b{display:block;margin-top:5px;font-size:10px}.monthly-special td{border-color:#edaa72;height:68px}.monthly-rest-column .monthly-mini td{border-color:#edaa72}.monthly-footnote{margin:2px 4px;color:#61708a;font-size:6.5px}.monthly-worked-hours{text-align:center;margin:5px 4px;padding:6px;border:2px solid #298462;border-radius:15px;background:#f0faf5;font-weight:bold;font-size:9px}.monthly-hour-ledger{margin:3px 0 6px}.monthly-hour-ledger>tbody>tr>td{vertical-align:top}.monthly-hour-source{width:25%}.monthly-hour-extra{width:46%;background:#e8ebef;border-radius:10px}.monthly-hour-balance{width:29%}.monthly-hour-card{text-align:center;padding:6px 4px;margin-bottom:4px;border:1px solid #9db5ce;border-radius:10px;background:#f4f7fc;font-size:7px}.monthly-hour-card span,.monthly-hour-card b,.monthly-hour-card small{display:block}.monthly-hour-card b{margin-top:4px;font-size:12px}.monthly-hour-card small{font-size:6px;color:#65738a}.monthly-hour-extra table{width:100%;table-layout:fixed}.monthly-hour-extra td{width:50%;vertical-align:middle}.monthly-hour-extra .monthly-hour-card{margin:2px}.monthly-hour-total{border:2px solid #298462}.monthly-hour-balance .monthly-hour-card{border:2px solid #ed8439;padding:19px 4px}.monthly-hour-balance b{font-size:14px}
</style></head><body>
<div class="header"><div class="period"><b>Periodo</b><br>' . date('d/m/Y', strtotime($dateFrom)) . ' - ' . date('d/m/Y', strtotime($dateTo)) . '<br><span class="muted">Generado: ' . date('d/m/Y H:i') . '</span></div><h1>REPORTE INDIVIDUAL DE ASISTENCIA</h1><div class="muted">Life Maquinarias · Control de personal</div></div>
' . $monthlyHtml . '
<div class="muted">Si la salida supera los 15 minutos de tolerancia, se cuenta todo el tiempo desde la hora programada.</div><h2 class="section">Detalle diario</h2><table class="detail"><thead><tr><th>Fecha</th><th>Día</th><th>Horario</th><th>Tolerancia</th><th style="width:10%">Proyecto</th><th>Lugar de entrada</th><th>Lugar de salida</th><th>Entrada</th><th>Salida</th><th>Tardanza</th><th>Horas extras</th><th>Horas trabajadas</th><th>Asistencia</th><th>Jornada</th></tr></thead><tbody>' . $bodyRows . '</tbody></table>
' . $tripSection . '
<div class="note" style="border-left:1px solid #cbd9ea"><b>Observación general del responsable</b><br><br>' . $h($note['observation'] ?? 'Sin observaciones.') . '</div>
<table class="signatures" style="margin-top:65px"><tr><td><div class="line"><b>' . $h($responsible) . '</b><br><span class="muted">Responsable del reporte</span></div></td><td>' . $signatureHtml . '<div class="line"><b>' . $h($worker['full_name']) . '</b><br><span class="muted">Trabajador</span></div></td></tr></table>
<div class="footer">Documento generado por Life Maquinarias. La información corresponde al periodo seleccionado.</div></body></html>';
$options = new Options(); $options->set('defaultFont', 'DejaVu Sans'); $options->set('isRemoteEnabled', false);
$pdf = new Dompdf($options); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4', 'landscape'); $pdf->render();
$safeName = preg_replace('/[^a-z0-9_-]+/i', '_', (string) $worker['full_name']);
$pdf->stream('reporte_asistencia_' . trim((string)$safeName, '_') . '_' . $dateFrom . '_' . $dateTo . '.pdf', ['Attachment' => true]);
