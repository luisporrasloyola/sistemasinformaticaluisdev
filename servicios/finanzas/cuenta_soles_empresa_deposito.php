<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../includes/finanzas_depositos_personal_match.php';
require_module_access('finanzas.gastos_personal');
if (!is_admin()) json_response(['ok' => false, 'message' => 'No tiene permiso para modificar esta cuenta.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);

function company_source_cents(string $value): ?int
{
    $value = trim($value);
    if ($value === '') return 0;
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) return null;
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    return $cents <= 999999999999 ? $cents : null;
}

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = (string) ($_POST['action'] ?? '');
if (!$id || !in_array($action, ['save', 'remove'], true)) json_response(['ok' => false, 'message' => 'Solicitud no válida.'], 422);
$pdo = db();
$uploadedPath = null;
$oldProof = null;
try {
    if ($action === 'save') {
        $date = trim((string) ($_POST['fecha'] ?? ''));
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $creditor = trim((string) ($_POST['acreedor'] ?? ''));
        $expenseCents = company_source_cents((string) ($_POST['monto'] ?? ''));
        $depositCents = company_source_cents((string) ($_POST['deposit'] ?? ''));
        $depositConcept = trim((string) ($_POST['deposit_concept'] ?? ''));
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) throw new DomainException('Ingrese una fecha válida.');
        if ($creditor === '' || mb_strlen($creditor) > 150) throw new DomainException('Ingrese un concepto de gasto válido.');
        if ($expenseCents === null || $expenseCents < 1 || $depositCents === null) throw new DomainException('Ingrese importes válidos.');
        if ($depositCents > 0 && $depositConcept === '') throw new DomainException('Ingrese el concepto del depósito.');
        if (mb_strlen($depositConcept) > 500) throw new DomainException('El concepto del depósito es demasiado largo.');
        if ($depositCents === 0) $depositConcept = '';
        $file = $_FILES['proof'] ?? null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($file['size'] ?? 0) > 5 * 1024 * 1024) throw new DomainException('El archivo debe tener hasta 5 MB.');
            $uploaded = upload_file($file, 'finanzas_cuenta_soles', document_attachment_mimes(), [
                'pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
                'png' => ['image/png'], 'webp' => ['image/webp'],
            ]);
            $uploadedPath = $uploaded['path'];
        }
    }
    $pdo->beginTransaction();
    $sourceStmt = $pdo->prepare("SELECT id, creditor, batch_id FROM finance_personal_deposits WHERE id = ? AND depositor = 'Cuenta soles empresa' FOR UPDATE");
    $sourceStmt->execute([$id]);
    $source = $sourceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$source) throw new DomainException('El gasto ya no pertenece a esta cuenta. Actualice la página.');
    $detailStmt = $pdo->prepare('SELECT proof_path FROM finance_company_sole_deposit_details WHERE source_deposit_id = ? FOR UPDATE');
    $detailStmt->execute([$id]);
    $oldProof = $detailStmt->fetchColumn() ?: null;
    if ($action === 'remove') {
        $pdo->prepare('DELETE FROM finance_company_sole_deposit_details WHERE source_deposit_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE finance_personal_deposits SET depositor = NULL, last_edited_by_user_id = ? WHERE id = ?')->execute([(int) (current_user()['id'] ?? 0), $id]);
        $pdo->commit();
        delete_uploaded_file($oldProof);
        json_response(['ok' => true]);
    }
    $linkedStmt = $pdo->prepare('SELECT 1 FROM finance_pending_payment_abonos WHERE deposit_id = ? LIMIT 1');
    $linkedStmt->execute([$id]);
    if ($linkedStmt->fetchColumn()) throw new DomainException('Este depósito está aplicado a un pago pendiente. Desvincúlelo desde Ver pagos antes de editarlo.');
    $workerId = $source['batch_id'] === null ? personal_deposit_unique_worker_id($creditor, personal_deposit_workers()) : null;
    $params = [$date, $creditor, number_format($expenseCents / 100, 2, '.', ''), (int) (current_user()['id'] ?? 0)];
    if ($source['batch_id'] === null) {
        $pdo->prepare('UPDATE finance_personal_deposits SET deposit_date = ?, creditor = ?, amount = ?, last_edited_by_user_id = ?, worker_id = ? WHERE id = ?')
            ->execute([...$params, $workerId, $id]);
    } else {
        $pdo->prepare('UPDATE finance_personal_deposits SET deposit_date = ?, creditor = ?, amount = ?, last_edited_by_user_id = ? WHERE id = ?')
            ->execute([...$params, $id]);
    }
    $proof = $uploadedPath ?: $oldProof;
    $pdo->prepare('INSERT INTO finance_company_sole_deposit_details (source_deposit_id, deposit, deposit_concept, proof_path) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE deposit = VALUES(deposit), deposit_concept = VALUES(deposit_concept), proof_path = VALUES(proof_path)')
        ->execute([$id, number_format($depositCents / 100, 2, '.', ''), $depositConcept, $proof]);
    $pdo->commit();
    if ($uploadedPath && $oldProof) delete_uploaded_file($oldProof);
    json_response(['ok' => true]);
} catch (DomainException | RuntimeException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($uploadedPath) delete_uploaded_file($uploadedPath);
    json_response(['ok' => false, 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($uploadedPath) delete_uploaded_file($uploadedPath);
    error_log('Cuenta soles empresa depósito: ' . get_class($error));
    json_response(['ok' => false, 'message' => 'No se pudo guardar el movimiento.'], 500);
}
