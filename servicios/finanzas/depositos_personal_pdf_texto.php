<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/finanzas_depositos_personal_match.php';
require_module_access('finanzas.depositos_personal');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function deposit_pdf_reply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_admin()) deposit_pdf_reply(403, ['error' => 'Los lotes requieren una cuenta de administrador.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) deposit_pdf_reply(403, ['error' => 'Solicitud no autorizada.']);
$file = $_FILES['pdf'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) < 1000 || ($file['size'] ?? 0) > 1500000) deposit_pdf_reply(422, ['error' => 'Seleccione un PDF de hasta 1,5 MB.']);
$path = (string) $file['tmp_name'];
if ((new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf' || strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'pdf') deposit_pdf_reply(422, ['error' => 'El archivo no es un PDF válido.']);
$hash = hash_file('sha256', $path);
if (!$hash || !hash_equals($hash, strtolower(trim((string) ($_POST['original_hash'] ?? ''))))) deposit_pdf_reply(422, ['error' => 'El PDF no coincide con la lectura.']);
$submitted = json_decode((string) ($_POST['lectura'] ?? ''), true);
if (!is_array($submitted) || !is_array($submitted['rows'] ?? null) || count($submitted['rows']) < 2 || count($submitted['rows']) > 30) deposit_pdf_reply(422, ['error' => 'El texto del PDF no contiene un lote completo.']);
$date = (string) ($submitted['date'] ?? '');
$parsedDate = preg_match('/^\d{2}\/\d{2}\/\d{4}$/D', $date) ? DateTimeImmutable::createFromFormat('!d/m/Y', $date) : false;
if (!$parsedDate || $parsedDate->format('d/m/Y') !== $date) deposit_pdf_reply(422, ['error' => 'La fecha del PDF no es válida.']);
$declaredTotal = filter_var($submitted['total'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$declaredTotal) deposit_pdf_reply(422, ['error' => 'El total del PDF no es válido.']);

$lookup = db()->prepare('SELECT id, full_name FROM workers WHERE document_number = ? LIMIT 2');
$knownWorkers = personal_deposit_workers();
$records = [];
$sum = 0;
foreach ($submitted['rows'] as $row) {
    if (!is_array($row)) deposit_pdf_reply(422, ['error' => 'Una fila del PDF está incompleta.']);
    $document = trim((string) ($row['document'] ?? ''));
    $amount = filter_var($row['amount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!preg_match('/^\d{7,9}$/D', $document) || !$amount) deposit_pdf_reply(422, ['error' => 'Una fila del PDF no tiene DNI o monto válido.']);
    $lookup->execute([$document]);
    $workers = $lookup->fetchAll();
    $byName = personal_deposit_batch_worker(trim((string) ($row['name'] ?? '')), $knownWorkers);
    if (count($workers) === 1 && $byName && (int) $workers[0]['id'] !== $byName['id']) deposit_pdf_reply(422, ['error' => 'El nombre y el documento del PDF corresponden a personas diferentes.']);
    if (count($workers) !== 1 && !$byName) deposit_pdf_reply(422, ['error' => 'No se pudo vincular de forma única a un beneficiario del PDF.']);
    $worker = count($workers) === 1 ? ['id' => (int) $workers[0]['id'], 'full_name' => (string) $workers[0]['full_name']] : ['id' => $byName['id'], 'full_name' => $byName['creditor']];
    $sum += $amount;
    $records[] = [
        'fecha' => $date,
        'acreedor' => $worker['full_name'],
        'monto' => number_format($amount / 100, 2, '.', ''),
        'modo_pago' => 'Transferencia Interbank',
        'depositante' => 'Cuenta soles empresa',
        'worker_id' => $worker['id'],
    ];
}
if ($sum !== $declaredTotal) deposit_pdf_reply(422, ['error' => 'La suma de beneficiarios no coincide con el total del PDF.']);

$token = bin2hex(random_bytes(16));
$pending = array_filter($_SESSION['personal_deposit_batches'] ?? [], static fn(array $item): bool => ($item['expires'] ?? 0) > time());
if (count($pending) >= 5) array_shift($pending);
$pending[$token] = ['expires' => time() + 1200, 'original_hash' => $hash, 'records' => $records];
$_SESSION['personal_deposit_batches'] = $pending;
deposit_pdf_reply(200, ['model' => 'TRANSFERENCIA_INTERBANK_LOTE', 'records' => $records, 'batch_token' => $token]);
