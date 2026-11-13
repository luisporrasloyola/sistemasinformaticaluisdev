<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload.php';
require_module_access('compras');
require_module_access('compras.registro');
if (is_personal_role()) { http_response_code(403); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function deposit_save_reply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) deposit_save_reply(403, ['error' => 'Solicitud no autorizada.']);
$date = trim((string) ($_POST['fecha'] ?? ''));
$creditor = trim((string) ($_POST['acreedor'] ?? ''));
$amount = trim((string) ($_POST['monto'] ?? ''));
$method = trim((string) ($_POST['modo_pago'] ?? ''));
$workerId = null;
$depositor = trim((string) ($_POST['depositante'] ?? ''));
$workerId = filter_var($_POST['trabajador_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$workerId) deposit_save_reply(422, ['error' => 'Seleccione un trabajador.']);
$workerStmt = db()->prepare('SELECT full_name FROM workers WHERE id = ? LIMIT 1');
$workerStmt->execute([$workerId]);
$workerName = $workerStmt->fetchColumn();
if (!$workerName) deposit_save_reply(422, ['error' => 'El trabajador seleccionado ya no existe.']);
$creditor = (string) $workerName;
$parsedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) deposit_save_reply(422, ['error' => 'Ingrese una fecha válida.']);
if ($creditor === '' || mb_strlen($creditor) > 150) deposit_save_reply(422, ['error' => 'Ingrese el acreedor (máximo 150 caracteres).']);
if ($depositor === '' || mb_strlen($depositor) > 150) deposit_save_reply(422, ['error' => 'Ingrese el depositante (máximo 150 caracteres).']);
if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $amount) || (float) $amount <= 0) deposit_save_reply(422, ['error' => 'Ingrese un monto válido en soles.']);
if ($method === '' || mb_strlen($method) > 100) deposit_save_reply(422, ['error' => 'Ingrese un modo de pago (máximo 100 caracteres).']);
$photo = $_FILES['foto'] ?? null;
$hasPhoto = is_array($photo) && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
$hash = null;
if ($hasPhoto) {
    if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($photo['size'] ?? 0) < 1000 || ($photo['size'] ?? 0) > 5 * 1024 * 1024) deposit_save_reply(422, ['error' => 'La foto debe ocupar entre 1 KB y 5 MB.']);
    $info = @getimagesize((string) $photo['tmp_name']);
    if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) deposit_save_reply(422, ['error' => 'Seleccione una foto JPG, PNG o WEBP válida.']);
    $hash = hash_file('sha256', (string) $photo['tmp_name']);
    if ($hash === false) deposit_save_reply(422, ['error' => 'No se pudo leer la foto.']);
}

$newPath = null;
try {
    $uploaded = null;
    if ($hasPhoto) {
        $existing = db()->prepare('SELECT id FROM purchase_records WHERE image_sha256 = ? LIMIT 1');
        $existing->execute([$hash]);
        if ($existing->fetchColumn()) deposit_save_reply(409, ['error' => 'Esta foto ya fue registrada.', 'duplicate' => true]);
        $batch = db()->prepare('SELECT id FROM purchase_record_batches WHERE image_sha256 = ? LIMIT 1');
        $batch->execute([$hash]);
        if ($batch->fetchColumn()) deposit_save_reply(409, ['error' => 'Esta foto ya fue registrada como lote.', 'duplicate' => true]);
        $uploaded = upload_file($photo, 'compras_registro', ['image/jpeg', 'image/png', 'image/webp']);
        $newPath = $uploaded['path'];
    }
    db()->prepare('INSERT INTO purchase_records (purchase_date, registration_source, creditor, depositor, amount, payment_method, image_path, image_original_name, image_sha256, registered_by_user_id, worker_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$date, 'manual', $creditor, $depositor, $amount, $method, $newPath, $uploaded['name'] ?? null, $hash, (int) (current_user()['id'] ?? 0) ?: null, $workerId]);
    deposit_save_reply(201, ['id' => (int) db()->lastInsertId(), 'fecha' => $date, 'registro' => 'Manual', 'acreedor' => $creditor, 'depositante' => $depositor, 'monto' => number_format((float) $amount, 2, '.', ''), 'modo_pago' => $method, 'hasPhoto' => $hasPhoto]);
} catch (Throwable $error) {
    if ($newPath) delete_uploaded_file($newPath);
    if ($error instanceof PDOException && $error->getCode() === '23000') deposit_save_reply(409, ['error' => 'Esta foto ya fue registrada.', 'duplicate' => true]);
    error_log('Error al guardar compra: ' . $error->getMessage());
    deposit_save_reply(500, ['error' => 'No se pudo guardar la compra. Verifique que la tabla SQL esté instalada.']);
}
