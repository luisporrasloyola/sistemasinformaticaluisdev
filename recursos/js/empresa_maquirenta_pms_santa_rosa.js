(() => {
    const config = window.pmsMaquirentaConfig;
    const tbody = document.getElementById('pmsDocumentsBody');
    const form = document.getElementById('pmsDocumentForm');
    let rows = [];

    const downloadSelectedBtn = document.getElementById('downloadSelectedPmsBtn');
    const downloadAllBtn = document.getElementById('downloadAllPmsBtn');

    const weeks = [
        ['2026-12-26', '2027-01-01', 52], ['2026-12-19', '2026-12-25', 51], ['2026-12-12', '2026-12-18', 50],
        ['2026-12-05', '2026-12-11', 49], ['2026-11-28', '2026-12-04', 48], ['2026-11-21', '2026-11-27', 47],
        ['2026-11-14', '2026-11-20', 46], ['2026-11-07', '2026-11-13', 45], ['2026-10-31', '2026-11-06', 44],
        ['2026-10-24', '2026-10-30', 43], ['2026-10-17', '2026-10-23', 42], ['2026-10-10', '2026-10-16', 41],
        ['2026-10-03', '2026-10-09', 40], ['2026-09-26', '2026-10-02', 39], ['2026-09-19', '2026-09-25', 38],
        ['2026-09-12', '2026-09-18', 37], ['2026-09-05', '2026-09-11', 36], ['2026-08-29', '2026-09-04', 35],
        ['2026-08-22', '2026-08-28', 34], ['2026-08-15', '2026-08-21', 33], ['2026-08-08', '2026-08-14', 32],
        ['2026-08-01', '2026-08-07', 31], ['2026-07-25', '2026-07-31', 30], ['2026-07-18', '2026-07-24', 29],
        ['2026-07-11', '2026-07-17', 28], ['2026-07-04', '2026-07-10', 27], ['2026-06-27', '2026-07-03', 26],
        ['2026-06-20', '2026-06-26', 25], ['2026-06-13', '2026-06-19', 24], ['2026-06-06', '2026-06-12', 23],
        ['2026-05-30', '2026-06-05', 22], ['2026-05-23', '2026-05-29', 21], ['2026-05-16', '2026-05-22', 20],
        ['2026-05-09', '2026-05-15', 19], ['2026-05-02', '2026-05-08', 18], ['2026-04-25', '2026-05-01', 17],
        ['2026-04-18', '2026-04-24', 16], ['2026-04-11', '2026-04-17', 15], ['2026-04-04', '2026-04-10', 14],
        ['2026-03-28', '2026-04-03', 13], ['2026-03-21', '2026-03-27', 12], ['2026-03-14', '2026-03-20', 11],
        ['2026-03-07', '2026-03-13', 10]
    ];

    const esc = v => String(v ?? '').replace(/[&<>'"]/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    }[c]));

    const displayDate = v => {
        const [y, m, d] = String(v).split('-');
        return `${Number(d)}/${Number(m)}/${y}`;
    };

    const range = document.getElementById('pmsWeeklyRange');
    const pmsSelect = document.getElementById('pmsNumber');
    const modalElement = document.getElementById('pmsDocumentModal');
    let searchableSelectsReady = false;

    modalElement.addEventListener('shown.bs.modal', () => {
        if (searchableSelectsReady || !window.jQuery || !jQuery.fn.select2) return;
        const modal = $(modalElement);
        $(range).select2({
            theme: 'bootstrap4',
            width: '100%',
            dropdownParent: modal,
            placeholder: 'Buscar rango semanal',
            minimumResultsForSearch: 0,
            language: { noResults: () => 'No se encontraron rangos' }
        });
        $(pmsSelect).select2({
            theme: 'bootstrap4',
            width: '100%',
            dropdownParent: modal,
            placeholder: 'Buscar Nro. PMS',
            minimumResultsForSearch: 0,
            language: { noResults: () => 'No se encontró el PMS' }
        });
        $(range).on('select2:select', () => selectWeek(weeks.find(w => w.join('|') === range.value)));
        $(pmsSelect).on('select2:select', () => selectWeek(weeks.find(w => String(w[2]) === pmsSelect.value)));
        searchableSelectsReady = true;
    });

    weeks.forEach(w => {
        range.insertAdjacentHTML('beforeend', `<option value="${w.join('|')}">${displayDate(w[0])} al ${displayDate(w[1])}</option>`);
        pmsSelect.insertAdjacentHTML('beforeend', `<option value="${w[2]}">PMS ${w[2]}</option>`);
    });

    function selectWeek(w) {
        if (!w) {
            range.value = '';
            pmsSelect.value = '';
            document.getElementById('pmsRangeStart').value = '';
            document.getElementById('pmsRangeEnd').value = '';
            if (searchableSelectsReady) {
                jQuery(range).trigger('change.select2');
                jQuery(pmsSelect).trigger('change.select2');
            }
            return;
        }
        range.value = w.join('|');
        pmsSelect.value = String(w[2]);
        document.getElementById('pmsRangeStart').value = w[0];
        document.getElementById('pmsRangeEnd').value = w[1];
        if (searchableSelectsReady) {
            jQuery(range).trigger('change.select2');
            jQuery(pmsSelect).trigger('change.select2');
        }
    }

    range.addEventListener('change', () => selectWeek(weeks.find(w => w.join('|') === range.value)));
    pmsSelect.addEventListener('change', () => selectWeek(weeks.find(w => String(w[2]) === pmsSelect.value)));

    async function request(url, options = {}) {
        const response = await fetch(url, {
            headers: { Accept: 'application/json', ...(options.headers || {}) },
            ...options
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'No se pudo completar la operación.');
        }
        return data;
    }

    async function loadDocuments() {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Cargando...</td></tr>';
        try {
            const data = await request(`${config.serviceUrl}?action=list`);
            rows = data.rows || [];
            renderRows();
            updateDownloadButtons();
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">${esc(e.message)}</td></tr>`;
        }
    }

    function renderRows() {
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted"><i class="fa-regular fa-folder-open me-2"></i>No hay documentos registrados.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(r => {
            const hasFile = !!r.archivo_path;
            const download = hasFile ? `<a class="btn btn-sm btn-outline-success" href="${config.serviceUrl}?action=file&download=1&id=${+r.id}" title="Descargar adjunto"><i class="fa-solid fa-download"></i></a>` : '';
            return `
                <tr>
                    <td class="text-center">
                        <input class="form-check-input pms-check" type="checkbox" value="${+r.id}" ${hasFile ? '' : 'disabled'}>
                    </td>
                    <td>${displayDate(r.rango_inicio)} al ${displayDate(r.rango_fin)}</td>
                    <td><strong>PMS ${+r.nro_pms}</strong></td>
                    <td>
                        ${hasFile ? '<span class="badge bg-success">APTO</span>' : '<span class="badge bg-danger">NO APTO</span>'}
                    </td>
                    <td>${esc(r.registered_by || '—')}</td>
                    <td>
                        <div class="pms-row-actions">
                            <button class="btn btn-outline-primary edit-pms" data-id="${+r.id}" title="Editar"><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-outline-danger delete-pms" data-id="${+r.id}" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                            ${download}
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        tbody.querySelectorAll('.edit-pms').forEach(b => b.addEventListener('click', () => openModal(+b.dataset.id)));
        tbody.querySelectorAll('.delete-pms').forEach(b => b.addEventListener('click', () => deleteRecord(+b.dataset.id)));
    }

    function openModal(id = 0) {
        form.reset();
        document.getElementById('pmsId').value = id || '';
        document.getElementById('pmsModalTitle').textContent = id ? 'Editar PMS' : 'Agregar documentos';
        document.getElementById('pmsCurrentFile').classList.add('d-none');
        selectWeek(null);
        
        if (id) {
            const r = rows.find(x => +x.id === id);
            selectWeek(weeks.find(w => w[0] === r.rango_inicio && +w[2] === +r.nro_pms));
            document.getElementById('pmsObservations').value = r.observaciones || '';
            if (r.archivo_path) {
                const current = document.getElementById('pmsCurrentFile');
                current.innerHTML = `<a class="btn btn-sm btn-outline-primary" href="${config.serviceUrl}?action=file&id=${id}" target="_blank" rel="noopener"><i class="fa-solid fa-eye me-2"></i>Ver archivo actual</a>`;
                current.classList.remove('d-none');
            }
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('pmsDocumentModal')).show();
    }

    async function deleteRecord(id) {
        const confirm = await Swal.fire({
            icon: 'warning',
            title: '¿Eliminar registro PMS?',
            text: 'Esta acción eliminará también el archivo adjunto.',
            showCancelButton: true,
            confirmButtonText: 'Eliminar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#dc3545'
        });
        if (!confirm.isConfirmed) return;
        try {
            const body = new URLSearchParams({
                action: 'delete',
                csrf_token: config.csrfToken,
                id: String(id)
            });
            await request(config.serviceUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body
            });
            await loadDocuments();
            Swal.fire({ icon: 'success', title: 'Registro eliminado', timer: 1300, showConfirmButton: false });
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'No se pudo eliminar', text: e.message });
        }
    }

    async function downloadArchive(ids = []) {
        const params = new URLSearchParams({ action: 'download' });
        if (ids.length) params.set('ids', ids.join(','));
        const button = ids.length ? downloadSelectedBtn : downloadAllBtn;
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Preparando...';
        
        try {
            const response = await fetch(`${config.serviceUrl}?${params}`);
            if (!response.ok) {
                let message = 'No se pudo preparar la descarga.';
                try {
                    const error = await response.json();
                    message = error.message || message;
                } catch {}
                throw new Error(message);
            }
            const blob = await response.blob();
            const disposition = response.headers.get('Content-Disposition') || '';
            const match = disposition.match(/filename="?([^";]+)"?/i);
            const filename = match ?.[1] || 'PMS_Santa_Rosa.zip';
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        } catch (error) {
            Swal.fire({ icon: 'warning', title: 'Descarga no disponible', text: error.message });
        } finally {
            button.innerHTML = original;
            updateDownloadButtons();
        }
    }

    function selectedIds() {
        return [...tbody.querySelectorAll('.pms-check:checked')].map(check => +check.value);
    }

    function updateDownloadButtons() {
        downloadSelectedBtn.disabled = !selectedIds().length;
        downloadAllBtn.disabled = !rows.some(row => row.archivo_path);
    }

    tbody.addEventListener('change', event => {
        if (event.target.classList.contains('pms-check')) updateDownloadButtons();
    });

    downloadSelectedBtn.addEventListener('click', () => downloadArchive(selectedIds()));
    downloadAllBtn.addEventListener('click', () => downloadArchive());
    document.getElementById('addPmsDocumentBtn').addEventListener('click', () => openModal());

    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (!range.value || !pmsSelect.value) {
            Swal.fire({ icon: 'warning', title: 'Complete el rango semanal y Nro. PMS' });
            return;
        }
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            await request(config.serviceUrl, {
                method: 'POST',
                body: new FormData(form)
            });
            bootstrap.Modal.getInstance(document.getElementById('pmsDocumentModal'))?.hide();
            await loadDocuments();
            Swal.fire({ icon: 'success', title: 'PMS guardado', timer: 1400, showConfirmButton: false });
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'No se pudo guardar', text: err.message });
        } finally {
            button.disabled = false;
        }
    });

    loadDocuments();
})();
