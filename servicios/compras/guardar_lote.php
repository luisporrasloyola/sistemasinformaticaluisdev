<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../includes/finanzas_depositos_personal_lote.php';
require_module_access('compras');
require_module_access('compras.registro');
if (is_personal_role()) { http_response_code(403); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function deposit_batch_reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    deposit_batch_reply(403, ['error' => 'Solicitud no autorizada.']);
}
$token = (string) ($_POST['batch_token'] ?? '');
$pending = $_SESSION['purchase_record_batches'][$token] ?? null;
if (!preg_match('/^[a-f0-9]{32}$/D', $token) || !is_array($pending) || ($pending['expires'] ?? 0) < time()) {
    deposit_batch_reply(422, ['error' => 'La lectura del lote venció. Vuelva a seleccionar la foto.']);
}
$entries = $pending['records'] ?? null;
if (!is_array($entries) || count($entries) !== count($pending['records']) || count($entries) < 2 || count($entries) > 30) {
    deposit_batch_reply(422, ['error' => 'El lote debe conservar todos los beneficiarios leídos.']);
}
if (!empty($pending['review_required'])) {
    $corrections = json_decode((string) ($_POST['records'] ?? ''), true);
    if (!is_array($corrections) || array_keys($corrections) !== array_keys($entries)) {
        deposit_batch_reply(422, ['error' => 'Revise todos los beneficiarios antes de guardar el lote.']);
    }
    $workerNameStmt = db()->prepare('SELECT full_name FROM workers WHERE id = ? LIMIT 1');
    foreach ($corrections as $index => $correction) {
        if (!is_array($correction)) deposit_batch_reply(422, ['error' => 'La revisión del lote está incompleta.']);
        $workerId = filter_var($correction['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$workerId) deposit_batch_reply(422, ['error' => 'Seleccione el trabajador de cada beneficiario.']);
        $workerNameStmt->execute([$workerId]);
        $workerName = $workerNameStmt->fetchColumn();
        if (!$workerName) deposit_batch_reply(422, ['error' => 'Uno de los trabajadores seleccionados ya no existe.']);
        $entries[$index]['fecha'] = trim((string) ($correction['fecha'] ?? ''));
        $entries[$index]['monto'] = trim((string) ($correction['monto'] ?? ''));
        $entries[$index]['worker_id'] = $workerId;
        $entries[$index]['acreedor'] = (string) $workerName;
    }
}
$photo = $_FILES['foto'] ?? null;
if (!is_array($photo) || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || ($photo['size'] ?? 0) < 1000 || ($photo['size'] ?? 0) > 5 * 1024 * 1024) {
    deposit_batch_reply(422, ['error' => 'La foto debe ocupar entre 1 KB y 5 MB.']);
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $photo['tmp_name']);
$isPdf = $mime === 'application/pdf' && strtolower(pathinfo((string) ($photo['name'] ?? ''), PATHINFO_EXTENSION)) === 'pdf';
$imageInfo = $isPdf ? null : @getimagesize((string) $photo['tmp_name']);
if (!$isPdf && (!$imageInfo || !in_array($imageInfo['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
    || $imageInfo[0] * $imageInfo[1] > 8000000)) {
    deposit_batch_reply(422, ['error' => 'Seleccione una imagen JPG, PNG o WEBP válida de hasta 8 megapíxeles, o un PDF.']);
}
$hash = hash_file('sha256', (string) $photo['tmp_name']);
if (!$hash || !hash_equals((string) $pending['original_hash'], $hash)) {
    deposit_batch_reply(422, ['error' => 'La foto no coincide con la lectura revisada.']);
}
$regions = $isPdf ? null : personal_deposit_batch_detect_regions((string) $photo['tmp_name'], count($entries));
$greenLayout = false;
if (!$isPdf && $regions === null) {
    $regions = personal_deposit_batch_detect_green_regions((string) $photo['tmp_name'], count($entries));
    $greenLayout = $regions !== null;
}
// Para formatos sin límites de fila verificables se guarda el original para
// administración y el personal recibe un extracto individual.
$summaryOnly = $regions === null;
if ($summaryOnly) $regions = array_fill(0, count($entries), [0, 0, 0, 0]);
$validated = [];
$workerIds = [];
foreach ($entries as $index => $entry) {
    if (!is_array($entry) || !isset($pending['records'][$index])) deposit_batch_reply(422, ['error' => 'Registro de lote inválido.']);
    $date = trim((string) ($entry['fecha'] ?? ''));
    $parsed = preg_match('/^\d{2}\/\d{2}\/\d{4}$/D', $date) ? DateTimeImmutable::createFromFormat('!d/m/Y', $date) : false;
    $creditor = trim((string) ($entry['acreedor'] ?? ''));
    $amount = trim((string) ($entry['monto'] ?? ''));
    $depositor = trim((string) ($entry['depositante'] ?? ''));
    $workerId = filter_var($entry['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $region = $regions[$index] ?? null;
    if (!$parsed || $parsed->format('d/m/Y') !== $date || $creditor === '' || mb_strlen($creditor) > 150
        || !preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $amount) || (float) $amount <= 0
        || mb_strlen($depositor) > 150 || !$workerId || !$region) {
        deposit_batch_reply(422, ['error' => 'Complete fecha, trabajador, acreedor, monto y recorte de cada fila.']);
    }
    $date = $parsed->format('Y-m-d');
    if (!$summaryOnly && personal_deposit_batch_redacted_jpeg((string) $photo['tmp_name'], $region, $greenLayout) === null) {
        deposit_batch_reply(422, ['error' => 'No se pudo proteger la imagen de uno de los beneficiarios.']);
    }
    $workerIds[] = $workerId;
    $validated[] = compact('date', 'creditor', 'amount', 'depositor', 'workerId', 'region');
}
foreach ($summaryOnly ? [] : $validated as $i => $entry) {
    foreach (array_slice($validated, $i + 1) as $other) {
        $a = $entry['region']; $b = $other['region'];
        if (min($a[2], $b[2]) > max($a[0], $b[0]) && min($a[3], $b[3]) > max($a[1], $b[1])) {
            deposit_batch_reply(422, ['error' => 'Los recortes de beneficiarios se superponen. Corrija las filas antes de guardar.']);
        }
    }
}
$ids = array_values(array_unique($workerIds));
$marks = implode(',', array_fill(0, count($ids), '?'));
$workerStmt = db()->prepare("SELECT id FROM workers WHERE id IN ($marks)");
$workerStmt->execute($ids);
if (count($workerStmt->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) deposit_batch_reply(422, ['error' => 'Uno de los trabajadores seleccionados ya no existe.']);

$uploadedPath = null;
try {
    $db = db();
    $db->beginTransaction();
    $existing = $db->prepare('SELECT id FROM purchase_record_batches WHERE image_sha256 = ? LIMIT 1');
    $existing->execute([$hash]);
    $single = $db->prepare('SELECT id FROM purchase_records WHERE image_sha256 = ? LIMIT 1');
    $single->execute([$hash]);
    if ($existing->fetchColumn() || $single->fetchColumn()) {
        $db->rollBack();
        deposit_batch_reply(409, ['error' => 'Esta foto ya fue registrada.', 'duplicate' => true]);
    }
    $uploaded = upload_file($photo, 'compras_registro', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    $uploadedPath = $uploaded['path'];
    $batch = $db->prepare('INSERT INTO purchase_record_batches (image_path, image_sha256, image_original_name) VALUES (?, ?, ?)');
    $batch->execute([$uploadedPath, $hash, $uploaded['name']]);
    $batchId = (int) $db->lastInsertId();
    $insert = $db->prepare('INSERT INTO purchase_records
        (purchase_date, registration_source, creditor, depositor, amount, payment_method,
         image_path, image_original_name, image_sha256, registered_by_user_id,
         batch_id, worker_id, image_region_x1, image_region_y1, image_region_x2, image_region_y2)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $saved = [];
    foreach ($validated as $entry) {
        [$x1, $y1, $x2, $y2] = $entry['region'];
        $insert->execute([$entry['date'], 'gemini', $entry['creditor'], $entry['depositor'] ?: null,
            $entry['amount'], 'Transferencia Interbank', $uploadedPath, $uploaded['name'], null,
            (int) (current_user()['id'] ?? 0), $batchId, $entry['workerId'], $x1, $y1, $x2, $y2]);
        $saved[] = ['id' => (int) $db->lastInsertId(), 'fecha' => $entry['date'],
            'acreedor' => $entry['creditor'], 'monto' => $entry['amount']];
    }
    $db->commit();
    unset($_SESSION['purchase_record_batches'][$token]);
    deposit_batch_reply(201, ['records' => $saved]);
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    if ($uploadedPath) delete_uploaded_file($uploadedPath);
    if ($error instanceof PDOException && $error->getCode() === '23000') {
        deposit_batch_reply(409, ['error' => 'Esta foto ya fue registrada.', 'duplicate' => true]);
    }
    error_log('Error al registrar lote de compras: ' . $error->getMessage());
    deposit_batch_reply(500, ['error' => 'No se pudo guardar el lote. Verifique que la migración SQL esté instalada.']);
}
