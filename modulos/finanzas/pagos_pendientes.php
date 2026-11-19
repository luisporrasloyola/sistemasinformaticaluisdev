<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('finanzas.pagos_pendientes');

$payments = [];
$history = [];
$databaseReady = true;
try {
    $payments = db()->query('SELECT p.id, p.creditor, p.due_date, p.amount, p.reason, COALESCE(SUM(a.amount), 0) AS paid_amount
        FROM finance_pending_payments p LEFT JOIN finance_pending_payment_abonos a ON a.pending_payment_id = p.id
        GROUP BY p.id, p.creditor, p.due_date, p.amount, p.reason
        ORDER BY (p.amount > COALESCE(SUM(a.amount), 0)) DESC, p.due_date, p.id DESC')->fetchAll(PDO::FETCH_ASSOC);
    if ($payments) {
        $ids = array_column($payments, 'id');
        $stmt = db()->prepare('SELECT a.id, a.pending_payment_id, a.payment_date, a.amount, a.deposit_id, (d.image_path IS NOT NULL) AS deposit_has_file, (a.proof_path IS NOT NULL) AS abono_has_file, u.name AS recorded_by
            FROM finance_pending_payment_abonos a LEFT JOIN users u ON u.id = a.recorded_by_user_id
            LEFT JOIN finance_personal_deposits d ON d.id = a.deposit_id
            WHERE a.pending_payment_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY a.payment_date, a.id');
        $stmt->execute($ids);
        foreach ($stmt as $abono) $history[(int) $abono['pending_payment_id']][] = $abono;
    }
} catch (PDOException $error) {
    $databaseReady = false;
}
$today = date('Y-m-d');
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title"><div><h1>Pagos pendientes</h1><p>Finanzas</p></div></div>
<section class="work-panel">
    <?php if (!$databaseReady): ?>
        <div class="alert alert-warning mb-0">Para registrar pagos pendientes, ejecute <code>database/finance_pending_payments.sql</code> en la base de datos.</div>
    <?php else: ?>
        <div class="d-flex justify-content-end mb-3"><button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#newPendingPaymentModal"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Nuevo pago pendiente</button></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0" id="pendingPaymentsTable">
            <thead class="table-light"><tr><th>Acreedor</th><th>Fecha de vencimiento</th><th>Monto</th><th>Motivo</th><th>Status</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $payment):
                $totalCents = (int) round((float) $payment['amount'] * 100);
                $paidCents = (int) round((float) $payment['paid_amount'] * 100);
                $remainingCents = max(0, $totalCents - $paidCents);
                $status = $remainingCents === 0 ? 'Pagado' : ((string) $payment['due_date'] < $today ? 'Vencido' : 'Por vencer');
                $statusClass = $status === 'Pagado' ? 'bg-success' : ($status === 'Vencido' ? 'bg-danger' : 'bg-warning text-dark');
            ?>
                <tr>
                    <td><?= e((string) $payment['creditor']) ?></td>
                    <td class="text-nowrap"><?= e(date('d/m/Y', strtotime((string) $payment['due_date']))) ?></td>
                    <td class="text-nowrap"><strong>S/ <?= number_format($remainingCents / 100, 2, '.', ',') ?></strong><small class="d-block text-muted">Deuda: S/ <?= number_format($totalCents / 100, 2, '.', ',') ?> · Abonado: S/ <?= number_format($paidCents / 100, 2, '.', ',') ?></small></td>
                    <td class="pending-payment-reason"><?= nl2br(e((string) $payment['reason'])) ?></td>
                    <td><span class="badge <?= $statusClass ?>"><?= $status ?></span></td>
                    <td class="text-nowrap">
                        <?php if ($remainingCents > 0): ?><button class="btn btn-sm btn-pending-abono" type="button" data-bs-toggle="modal" data-bs-target="#pendingAbonoModal" data-id="<?= (int) $payment['id'] ?>" data-creditor="<?= e((string) $payment['creditor']) ?>" data-remaining="<?= number_format($remainingCents / 100, 2, '.', '') ?>"><i class="fa-solid fa-money-bill-transfer me-1" aria-hidden="true"></i>Abonar</button><?php endif; ?>
                        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#pendingHistoryModal" data-creditor="<?= e((string) $payment['creditor']) ?>" data-history="<?= e(json_encode($history[(int) $payment['id']] ?? [], JSON_UNESCAPED_UNICODE)) ?>">Ver pagos</button>
                        <button class="btn btn-sm btn-outline-danger pending-delete-button" type="button" data-id="<?= (int) $payment['id'] ?>" data-creditor="<?= e((string) $payment['creditor']) ?>" title="Eliminar pago pendiente" aria-label="Eliminar pago pendiente de <?= e((string) $payment['creditor']) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?><tr><td colspan="6" class="text-center text-muted py-4">Aún no hay pagos pendientes registrados.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php if ($databaseReady): ?>
<div class="modal fade" id="newPendingPaymentModal" tabindex="-1" aria-labelledby="newPendingPaymentTitle" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="newPendingPaymentTitle">Nuevo pago pendiente</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <form class="pending-payment-form" data-action="create"><div class="modal-body">
        <label class="form-label" for="pendingCreditor">Acreedor</label><input class="form-control mb-3" id="pendingCreditor" name="creditor" maxlength="150" required>
        <label class="form-label" for="pendingDueDate">Fecha de vencimiento</label><input class="form-control mb-3" id="pendingDueDate" name="due_date" type="date" required>
        <label class="form-label" for="pendingAmount">Monto de la deuda (S/)</label><input class="form-control mb-3" id="pendingAmount" name="amount" type="number" min="0.01" max="9999999999.99" step="0.01" inputmode="decimal" required>
        <label class="form-label" for="pendingReason">Motivo</label><textarea class="form-control" id="pendingReason" name="reason" rows="3" maxlength="2000" required></textarea>
    </div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar</button></div></form>
</div></div></div>
<div class="modal fade" id="pendingAbonoModal" tabindex="-1" aria-labelledby="pendingAbonoTitle" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="pendingAbonoTitle">Registrar abono</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <form class="pending-payment-form" data-action="abono"><div class="modal-body">
        <input type="hidden" name="id" id="pendingAbonoId"><p class="mb-3" id="pendingAbonoSummary"></p>
        <label class="form-label" for="pendingPaymentDate">Fecha del abono</label><input class="form-control mb-3" id="pendingPaymentDate" name="payment_date" type="date" value="<?= e($today) ?>" required>
        <label class="form-label" for="pendingAbonoAmount">Monto abonado (S/)</label><input class="form-control" id="pendingAbonoAmount" name="amount" type="number" min="0.01" step="0.01" inputmode="decimal" required>
        <label class="form-label mt-3" for="pendingAbonoProof">Comprobante <span class="fw-normal text-muted">(opcional)</span></label>
        <input class="form-control" id="pendingAbonoProof" name="proof" type="file" accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf">
        <div class="form-text">Imagen o PDF de hasta 5 MB.</div>
    </div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar abono</button></div></form>
</div></div></div>
<div class="modal fade" id="pendingHistoryModal" tabindex="-1" aria-labelledby="pendingHistoryTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="pendingHistoryTitle">Pagos realizados</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body"><p id="pendingHistoryCreditor" class="fw-semibold"></p><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Fecha</th><th>Monto</th><th>Registrado por</th><th>Comprobante</th><th>Acción</th></tr></thead><tbody id="pendingHistoryRows"></tbody></table></div></div>
    <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>
<style>.pending-payment-reason{min-width:180px;max-width:360px;white-space:normal}.pending-payment-form .form-label{font-weight:600}.btn-pending-abono{--bs-btn-color:#fff;--bs-btn-bg:#7b3fb2;--bs-btn-border-color:#7b3fb2;--bs-btn-hover-color:#fff;--bs-btn-hover-bg:#653294;--bs-btn-hover-border-color:#653294;--bs-btn-active-color:#fff;--bs-btn-active-bg:#542879;--bs-btn-active-border-color:#542879;--bs-btn-focus-shadow-rgb:123,63,178}</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const endpoint = <?= json_encode(APP_URL . '/servicios/finanzas/pagos_pendientes_guardar.php') ?>;
    const csrf = <?= json_encode(csrf_token()) ?>;
    const depositFileEndpoint = <?= json_encode(APP_URL . '/servicios/finanzas/depositos_personal_archivo.php') ?>;
    const abonoFileEndpoint = <?= json_encode(APP_URL . '/servicios/finanzas/pagos_pendientes_archivo.php') ?>;
    document.querySelectorAll('.pending-payment-form').forEach(form => form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const proof = form.querySelector('input[name="proof"]')?.files?.[0];
        if (proof && proof.size > 5 * 1024 * 1024) {
            Swal.fire('Archivo demasiado grande', 'El comprobante debe ocupar hasta 5 MB.', 'warning');
            return;
        }
        const data = new FormData(form);
        data.set('action', form.dataset.action);
        data.set('csrf_token', csrf);
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            const response = await fetch(endpoint, {method:'POST', body:data, credentials:'same-origin'});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo guardar el pago.');
            window.location.reload();
        } catch (error) {
            Swal.fire('Atención', error.message, 'warning');
            button.disabled = false;
        }
    }));
    document.querySelectorAll('.pending-delete-button').forEach(button => button.addEventListener('click', async () => {
        const confirmation = await Swal.fire({title:'Eliminar pago pendiente', text:`Se eliminará la deuda de ${button.dataset.creditor} y todos sus abonos.`, icon:'warning', showCancelButton:true, confirmButtonText:'Eliminar', cancelButtonText:'Cancelar', confirmButtonColor:'#dc3545'});
        if (!confirmation.isConfirmed) return;
        const data = new FormData();
        data.set('action', 'delete');
        data.set('id', button.dataset.id);
        data.set('csrf_token', csrf);
        button.disabled = true;
        try {
            const response = await fetch(endpoint, {method:'POST', body:data, credentials:'same-origin'});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo eliminar el pago.');
            window.location.reload();
        } catch (error) {
            Swal.fire('Atención', error.message, 'warning');
            button.disabled = false;
        }
    }));
    document.getElementById('pendingAbonoModal').addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        const remaining = Number(button.dataset.remaining);
        document.getElementById('pendingAbonoId').value = button.dataset.id;
        document.getElementById('pendingAbonoSummary').textContent = `${button.dataset.creditor} · Saldo pendiente: S/ ${remaining.toFixed(2)}`;
        const amount = document.getElementById('pendingAbonoAmount');
        amount.max = button.dataset.remaining;
        amount.value = '';
        document.getElementById('pendingAbonoProof').value = '';
    });
    document.getElementById('pendingHistoryModal').addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        document.getElementById('pendingHistoryCreditor').textContent = button.dataset.creditor;
        const rows = document.getElementById('pendingHistoryRows');
        rows.replaceChildren();
        for (const entry of JSON.parse(button.dataset.history || '[]')) {
            const row = document.createElement('tr');
            const date = document.createElement('td'); date.textContent = entry.payment_date.split('-').reverse().join('/');
            const amount = document.createElement('td'); amount.textContent = `S/ ${Number(entry.amount).toFixed(2)}`;
            const author = document.createElement('td'); author.textContent = entry.recorded_by || '—';
            const proof = document.createElement('td');
            if (entry.deposit_id && entry.deposit_has_file) {
                const link = document.createElement('a'); link.href = `${depositFileEndpoint}?id=${encodeURIComponent(entry.deposit_id)}`;
                link.target = '_blank'; link.rel = 'noopener noreferrer';
                link.className = 'btn btn-sm btn-outline-primary';
                link.title = link.ariaLabel = 'Ver comprobante del depósito';
                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-file-image'; icon.setAttribute('aria-hidden', 'true');
                link.append(icon);
                proof.append(link);
            } else if (entry.abono_has_file) {
                const link = document.createElement('a'); link.href = `${abonoFileEndpoint}?id=${encodeURIComponent(entry.id)}`;
                link.target = '_blank'; link.rel = 'noopener noreferrer';
                link.className = 'btn btn-sm btn-outline-primary'; link.title = link.ariaLabel = 'Ver comprobante del abono';
                const icon = document.createElement('i'); icon.className = 'fa-solid fa-file-image'; icon.setAttribute('aria-hidden', 'true');
                link.append(icon); proof.append(link);
            } else proof.textContent = entry.deposit_id ? 'Depósito sin archivo' : 'Sin comprobante';
            const action = document.createElement('td');
            if (entry.deposit_id) {
                const unlink = document.createElement('button'); unlink.type = 'button';
                unlink.className = 'btn btn-sm btn-outline-danger'; unlink.textContent = 'Desvincular';
                unlink.addEventListener('click', async () => {
                    const confirmation = await Swal.fire({title:'Desvincular depósito', text:'El importe volverá a figurar como pendiente.', icon:'question', showCancelButton:true, confirmButtonText:'Desvincular', cancelButtonText:'Cancelar'});
                    if (!confirmation.isConfirmed) return;
                    unlink.disabled = true;
                    try {
                        const data = new FormData(); data.set('action', 'unlink_deposit'); data.set('id', entry.id); data.set('csrf_token', csrf);
                        const response = await fetch(endpoint, {method:'POST', body:data, credentials:'same-origin'});
                        const result = await response.json();
                        if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo desvincular.');
                        window.location.reload();
                    } catch (error) { Swal.fire('Atención', error.message, 'warning'); unlink.disabled = false; }
                });
                action.append(unlink);
            } else {
                const remove = document.createElement('button'); remove.type = 'button';
                remove.className = 'btn btn-sm btn-outline-danger'; remove.textContent = 'Quitar';
                remove.addEventListener('click', async () => {
                    const confirmation = await Swal.fire({title:'Quitar abono', text:'El importe volverá a figurar como pendiente y se eliminará su comprobante adjunto.', icon:'question', showCancelButton:true, confirmButtonText:'Quitar', cancelButtonText:'Cancelar'});
                    if (!confirmation.isConfirmed) return;
                    remove.disabled = true;
                    try {
                        const data = new FormData(); data.set('action', 'delete_abono'); data.set('id', entry.id); data.set('csrf_token', csrf);
                        const response = await fetch(endpoint, {method:'POST', body:data, credentials:'same-origin'});
                        const result = await response.json();
                        if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo quitar el abono.');
                        window.location.reload();
                    } catch (error) { Swal.fire('Atención', error.message, 'warning'); remove.disabled = false; }
                });
                action.append(remove);
            }
            row.append(date, amount, author, proof, action); rows.append(row);
        }
        if (!rows.children.length) {
            const row = document.createElement('tr'); const cell = document.createElement('td');
            cell.colSpan = 5; cell.className = 'text-muted'; cell.textContent = 'Todavía no hay pagos registrados.';
            row.append(cell); rows.append(row);
        }
    });
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
