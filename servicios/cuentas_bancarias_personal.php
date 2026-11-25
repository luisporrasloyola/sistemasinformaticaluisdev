<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_module_access('control_personal.personal');
require_role('Administrador');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
}
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = filter_var($_POST['worker_id'] ?? null, FILTER_VALIDATE_INT);
if (!$workerId || $workerId < 1) {
    json_response(['ok' => false, 'message' => 'Seleccione un trabajador guardado.'], 400);
}

try {
    $worker = db()->prepare('SELECT 1 FROM workers WHERE id = :id');
    $worker->execute(['id' => $workerId]);
    if (!$worker->fetchColumn()) {
        json_response(['ok' => false, 'message' => 'El trabajador ya no existe.'], 404);
    }

    $action = (string) ($_POST['action'] ?? '');
    $accountId = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    if ($action === 'delete') {
        if ($accountId < 1) {
            json_response(['ok' => false, 'message' => 'Seleccione una cuenta válida.'], 400);
        }
        $delete = db()->prepare('DELETE FROM worker_bank_accounts WHERE id = :id AND worker_id = :worker_id');
        $delete->execute(['id' => $accountId, 'worker_id' => $workerId]);
        if ($delete->rowCount() === 0) {
            json_response(['ok' => false, 'message' => 'La cuenta ya no existe. Actualice la página.'], 404);
        }
        json_response(['ok' => true]);
    }

    if ($action !== 'save') {
        json_response(['ok' => false, 'message' => 'Acción no válida.'], 400);
    }

    $bankId = filter_var($_POST['bank_id'] ?? null, FILTER_VALIDATE_INT);
    $accountNumber = trim((string) ($_POST['account_number'] ?? ''));
    $currency = (string) ($_POST['currency'] ?? '');
    $cci = trim((string) ($_POST['cci'] ?? ''));
    if (!$bankId || $bankId < 1 || $accountNumber === '' || $cci === '' || !in_array($currency, ['PEN', 'USD'], true)) {
        json_response(['ok' => false, 'message' => 'Revise los datos de la cuenta bancaria.'], 400);
    }

    $bank = db()->prepare('SELECT name FROM worker_bank_catalog WHERE id = :id');
    $bank->execute(['id' => $bankId]);
    $bankName = $bank->fetchColumn();
    if ($bankName === false) {
        json_response(['ok' => false, 'message' => 'El banco seleccionado ya no existe.'], 400);
    }

    $duplicate = db()->prepare('SELECT 1 FROM worker_bank_accounts WHERE worker_id = :worker_id AND bank_id = :bank_id AND account_number = :account_number AND currency = :currency AND id <> :id LIMIT 1');
    $duplicate->execute(['worker_id' => $workerId, 'bank_id' => $bankId, 'account_number' => $accountNumber, 'currency' => $currency, 'id' => $accountId]);
    if ($duplicate->fetchColumn()) {
        json_response(['ok' => false, 'message' => 'Esta cuenta bancaria ya está registrada.'], 409);
    }

    if ($accountId > 0) {
        $update = db()->prepare('UPDATE worker_bank_accounts SET bank_id = :bank_id, account_number = :account_number, currency = :currency, cci = :cci WHERE id = :id AND worker_id = :worker_id');
        $update->execute(['bank_id' => $bankId, 'account_number' => $accountNumber, 'currency' => $currency, 'cci' => $cci, 'id' => $accountId, 'worker_id' => $workerId]);
        $exists = db()->prepare('SELECT 1 FROM worker_bank_accounts WHERE id = :id AND worker_id = :worker_id');
        $exists->execute(['id' => $accountId, 'worker_id' => $workerId]);
        if (!$exists->fetchColumn()) {
            json_response(['ok' => false, 'message' => 'La cuenta ya no existe. Actualice la página.'], 404);
        }
    } else {
        $count = db()->prepare('SELECT COUNT(*) FROM worker_bank_accounts WHERE worker_id = :worker_id');
        $count->execute(['worker_id' => $workerId]);
        if ((int) $count->fetchColumn() >= 20) {
            json_response(['ok' => false, 'message' => 'Puede registrar hasta 20 cuentas bancarias por trabajador.'], 400);
        }
        $insert = db()->prepare('INSERT INTO worker_bank_accounts (worker_id, bank_id, account_number, currency, cci) VALUES (:worker_id, :bank_id, :account_number, :currency, :cci)');
        $insert->execute(['worker_id' => $workerId, 'bank_id' => $bankId, 'account_number' => $accountNumber, 'currency' => $currency, 'cci' => $cci]);
        $accountId = (int) db()->lastInsertId();
    }

    json_response(['ok' => true, 'id' => $accountId, 'bank_name' => $bankName]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => 'No se pudo guardar la cuenta bancaria. Intente nuevamente.'], 500);
}
