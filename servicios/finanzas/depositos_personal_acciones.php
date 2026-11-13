<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload.php';
require_module_access('finanzas.depositos_personal');
require_once __DIR__ . '/../../includes/finanzas_depositos_personal_match.php';
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
    $stmt = db()->prepare('SELECT id, image_path, creditor, batch_id, worker_id, registration_source, registered_by_user_id FROM finance_personal_deposits WHERE id = ?');
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    if (!$record) deposit_action_reply(404, ['error' => 'El depósito ya no existe. Actualice la página.']);
    $userId = (int) (current_user()['id'] ?? 0);
    if (!is_admin() && (!is_personal_role() || $userId <= 0 || (int) $record['registered_by_user_id'] !== $userId)) {
        deposit_action_reply(403, ['error' => 'Solo puede modificar los depósitos que usted subió.']);
    }
    if ($action === 'delete') {
        if ($record['batch_id'] !== null) {
            $db = db();
            $db->beginTransaction();
            $batchStmt = $db->prepare('SELECT image_path FROM finance_personal_deposit_batches WHERE id = ? FOR UPDATE');
            $batchStmt->execute([(int) $record['batch_id']]);
            $batchPath = $batchStmt->fetchColumn();
            if (!$batchPath) { $db->rollBack(); deposit_action_reply(404, ['error' => 'El lote ya no existe.']); }
            $delete = $db->prepare('DELETE FROM finance_personal_deposits WHERE id = ?' . (is_admin() ? '' : ' AND registered_by_user_id = ?'));
            $delete->execute(is_admin() ? [$id] : [$id, $userId]);
            if ($delete->rowCount() !== 1) { $db->rollBack(); deposit_action_reply(403, ['error' => 'No puede eliminar este depósito.']); }
            $remaining = $db->prepare('SELECT COUNT(*) FROM finance_personal_deposits WHERE batch_id = ?');
            $remaining->execute([(int) $record['batch_id']]);
            $last = (int) $remaining->fetchColumn() === 0;
            if ($last) $db->prepare('DELETE FROM finance_personal_deposit_batches WHERE id = ?')->execute([(int) $record['batch_id']]);
            $db->commit();
            if ($last) delete_uploaded_file((string) $batchPath);
            deposit_action_reply(200, ['message' => 'Depósito eliminado.']);
        }
        $delete = db()->prepare('DELETE FROM finance_personal_deposits WHERE id = ?' . (is_admin() ? '' : ' AND registered_by_user_id = ?'));
        $delete->execute(is_admin() ? [$id] : [$id, $userId]);
        if ($delete->rowCount() !== 1) deposit_action_reply(403, ['error' => 'No puede eliminar este depósito.']);
        delete_uploaded_file((string) $record['image_path']);
        deposit_action_reply(200, ['message' => 'Depósito eliminado.']);
    }

    $date = trim((string) ($_POST['fecha'] ?? ''));
    $creditor = trim((string) ($_POST['acreedor'] ?? ''));
    $amount = trim((string) ($_POST['monto'] ?? ''));
    $method = trim((string) ($_POST['modo_pago'] ?? ''));
    $depositor = trim((string) ($_POST['depositante'] ?? ''));
    if ($record['worker_id'] !== null && $creditor !== (string) $record['creditor']) {
        deposit_action_reply(422, ['error' => 'El acreedor de un lote está vinculado a un trabajador. Corrija los demás datos sin cambiar su nombre.']);
    }
    if (is_personal_role() && !personal_deposit_is_own($creditor)) deposit_action_reply(403, ['error' => 'El acreedor debe corresponder al personal vinculado a su cuenta.']);
    $parsedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) deposit_action_reply(422, ['error' => 'Ingrese una fecha válida.']);
    if ($creditor === '' || mb_strlen($creditor) > 150) deposit_action_reply(422, ['error' => 'Ingrese el acreedor (máximo 150 caracteres).']);
    if (mb_strlen($depositor) > 150) deposit_action_reply(422, ['error' => 'El depositante no puede superar 150 caracteres.']);
    if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $amount) || (float) $amount <= 0) deposit_action_reply(422, ['error' => 'Ingrese un monto válido en soles.']);
    if ($record['registration_source'] === 'manual') {
        if ($method === '' || mb_strlen($method) > 100) deposit_action_reply(422, ['error' => 'Ingrese un modo de pago (máximo 100 caracteres).']);
    } elseif (!in_array($method, ['YAPE', 'PLIN', 'Tranferencia Interbank', 'Transferencia Interbank', 'Tranferencia BCP', 'Transferencia'], true)) {
        deposit_action_reply(422, ['error' => 'El modo de pago no corresponde a un modelo configurado.']);
    }
    $update = db()->prepare('UPDATE finance_personal_deposits SET deposit_date = ?, creditor = ?, depositor = ?, amount = ?, payment_method = ? WHERE id = ?' . (is_admin() ? '' : ' AND registered_by_user_id = ?'));
    $update->execute(is_admin() ? [$date, $creditor, $depositor !== '' ? $depositor : null, $amount, $method, $id] : [$date, $creditor, $depositor !== '' ? $depositor : null, $amount, $method, $id, $userId]);
    deposit_action_reply(200, ['id' => $id, 'fecha' => $date, 'acreedor' => $creditor, 'depositante' => $depositor, 'monto' => number_format((float) $amount, 2, '.', ''), 'modo_pago' => $method]);
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('Error en acciones de depósitos personales: ' . $error->getMessage());
    deposit_action_reply(500, ['error' => 'No se pudo completar la acción. Intente nuevamente.']);
}
