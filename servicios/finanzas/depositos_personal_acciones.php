<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload.php';
require_module_access('finanzas.depositos_personal');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function deposit_action_reply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) deposit_action_reply(403, ['error' => 'Solicitud no autorizada.']);
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = (string) ($_POST['action'] ?? '');
if (!$id || !in_array($action, ['update', 'delete'], true)) deposit_action_reply(400, ['error' => 'Acción no válida.']);

try {
    $stmt = db()->prepare('SELECT id, image_path FROM finance_personal_deposits WHERE id = ?');
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    if (!$record) deposit_action_reply(404, ['error' => 'El depósito ya no existe. Actualice la página.']);
    if ($action === 'delete') {
        db()->prepare('DELETE FROM finance_personal_deposits WHERE id = ?')->execute([$id]);
        delete_uploaded_file((string) $record['image_path']);
        deposit_action_reply(200, ['message' => 'Depósito eliminado.']);
    }

    $date = trim((string) ($_POST['fecha'] ?? ''));
    $creditor = trim((string) ($_POST['acreedor'] ?? ''));
    $amount = trim((string) ($_POST['monto'] ?? ''));
    $method = trim((string) ($_POST['modo_pago'] ?? ''));
    $parsedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) deposit_action_reply(422, ['error' => 'Ingrese una fecha válida.']);
    if ($creditor === '' || mb_strlen($creditor) > 150) deposit_action_reply(422, ['error' => 'Ingrese el acreedor (máximo 150 caracteres).']);
    if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $amount) || (float) $amount <= 0) deposit_action_reply(422, ['error' => 'Ingrese un monto válido en soles.']);
    if (!in_array($method, ['YAPE', 'PLIN', 'Tranferencia Interbank', 'Tranferencia BCP'], true)) deposit_action_reply(422, ['error' => 'El modo de pago no corresponde a un modelo configurado.']);
    db()->prepare('UPDATE finance_personal_deposits SET deposit_date = ?, creditor = ?, amount = ?, payment_method = ? WHERE id = ?')
        ->execute([$date, $creditor, $amount, $method, $id]);
    deposit_action_reply(200, ['id' => $id, 'fecha' => $date, 'acreedor' => $creditor, 'monto' => number_format((float) $amount, 2, '.', ''), 'modo_pago' => $method]);
} catch (Throwable $error) {
    error_log('Error en acciones de depósitos personales: ' . $error->getMessage());
    deposit_action_reply(500, ['error' => 'No se pudo completar la acción. Intente nuevamente.']);
}
