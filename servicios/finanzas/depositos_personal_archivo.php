<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/finanzas_depositos_personal_lote.php';
require_any_module_access(['finanzas.depositos_personal', 'finanzas.gastos_personal']);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(400); exit('Archivo no válido.'); }
$stmt = db()->prepare('SELECT image_path, creditor, worker_id, batch_id, deposit_date, amount, payment_method, depositor,
    image_region_x1, image_region_y1, image_region_x2, image_region_y2
    FROM finance_personal_deposits WHERE id = ?');
$stmt->execute([$id]);
$deposit = $stmt->fetch(PDO::FETCH_ASSOC);
$relative = $deposit['image_path'] ?? null;
if (is_personal_role()) {
    require_once __DIR__ . '/../../includes/finanzas_depositos_personal_match.php';
    $ownsBatchRecord = $deposit && $deposit['batch_id'] !== null
        && (int) $deposit['worker_id'] === (int) current_user_worker_id();
    $ownsSingleRecord = $deposit && $deposit['batch_id'] === null
        && ($deposit['worker_id'] !== null
            ? (int) $deposit['worker_id'] === (int) current_user_worker_id()
            : personal_deposit_is_own((string) $deposit['creditor']));
    if (!$ownsBatchRecord && !$ownsSingleRecord) {
        http_response_code(403);
        exit('Solo puede consultar sus propios comprobantes.');
    }
}
if (!$relative || !str_starts_with((string) $relative, 'archivos/finanzas_depositos_personal/')) { http_response_code(404); exit('Archivo no encontrado.'); }
$root = realpath(UPLOAD_PATH . DIRECTORY_SEPARATOR . 'finanzas_depositos_personal');
$path = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relative);
if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) { http_response_code(404); exit('Archivo no encontrado.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) { http_response_code(404); exit('Archivo no válido.'); }
if (is_personal_role() && $deposit['batch_id'] !== null) {
    if ($mime === 'application/pdf' || ((int) $deposit['image_region_x1'] === 0 && (int) $deposit['image_region_y1'] === 0
        && (int) $deposit['image_region_x2'] === 0 && (int) $deposit['image_region_y2'] === 0)) {
        $safe = null;
        if ($mime === 'application/pdf') {
            require_once __DIR__ . '/../../includes/finanzas_depositos_personal_pdf.php';
            $idsStmt = db()->prepare('SELECT id FROM finance_personal_deposits WHERE batch_id = ? ORDER BY id');
            $idsStmt->execute([(int) $deposit['batch_id']]);
            $batchIds = array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN));
            $rowIndex = array_search($id, $batchIds, true);
            if ($rowIndex !== false) {
                $safe = personal_deposit_batch_redacted_pdf($path, $rowIndex, count($batchIds),
                    date('d/m/Y', strtotime((string) $deposit['deposit_date'])));
            }
        }
        if ($safe === null) {
        require_once __DIR__ . '/../../vendor/autoload.php';
        $esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $date = date('d/m/Y', strtotime((string) $deposit['deposit_date']));
        $html = '<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#17345d;margin:36px}h1{font-size:20px}table{width:100%;border-collapse:collapse;margin-top:24px}th,td{border:1px solid #b8c8df;padding:12px;text-align:left}th{background:#edf3fa;font-size:11px}td{font-size:12px}.note{color:#5d6b7b;font-size:11px;margin-top:20px}</style></head><body>'
            . '<h1>Comprobante de depósito personal</h1><table><tr><th>Fecha</th><th>Acreedor de depósito</th><th>Monto depositado</th><th>Modo de pago</th><th>Depositante</th></tr><tr>'
            . '<td>' . $esc($date) . '</td><td>' . $esc($deposit['creditor']) . '</td><td>S/ ' . number_format((float) $deposit['amount'], 2) . '</td><td>' . $esc($deposit['payment_method']) . '</td><td>' . $esc($deposit['depositor']) . '</td></tr></table>'
            . '<p class="note">Extracto individual del comprobante: se muestran únicamente los datos del trabajador autorizado.</p></body></html>';
        $pdf = new Dompdf\Dompdf();
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $safe = $pdf->output();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="deposito-' . $id . '-personal.pdf"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . strlen($safe));
        echo $safe;
        exit;
    }
    $region = personal_deposit_batch_region([
        (int) $deposit['image_region_x1'], (int) $deposit['image_region_y1'],
        (int) $deposit['image_region_x2'], (int) $deposit['image_region_y2'],
    ]);
    $greenLayout = false;
    if ($region) {
        $countStmt = db()->prepare('SELECT COUNT(*) FROM finance_personal_deposits WHERE batch_id = ?');
        $countStmt->execute([(int) $deposit['batch_id']]);
        $greenRegions = personal_deposit_batch_detect_green_regions($path, (int) $countStmt->fetchColumn());
        $greenLayout = $greenRegions !== null && in_array($region, $greenRegions, true);
    }
    $redacted = $region ? personal_deposit_batch_redacted_jpeg($path, $region, $greenLayout) : null;
    if ($redacted === null) { http_response_code(404); exit('No se puede mostrar el comprobante protegido.'); }
    header('Content-Type: image/jpeg');
    header('Content-Disposition: inline; filename="deposito-' . $id . '-personal.jpg"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . strlen($redacted));
    echo $redacted;
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="deposito-' . $id . '.' . ($mime === 'application/pdf' ? 'pdf' : ($mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/png' ? 'png' : 'webp'))) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($path));
readfile($path);
