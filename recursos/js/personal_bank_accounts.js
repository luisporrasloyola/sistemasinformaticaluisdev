document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('workerBankAccounts');
    const mainForm = document.getElementById('personalForm');
    if (!container || !mainForm) return;

    const accounts = JSON.parse(container.dataset.accounts || '[]');
    const body = document.getElementById('workerBankAccountsBody');
    const dataInput = document.getElementById('workerBankAccountsData');
    const form = document.getElementById('workerBankAccountForm');
    const modalElement = document.getElementById('workerBankAccountModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const bankSelect = document.getElementById('workerBankSelect');
    const accountNumber = document.getElementById('workerBankAccountNumber');
    const currency = document.getElementById('workerBankCurrency');
    const cci = document.getElementById('workerBankCci');
    const csrfToken = mainForm.querySelector('[name="csrf_token"]').value;
    const serviceUrl = container.dataset.bankService;
    const accountServiceUrl = container.dataset.accountService;
    const workerId = Number(container.dataset.workerId);
    let editingIndex = -1;

    function syncAccounts() {
        dataInput.value = JSON.stringify(accounts.map(({bank_id, account_number, currency, cci}) => ({bank_id, account_number, currency, cci})));
    }

    function renderAccounts() {
        body.replaceChildren();
        if (!accounts.length) {
            const row = body.insertRow();
            const cell = row.insertCell();
            cell.colSpan = 5;
            cell.className = 'text-muted text-center py-3';
            cell.textContent = 'Aún no hay cuentas bancarias registradas.';
            syncAccounts();
            return;
        }
        accounts.forEach((account, index) => {
            const row = body.insertRow();
            const bankName = [...bankSelect.options].find(option => option.value === String(account.bank_id))?.textContent || account.bank_name || 'Banco';
            [bankName, account.account_number, account.currency === 'USD' ? 'Dólares (USD)' : 'Soles (PEN)', account.cci].forEach(value => {
                row.insertCell().textContent = value;
            });
            const actions = row.insertCell();
            actions.className = 'text-nowrap';
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'btn btn-sm btn-outline-primary me-1';
            edit.title = 'Editar cuenta';
            edit.setAttribute('aria-label', `Editar cuenta de ${bankName}`);
            edit.innerHTML = '<i class="fa-solid fa-pen" aria-hidden="true"></i>';
            edit.addEventListener('click', () => openAccount(index));
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-outline-danger';
            remove.title = 'Eliminar cuenta';
            remove.setAttribute('aria-label', `Eliminar cuenta de ${bankName}`);
            remove.innerHTML = '<i class="fa-solid fa-trash" aria-hidden="true"></i>';
            remove.addEventListener('click', async () => {
                remove.disabled = true;
                try {
                    if (workerId > 0 && account.id) {
                        await requestAccount('delete', {id: account.id});
                    }
                    accounts.splice(index, 1);
                    renderAccounts();
                } catch (error) {
                    remove.disabled = false;
                    await Swal.fire('Atención', error.message, 'warning');
                }
            });
            actions.append(edit, remove);
        });
        syncAccounts();
    }

    function openAccount(index = -1) {
        editingIndex = index;
        form.reset();
        form.classList.remove('was-validated');
        const account = accounts[index];
        document.getElementById('workerBankAccountModalTitle').textContent = account ? 'Editar cuenta bancaria' : 'Nueva cuenta bancaria';
        form.querySelector('[type="submit"]').textContent = account ? 'Guardar cambios' : 'Agregar cuenta';
        if (account) {
            bankSelect.value = String(account.bank_id);
            accountNumber.value = account.account_number;
            currency.value = account.currency;
            cci.value = account.cci;
        }
        modal.show();
    }

    document.getElementById('newWorkerBankAccountBtn').addEventListener('click', () => openAccount());
    form.addEventListener('submit', async event => {
        event.preventDefault();
        form.classList.add('was-validated');
        if (!form.checkValidity()) return;
        if (editingIndex < 0 && accounts.length >= 20) {
            Swal.fire('Atención', 'Puede registrar hasta 20 cuentas bancarias por trabajador.', 'warning');
            return;
        }
        const account = {
            bank_id: Number(bankSelect.value),
            account_number: accountNumber.value.trim(),
            currency: currency.value,
            cci: cci.value.trim(),
        };
        if (accounts.some((existing, index) => index !== editingIndex && Number(existing.bank_id) === account.bank_id && existing.account_number === account.account_number && existing.currency === account.currency)) {
            Swal.fire('Atención', 'Esta cuenta bancaria ya está en la lista.', 'warning');
            return;
        }
        const submitButton = form.querySelector('[type="submit"]');
        submitButton.disabled = true;
        try {
            if (workerId > 0) {
                const result = await requestAccount('save', {id: accounts[editingIndex]?.id || 0, ...account});
                account.id = result.id;
                account.bank_name = result.bank_name;
            }
            if (editingIndex < 0) accounts.push(account);
            else accounts[editingIndex] = account;
            renderAccounts();
            modal.hide();
        } catch (error) {
            await bankDialog({title: 'Atención', text: error.message, icon: 'warning'});
        } finally {
            submitButton.disabled = false;
        }
    });

    async function requestAccount(action, values) {
        const payload = new FormData();
        payload.set('csrf_token', csrfToken);
        payload.set('worker_id', String(workerId));
        payload.set('action', action);
        Object.entries(values).forEach(([key, value]) => payload.set(key, String(value)));
        const response = await fetch(accountServiceUrl, {method: 'POST', body: payload});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo actualizar la cuenta bancaria.');
        return result;
    }

    async function requestBank(action, values = {}) {
        const payload = new FormData();
        payload.set('csrf_token', csrfToken);
        payload.set('action', action);
        Object.entries(values).forEach(([key, value]) => payload.set(key, value));
        const response = await fetch(serviceUrl, {method: 'POST', body: payload});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo actualizar el banco.');
        return result;
    }

    async function bankDialog(options) {
        const focusTrap = modal._focustrap;
        focusTrap?.deactivate?.();
        try { return await Swal.fire(options); }
        finally { if (modalElement.classList.contains('show')) focusTrap?.activate?.(); }
    }

    document.getElementById('addWorkerBankBtn').addEventListener('click', async () => {
        const answer = await bankDialog({title: 'Agregar banco', input: 'text', inputPlaceholder: 'Nombre del banco', inputAttributes: {maxlength: '120'}, showCancelButton: true, confirmButtonText: 'Agregar', cancelButtonText: 'Cancelar', inputValidator: value => !value?.trim() ? 'Ingrese el nombre del banco.' : undefined});
        if (!answer.isConfirmed) return;
        try {
            const result = await requestBank('add', {name: answer.value.trim()});
            let option = [...bankSelect.options].find(item => item.value === String(result.id));
            if (!option) {
                option = new Option(result.name, String(result.id));
                bankSelect.add(option);
            }
            bankSelect.value = String(result.id);
        } catch (error) {
            await bankDialog({title: 'Atención', text: error.message, icon: 'warning'});
        }
    });

    document.getElementById('deleteWorkerBankBtn').addEventListener('click', async () => {
        const bankId = Number(bankSelect.value);
        if (!bankId) {
            await bankDialog({title: 'Atención', text: 'Seleccione un banco para eliminar.', icon: 'warning'});
            return;
        }
        if (accounts.some(account => Number(account.bank_id) === bankId)) {
            await bankDialog({title: 'Atención', text: 'Este banco tiene una cuenta en la lista. Quite la cuenta primero.', icon: 'warning'});
            return;
        }
        const answer = await bankDialog({title: '¿Eliminar banco?', text: 'Solo se eliminará si no tiene cuentas registradas.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar'});
        if (!answer.isConfirmed) return;
        try {
            await requestBank('delete', {id: String(bankId)});
            bankSelect.selectedOptions[0]?.remove();
            bankSelect.value = '';
        } catch (error) {
            await bankDialog({title: 'Atención', text: error.message, icon: 'warning'});
        }
    });

    renderAccounts();
});
