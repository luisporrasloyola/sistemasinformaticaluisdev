<?php
require_once __DIR__ . '/../includes/security.php';
require_login();

$file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'CONTROL DE ASISTENCIA - LIFE MAQUINARIAS.xlsx';
if (!is_file($file)) {
    json_response(['ok' => false, 'message' => 'No se encontró el formato de ejemplo.'], 404);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="CONTROL_DE_ASISTENCIA_LIFE_MAQUINARIAS.xlsx"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($file);
exit;