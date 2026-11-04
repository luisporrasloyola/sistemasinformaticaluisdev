<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('finanzas.gastos_personal');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = filter_var($_POST['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$markDate = (string) ($_POST['mark_date'] ?? '');
$amountText = trim((string) ($_POST['amount_override'] ?? ''));
$additionalText = trim((string) ($_POST['additional_expense'] ?? ''));
$additionalReason = trim((string) ($_POST['additional_reason'] ?? ''));
$observations = trim((string) ($_POST['observations'] ?? ''));
$newObservation = trim((string) ($_POST['new_observation'] ?? ''));
$directObservations = isset($_POST['observations_direct']) ? trim((string) $_POST['observations_direct']) : null;
$expenseItems = null;
if (isset($_POST['additional_expenses'])) {
    $expenseItems = json_decode((string) $_POST['additional_expenses'], true);
    if (!is_array($expenseItems) || !array_is_list($expenseItems) || count($expenseItems) > 30) {
        json_response(['ok' => false, 'message' => 'La lista de gastos adicionales no es válida.'], 422);
    }
    foreach ($expenseItems as $item) {
        if (!is_array($item) || !isset($item['amount'], $item['reason']) || !is_scalar($item['amount']) || !is_string($item['reason'])) {
            json_response(['ok' => false, 'message' => 'Revise los gastos adicionales.'], 422);
        }
        $expenseAmount = trim((string) $item['amount']);
        $expenseReason = trim($item['reason']);
        if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $expenseAmount) || (float) $expenseAmount > 999999.99 || $expenseReason === '' || mb_strlen($expenseReason) > 1000) {
            json_response(['ok' => false, 'message' => 'Cada gasto debe tener un importe válido y su motivo.'], 422);
        }
    }
}

if (!$workerId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $markDate) || DateTimeImmutable::createFromFormat('!Y-m-d', $markDate)?->format('Y-m-d') !== $markDate) {
    json_response(['ok' => false, 'message' => 'Trabajador o fecha no válidos.'], 422);
}
if ($amountText !== '' && (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $amountText) || (float) $amountText > 999999.99)) {
    json_response(['ok' => false, 'message' => 'Ingrese un importe válido de hasta dos decimales.'], 422);
}
if ($additionalText !== '' && (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $additionalText) || (float) $additionalText > 999999.99)) {
    json_response(['ok' => false, 'message' => 'Ingrese un gasto adicional válido de hasta dos decimales.'], 422);
}
if (mb_strlen($additionalReason) > 1000 || ($additionalText !== '' && $additionalReason === '') || ($additionalText === '' && $additionalReason !== '')) {
    json_response(['ok' => false, 'message' => 'El gasto adicional y su motivo deben ingresarse juntos.'], 422);
}
if ($directObservations !== null && ($directObservations === '' || mb_strlen($directObservations) > 10000)) {
    json_response(['ok' => false, 'message' => 'Ingrese una observación de hasta 10000 caracteres.'], 422);
}
if ($directObservations === null && ($newObservation === '' || mb_strlen($newObservation) > 1000 || mb_strlen($observations) > 9000)) {
    json_response(['ok' => false, 'message' => 'Ingrese una nueva observación de hasta 1000 caracteres.'], 422);
}

try {
    $mark = db()->prepare('SELECT 1 FROM attendance_marks WHERE worker_id = ? AND mark_date = ? LIMIT 1');
    $mark->execute([$workerId, $markDate]);
    if (!$mark->fetchColumn()) json_response(['ok' => false, 'message' => 'La marcación ya no existe para esa fecha.'], 404);

    if ($directObservations !== null) {
        $combined = $directObservations;
    } else {
        $author = trim((string) (current_user()['name'] ?? 'Usuario'));
        $entry = date('d/m/Y H:i') . ' · ' . $author . ': ' . $newObservation;
        $combined = $observations === '' ? $entry : $observations . "\n" . $entry;
    }
    if (mb_strlen($combined) > 10000) json_response(['ok' => false, 'message' => 'El campo de observaciones alcanzó su límite.'], 422);

    db()->beginTransaction();
    $save = db()->prepare('INSERT INTO finance_personal_per_diem_edits (worker_id, mark_date, amount_override, additional_expense, additional_reason, observations, updated_by_user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE amount_override = VALUES(amount_override), additional_expense = VALUES(additional_expense), additional_reason = VALUES(additional_reason), observations = VALUES(observations), updated_by_user_id = VALUES(updated_by_user_id)');
    $save->execute([$workerId, $markDate, $amountText === '' ? null : number_format((float) $amountText, 2, '.', ''), $expenseItems !== null || $additionalText === '' ? null : number_format((float) $additionalText, 2, '.', ''), $expenseItems !== null || $additionalReason === '' ? null : $additionalReason, $combined, (int) (current_user()['id'] ?? 0) ?: null]);
    if ($expenseItems !== null) {
        $delete = db()->prepare('DELETE FROM finance_personal_additional_expenses WHERE worker_id = ? AND mark_date = ?');
        $delete->execute([$workerId, $markDate]);
        $insert = db()->prepare('INSERT INTO finance_personal_additional_expenses (worker_id, mark_date, sort_order, amount, reason) VALUES (?, ?, ?, ?, ?)');
        foreach ($expenseItems as $index => $item) {
            $insert->execute([$workerId, $markDate, $index, number_format((float) $item['amount'], 2, '.', ''), trim($item['reason'])]);
        }
    }
    db()->commit();
    json_response(['ok' => true]);
} catch (PDOException $error) {
    if (db()->inTransaction()) db()->rollBack();
    json_response(['ok' => false, 'message' => 'No se pudo guardar el viático.'], 500);
}
