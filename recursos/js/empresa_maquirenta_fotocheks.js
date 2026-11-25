let currentMaquirentaFotocheksCompanyId = null;
let maquirentaFotocheksModal = null;
let maquirentaFotocheksReadOnly = false;
let maquirentaFotocheksRows = [];
let maquirentaFotocheksSearchTerm = '';

document.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('maquirentaFotocheksCompanySearch');
    if (!search) return;

    maquirentaFotocheksModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('maquirentaFotocheksModal'));

    if (window.jQuery && $.fn.select2) {
        $('#maquirentaFotocheksCompanySearch').select2({
            theme: 'bootstrap4',
            width: '100%',
            placeholder: 'Escriba razón social o RUC'
        });
        $('#maquirentaFotocheksCompanySearch').on('select2:select', event => loadMaquirentaFotocheksCompany(event.params.data.id));
        $('#maquirentaFotocheksSelect').select2({
            theme: 'bootstrap4',
            width: '100%',
            dropdownParent: $('#maquirentaFotocheksModal'),
            placeholder: 'Buscar documento',
            ajax: {
                url: `${BASE_URL}/servicios/empresa_maquirenta/fotocheks.php?action=catalog`,
                dataType: 'json',
                delay: 200,
                data: params => ({ q: params.term || '' })
            }
        });
    }

    search.addEventListener('change', event => {
        if (event.target.value) loadMaquirentaFotocheksCompany(event.target.value);
    });
    document.getElementById('maquirentaFotocheksDocumentSearchForm').addEventListener('submit', event => {
        event.preventDefault();
        maquirentaFotocheksSearchTerm = document.getElementById('maquirentaFotocheksDocumentSearchInput').value.trim();
        renderMaquirentaFotocheksRows();
    });

    document.getElementById('addMaquirentaFotocheksBtn')?.addEventListener('click', openAddMaquirentaFotocheks);
    document.getElementById('maquirentaFotocheksForm')?.addEventListener('submit', saveMaquirentaFotocheks);
    document.getElementById('newMaquirentaFotocheksCatalogBtn')?.addEventListener('click', addMaquirentaFotocheksCatalog);
    document.getElementById('deleteMaquirentaFotocheksCatalogBtn')?.addEventListener('click', deleteMaquirentaFotocheksCatalog);
    document.getElementById('downloadMaquirentaFotocheksBtn')?.addEventListener('click', () => downloadMaquirentaFotocheks());
    document.getElementById('downloadSelectedMaquirentaFotocheksBtn')?.addEventListener('click', downloadSelectedMaquirentaFotocheks);
    document.getElementById('changeMaquirentaFotocheksPhotoBtn')?.addEventListener('click', () => document.getElementById('maquirentaFotocheksPhotoInput')?.click());
    document.getElementById('maquirentaFotocheksPhotoInput')?.addEventListener('change', uploadMaquirentaFotocheksPhoto);
});

async function maquirentaFotocheksRequest(url, options = {}) {
    const response = await fetch(url, options);
    const data = await response.json();
    if (!response.ok || !data.ok) {
        throw new Error(data.message || 'No se pudo completar la operación.');
    }
    return data;
}

async function maquirentaFotocheksPost(action, values) {
    const body = new FormData();
    body.append('csrf_token', csrf);
    Object.entries(values).forEach(([key, value]) => body.append(key, value));
    return maquirentaFotocheksRequest(`${BASE_URL}/servicios/empresa_maquirenta/fotocheks.php?action=${action}`, { method: 'POST', body });
}

