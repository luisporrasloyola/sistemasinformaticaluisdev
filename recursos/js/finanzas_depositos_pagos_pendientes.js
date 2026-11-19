document.addEventListener('DOMContentLoaded', () => {
    const settings = window.pendingDepositLinks;
    if (!settings?.canLink) return;
    const rows = document.getElementById('personalDepositRows');
    const modal = document.getElementById('applyDepositModal');
    const form = document.getElementById('applyDepositForm');
    if (!rows || !modal || !form) return;
    const choice = document.getElementById('applyDepositDebt');
    const amount = document.getElementById('applyDepositAmount');
    const detail = document.getElementById('applyDepositDebtDetail');
    const debts = settings.debts || [];
    const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLocaleLowerCase('es');
    const tokens = value => normalize(value).match(/[\p{L}\p{N}]+\*?/gu) || [];
    const matchingDebts = creditor => debts.filter(debt => {
        const depositWords = tokens(creditor), debtWords = tokens(debt.creditor);
        return depositWords.some(word => {
            const masked = word.endsWith('*');
            const value = word.replace(/\*$/, '');
            if (value.length < (masked ? 3 : 4)) return false;
            return debtWords.some(candidate => masked ? candidate.startsWith(value) : candidate === value);
        });
    });
    const money = value => `S/ ${Number(value).toLocaleString('es-PE', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    let activeRow = null;

    function addButtons() {
        rows.querySelectorAll('tr[data-id]').forEach(row => {
            const actions = row.querySelector('.personal-deposit-actions');
            if (!actions) return;
            const applied = Number(settings.applied?.[row.dataset.id] || 0);
            if (!matchingDebts(row.dataset.creditor).length || actions.querySelector('[data-pending-action="apply"]') || Number(row.dataset.amount) - applied < 0.01) return;
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'personal-deposit-action';
            button.dataset.pendingAction = 'apply';
            button.title = button.ariaLabel = 'Aplicar a pago pendiente';
            button.innerHTML = '<i class="fa-solid fa-link" aria-hidden="true"></i>';
            actions.prepend(button);
        });
    }
    addButtons();
    new MutationObserver(addButtons).observe(rows, {childList: true, subtree: true});

    rows.addEventListener('click', event => {
        const button = event.target.closest('[data-pending-action="apply"]');
        if (!button) return;
        event.stopPropagation();
        activeRow = button.closest('tr[data-id]');
        if (!activeRow) return;
        const available = Math.max(0, Number(activeRow.dataset.amount) - Number(settings.applied?.[activeRow.dataset.id] || 0));
        document.getElementById('applyDepositSummary').textContent = `${activeRow.dataset.creditor} · ${activeRow.dataset.date.split('-').reverse().join('/')} · Disponible: ${money(available)}`;
        choice.replaceChildren(new Option('Seleccione la deuda y su motivo', ''));
        for (const debt of matchingDebts(activeRow.dataset.creditor)) {
            choice.add(new Option(`${debt.creditor} · ${debt.reason} · pendiente ${money(debt.remaining)}`, debt.id));
        }
        choice.value = ''; amount.value = ''; detail.textContent = '';
        bootstrap.Modal.getOrCreateInstance(modal).show();
    });
    choice.addEventListener('change', () => {
        const debt = debts.find(item => String(item.id) === choice.value);
        if (!debt || !activeRow) { detail.textContent = ''; return; }
        const available = Math.max(0, Number(activeRow.dataset.amount) - Number(settings.applied?.[activeRow.dataset.id] || 0));
        const max = Math.min(available, Number(debt.remaining));
        amount.max = max.toFixed(2); amount.value = max.toFixed(2);
        detail.textContent = `Motivo: ${debt.reason} · Vence: ${debt.due_date.split('-').reverse().join('/')} · Saldo de la deuda: ${money(debt.remaining)}`;
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!form.reportValidity() || !activeRow) return;
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        const data = new FormData();
        data.set('action', 'apply_deposit'); data.set('id', choice.value);
        data.set('deposit_id', activeRow.dataset.id); data.set('amount', amount.value);
        data.set('csrf_token', window.personalDepositGemini.csrf);
        try {
            const response = await fetch(settings.endpoint, {method: 'POST', body: data, credentials: 'same-origin'});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo aplicar el depósito.');
            window.location.reload();
        } catch (error) {
            Swal.fire('No se pudo aplicar', error.message, 'warning');
            button.disabled = false;
        }
    });
});
