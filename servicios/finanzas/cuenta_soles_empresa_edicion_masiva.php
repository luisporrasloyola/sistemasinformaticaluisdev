<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/finanzas_depositos_personal_match.php';
require_module_access('finanzas.gastos_personal');
if (!is_admin() || is_personal_role()) json_response(['ok' => false, 'message' => 'No tiene permiso para modificar esta cuenta.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);

function company_bulk_amount(mixed $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') return '0.00';
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) return null;
    $parts = explode('.', $value, 2);
    $cents = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    return $cents <= 999999999999 ? number_format($cents / 100, 2, '.', '') : null;
}

$rows = json_decode((string) ($_POST['rows'] ?? ''), true);
if (!is_array($rows) || !$rows || count($rows) > 200) json_response(['ok' => false, 'message' => 'Seleccione movimientos válidos para guardar.'], 422);
$validated = [];
$seen = [];
foreach ($rows as $row) {
    if (!is_array($row)) json_response(['ok' => false, 'message' => 'Datos de movimientos inválidos.'], 422);
    $source = $row['source'] ?? '';
    $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $date = trim((string) ($row['movement_date'] ?? ''));
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $expense = company_bulk_amount($row['expense'] ?? '');
    $deposit = company_bulk_amount($row['deposit'] ?? '');
    $expenseConcept = trim((string) ($row['expense_concept'] ?? ''));
    $depositConcept = trim((string) ($row['deposit_concept'] ?? ''));
    $key = $source . ':' . $id;
    if (!in_array($source, ['manual', 'personal_deposit'], true) || !$id || isset($seen[$key]) || !$parsed || $parsed->format('Y-m-d') !== $date || $expense === null || $deposit === null || mb_strlen($expenseConcept) > 500 || mb_strlen($depositConcept) > 500) {
        json_response(['ok' => false, 'message' => 'Revise fechas, importes y conceptos.'], 422);
    }
    $seen[$key] = true;
    if ($source === 'personal_deposit') {
        if ((float) $expense <= 0 || $expenseConcept === '' || mb_strlen($expenseConcept) > 150 || ((float) $deposit > 0 && $depositConcept === '')) json_response(['ok' => false, 'message' => 'Revise el gasto y su concepto.'], 422);
        if ((float) $deposit === 0) $depositConcept = '';
    } else {
        if (((float) $expense <= 0 && (float) $deposit <= 0) || ((float) $expense > 0 && (float) $deposit > 0) || ((float) $expense > 0 && $expenseConcept === '') || ((float) $deposit > 0 && $depositConcept === '')) json_response(['ok' => false, 'message' => 'Ingrese un gasto o un depósito por movimiento.'], 422);
        if ((float) $expense === 0) $expenseConcept = '';
        if ((float) $deposit === 0) $depositConcept = '';
    }
    $validated[] = [$source, $id, $date, $expense, $expenseConcept, $deposit, $depositConcept];
}

$pdo = db();
try {
    $pdo->beginTransaction();
    $manualLock = $pdo->prepare('SELECT id FROM finance_company_sole_movements WHERE id = ? FOR UPDATE');
    $manualUpdate = $pdo->prepare('UPDATE finance_company_sole_movements SET movement_date = ?, expense = ?, expense_concept = ?, deposit = ?, deposit_concept = ?, updated_at = NOW() WHERE id = ?');
    $sourceLock = $pdo->prepare("SELECT id, creditor, batch_id FROM finance_personal_deposits WHERE id = ? AND depositor = 'Cuenta soles empresa' FOR UPDATE");
    $linked = $pdo->prepare('SELECT 1 FROM finance_pending_payment_abonos WHERE deposit_id = ? LIMIT 1');
    $sourceUpdate = $pdo->prepare('UPDATE finance_personal_deposits SET deposit_date = ?, creditor = ?, amount = ?, last_edited_by_user_id = ?, worker_id = ? WHERE id = ?');
    $batchUpdate = $pdo->prepare('UPDATE finance_personal_deposits SET deposit_date = ?, creditor = ?, amount = ?, last_edited_by_user_id = ? WHERE id = ?');
    $detailUpdate = $pdo->prepare('INSERT INTO finance_company_sole_deposit_details (source_deposit_id, deposit, deposit_concept) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE deposit = VALUES(deposit), deposit_concept = VALUES(deposit_concept)');
    $workers = null;
    foreach ($validated as [$source, $id, $date, $expense, $expenseConcept, $deposit, $depositConcept]) {
        if ($source === 'manual') {
            $manualLock->execute([$id]);
            if (!$manualLock->fetchColumn()) throw new DomainException('Un movimiento ya no existe. Actualice la página.');
            $manualUpdate->execute([$date, $expense, $expenseConcept, $deposit, $depositConcept, $id]);
            continue;
        }
        $sourceLock->execute([$id]);
        $original = $sourceLock->fetch(PDO::FETCH_ASSOC);
        if (!$original) throw new DomainException('Un gasto ya no pertenece a esta cuenta. Actualice la página.');
        $linked->execute([$id]);
        if ($linked->fetchColumn()) throw new DomainException('Un depósito está aplicado a un pago pendiente. Desvincúlelo desde Ver pagos antes de editarlo.');
        $userId = (int) (current_user()['id'] ?? 0);
        if ($original['batch_id'] === null) {
            $workers ??= personal_deposit_workers();
            $workerId = personal_deposit_unique_worker_id($expenseConcept, $workers);
            $sourceUpdate->execute([$date, $expenseConcept, $expense, $userId, $workerId, $id]);
        } else {
            $batchUpdate->execute([$date, $expenseConcept, $expense, $userId, $id]);
        }
        $detailUpdate->execute([$id, $deposit, $depositConcept]);
    }
    $pdo->commit();
    json_response(['ok' => true, 'updated' => count($validated)]);
} catch (DomainException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Edición masiva cuenta soles: ' . get_class($error));
    json_response(['ok' => false, 'message' => 'No se pudieron guardar los movimientos.'], 500);
}
