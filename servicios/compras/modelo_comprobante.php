<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_module_access('compras');
require_module_access('compras.registro');

$models = [
    'lote' => ['Transferencia Interbank otros.png', 'image/png', 'modelo-transferencia-interbank-lote.png'],
    'pdf' => ['Depositos.pdf', 'application/pdf', 'modelo-compras-interbank.pdf'],
    'imagen' => ['Depositos.png', 'image/png', 'modelo-compras-interbank.png'],
    'plin' => ['Plin_otros.jpeg', 'image/jpeg', 'modelo-plin-constancia.jpeg'],
    'interbank_constancia' => ['transferencia Interbank-Otros.jpg', 'image/jpeg', 'modelo-interbank-constancia.jpg'],
    'interbank_oculto' => ['Tranferencia Interbank 2.jpeg', 'image/jpeg', 'modelo-interbank-destinatario-oculto.jpeg'],
    'pago_efectivo' => ['Pago efectivo.jpeg', 'image/jpeg', 'modelo-pago-efectivo.jpeg'],
    'transferencia_masiva' => ['TransferenciaOtros.jpeg', 'image/jpeg', 'modelo-transferencia-masiva.jpeg'],
];
$format = (string) ($_GET['formato'] ?? '');
if (!isset($models[$format])) {
    http_response_code(404);
    exit;
}

[$filename, $mime, $downloadName] = $models[$format];
$path = __DIR__ . '/../../img/depositosgemini/' . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($path));
readfile($path);
