<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function hour_purchase_export_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$data = file_get_contents($root . '/includes/attendance_report_data.php');
$screen = file_get_contents($root . '/modulos/control_personal/reporte_asistencias.php');
$excel = file_get_contents($root . '/servicios/control_personal/descargar_reporte_asistencia_excel.php');
$pdf = file_get_contents($root . '/servicios/control_personal/descargar_reporte_asistencia.php');

foreach ([
    'function attendance_report_hour_purchases',
    'attendance_hour_purchases p',
    "COALESCE(u.name, \\'\\') AS registered_by",
    'p.purchase_date BETWEEN ? AND ?',
] as $required) {
    hour_purchase_export_check(str_contains($data, $required), 'Falta la consulta segura de horas compradas: ' . $required);
}
hour_purchase_export_check(
    str_contains($screen, 'attendance_report_hour_purchases($workerId, $dateFrom, $dateTo)'),
    'La pantalla debe usar la misma consulta de horas compradas.'
);
foreach ([$excel, $pdf] as $export) {
    foreach (['Horas compradas', 'Horas decimales', 'Precio por hora', 'Registrado por'] as $required) {
        hour_purchase_export_check(str_contains($export, $required), 'Falta la columna exportable: ' . $required);
    }
    hour_purchase_export_check(
        str_contains($export, 'attendance_report_hour_purchases($workerId, $dateFrom, $dateTo)'),
        'La exportación debe consultar las horas compradas del período solicitado.'
    );
}

echo "Exportación de horas compradas verificada.\n";
