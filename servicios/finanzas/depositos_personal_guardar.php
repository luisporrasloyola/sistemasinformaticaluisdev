<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload.php';
require_module_access('finanzas.depositos_personal');
require_once __DIR__ . '/../../includes/finanzas_depositos_personal_match.php';
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
if (is_personal_role() && !personal_deposit_is_own($creditor)) deposit_save_reply(403, ['error' => 'El acreedor debe corresponder al personal vinculado a su cuenta.']);
$parsedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) deposit_save_reply(422, ['error' => 'Ingrese una fecha válida.']);
if ($creditor === '' || mb_strlen($creditor) > 150) deposit_save_reply(422, ['error' => 'Ingrese el acreedor (máximo 150 caracteres).']);
if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $amount) || (float) $amount <= 0) deposit_save_reply(422, ['error' => 'Ingrese un monto válido en soles.']);
if (!in_array($method, ['YAPE', 'PLIN', 'Tranferencia Interbank', 'Tranferencia BCP', 'Transferencia'], true)) deposit_save_reply(422, ['error' => 'El modo de pago no corresponde a un modelo configurado.']);
$photo = $_FILES['foto'] ?? null;
if (!is_array($photo) || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($photo['size'] ?? 0) < 1000 || ($photo['size'] ?? 0) > 5 * 1024 * 1024) deposit_save_reply(422, ['error' => 'La foto debe ocupar entre 1 KB y 5 MB.']);
$info = @getimagesize((string) $photo['tmp_name']);
if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) deposit_save_reply(422, ['error' => 'Seleccione una foto JPG, PNG o WEBP válida.']);
$hash = hash_file('sha256', (string) $photo['tmp_name']);
if ($hash === false) deposit_save_reply(422, ['error' => 'No se pudo leer la foto.']);

$newPath = null;
try {
    $existing = db()->prepare('SELECT id FROM finance_personal_deposits WHERE image_sha256 = ? LIMIT 1');
    $existing->execute([$hash]);
    if ($existing->fetchColumn()) deposit_save_reply(409, ['error' => 'Esta foto ya fue registrada.', 'duplicate' => true]);
    $uploaded = upload_file($photo, 'finanzas_depositos_personal', ['image/jpeg', 'image/png', 'image/webp']);
    $newPath = $uploaded['path'];
    db()->prepare('INSERT INTO finance_personal_deposits (deposit_date, creditor, amount, payment_method, image_path, image_original_name, image_sha256, registered_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$date, $creditor, $amount, $method, $newPath, $uploaded['name'], $hash, (int) (current_user()['id'] ?? 0) ?: null]);
    deposit_save_reply(201, ['id' => (int) db()->lastInsertId(), 'fecha' => $date, 'acreedor' => $creditor, 'depositante' => '', 'monto' => number_format((float) $amount, 2, '.', ''), 'modo_pago' => $method]);
} catch (Throwable $error) {
    if ($newPath) delete_uploaded_file($newPath);
    if ($error instanceof PDOException && $error->getCode() === '23000') deposit_save_reply(409, ['error' => 'Esta foto ya fue registrada.', 'duplicate' => true]);
    error_log('Error al guardar depósito personal: ' . $error->getMessage());
    deposit_save_reply(500, ['error' => 'No se pudo guardar el depósito. Verifique que la tabla SQL esté instalada.']);
}