async function loadMaquirentaFotocheksCompany(id) {
    try {
        const data = await maquirentaFotocheksRequest(`${BASE_URL}/servicios/empresa_maquirenta/fotocheks.php?action=profile&id=${encodeURIComponent(id)}`);
        const company = data.empresa;
        currentMaquirentaFotocheksCompanyId = String(id);
        maquirentaFotocheksSearchTerm = '';
        document.getElementById('maquirentaFotocheksDocumentSearchInput').value = '';
        document.getElementById('maquirentaFotocheksWorkspace').classList.remove('d-none');
        document.getElementById('maquirentaFotocheksPhoto').src = company.foto_path ? `${BASE_URL}/${company.foto_path}` : `${BASE_URL}/recursos/imagen_referencial.php`;
        document.getElementById('maquirentaFotocheksName').textContent = company.razon_social || '';
        document.getElementById('maquirentaFotocheksRuc').textContent = company.ruc || '';
        document.getElementById('maquirentaFotocheksAddress').textContent = company.direccion || '';
        await loadMaquirentaFotocheksRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
}

async function loadMaquirentaFotocheksRows() {
    if (!currentMaquirentaFotocheksCompanyId) return;
    const data = await maquirentaFotocheksRequest(`${BASE_URL}/servicios/empresa_maquirenta/fotocheks.php?action=list&empresa_maquirenta_id=${encodeURIComponent(currentMaquirentaFotocheksCompanyId)}&t=${Date.now()}`);
    maquirentaFotocheksRows = data.rows || [];
    renderMaquirentaFotocheksRows();
}

function renderMaquirentaFotocheksRows() {
    const tbody = document.querySelector('#maquirentaFotocheksTable tbody');
    tbody.innerHTML = '';
    const search = maquirentaFotocheksSearchTerm.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const rows = search ? maquirentaFotocheksRows.filter(row => String(row.documento || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().includes(search)) : maquirentaFotocheksRows;
    document.getElementById('maquirentaFotocheksDocumentSearchFeedback').textContent = search ? `${rows.length} fotocheck${rows.length === 1 ? '' : 's'} encontrado${rows.length === 1 ? '' : 's'}` : '';
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-4">${search ? 'No se encontraron fotocheks con esa búsqueda.' : 'No hay fotocheks registrados para esta empresa.'}</td></tr>`;
        return;
    }
    rows.forEach(row => {
        const hasFile = !!row.archivo_path;
        const download = hasFile ? `<a class="btn btn-sm btn-outline-success" href="${BASE_URL}/${row.archivo_path}" download="${escapeHtml(row.archivo_nombre_original || row.documento)}" title="Descargar"><i class="fa-solid fa-download"></i></a>` : '';
        tbody.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="text-center">
                    <input class="form-check-input maquirenta-fotocheks-check" type="checkbox" value="${row.id}" ${hasFile ? '' : 'disabled'} title="${hasFile ? 'Seleccionar archivo' : 'Sin archivo adjunto'}">
                </td>
                <td>${escapeHtml(row.documento)}</td>
                <td>${row.fecha_registro}</td>
                <td>${row.fecha_inicio}</td>
                <td>${row.fecha_fin}</td>
                <td><span class="badge ${row.status.class}">${row.status.label}</span></td>
                <td>${escapeHtml(row.registered_by || '')}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" type="button" onclick="openEditMaquirentaFotocheks(${row.id})"><i class="fa-solid fa-pen"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="openViewMaquirentaFotocheks(${row.id})"><i class="fa-solid fa-eye"></i></button>
                    <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteMaquirentaFotocheks(${row.id})"><i class="fa-solid fa-trash"></i></button>
                    ${download}
                </td>
            </tr>
        `);
    });
}

function openAddMaquirentaFotocheks() {
    if (!currentMaquirentaFotocheksCompanyId) return Swal.fire('Atención', 'Seleccione una empresa Maquirenta.', 'warning');
    maquirentaFotocheksReadOnly = false;
    const form = document.getElementById('maquirentaFotocheksForm');
    form.reset();
    form.classList.remove('was-validated');
    setMaquirentaFotocheksReadonly(false);
    document.getElementById('maquirentaFotocheksModalTitle').textContent = 'Agregar fotocheck';
    document.getElementById('maquirentaFotocheksId').value = '';
    document.getElementById('maquirentaFotocheksCompanyId').value = currentMaquirentaFotocheksCompanyId;
    document.getElementById('maquirentaFotocheksRegistration').value = localDateValue();
    $('#maquirentaFotocheksSelect').val(null).trigger('change');
    renderMaquirentaFotocheksFile(null);
    maquirentaFotocheksModal.show();
}

async function fillMaquirentaFotocheks(id) {
    const data = await maquirentaFotocheksRequest(`${BASE_URL}/servicios/empresa_maquirenta/fotocheks.php?action=get&id=${id}`);
    const row = data.row;
    const form = document.getElementById('maquirentaFotocheksForm');
    form.reset();
    document.getElementById('maquirentaFotocheksId').value = row.id;
    document.getElementById('maquirentaFotocheksCompanyId').value = row.empresa_maquirenta_id;
    document.getElementById('maquirentaFotocheksRegistration').value = row.fecha_registro;
    document.getElementById('maquirentaFotocheksStart').value = row.fecha_inicio;
    document.getElementById('maquirentaFotocheksEnd').value = row.fecha_fin;
    document.getElementById('maquirentaFotocheksObservations').value = row.observaciones || '';
    $('#maquirentaFotocheksSelect').append(new Option(row.documento, row.documento_id, true, true)).trigger('change');
    renderMaquirentaFotocheksFile(row);
}

window.openEditMaquirentaFotocheks = async id => {
    try {
        maquirentaFotocheksReadOnly = false;
        await fillMaquirentaFotocheks(id);
        setMaquirentaFotocheksReadonly(false);
        document.getElementById('maquirentaFotocheksModalTitle').textContent = 'Editar fotocheck';
        maquirentaFotocheksModal.show();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

window.openViewMaquirentaFotocheks = async id => {
    try {
        maquirentaFotocheksReadOnly = true;
        await fillMaquirentaFotocheks(id);
        setMaquirentaFotocheksReadonly(true);
        document.getElementById('maquirentaFotocheksModalTitle').textContent = 'Visualizar fotocheck';
        maquirentaFotocheksModal.show();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

function setMaquirentaFotocheksReadonly(state) {
    document.querySelectorAll('#maquirentaFotocheksForm input,#maquirentaFotocheksForm textarea,#maquirentaFotocheksForm select').forEach(element => {
        if (element.type !== 'hidden') element.disabled = state;
    });
    document.querySelector('#maquirentaFotocheksForm button[type="submit"]')?.classList.toggle('d-none', state);
    document.getElementById('newMaquirentaFotocheksCatalogBtn')?.classList.toggle('d-none', state);
    document.getElementById('deleteMaquirentaFotocheksCatalogBtn')?.classList.toggle('d-none', state);
}

function renderMaquirentaFotocheksFile(row) {
    const box = document.getElementById('maquirentaFotocheksCurrentFile');
    if (!row?.archivo_path) {
        box.classList.add('d-none');
        box.innerHTML = '';
        return;
    }
    box.classList.remove('d-none');
    box.innerHTML = `
        ${documentAttachmentHeader(row)}
        <div class="d-flex gap-2 mt-2">
            <a class="btn btn-sm btn-outline-primary" target="_blank" href="${BASE_URL}/${row.archivo_path}"><i class="fa-solid fa-up-right-from-square me-1"></i>Abrir</a>
            <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteMaquirentaFotocheksFile(${row.id})"><i class="fa-solid fa-trash me-1"></i>Eliminar</button>
        </div>
    `;
}

async function saveMaquirentaFotocheks(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (maquirentaFotocheksReadOnly || !form.checkValidity()) {
        form.classList.add('was-validated');
        return;
    }
    const button = form.querySelector('[type="submit"]');
    const progress = document.getElementById('maquirentaFotocheksProgress');
    const bar = progress.querySelector('.progress-bar');
    const label = progress.querySelector('small');
    button.disabled = true;

    try {
        const data = await postFormWithProgress(`${BASE_URL}/servicios/empresa_maquirenta/fotocheks.php?action=save`, new FormData(form), percent => {
            progress.classList.remove('d-none');
            bar.style.width = `${percent}%`;
            label.textContent = percent < 100 ? `Subiendo archivo: ${percent}%` : 'Procesando archivo...';
        });
        if (!data.ok) throw new Error(data.message || 'No se pudo guardar.');
        maquirentaFotocheksModal.hide();
        await loadMaquirentaFotocheksRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    } finally {
        button.disabled = false;
        progress.classList.add('d-none');
        bar.style.width = '0%';
    }
}

window.deleteMaquirentaFotocheks = async id => {
    if (!await confirmAction('¿Eliminar documento?')) return;
    try {
        await maquirentaFotocheksPost('delete', { id });
        await loadMaquirentaFotocheksRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

window.deleteMaquirentaFotocheksFile = async id => {
    if (!await confirmAction('¿Eliminar archivo adjunto?')) return;
    try {
        await maquirentaFotocheksPost('delete_file', { id });
        renderMaquirentaFotocheksFile(null);
        await loadMaquirentaFotocheksRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

async function addMaquirentaFotocheksCatalog() {
    const focusTrap = maquirentaFotocheksModal?._focustrap;
    focusTrap?.deactivate?.();
    let value = '';
    try {
        const result = await Swal.fire({
            title: 'Nuevo fotocheck',
            input: 'text',
            inputPlaceholder: 'Nombre del documento',
            showCancelButton: true,
            confirmButtonText: 'Agregar',
            cancelButtonText: 'Cancelar',
            didOpen: () => Swal.getInput()?.focus()
        });
        value = (result.value || '').trim();
    } finally {
        setTimeout(() => focusTrap?.activate?.(), 0);
    }
    if (!value) return;
    try {
        const data = await maquirentaFotocheksPost('catalog_save', { nombre: value });
        $('#maquirentaFotocheksSelect').append(new Option(data.text, data.id, true, true)).trigger('change');
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
}

async function deleteMaquirentaFotocheksCatalog() {
    const select = $('#maquirentaFotocheksSelect');
    const id = select.val();
    if (!id) return Swal.fire('Atención', 'Seleccione un documento para eliminar.', 'warning');
    const answer = await Swal.fire({
        title: '¿Eliminar documento?',
        text: 'Se quitará del catálogo si no tiene registros asociados.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    });
    if (!answer.isConfirmed) return;
    try {
        const data = await maquirentaFotocheksPost('catalog_delete', { id });
        select.find(`option[value="${id}"]`).remove();
        select.val(null).trigger('change');
        Swal.fire('Eliminado', data.message, 'success');
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
}

async function uploadMaquirentaFotocheksPhoto(event) {
    const input = event.currentTarget;
    if (!input.files?.[0] || !currentMaquirentaFotocheksCompanyId) return;
    const body = new FormData();
    body.append('csrf_token', csrf);
    body.append('empresa_maquirenta_id', currentMaquirentaFotocheksCompanyId);
    body.append('foto', input.files[0]);
    try {
        const data = await maquirentaFotocheksRequest(`${BASE_URL}/servicios/empresa_maquirenta/fotocheks.php?action=upload_photo`, { method: 'POST', body });
        document.getElementById('maquirentaFotocheksPhoto').src = `${data.path}?v=${Date.now()}`;
        Swal.fire('Actualizado', 'Foto actualizada.', 'success');
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    } finally {
        input.value = '';
    }
}

async function downloadSelectedMaquirentaFotocheks() {
    const ids = Array.from(document.querySelectorAll('.maquirenta-fotocheks-check:checked')).map(item => item.value);
    if (!ids.length) return Swal.fire('Atención', 'Seleccione al menos un documento.', 'warning');
    await downloadMaquirentaFotocheks(ids);
}

async function downloadMaquirentaFotocheks(ids = []) {
    if (!currentMaquirentaFotocheksCompanyId) return Swal.fire('Atención', 'Seleccione una empresa Maquirenta.', 'warning');
    const params = new URLSearchParams({
        action: 'download',
        empresa_maquirenta_id: currentMaquirentaFotocheksCompanyId
    });
    if (ids.length) params.set('ids', ids.join(','));
    const response = await fetch(`${BASE_URL}/servicios/empresa_maquirenta/fotocheks.php?${params}`);
    if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'No se pudo generar la descarga.' }));
        return Swal.fire('Atención', data.message, 'warning');
    }
    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = match?.[1] || 'fotocheks_empresa_maquirenta.zip';
    document.body.appendChild(link);
    link.click();
    URL.revokeObjectURL(link.href);
    link.remove();
}
