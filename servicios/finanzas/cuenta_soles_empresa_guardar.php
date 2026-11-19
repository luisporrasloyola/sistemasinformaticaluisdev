<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('finanzas.gastos_personal');
if (!is_admin() || is_personal_role()) json_response(['ok' => false, 'message' => 'No tiene permiso para modificar esta cuenta.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);

function company_sole_cents(string $value): ?int
{
    $value = trim($value);
    if ($value === '') return 0;
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) return null;
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    return $cents <= 999999999999 ? $cents : null;
}

$action = (string) ($_POST['action'] ?? '');
$pdo = db();
try {
    if ($action === 'opening') {
        $month = trim((string) ($_POST['month'] ?? ''));
        $cents = company_sole_cents((string) ($_POST['amount'] ?? ''));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) || $cents === null) throw new DomainException('Ingrese un mes y un saldo inicial válidos.');
        $stmt = $pdo->prepare('INSERT INTO finance_company_sole_opening_balances (month_key, amount, updated_by_user_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_by_user_id = VALUES(updated_by_user_id)');
        $stmt->execute([$month, number_format($cents / 100, 2, '.', ''), (int) (current_user()['id'] ?? 0) ?: null]);
        json_response(['ok' => true]);
    }

    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($action === 'delete') {
        if (!$id) throw new DomainException('El registro no es válido.');
        $stmt = $pdo->prepare('DELETE FROM finance_company_sole_movements WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->rowCount()) throw new DomainException('El registro ya no existe.');
        json_response(['ok' => true]);
    }
    if ($action !== 'save') throw new DomainException('Acción no válida.');
    if (trim((string) ($_POST['id'] ?? '')) !== '' && !$id) throw new DomainException('El registro no es válido.');
    $date = trim((string) ($_POST['movement_date'] ?? ''));
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) throw new DomainException('Ingrese una fecha válida.');
    $expense = company_sole_cents((string) ($_POST['expense'] ?? ''));
    $deposit = company_sole_cents((string) ($_POST['deposit'] ?? ''));
    $expenseConcept = trim((string) ($_POST['expense_concept'] ?? ''));
    $depositConcept = trim((string) ($_POST['deposit_concept'] ?? ''));
    if ($expense === null || $deposit === null || ($expense === 0 && $deposit === 0)) throw new DomainException('Ingrese un gasto o depósito mayor que cero.');
    if ($expense > 0 && $deposit > 0) throw new DomainException('Registre el gasto y el depósito en filas separadas.');
    if ($expense > 0 && $expenseConcept === '') throw new DomainException('Ingrese el concepto del gasto.');
    if ($deposit > 0 && $depositConcept === '') throw new DomainException('Ingrese el concepto del depósito.');
    if (mb_strlen($expenseConcept) > 500 || mb_strlen($depositConcept) > 500) throw new DomainException('El concepto es demasiado largo.');
    if ($expense === 0) $expenseConcept = '';
    if ($deposit === 0) $depositConcept = '';
    $values = [$date, number_format($expense / 100, 2, '.', ''), $expenseConcept, number_format($deposit / 100, 2, '.', ''), $depositConcept];
    if ($id) {
        $stmt = $pdo->prepare('UPDATE finance_company_sole_movements SET movement_date = ?, expense = ?, expense_concept = ?, deposit = ?, deposit_concept = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([...$values, $id]);
        if (!$stmt->rowCount()) {
            $exists = $pdo->prepare('SELECT 1 FROM finance_company_sole_movements WHERE id = ?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) throw new DomainException('El registro ya no existe.');
        }
    } else {
        $stmt = $pdo->prepare('INSERT INTO finance_company_sole_movements (movement_date, expense, expense_concept, deposit, deposit_concept, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([...$values, (int) (current_user()['id'] ?? 0) ?: null]);
    }
    json_response(['ok' => true]);
} catch (DomainException $error) {
    json_response(['ok' => false, 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('Cuenta soles empresa: ' . get_class($error));
    json_response(['ok' => false, 'message' => 'No se pudo guardar. Compruebe que la consulta de la cuenta esté instalada.'], 500);
}
