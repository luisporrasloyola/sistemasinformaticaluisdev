<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../includes/finanzas_pagos_pendientes_match.php';
require_module_access('finanzas.pagos_pendientes');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);

function pending_payment_cents(string $value): ?int
{
    $value = trim($value);
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) return null;
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    return $cents > 0 && $cents <= 999999999999 ? $cents : null;
}

function pending_payment_date(string $value): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
        && DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value;
}

$action = (string) ($_POST['action'] ?? '');
$amountCents = in_array($action, ['delete', 'unlink_deposit', 'delete_abono'], true) ? null : pending_payment_cents((string) ($_POST['amount'] ?? ''));
if (!in_array($action, ['delete', 'unlink_deposit', 'delete_abono'], true) && $amountCents === null) json_response(['ok' => false, 'message' => 'Ingrese un monto mayor que cero, con hasta dos decimales.'], 422);
$amount = $amountCents === null ? null : number_format($amountCents / 100, 2, '.', '');
$userId = (int) (current_user()['id'] ?? 0) ?: null;
$proofPath = null;

try {
    if ($action === 'delete_abono') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) json_response(['ok' => false, 'message' => 'Abono no válido.'], 422);
        db()->beginTransaction();
        $stmt = db()->prepare('SELECT proof_path FROM finance_pending_payment_abonos WHERE id = ? AND deposit_id IS NULL FOR UPDATE');
        $stmt->execute([$id]);
        $abono = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$abono) {
            db()->rollBack();
            json_response(['ok' => false, 'message' => 'El abono ya no existe.'], 404);
        }
        $stmt = db()->prepare('DELETE FROM finance_pending_payment_abonos WHERE id = ? AND deposit_id IS NULL');
        $stmt->execute([$id]);
        db()->commit();
        delete_uploaded_file($abono['proof_path'] !== null ? (string) $abono['proof_path'] : null);
        json_response(['ok' => true, 'message' => 'Abono quitado.']);
    }
    if ($action === 'unlink_deposit') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) json_response(['ok' => false, 'message' => 'Pago no válido.'], 422);
        $stmt = db()->prepare('DELETE FROM finance_pending_payment_abonos WHERE id = ? AND deposit_id IS NOT NULL');
        $stmt->execute([$id]);
        if (!$stmt->rowCount()) json_response(['ok' => false, 'message' => 'El vínculo ya no existe.'], 404);
        json_response(['ok' => true, 'message' => 'Depósito desvinculado.']);
    }
    if ($action === 'delete') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) json_response(['ok' => false, 'message' => 'Pago no válido.'], 422);
        $pathsStmt = db()->prepare('SELECT proof_path FROM finance_pending_payment_abonos WHERE pending_payment_id = ? AND proof_path IS NOT NULL');
        $pathsStmt->execute([$id]);
        $paths = $pathsStmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt = db()->prepare('DELETE FROM finance_pending_payments WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) json_response(['ok' => false, 'message' => 'El pago pendiente ya no existe.'], 404);
        foreach ($paths as $path) delete_uploaded_file((string) $path);
        json_response(['ok' => true, 'message' => 'Pago pendiente eliminado.']);
    }
    if ($action === 'create') {
        $creditor = trim((string) ($_POST['creditor'] ?? ''));
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($creditor === '' || mb_strlen($creditor) > 150 || !pending_payment_date($dueDate)
            || $reason === '' || mb_strlen($reason) > 2000) {
            json_response(['ok' => false, 'message' => 'Complete el acreedor, la fecha de vencimiento y el motivo.'], 422);
        }
        $stmt = db()->prepare('INSERT INTO finance_pending_payments (creditor, due_date, amount, reason, created_by_user_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$creditor, $dueDate, $amount, $reason, $userId]);
        json_response(['ok' => true, 'message' => 'Pago pendiente registrado.']);
    }
    if ($action === 'edit') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $creditor = trim((string) ($_POST['creditor'] ?? ''));
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!$id || $creditor === '' || mb_strlen($creditor) > 150 || !pending_payment_date($dueDate)
            || $reason === '' || mb_strlen($reason) > 2000) {
            json_response(['ok' => false, 'message' => 'Complete el acreedor, la fecha de vencimiento y el motivo.'], 422);
        }
        db()->beginTransaction();
        $stmt = db()->prepare('SELECT creditor FROM finance_pending_payments WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $originalCreditor = $stmt->fetchColumn();
        if ($originalCreditor === false) {
            db()->rollBack();
            json_response(['ok' => false, 'message' => 'El pago pendiente ya no existe.'], 404);
        }
        $stmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM finance_pending_payment_abonos WHERE pending_payment_id = ?');
        $stmt->execute([$id]);
        $paidCents = (int) round((float) $stmt->fetchColumn() * 100);
        if ($amountCents < $paidCents) {
            db()->rollBack();
            json_response(['ok' => false, 'message' => 'El monto de la deuda no puede ser menor que los S/ ' . number_format($paidCents / 100, 2, '.', ',') . ' ya abonados.'], 422);
        }
        if ($creditor !== $originalCreditor) {
            $stmt = db()->prepare('SELECT d.creditor FROM finance_pending_payment_abonos a JOIN finance_personal_deposits d ON d.id = a.deposit_id WHERE a.pending_payment_id = ?');
            $stmt->execute([$id]);
            foreach ($stmt as $deposit) {
                if (!pending_payment_creditor_matches((string) $deposit['creditor'], $creditor)) {
                    db()->rollBack();
                    json_response(['ok' => false, 'message' => 'El nuevo acreedor no coincide con un depósito vinculado. Desvincúlelo antes de cambiar el acreedor.'], 422);
                }
            }
        }
        $stmt = db()->prepare('UPDATE finance_pending_payments SET creditor = ?, due_date = ?, amount = ?, reason = ? WHERE id = ?');
        $stmt->execute([$creditor, $dueDate, $amount, $reason, $id]);
        db()->commit();
        json_response(['ok' => true, 'message' => 'Pago pendiente actualizado.']);
    }
    if ($action === 'abono' || $action === 'apply_deposit') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $depositId = $action === 'apply_deposit' ? filter_var($_POST['deposit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
        if (!$id || ($action === 'apply_deposit' && !$depositId) || ($action === 'abono' && !pending_payment_date($paymentDate))) {
            json_response(['ok' => false, 'message' => 'Seleccione un pago y una fecha de abono válidos.'], 422);
        }
        db()->beginTransaction();
        if ($action === 'apply_deposit') {
            $stmt = db()->prepare('SELECT deposit_date, creditor, amount FROM finance_personal_deposits WHERE id = ? FOR UPDATE');
            $stmt->execute([$depositId]);
            $deposit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$deposit) {
                db()->rollBack();
                json_response(['ok' => false, 'message' => 'El depósito ya no existe.'], 404);
            }
            $paymentDate = (string) $deposit['deposit_date'];
            $stmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM finance_pending_payment_abonos WHERE deposit_id = ?');
            $stmt->execute([$depositId]);
            $depositAvailableCents = (int) round((float) $deposit['amount'] * 100) - (int) round((float) $stmt->fetchColumn() * 100);
            if ($amountCents > $depositAvailableCents) {
                db()->rollBack();
                json_response(['ok' => false, 'message' => 'El importe supera lo disponible de este depósito.'], 422);
            }
        }
        $stmt = db()->prepare('SELECT amount, creditor FROM finance_pending_payments WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pending) {
            db()->rollBack();
            json_response(['ok' => false, 'message' => 'El pago pendiente ya no existe.'], 404);
        }
        $original = $pending['amount'];
        if ($action === 'apply_deposit' && !pending_payment_creditor_matches((string) $deposit['creditor'], (string) $pending['creditor'])) {
            db()->rollBack();
            json_response(['ok' => false, 'message' => 'El acreedor del depósito no coincide con el del pago pendiente.'], 422);
        }
        $stmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM finance_pending_payment_abonos WHERE pending_payment_id = ?');
        $stmt->execute([$id]);
        $paidCents = (int) round((float) $stmt->fetchColumn() * 100);
        $remainingCents = (int) round((float) $original * 100) - $paidCents;
        if ($amountCents > $remainingCents) {
            db()->rollBack();
            json_response(['ok' => false, 'message' => 'El abono supera el saldo pendiente de S/ ' . number_format(max(0, $remainingCents) / 100, 2, '.', ',') . '.'], 422);
        }
        if ($action === 'abono' && isset($_FILES['proof']) && ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['proof'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException(upload_error_message((int) $file['error']));
            }
            if (($file['size'] ?? 0) < 1 || $file['size'] > 5 * 1024 * 1024) {
                throw new RuntimeException('El comprobante debe ocupar hasta 5 MB.');
            }
            $uploaded = upload_file($file, 'finanzas_pagos_pendientes', document_attachment_mimes(), [
                'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
                'webp' => ['image/webp'], 'pdf' => ['application/pdf'],
            ]);
            $proofPath = $uploaded['path'];
        }
        $stmt = db()->prepare('INSERT INTO finance_pending_payment_abonos (pending_payment_id, payment_date, amount, deposit_id, proof_path, recorded_by_user_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$id, $paymentDate, $amount, $depositId, $proofPath, $userId]);
        db()->commit();
        json_response(['ok' => true, 'message' => $amountCents === $remainingCents ? 'Deuda pagada por completo.' : 'Abono registrado.']);
    }
    json_response(['ok' => false, 'message' => 'Acción no válida.'], 400);
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    if ($proofPath !== null) delete_uploaded_file($proofPath);
    if ($error instanceof RuntimeException) json_response(['ok' => false, 'message' => $error->getMessage()], 422);
    error_log('Pending payment save failed: ' . get_class($error));
    json_response(['ok' => false, 'message' => 'No se pudo guardar el pago.'], 500);
}
