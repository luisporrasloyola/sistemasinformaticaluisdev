<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('finanzas.gastos_personal');
if (is_personal_role()) { http_response_code(403); exit('No tiene acceso a la cuenta de la empresa.'); }

$mode = ($_GET['periodo'] ?? 'mes') === 'fechas' ? 'fechas' : 'mes';
$month = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) $month = date('Y-m');
$from = (string) ($_GET['desde'] ?? date('Y-m-01'));
$to = (string) ($_GET['hasta'] ?? date('Y-m-d'));
foreach (['from', 'to'] as $key) {
    $value = $$key;
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$parsed || $parsed->format('Y-m-d') !== $value) $$key = $key === 'from' ? date('Y-m-01') : date('Y-m-d');
}
if ($from > $to) [$from, $to] = [$to, $from];
$dateFrom = $mode === 'mes' ? $month . '-01' : $from;
$dateTo = $mode === 'mes' ? (new DateTimeImmutable($month . '-01'))->format('Y-m-t') : $to;
$records = [];
$opening = null;
$databaseReady = true;
try {
    $stmt = db()->prepare('SELECT id, movement_date, expense, expense_concept, deposit, deposit_concept FROM finance_company_sole_movements WHERE movement_date BETWEEN ? AND ? ORDER BY movement_date, id');
    $stmt->execute([$dateFrom, $dateTo]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($records as &$record) $record['source'] = 'manual';
    unset($record);
    $depositStmt = db()->prepare("SELECT p.id, p.deposit_date, p.creditor, p.amount, p.payment_method, p.batch_id,
        COALESCE(c.deposit, 0) AS company_deposit, COALESCE(c.deposit_concept, '') AS company_deposit_concept, c.proof_path
        FROM finance_personal_deposits p LEFT JOIN finance_company_sole_deposit_details c ON c.source_deposit_id = p.id
        WHERE p.depositor = 'Cuenta soles empresa' AND p.deposit_date BETWEEN ? AND ?");
    $depositStmt->execute([$dateFrom, $dateTo]);
    foreach ($depositStmt as $deposit) {
        $records[] = [
            'id' => (int) $deposit['id'], 'movement_date' => $deposit['deposit_date'],
            'expense' => $deposit['amount'], 'expense_concept' => $deposit['creditor'],
            'deposit' => $deposit['company_deposit'], 'deposit_concept' => $deposit['company_deposit_concept'], 'source' => 'personal_deposit',
            'payment_method' => $deposit['payment_method'], 'batch_id' => $deposit['batch_id'],
            'file_url' => !empty($deposit['proof_path'])
                ? APP_URL . '/servicios/finanzas/cuenta_soles_empresa_archivo.php?id=' . (int) $deposit['id']
                : null,
            'file_is_pdf' => strtolower(pathinfo((string) ($deposit['proof_path'] ?? ''), PATHINFO_EXTENSION)) === 'pdf',
        ];
    }
    usort($records, static function (array $a, array $b): int {
        return strcmp((string) $a['movement_date'], (string) $b['movement_date'])
            ?: strcmp((string) $a['source'], (string) $b['source'])
            ?: ((int) $a['id'] <=> (int) $b['id']);
    });
    if ($mode === 'mes') {
        $stmt = db()->prepare('SELECT amount FROM finance_company_sole_opening_balances WHERE month_key = ?');
        $stmt->execute([$month]);
        $amount = $stmt->fetchColumn();
        $opening = $amount === false ? null : (string) $amount;
    }
} catch (PDOException $error) {
    $databaseReady = false;
}
$expenseTotalCents = 0;
foreach ($records as $record) $expenseTotalCents += (int) round((float) $record['expense'] * 100);
$money = static fn (int $cents): string => 'S/ ' . number_format($cents / 100, 2, '.', ',');
$canManage = is_admin();
$monthNames = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$openingMonthLabel = $monthNames[(int) substr($month, 5, 2)] . ' de ' . substr($month, 0, 4);
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title"><div><h1 class="mb-0">Cuenta soles empresa</h1><p class="mb-0">Gastos y depósitos de la cuenta.</p></div><a class="btn btn-outline-primary" href="<?= e(APP_URL . '/modulos/finanzas/gastos_personal.php') ?>"><i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Volver a la lista</a></div>
<section class="work-panel company-account-panel mb-3">
    <div class="company-account-details"><div><small class="text-uppercase text-muted fw-bold">Cuenta</small><h2 class="h5 mb-0">Cuenta soles empresa</h2></div><div><small class="text-uppercase text-muted fw-bold">Empresa</small><div class="fw-bold">Life Maquinarias</div></div></div>
    <?php if ($databaseReady && $mode === 'mes'): ?><div class="company-opening-balance"><span class="company-opening-label"><i class="fa-solid fa-wallet" aria-hidden="true"></i>Saldo inicial de <?= e($openingMonthLabel) ?></span><div class="company-opening-value"><strong><?= $opening === null ? 'Sin registrar' : e($money((int) round((float) $opening * 100))) ?></strong><?php if ($canManage): ?><button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#companyOpeningModal" title="Editar saldo inicial" aria-label="Editar saldo inicial"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><?php endif; ?></div></div><?php endif; ?>
</section>
<section class="work-panel">
    <div class="mb-3"><h2 class="h5 mb-0">Movimientos</h2></div>
    <form class="d-flex flex-wrap align-items-end gap-2 mb-3" method="get">
        <div><label class="form-label mb-1" for="companyPeriodMode">Periodo</label><select class="form-select form-select-sm" id="companyPeriodMode" name="periodo"><option value="mes" <?= $mode === 'mes' ? 'selected' : '' ?>>Por mes</option><option value="fechas" <?= $mode === 'fechas' ? 'selected' : '' ?>>Entre fechas</option></select></div>
        <div id="companyMonthField" <?= $mode !== 'mes' ? 'hidden' : '' ?>><label class="form-label mb-1" for="companyMonth">Mes</label><input class="form-control form-control-sm" id="companyMonth" name="mes" type="month" value="<?= e($month) ?>" <?= $mode === 'mes' ? '' : 'disabled' ?>></div>
        <div id="companyFromField" <?= $mode !== 'fechas' ? 'hidden' : '' ?>><label class="form-label mb-1" for="companyFrom">Desde</label><input class="form-control form-control-sm" id="companyFrom" name="desde" type="date" value="<?= e($from) ?>" <?= $mode === 'fechas' ? '' : 'disabled' ?>></div>
        <div id="companyToField" <?= $mode !== 'fechas' ? 'hidden' : '' ?>><label class="form-label mb-1" for="companyTo">Hasta</label><input class="form-control form-control-sm" id="companyTo" name="hasta" type="date" value="<?= e($to) ?>" <?= $mode === 'fechas' ? '' : 'disabled' ?>></div>
        <button class="btn btn-sm btn-outline-primary" type="submit">Ver</button>
    </form>
    <?php if (!$databaseReady): ?><div class="alert alert-warning mb-0">Para usar esta cuenta, ejecute primero <strong>database/finance_company_sole_account.sql</strong> en la base de datos.</div><?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Fecha</th><th>Gastos</th><th>Concepto Gastos</th><th>Depósito</th><th>Concepto Depósito</th><?php if ($canManage): ?><th>Acciones</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($records as $record): ?>
    <tr>
        <td class="text-nowrap"><?= e(date('d/m/Y', strtotime((string) $record['movement_date']))) ?></td>
        <td class="text-nowrap"><?= (float) $record['expense'] > 0 ? e($money((int) round((float) $record['expense'] * 100))) : '—' ?></td>
        <td><?= e((string) ($record['expense_concept'] ?: '—')) ?></td>
        <td class="text-nowrap"><?= (float) $record['deposit'] > 0 ? e($money((int) round((float) $record['deposit'] * 100))) : '—' ?></td>
        <td><?= e((string) ($record['deposit_concept'] ?: '—')) ?></td>
        <?php if ($canManage): ?><td class="text-nowrap">
            <?php if ($record['source'] === 'manual'): ?>
            <button class="btn btn-sm btn-outline-primary company-edit" type="button" data-bs-toggle="modal" data-bs-target="#companyMovementModal" data-record="<?= e(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>" title="Editar" aria-label="Editar movimiento"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
            <button class="btn btn-sm btn-outline-danger company-delete" type="button" data-id="<?= (int) $record['id'] ?>" title="Eliminar" aria-label="Eliminar movimiento"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
            <?php else: ?>
            <button class="btn btn-sm btn-outline-primary company-source-edit" type="button" data-bs-toggle="modal" data-bs-target="#companySourceModal" data-record="<?= e(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>" title="Editar" aria-label="Editar gasto"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
            <?php if (!empty($record['file_url'])): ?><a class="btn btn-sm btn-outline-primary" href="<?= e((string) $record['file_url']) ?>" target="_blank" rel="noopener noreferrer" title="Abrir archivo" aria-label="Abrir imagen o PDF adjunto"><i class="fa-solid <?= !empty($record['file_is_pdf']) ? 'fa-file-pdf' : 'fa-file-image' ?>" aria-hidden="true"></i></a><?php endif; ?>
            <button class="btn btn-sm btn-outline-danger company-source-delete" type="button" data-id="<?= (int) $record['id'] ?>" title="Eliminar" aria-label="Eliminar gasto de la cuenta"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
            <?php endif; ?>
        </td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
    <?php if (!$records): ?><tr><td colspan="<?= $canManage ? 6 : 5 ?>" class="text-center text-muted py-4">Aún no hay movimientos en este periodo.</td></tr><?php endif; ?>
    </tbody><tfoot class="table-light"><tr><td></td><td class="fw-bold text-nowrap"><?= e($money($expenseTotalCents)) ?></td><td colspan="<?= $canManage ? 4 : 3 ?>"></td></tr></tfoot></table></div>
    <?php endif; ?>
</section>
<?php if ($databaseReady && $canManage): ?>
<div class="modal fade" id="companyOpeningModal" tabindex="-1" aria-labelledby="companyOpeningTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="companyOpeningTitle">Saldo inicial del mes</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><form id="companyOpeningForm"><div class="modal-body"><label class="form-label" for="companyOpeningMonth">Mes</label><input class="form-control mb-3" id="companyOpeningMonth" name="month" type="month" value="<?= e($month) ?>" required><label class="form-label" for="companyOpeningAmount">Saldo inicial (S/)</label><input class="form-control" id="companyOpeningAmount" name="amount" type="number" min="0" max="9999999999.99" step="0.01" value="<?= e($opening ?? '') ?>" required><div class="form-text">Este valor inicia el saldo de ese mes. Puede corregirlo después.</div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar saldo</button></div></form></div></div></div>
<div class="modal fade" id="companyMovementModal" tabindex="-1" aria-labelledby="companyMovementTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="companyMovementTitle">Nuevo movimiento</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><form id="companyMovementForm"><div class="modal-body"><input type="hidden" name="id"><label class="form-label" for="companyMovementDate">Fecha</label><input class="form-control mb-3" id="companyMovementDate" name="movement_date" type="date" value="<?= e(date('Y-m-d')) ?>" required><div class="row g-3"><div class="col-md-6"><label class="form-label" for="companyExpense">Gastos (S/)</label><input class="form-control" id="companyExpense" name="expense" type="number" min="0" max="9999999999.99" step="0.01" placeholder="0.00"><label class="form-label mt-3" for="companyExpenseConcept">Concepto Gastos</label><textarea class="form-control" id="companyExpenseConcept" name="expense_concept" maxlength="500" rows="2"></textarea></div><div class="col-md-6"><label class="form-label" for="companyDeposit">Depósito (S/)</label><input class="form-control" id="companyDeposit" name="deposit" type="number" min="0" max="9999999999.99" step="0.01" placeholder="0.00"><label class="form-label mt-3" for="companyDepositConcept">Concepto Depósito</label><textarea class="form-control" id="companyDepositConcept" name="deposit_concept" maxlength="500" rows="2"></textarea></div></div><div class="form-text mt-3">Registre un gasto o un depósito por movimiento.</div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar</button></div></form></div></div></div>
<div class="modal fade" id="companySourceModal" tabindex="-1" aria-labelledby="companySourceTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="companySourceTitle">Editar gasto</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><form id="companySourceForm"><div class="modal-body">
    <input type="hidden" name="id"><input type="hidden" name="action" value="save">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="companySourceDate">Fecha</label><input class="form-control mb-3" id="companySourceDate" name="fecha" type="date" required>
            <label class="form-label" for="companySourceCreditor">Concepto Gastos</label><input class="form-control mb-3" id="companySourceCreditor" name="acreedor" maxlength="150" required>
            <label class="form-label" for="companySourceAmount">Gastos (S/)</label><input class="form-control" id="companySourceAmount" name="monto" type="number" min="0.01" max="999999999.99" step="0.01" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="companySourceDeposit">Depósito (S/)</label><input class="form-control mb-3" id="companySourceDeposit" name="deposit" type="number" min="0" max="9999999999.99" step="0.01" placeholder="0.00">
            <label class="form-label" for="companySourceDepositConcept">Concepto Depósito</label><textarea class="form-control mb-3" id="companySourceDepositConcept" name="deposit_concept" maxlength="500" rows="2"></textarea>
            <label class="form-label" for="companySourceProof">Imagen o PDF <span class="fw-normal text-muted">(opcional)</span></label><input class="form-control" id="companySourceProof" name="proof" type="file" accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf"><div class="form-text">Hasta 5 MB.</div>
        </div>
    </div>
</div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar cambios</button></div></form></div></div></div>
<?php endif; ?>
<style>
.company-account-panel{display:flex;align-items:center;justify-content:space-between;gap:24px;background:#f9f7f3}
.company-account-details{display:grid;grid-template-columns:minmax(170px,1fr) minmax(170px,1fr);gap:18px 28px;flex:1;min-width:0}
.company-account-details h2,.company-account-details .fw-bold{color:#172b4d}
.company-opening-balance{flex:none;width:min(350px,100%);min-height:96px;padding:16px 18px;border:1px solid #ecd3a1;border-radius:13px;background:linear-gradient(135deg,#fff2d9 0%,#fffcf5 100%);box-shadow:0 5px 15px rgba(139,92,22,.09)}
.company-opening-label{display:flex;align-items:center;gap:7px;margin-bottom:7px;color:#805a24;font-size:.7rem;font-weight:800;letter-spacing:.035em;line-height:1.35;text-transform:uppercase}
.company-opening-label i{display:inline-grid;place-items:center;flex:none;width:25px;height:25px;border-radius:7px;background:#fce6bc;color:#a36b18;font-size:.75rem}
.company-opening-value{display:flex;align-items:center;justify-content:space-between;gap:8px}
.company-opening-value strong{color:#75480d;font-size:1.45rem;font-weight:800;line-height:1.15}
.company-opening-value .btn{flex:none;width:36px;height:36px;padding:0;border-radius:9px;background:#fff;border-color:#e6c98e;color:#885b17}
.company-opening-value .btn:hover{background:#fff0d4;border-color:#d6ac62;color:#75480d}
@media(max-width:800px){.company-account-panel{flex-wrap:wrap}.company-opening-balance{width:100%}}
@media(max-width:520px){.company-account-details{grid-template-columns:1fr}.company-opening-value strong{font-size:1.25rem}}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const mode = document.getElementById('companyPeriodMode');
    mode.addEventListener('change', () => { const monthly = mode.value === 'mes'; for (const [id, show] of [['companyMonthField', monthly], ['companyFromField', !monthly], ['companyToField', !monthly]]) { const field = document.getElementById(id); field.hidden = !show; field.querySelector('input').disabled = !show; } });
    const endpoint = <?= json_encode(APP_URL . '/servicios/finanzas/cuenta_soles_empresa_guardar.php') ?>;
    const sourceEndpoint = <?= json_encode(APP_URL . '/servicios/finanzas/cuenta_soles_empresa_deposito.php') ?>;
    const csrf = <?= json_encode(csrf_token()) ?>;
    async function submit(data, button) {
        data.set('csrf_token', csrf); button.disabled = true;
        try { const response = await fetch(endpoint, {method: 'POST', body: data, credentials: 'same-origin'}); const result = await response.json(); if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo guardar.'); location.reload(); }
        catch (error) { Swal.fire('Atención', error.message || 'Inténtelo nuevamente.', 'warning'); button.disabled = false; }
    }
    const openingForm = document.getElementById('companyOpeningForm');
    openingForm?.addEventListener('submit', event => { event.preventDefault(); if (openingForm.reportValidity()) { const data = new FormData(openingForm); data.set('action', 'opening'); submit(data, openingForm.querySelector('[type="submit"]')); } });
    const movementForm = document.getElementById('companyMovementForm');
    if (!movementForm) return;
    document.getElementById('companyMovementModal').addEventListener('show.bs.modal', event => { movementForm.reset(); movementForm.elements.id.value = ''; document.getElementById('companyMovementTitle').textContent = 'Nuevo movimiento'; const edit = event.relatedTarget?.closest('.company-edit'); if (!edit) return; const row = JSON.parse(edit.dataset.record); for (const key of ['id', 'movement_date', 'expense', 'expense_concept', 'deposit', 'deposit_concept']) movementForm.elements[key].value = row[key] ?? ''; document.getElementById('companyMovementTitle').textContent = 'Editar movimiento'; });
    movementForm.addEventListener('submit', event => { event.preventDefault(); if (!movementForm.reportValidity()) return; const expense = Number(movementForm.elements.expense.value || 0); const deposit = Number(movementForm.elements.deposit.value || 0); if ((expense <= 0 && deposit <= 0) || (expense > 0 && deposit > 0)) { Swal.fire('Revise los importes', 'Ingrese solo un gasto o un depósito mayor que cero.', 'warning'); return; } const data = new FormData(movementForm); data.set('action', 'save'); submit(data, movementForm.querySelector('[type="submit"]')); });
    document.querySelectorAll('.company-delete').forEach(button => button.addEventListener('click', async () => { const answer = await Swal.fire({title: '¿Eliminar este movimiento?', text: 'El saldo se actualizará automáticamente.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc3545'}); if (!answer.isConfirmed) return; const data = new FormData(); data.set('action', 'delete'); data.set('id', button.dataset.id); submit(data, button); }));
    async function sourceSubmit(data, button) {
        data.set('csrf_token', csrf);
        button.disabled = true;
        try {
            const response = await fetch(sourceEndpoint, {method: 'POST', body: data, credentials: 'same-origin'});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo modificar el gasto.');
            location.reload();
        } catch (error) {
            Swal.fire('Atención', error.message || 'Inténtelo nuevamente.', 'warning');
            button.disabled = false;
        }
    }
    const sourceForm = document.getElementById('companySourceForm');
    document.getElementById('companySourceModal').addEventListener('show.bs.modal', event => {
        sourceForm.reset();
        const row = JSON.parse(event.relatedTarget.dataset.record);
        sourceForm.elements.id.value = row.id;
        sourceForm.elements.fecha.value = row.movement_date;
        sourceForm.elements.acreedor.value = row.expense_concept;
        sourceForm.elements.monto.value = row.expense;
        sourceForm.elements.deposit.value = row.deposit;
        sourceForm.elements.deposit_concept.value = row.deposit_concept;
        const batch = row.batch_id !== null;
        sourceForm.elements.acreedor.readOnly = batch;
    });
    sourceForm.addEventListener('submit', event => {
        event.preventDefault();
        if (!sourceForm.reportValidity()) return;
        const file = sourceForm.elements.proof.files[0];
        if (file && file.size > 5 * 1024 * 1024) { Swal.fire('Archivo demasiado grande', 'La imagen o PDF debe tener hasta 5 MB.', 'warning'); return; }
        sourceSubmit(new FormData(sourceForm), sourceForm.querySelector('[type="submit"]'));
    });
    document.querySelectorAll('.company-source-delete').forEach(button => button.addEventListener('click', async () => {
        const answer = await Swal.fire({title: '¿Quitar este gasto de la cuenta?', text: 'El depósito original se conservará en Depósitos al personal. El saldo de esta cuenta se recalculará.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, quitar', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc3545'});
        if (!answer.isConfirmed) return;
        const data = new FormData();
        data.set('action', 'remove');
        data.set('id', button.dataset.id);
        sourceSubmit(data, button);
    }));
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
