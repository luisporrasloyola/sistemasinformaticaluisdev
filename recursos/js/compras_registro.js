document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('personalDepositFile');
    if (!input) return;
    const errorBox = document.getElementById('personalDepositFileError');
    const selection = document.getElementById('personalDepositSelection');
    const cards = document.getElementById('personalDepositCards');
    const count = document.getElementById('personalDepositFileCount');
    const progress = document.getElementById('personalDepositProgress');
    const progressText = document.getElementById('personalDepositProgressText');
    const progressBar = document.getElementById('personalDepositProgressBar');
    const settings = window.purchaseRegistration;
    const periodMode = document.getElementById('personalDepositPeriodMode');
    const periodFields = [
        ['mes', document.getElementById('personalDepositMonthField'), document.getElementById('personalDepositMonth')],
        ['fechas', document.getElementById('personalDepositFromField'), document.getElementById('personalDepositFrom')],
        ['fechas', document.getElementById('personalDepositToField'), document.getElementById('personalDepositTo')]
    ];
    periodMode.addEventListener('change', () => {
        for (const [mode, wrapper, field] of periodFields) {
            const active = periodMode.value === mode;
            wrapper.hidden = !active;
            field.disabled = !active;
            field.required = active;
        }
    });
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    const readable = [...allowed, 'application/pdf'];
    let generation = 0;
    let previewUrls = [];
    let readingQueue = false;
    let savedDates = [];
    let batchSaved = false;

    function showSavedPeriod() {
        if (!savedDates.length) return;
        const last = [...savedDates].sort().at(-1);
        const latestMonth = last.slice(0, 7);
        if (batchSaved && settings.periodMode === 'mes' && settings.periodMonth === latestMonth
            && settings.page === 1 && !settings.search) {
            window.location.reload();
            return;
        }
        if (!batchSaved && settings.periodMode === 'mes' && settings.periodMonth === latestMonth && settings.page === 1 && !settings.search) return;
        const url = new URL(window.location.href);
        url.searchParams.delete('pagina');
        url.searchParams.set('periodo', 'mes');
        url.searchParams.set('mes', latestMonth);
        url.searchParams.delete('desde');
        url.searchParams.delete('hasta');
        url.hash = 'personalDepositRecordsTitle';
        window.location.assign(url.toString());
    }

    function renderRow(row, record) {
        row.dataset.id = String(record.id);
        row.dataset.date = record.fecha;
        row.dataset.registration = record.registro || row.dataset.registration || 'gemini';
        row.dataset.creditor = record.acreedor;
        row.dataset.depositor = record.depositante || '';
        row.dataset.amount = record.monto;
        row.dataset.method = record.modo_pago;
        row.dataset.hasPhoto = record.hasPhoto === undefined ? row.dataset.hasPhoto || '0' : (record.hasPhoto ? '1' : '0');
        row.dataset.isBatch = record.isBatch === undefined ? row.dataset.isBatch || '0' : (record.isBatch ? '1' : '0');
        row.dataset.canManage = record.canManage === undefined ? row.dataset.canManage || '0' : (record.canManage ? '1' : '0');
        row.classList.remove('is-editing');
        row.replaceChildren();
        const [year, month, day] = record.fecha.split('-');
        for (const value of [`${day}/${month}/${year}`, row.dataset.registration === 'manual' || row.dataset.registration === 'Manual' ? 'Manual' : 'Gemini', record.acreedor, `S/ ${Number(record.monto).toFixed(2)}`, record.modo_pago, record.depositante || '—']) {
            const cell = document.createElement('td');
            cell.textContent = value;
            row.appendChild(cell);
        }
        const cell = document.createElement('td');
        const actions = document.createElement('div');
        actions.className = 'personal-deposit-actions';
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'personal-deposit-action';
        edit.dataset.action = 'edit';
        edit.title = edit.ariaLabel = 'Editar compra';
        edit.innerHTML = '<i class="fa-solid fa-pen" aria-hidden="true"></i>';
        const view = document.createElement('a');
        view.className = 'personal-deposit-action is-file';
        view.href = `${settings.fileEndpoint}?id=${encodeURIComponent(record.id)}`;
        view.target = '_blank';
        view.rel = 'noopener noreferrer';
        view.title = view.ariaLabel = 'Ver foto';
        view.innerHTML = '<i class="fa-solid fa-file-image" aria-hidden="true"></i>';
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'personal-deposit-action is-delete';
        remove.dataset.action = 'delete';
        remove.title = remove.ariaLabel = 'Eliminar compra';
        remove.innerHTML = '<i class="fa-solid fa-trash" aria-hidden="true"></i>';
        if (row.dataset.canManage === '1') actions.appendChild(edit);
        if (row.dataset.hasPhoto === '1') actions.appendChild(view);
        if (row.dataset.canManage === '1') actions.appendChild(remove);
        cell.appendChild(actions);
        row.appendChild(cell);
    }

    const rows = document.getElementById('personalDepositRows');
    const totalCell = document.getElementById('personalDepositTotal');
    const manualForm = document.getElementById('personalDepositManualForm');
    manualForm?.addEventListener('submit', async event => {
        event.preventDefault();
        const workerSelect = document.getElementById('manualDepositCreditor');
        if (!workerSelect.value) {
            const error = document.getElementById('personalDepositManualError');
            error.textContent = 'Busque y seleccione al acreedor de la compra.';
            error.hidden = false;
            if (window.jQuery && window.jQuery.fn.select2) window.jQuery(workerSelect).select2('open');
            else workerSelect.focus();
            return;
        }
        if (!manualForm.reportValidity()) return;
        const photo = manualForm.elements.foto.files[0];
        if (photo && (!allowed.includes(photo.type) || photo.size > 5 * 1024 * 1024)) {
            const error = document.getElementById('personalDepositManualError');
            error.textContent = 'Seleccione una foto JPG, PNG o WEBP de máximo 5 MB.';
            error.hidden = false;
            return;
        }
        const button = document.getElementById('personalDepositManualSave');
        const error = document.getElementById('personalDepositManualError');
        error.hidden = true;
        button.disabled = true;
        button.textContent = 'Guardando…';
        try {
            const data = new FormData(manualForm);
            data.set('manual', '1');
            data.set('csrf_token', settings.csrf);
            const response = await fetch(settings.manualSaveEndpoint, {method: 'POST', body: data, credentials: 'same-origin'});
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'No se pudo guardar la compra.');
            const url = new URL(window.location.href);
            url.searchParams.set('periodo', 'mes');
            url.searchParams.set('mes', result.fecha.slice(0, 7));
            url.searchParams.delete('desde');
            url.searchParams.delete('hasta');
            url.searchParams.delete('buscar');
            url.searchParams.delete('pagina');
            url.hash = 'personalDepositRecordsTitle';
            window.location.assign(url.toString());
        } catch (cause) {
            error.textContent = cause.message || 'No se pudo guardar la compra.';
            error.hidden = false;
            button.disabled = false;
            button.textContent = 'Guardar compra';
        }
    });
    function adjustTotal(cents) {
        const next = Number(totalCell.dataset.cents || 0) + cents;
        totalCell.dataset.cents = String(next);
        totalCell.textContent = `S/ ${(next / 100).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }
    rows.addEventListener('click', async event => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;
        const row = button.closest('tr[data-id]');
        if (!row) return;
        const action = button.dataset.action;
        if (row.dataset.canManage !== '1') return;
        if (action === 'edit') {
            if (rows.querySelector('tr.is-editing')) return;
            row.classList.add('is-editing');
            const values = [row.dataset.date, null, row.dataset.creditor, row.dataset.amount, row.dataset.method, row.dataset.depositor || ''];
            const types = ['date', null, 'text', 'number', 'text', 'text'];
            row.querySelectorAll('td').forEach((cell, index) => {
                if (index === 1 || index > 5) return;
                const field = document.createElement('input');
                field.className = 'form-control form-control-sm';
                field.type = types[index];
                field.value = values[index];
                field.required = index !== 5;
                if (index === 2) { field.maxLength = 150; field.readOnly = row.dataset.isBatch === '1' || row.dataset.registration === 'manual'; }
                if (index === 3) { field.min = '0.01'; field.step = '0.01'; }
                if (index === 4) field.maxLength = 100;
                if (index === 5) { field.maxLength = 150; field.placeholder = 'Depositante (opcional)'; }
                cell.replaceChildren(field);
            });
            const actions = row.querySelector('.personal-deposit-actions');
            actions.querySelector('[data-action="edit"]').remove();
            actions.querySelector('[data-action="delete"]').remove();
            for (const [name, icon, className] of [['save', 'fa-check', 'btn-primary'], ['cancel', 'fa-xmark', 'btn-outline-secondary']]) {
                const control = document.createElement('button');
                control.type = 'button';
                control.className = `btn btn-sm ${className}`;
                control.dataset.action = name;
                control.title = control.ariaLabel = name === 'save' ? 'Guardar cambios' : 'Cancelar edición';
                control.innerHTML = `<i class="fa-solid ${icon}" aria-hidden="true"></i>`;
                actions.appendChild(control);
            }
            row.querySelector('input').focus();
            return;
        }
        const record = {id: row.dataset.id, fecha: row.dataset.date, registro: row.dataset.registration, acreedor: row.dataset.creditor, monto: row.dataset.amount, modo_pago: row.dataset.method, depositante: row.dataset.depositor || ''};
        if (action === 'cancel') { renderRow(row, record); return; }
        if (action === 'delete') {
            const confirmed = window.Swal ? (await Swal.fire({title: '¿Eliminar esta compra?', text: row.dataset.hasPhoto === '1' ? 'También se eliminará el comprobante. Esta acción no se puede deshacer.' : 'Esta acción no se puede deshacer.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc3545'})).isConfirmed : window.confirm(row.dataset.hasPhoto === '1' ? '¿Eliminar esta compra y su comprobante?' : '¿Eliminar esta compra?');
            if (!confirmed) return;
        }
        if (action === 'save') {
            const fields = Array.from(row.querySelectorAll('td input'));
            if (!fields.every(field => field.reportValidity())) return;
            [record.fecha, record.acreedor, record.monto, record.modo_pago, record.depositante] = fields.map(field => field.value.trim());
        }
        button.disabled = true;
        try {
            const data = new FormData();
            data.append('csrf_token', settings.csrf);
            data.append('id', record.id);
            data.append('action', action === 'save' ? 'update' : 'delete');
            if (action === 'save') {
                for (const key of ['fecha', 'acreedor', 'monto', 'modo_pago', 'depositante']) data.append(key, record[key]);
            }
            const response = await fetch(settings.actionsEndpoint, {method: 'POST', body: data, credentials: 'same-origin'});
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'No se pudo completar la acción.');
            window.location.reload();
            return;
        } catch (error) {
            if (window.Swal) Swal.fire({icon: 'error', text: error.message});
            else window.alert(error.message);
        } finally {
            button.disabled = false;
        }
    });

    function showError(message) {
        errorBox.textContent = message;
        errorBox.hidden = !message;
    }

    function showProgress(label, percent) {
        progress.hidden = false;
        progressText.textContent = label;
        progressBar.style.width = `${percent}%`;
        progress.querySelector('.progress').setAttribute('aria-valuenow', String(percent));
    }

    function makeField(label, type, key, maxLength) {
        const wrapper = document.createElement('div');
        const caption = document.createElement('label');
        caption.textContent = label;
        const field = document.createElement('input');
        field.className = 'form-control';
        field.type = type;
        field.dataset.field = key;
        if (maxLength) field.maxLength = maxLength;
        if (type === 'number') {
            field.min = '0.01';
            field.step = '0.01';
            field.inputMode = 'decimal';
        }
        caption.appendChild(field);
        wrapper.appendChild(caption);
        return wrapper;
    }

    function makeCard(file, index) {
        const card = document.createElement('article');
        card.className = 'personal-deposit-card';
        const header = document.createElement('div');
        header.className = 'personal-deposit-card-header';
        const title = document.createElement('strong');
        title.textContent = `${index + 1}. ${file.name}`;
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-sm btn-outline-primary';
        retry.textContent = 'Reintentar lectura';
        const save = document.createElement('button');
        save.type = 'button';
        save.className = 'btn btn-sm btn-primary';
        save.textContent = 'Guardar compra';
        save.hidden = true;
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn btn-sm btn-outline-secondary';
        close.textContent = 'Cerrar';
        close.hidden = true;
        const actions = document.createElement('div');
        actions.className = 'd-flex gap-2';
        actions.append(retry, save, close);
        const status = document.createElement('div');
        status.className = 'personal-deposit-status';
        status.setAttribute('role', 'status');
        status.textContent = 'Esperando lectura…';
        header.append(title, actions);
        const preview = document.createElement(file.type === 'application/pdf' ? 'iframe' : 'img');
        preview.className = 'personal-deposit-preview';
        if (file.type === 'application/pdf') {
            preview.title = `PDF ${index + 1} de la compra`;
            preview.style.height = '300px';
            preview.style.width = '100%';
        } else preview.alt = `Foto ${index + 1} de la compra`;
        const url = URL.createObjectURL(file);
        previewUrls.push(url);
        preview.src = url;
        preview.addEventListener('error', () => {
            preview.hidden = true;
        });
        const fields = document.createElement('div');
        fields.className = 'personal-deposit-fields mt-3';
        fields.append(
            makeField('Fecha', 'date', 'fecha'),
            makeField('Acreedor de compra', 'text', 'acreedor', 150),
            makeField('Monto depositado (S/)', 'number', 'monto'),
            makeField('Modo de pago', 'text', 'modo_pago', 100)
        );
        card.append(header, status, preview, fields);
        cards.appendChild(card);
        return {card, status, retry, save, close, file, url, preview, preparedFile: null, saved: false};
    }

    function validFields(card) {
        const fields = Object.fromEntries(Array.from(card.querySelectorAll('[data-field]')).map(field => [field.dataset.field, field]));
        for (const field of Object.values(fields)) {
            field.required = true;
            if (!field.reportValidity()) return null;
        }
        return fields;
    }

    async function saveCard(item, currentGeneration) {
        if (item.saved) return;
        const fields = validFields(item.card);
        if (!fields) {
            item.save.hidden = false;
            return;
        }
        item.save.disabled = true;
        item.retry.disabled = true;
        item.status.textContent = 'Guardando compra…';
        try {
            const data = new FormData();
            data.append('csrf_token', settings.csrf);
            data.append('foto', item.file, item.file.name);
            for (const [key, field] of Object.entries(fields)) data.append(key, field.value.trim());
            const response = await fetch(settings.saveEndpoint, {method: 'POST', body: data, credentials: 'same-origin'}).catch(() => {
                throw new Error('No se pudo conectar para guardar la compra. Revise su conexión e intente de nuevo.');
            });
            const result = await response.json();
            if (currentGeneration !== generation) return;
            if (!response.ok) {
                const error = new Error(result.error || 'No se pudo guardar la compra.');
                error.duplicate = response.status === 409 && result.duplicate === true;
                throw error;
            }
            item.saved = true;
            item.save.hidden = true;
            item.retry.hidden = true;
            item.card.querySelectorAll('[data-field]').forEach(field => { field.readOnly = true; });
            item.status.classList.remove('is-error');
            item.status.textContent = 'Compra guardada correctamente.';
            savedDates.push(result.fecha);
            const visiblePeriod = result.fecha >= settings.periodStart && result.fecha <= settings.periodEnd && settings.page === 1 && !settings.search;
            if (visiblePeriod) {
                adjustTotal(Math.round(Number(result.monto) * 100));
                document.getElementById('personalDepositEmpty')?.remove();
                const row = document.createElement('tr');
                result.canManage = settings.canManageNew;
                renderRow(row, result);
                rows.prepend(row);
                while (rows.querySelectorAll('tr[data-id]').length > 25) rows.querySelector('tr[data-id]:last-of-type')?.remove();
            }
            URL.revokeObjectURL(item.url);
            item.card.remove();
            if (!cards.childElementCount) selection.hidden = true;
            if (!readingQueue) showSavedPeriod();
        } catch (error) {
            if (currentGeneration !== generation) return;
            item.status.classList.add('is-error');
            item.status.textContent = error.message || 'No se pudo guardar la compra.';
            if (error.duplicate) {
                item.retry.hidden = true;
                item.save.hidden = true;
                item.close.hidden = false;
                item.card.querySelectorAll('[data-field]').forEach(field => { field.readOnly = true; });
            } else {
                item.save.hidden = false;
            }
        } finally {
            item.save.disabled = false;
            item.retry.disabled = false;
        }
    }

    async function saveReviewedBatch(item, currentGeneration) {
        const corrections = [];
        for (const row of item.batchReview.querySelectorAll('.purchase-batch-row')) {
            const date = row.querySelector('[data-batch-field="fecha"]');
            const worker = row.querySelector('[data-batch-field="worker_id"]');
            const amount = row.querySelector('[data-batch-field="monto"]');
            if (![date, worker, amount].every(field => field.reportValidity())) return;
            const [year, month, day] = date.value.split('-');
            corrections.push({fecha: `${day}/${month}/${year}`, worker_id: worker.value, monto: amount.value.trim()});
        }
        item.save.disabled = true;
        item.retry.disabled = true;
        try {
            const saved = await window.purchaseBatch.save(item, item.batchResult, currentGeneration, () => generation, corrections);
            if (currentGeneration !== generation) return;
            savedDates.push(...saved.map(record => record.fecha));
            batchSaved = true;
            URL.revokeObjectURL(item.url);
            item.card.remove();
            if (!cards.childElementCount) selection.hidden = true;
            if (!readingQueue) showSavedPeriod();
        } catch (error) {
            item.status.classList.add('is-error');
            item.status.textContent = error.message || 'No se pudo guardar el lote.';
            item.save.hidden = false;
        } finally {
            item.save.disabled = false;
            item.retry.disabled = false;
        }
    }

    function showBatchReview(item, result) {
        item.batchReview?.remove();
        item.batchResult = result;
        item.card.querySelector('.personal-deposit-fields').hidden = true;
        const review = document.createElement('div');
        review.className = 'mt-3';
        result.records.forEach((record, index) => {
            const block = document.createElement('div');
            block.className = 'purchase-batch-row border rounded p-3 mb-2';
            const title = document.createElement('strong');
            title.textContent = `Beneficiario ${index + 1}: ${record.acreedor_leido || 'nombre no identificado'}`;
            const grid = document.createElement('div');
            grid.className = 'row g-2 mt-1';
            const makeInput = (label, type, key, value) => {
                const wrap = document.createElement('label');
                wrap.className = 'col-md-4 small fw-semibold';
                wrap.textContent = label;
                const input = document.createElement('input');
                input.className = 'form-control form-control-sm mt-1';
                input.type = type;
                input.required = true;
                input.dataset.batchField = key;
                input.value = value;
                if (type === 'number') { input.min = '0.01'; input.step = '0.01'; input.max = '999999999.99'; }
                wrap.appendChild(input);
                return wrap;
            };
            const parsed = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(record.fecha || '');
            grid.appendChild(makeInput('Fecha', 'date', 'fecha', parsed ? `${parsed[3]}-${parsed[2]}-${parsed[1]}` : ''));
            const workerWrap = document.createElement('label');
            workerWrap.className = 'col-md-4 small fw-semibold';
            workerWrap.textContent = 'Trabajador vinculado';
            const worker = document.createElement('select');
            worker.className = 'form-select form-select-sm mt-1';
            worker.required = true;
            worker.dataset.batchField = 'worker_id';
            worker.add(new Option('Seleccione un trabajador', ''));
            for (const candidate of settings.workers) worker.add(new Option(candidate.name, String(candidate.id)));
            worker.value = record.worker_id ? String(record.worker_id) : '';
            workerWrap.appendChild(worker);
            grid.appendChild(workerWrap);
            grid.appendChild(makeInput('Monto depositado (S/)', 'number', 'monto', record.monto || ''));
            block.append(title, grid);
            review.appendChild(block);
        });
        item.batchReview = review;
        item.card.appendChild(review);
        item.save.textContent = 'Guardar lote';
        item.save.hidden = false;
        item.status.textContent = 'Revise los datos pendientes y vincule cada beneficiario antes de guardar.';
    }

    async function prepareImage(file) {
        let source;
        let release = () => {};
        try {
            if (typeof createImageBitmap !== 'function') throw new Error('Decodificador no disponible');
            source = await createImageBitmap(file);
            release = () => source.close();
        } catch {
            const objectUrl = URL.createObjectURL(file);
            try {
                source = await new Promise((resolve, reject) => {
                    const image = new Image();
                    image.onload = () => resolve(image);
                    image.onerror = () => reject(new Error('No se pudo abrir la foto. Seleccione otra copia en JPG, PNG o WEBP.'));
                    image.src = objectUrl;
                });
                release = () => URL.revokeObjectURL(objectUrl);
            } catch (error) {
                URL.revokeObjectURL(objectUrl);
                throw error;
            }
        }
        try {
            if (file.type === 'image/jpeg' && file.size <= 1500000 && Math.max(source.width, source.height) <= 2000) {
                return file;
            }
            const canvas = document.createElement('canvas');
            let scale = Math.min(1, 2000 / Math.max(source.width, source.height));
            let blob = null;
            for (let attempt = 0; attempt < 4; attempt++) {
                canvas.width = Math.max(1, Math.round(source.width * scale));
                canvas.height = Math.max(1, Math.round(source.height * scale));
                const context = canvas.getContext('2d', {alpha: false});
                if (!context) throw new Error('No se pudo preparar la foto en este dispositivo.');
                context.fillStyle = '#fff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(source, 0, 0, canvas.width, canvas.height);
                blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', .82 - attempt * .12));
                if (blob && blob.size <= 1500000) break;
                scale *= .75;
            }
            canvas.width = canvas.height = 0;
            if (!blob || blob.size > 1500000) throw new Error('No se pudo preparar una copia ligera de la foto.');
            return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', {type: 'image/jpeg', lastModified: file.lastModified});
        } finally {
            release();
        }
    }

    async function readCard(item, currentGeneration) {
        const {card, status, retry, file} = item;
        item.batchReview?.remove();
        item.batchReview = null;
        item.batchResult = null;
        card.querySelector('.personal-deposit-fields').hidden = false;
        item.save.hidden = true;
        item.save.textContent = 'Guardar compra';
        retry.disabled = true;
        status.classList.remove('is-error');
        status.textContent = 'Leyendo foto con Gemini…';
        try {
            let blob = item.preparedFile;
            if (!blob) {
                try {
                    blob = file.type === 'application/pdf' ? file : await prepareImage(file);
                } catch (decodeError) {
                    if (file.size > 1500000 || !allowed.includes(file.type)) throw decodeError;
                    // Algunos navegadores móviles no pueden dibujar el archivo,
                    // aunque el servidor sí puede leer el JPEG original.
                    blob = file;
                }
            }
            item.preparedFile = blob;
            if (currentGeneration !== generation) return;
            const data = new FormData();
            data.append('csrf_token', settings.csrf);
            const hashBytes = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
            data.append('original_hash', [...new Uint8Array(hashBytes)].map(value => value.toString(16).padStart(2, '0')).join(''));
            data.append('imagen', blob, blob === file ? file.name : 'lectura.jpg');
            const response = await fetch(settings.endpoint, {method: 'POST', body: data, credentials: 'same-origin'}).catch(() => {
                throw new Error('No se pudo conectar para leer la foto. Revise su conexión e intente de nuevo.');
            });
            const result = await response.json();
            if (currentGeneration !== generation) return;
            if (!response.ok) throw new Error(result.error || 'No se pudo leer la foto.');
            if (result.model === 'TRANSFERENCIA_INTERBANK_LOTE') {
                if (result.review_required) {
                    showBatchReview(item, result);
                    return;
                }
                const saved = await window.purchaseBatch.save(item, result, currentGeneration, () => generation);
                if (currentGeneration !== generation) return;
                savedDates.push(...saved.map(record => record.fecha));
                batchSaved = true;
                URL.revokeObjectURL(item.url);
                item.card.remove();
                if (!cards.childElementCount) selection.hidden = true;
                if (!readingQueue) showSavedPeriod();
                return;
            }
            if (file.type === 'application/pdf') throw new Error('El PDF debe contener varios beneficiarios de Interbank para guardarse automáticamente.');
            if (!['YAPE', 'PLIN', 'TRANSFERENCIA_INTERBANK', 'TRANSFERENCIA_BCP', 'TRANSFERENCIA_MOVIMIENTO', 'PAGO_EFECTIVO'].includes(result.model)) {
                status.textContent = 'Modelo pendiente de configurar. Esta foto aún no se puede guardar.';
                return;
            }
            const values = result.fields || {};
            const date = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(values.fecha || '');
            if (date) card.querySelector('[data-field="fecha"]').value = `${date[3]}-${date[2]}-${date[1]}`;
            for (const key of ['acreedor', 'monto', 'modo_pago']) {
                const field = card.querySelector(`[data-field="${key}"]`);
                if (values[key] !== null && values[key] !== undefined) field.value = values[key];
            }
            const complete = date && values.acreedor && values.monto && values.modo_pago;
            if (complete) {
                status.textContent = 'Datos detectados. Guardando compra…';
                await saveCard(item, currentGeneration);
            } else {
                const modelName = result.model === 'PAGO_EFECTIVO' ? 'Pago efectivo' : result.model === 'TRANSFERENCIA_INTERBANK' ? 'Transferencia Interbank' : result.model === 'TRANSFERENCIA_BCP' ? 'Transferencia BCP' : result.model === 'TRANSFERENCIA_MOVIMIENTO' ? 'Transferencia' : result.model === 'PLIN' ? 'Plin' : 'Yape';
                status.textContent = `${modelName} reconocido. Revise y complete los datos pendientes.`;
                item.save.hidden = false;
            }
        } catch (error) {
            if (currentGeneration !== generation) return;
            status.classList.add('is-error');
            status.textContent = error.message || 'No se pudo completar la lectura.';
        } finally {
            retry.disabled = false;
        }
    }

    input.addEventListener('change', async () => {
        generation++;
        const currentGeneration = generation;
        previewUrls.forEach(URL.revokeObjectURL);
        previewUrls = [];
        cards.replaceChildren();
        selection.hidden = true;
        progress.hidden = true;
        savedDates = [];
        batchSaved = false;
        showError('');
        const files = Array.from(input.files || []);
        if (!files.length) return;
        if (files.length > 5) showError('Seleccione como máximo 5 archivos.');
        else if (files.some(file => !readable.includes(file.type))) showError('Solo se admiten fotos JPG, PNG o WEBP y archivos PDF.');
        else if (files.some(file => file.size > (file.type === 'application/pdf' ? 1500000 : 5 * 1024 * 1024))) showError('Cada foto debe ocupar como máximo 5 MB y cada PDF 1,5 MB.');
        if (!errorBox.hidden) {
            input.value = '';
            return;
        }
        count.textContent = files.length === 1 ? '1 archivo seleccionado' : `${files.length} archivos seleccionados`;
        selection.hidden = false;
        const items = files.map(makeCard);
        items.forEach(item => {
            item.retry.addEventListener('click', () => { if (!readingQueue && !item.saved) readCard(item, generation); });
            item.save.addEventListener('click', () => item.batchResult ? saveReviewedBatch(item, generation) : saveCard(item, generation));
            item.close.addEventListener('click', () => {
                URL.revokeObjectURL(item.url);
                item.card.remove();
                if (!cards.childElementCount) selection.hidden = true;
            });
        });
        readingQueue = true;
        input.disabled = true;
        try {
            for (let index = 0; index < items.length; index++) {
                if (currentGeneration !== generation) break;
                const item = items[index];
                showProgress(`Leyendo foto ${index + 1} de ${items.length} con Gemini…`, Math.round(index * 100 / items.length + 20 / items.length));
                await readCard(item, currentGeneration);
                showProgress(`${index + 1} de ${items.length} fotos procesadas`, Math.round((index + 1) * 100 / items.length));
            }
        } finally {
            readingQueue = false;
            input.disabled = false;
            input.value = '';
            if (currentGeneration === generation) showSavedPeriod();
            if (currentGeneration === generation) setTimeout(() => { if (currentGeneration === generation) progress.hidden = true; }, 1200);
        }
    });
});
