<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('finanzas.gastos_personal');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = filter_var($_POST['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (is_personal_role()) require_personal_own_worker((int) $workerId);
$month = trim((string) ($_POST['month'] ?? ''));
$amount = trim((string) ($_POST['amount'] ?? ''));
if (!$workerId || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
    json_response(['ok' => false, 'message' => 'Trabajador o mes no válido.'], 422);
}
if ($amount !== '' && (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $amount) || (float) $amount > 999999999.99)) {
    json_response(['ok' => false, 'message' => 'Ingrese un saldo válido de hasta dos decimales.'], 422);
}

try {
    $worker = db()->prepare('SELECT 1 FROM workers WHERE id = ?');
    $worker->execute([$workerId]);
    if (!$worker->fetchColumn()) json_response(['ok' => false, 'message' => 'Trabajador no encontrado.'], 404);

    if ($amount === '') {
        $delete = db()->prepare('DELETE FROM finance_personal_monthly_balances WHERE worker_id = ? AND balance_month = ?');
        $delete->execute([$workerId, $month . '-01']);
    } else {
        $save = db()->prepare('INSERT INTO finance_personal_monthly_balances (worker_id, balance_month, opening_balance, updated_by_user_id)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE opening_balance = VALUES(opening_balance), updated_by_user_id = VALUES(updated_by_user_id)');
        $save->execute([$workerId, $month . '-01', number_format((float) $amount, 2, '.', ''), (int) (current_user()['id'] ?? 0) ?: null]);
    }
    json_response(['ok' => true]);
} catch (PDOException $error) {
    json_response(['ok' => false, 'message' => 'No se pudo guardar el saldo inicial.'], 500);
}
