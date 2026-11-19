<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/finanzas_depositos_personal_match.php';
require_once __DIR__ . '/../../includes/finanzas_personal_edicion_autorizacion.php';
require_module_access('finanzas.gastos_personal');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);
$workerId = filter_var($_POST['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$workerId) json_response(['ok' => false, 'message' => 'Trabajador no válido.'], 422);
if (is_personal_role()) require_personal_own_worker((int) $workerId);
$rows = json_decode((string) ($_POST['rows'] ?? ''), true);
if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > 400) {
    json_response(['ok' => false, 'message' => 'La selección debe contener entre 1 y 400 registros.'], 422);
}
$dates = [];
foreach ($rows as $row) {
    if (!is_array($row)) json_response(['ok' => false, 'message' => 'Registro no válido.'], 422);
    if (!is_string($row['mark_date'] ?? null) || !is_scalar($row['amount_override'] ?? null) || !is_scalar($row['honorarios'] ?? null)
        || !is_string($row['observations'] ?? null)) {
        json_response(['ok' => false, 'message' => 'Registro no válido.'], 422);
    }
    $date = (string) ($row['mark_date'] ?? '');
    $amount = trim((string) ($row['amount_override'] ?? ''));
    $honorarios = trim((string) ($row['honorarios'] ?? ''));
    $observations = trim((string) ($row['observations'] ?? ''));
    $expenses = $row['additional_expenses'] ?? null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date
        || isset($dates[$date]) || !is_array($expenses) || !array_is_list($expenses) || count($expenses) > 30
        || ($amount !== '' && (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $amount) || (float) $amount > 999999.99))
        || ($honorarios !== '' && (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $honorarios) || (float) $honorarios > 999999.99))
        || mb_strlen($observations) > 10000) {
        json_response(['ok' => false, 'message' => 'Revise la fecha, los importes y las observaciones.'], 422);
    }
    foreach ($expenses as $expense) {
        if (!is_array($expense) || !is_scalar($expense['amount'] ?? null) || !is_string($expense['reason'] ?? null)
            || !preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', trim((string) $expense['amount']))
            || (float) $expense['amount'] > 999999.99 || mb_strlen(trim($expense['reason'])) > 1000) {
            json_response(['ok' => false, 'message' => 'Revise los gastos adicionales.'], 422);
        }
    }
    $dates[$date] = true;
}

try {
    $workers = personal_deposit_workers();
    $dateList = array_keys($dates);
    $placeholders = implode(',', array_fill(0, count($dateList), '?'));
    $eligibleDates = [];
    $markStmt = db()->prepare("SELECT DISTINCT mark_date FROM attendance_marks WHERE worker_id = ? AND mark_date IN ($placeholders)");
    $markStmt->execute(array_merge([$workerId], $dateList));
    foreach ($markStmt->fetchAll(PDO::FETCH_COLUMN) as $date) $eligibleDates[(string) $date] = true;
    if (count($eligibleDates) < count($dateList)) {
        $depositStmt = db()->prepare("SELECT deposit_date, creditor, depositor, worker_id FROM finance_personal_deposits WHERE deposit_date IN ($placeholders)");
        $depositStmt->execute($dateList);
        foreach ($depositStmt as $deposit) {
            $date = (string) $deposit['deposit_date'];
            if (isset($eligibleDates[$date])) continue;
            $creditorId = $deposit['worker_id'] !== null ? (int) $deposit['worker_id']
                : personal_deposit_unique_worker_id((string) $deposit['creditor'], $workers);
            if ($creditorId === (int) $workerId
                || personal_expense_depositor_worker_id((string) ($deposit['depositor'] ?? ''), $workers) === (int) $workerId) {
                $eligibleDates[$date] = true;
            }
        }
    }
    if (count($eligibleDates) !== count($dateList)) {
        json_response(['ok' => false, 'message' => 'Un registro ya no tiene marcación ni depósito. Actualice la página.'], 409);
    }

    db()->beginTransaction();
    $lockedRecord = personal_expense_locked_record(db(), (int) $workerId, $dateList);
    if ($lockedRecord !== null) {
        db()->rollBack();
        json_response(['ok' => false, 'message' => personal_expense_locked_message($lockedRecord)], 403);
    }
    $save = db()->prepare('INSERT INTO finance_personal_per_diem_edits (worker_id, mark_date, amount_override, honorarios, additional_expense, additional_reason, observations, updated_by_user_id)
        VALUES (?, ?, ?, ?, NULL, NULL, ?, ?)
        ON DUPLICATE KEY UPDATE amount_override = VALUES(amount_override), honorarios = VALUES(honorarios), additional_expense = NULL, additional_reason = NULL, observations = VALUES(observations), updated_by_user_id = VALUES(updated_by_user_id)');
    $delete = db()->prepare('DELETE FROM finance_personal_additional_expenses WHERE worker_id = ? AND mark_date = ?');
    $insert = db()->prepare('INSERT INTO finance_personal_additional_expenses (worker_id, mark_date, sort_order, amount, reason) VALUES (?, ?, ?, ?, ?)');
    foreach ($rows as $row) {
        $date = (string) $row['mark_date'];
        $amount = trim((string) $row['amount_override']);
        $honorarios = trim((string) $row['honorarios']);
        $save->execute([$workerId, $date, $amount === '' ? null : number_format((float) $amount, 2, '.', ''), $honorarios === '' ? null : number_format((float) $honorarios, 2, '.', ''), trim((string) $row['observations']), (int) (current_user()['id'] ?? 0) ?: null]);
        $delete->execute([$workerId, $date]);
        foreach ($row['additional_expenses'] as $index => $expense) {
            $insert->execute([$workerId, $date, $index, number_format((float) $expense['amount'], 2, '.', ''), trim($expense['reason'])]);
        }
    }
    db()->commit();
    json_response(['ok' => true, 'saved' => count($rows)]);
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('Personal bulk expenses save failed: ' . get_class($error));
    json_response(['ok' => false, 'message' => 'No se pudieron guardar los cambios.'], 500);
}
