let currentMaquirentaFormatosCompanyId = null;
let maquirentaFormatosModal = null;
let maquirentaFormatosReadOnly = false;

document.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('maquirentaFormatosCompanySearch');
    if (!search) return;

    maquirentaFormatosModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('maquirentaFormatosModal'));

    if (window.jQuery && $.fn.select2) {
        $('#maquirentaFormatosCompanySearch').select2({
            theme: 'bootstrap4',
            width: '100%',
            placeholder: 'Escriba razón social o RUC'
        });
        $('#maquirentaFormatosCompanySearch').on('select2:select', event => loadMaquirentaFormatosCompany(event.params.data.id));
        $('#maquirentaFormatosSelect').select2({
            theme: 'bootstrap4',
            width: '100%',
            dropdownParent: $('#maquirentaFormatosModal'),
            placeholder: 'Buscar documento',
            ajax: {
                url: `${BASE_URL}/servicios/empresa_maquirenta/formatos.php?action=catalog`,
                dataType: 'json',
                delay: 200,
                data: params => ({ q: params.term || '' })
            }
        });
    }

    search.addEventListener('change', event => {
        if (event.target.value) loadMaquirentaFormatosCompany(event.target.value);
    });

    document.getElementById('addMaquirentaFormatosBtn')?.addEventListener('click', openAddMaquirentaFormatos);
    document.getElementById('maquirentaFormatosForm')?.addEventListener('submit', saveMaquirentaFormatos);
    document.getElementById('newMaquirentaFormatosCatalogBtn')?.addEventListener('click', addMaquirentaFormatosCatalog);
    document.getElementById('deleteMaquirentaFormatosCatalogBtn')?.addEventListener('click', deleteMaquirentaFormatosCatalog);
    document.getElementById('downloadMaquirentaFormatosBtn')?.addEventListener('click', () => downloadMaquirentaFormatos());
    document.getElementById('downloadSelectedMaquirentaFormatosBtn')?.addEventListener('click', downloadSelectedMaquirentaFormatos);
    document.getElementById('changeMaquirentaFormatosPhotoBtn')?.addEventListener('click', () => document.getElementById('maquirentaFormatosPhotoInput')?.click());
    document.getElementById('maquirentaFormatosPhotoInput')?.addEventListener('change', uploadMaquirentaFormatosPhoto);
});

async function maquirentaFormatosRequest(url, options = {}) {
    const response = await fetch(url, options);
    const data = await response.json();
    if (!response.ok || !data.ok) {
        throw new Error(data.message || 'No se pudo completar la operación.');
    }
    return data;
}

async function maquirentaFormatosPost(action, values) {
    const body = new FormData();
    body.append('csrf_token', csrf);
    Object.entries(values).forEach(([key, value]) => body.append(key, value));
    return maquirentaFormatosRequest(`${BASE_URL}/servicios/empresa_maquirenta/formatos.php?action=${action}`, { method: 'POST', body });
}

async function loadMaquirentaFormatosCompany(id) {
    try {
        const data = await maquirentaFormatosRequest(`${BASE_URL}/servicios/empresa_maquirenta/formatos.php?action=profile&id=${encodeURIComponent(id)}`);
        const company = data.empresa;
        currentMaquirentaFormatosCompanyId = String(id);
        document.getElementById('maquirentaFormatosWorkspace').classList.remove('d-none');
        document.getElementById('maquirentaFormatosPhoto').src = company.foto_path ? `${BASE_URL}/${company.foto_path}` : `${BASE_URL}/recursos/imagen_referencial.php`;
        document.getElementById('maquirentaFormatosName').textContent = company.razon_social || '';
        document.getElementById('maquirentaFormatosRuc').textContent = company.ruc || '';
        document.getElementById('maquirentaFormatosAddress').textContent = company.direccion || '';
        await loadMaquirentaFormatosRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
}

async function loadMaquirentaFormatosRows() {
    if (!currentMaquirentaFormatosCompanyId) return;
    const data = await maquirentaFormatosRequest(`${BASE_URL}/servicios/empresa_maquirenta/formatos.php?action=list&empresa_maquirenta_id=${encodeURIComponent(currentMaquirentaFormatosCompanyId)}&t=${Date.now()}`);
    const tbody = document.querySelector('#maquirentaFormatosTable tbody');
    tbody.innerHTML = '';

    (data.rows || []).forEach(row => {
        const hasFile = !!row.archivo_path;
        const download = hasFile ? `<a class="btn btn-sm btn-outline-success" href="${BASE_URL}/${row.archivo_path}" download="${escapeHtml(row.archivo_nombre_original || row.documento)}" title="Descargar"><i class="fa-solid fa-download"></i></a>` : '';
        tbody.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="text-center">
                    <input class="form-check-input maquirenta-formatos-check" type="checkbox" value="${row.id}" ${hasFile ? '' : 'disabled'} title="${hasFile ? 'Seleccionar archivo' : 'Sin archivo adjunto'}">
                </td>
                <td>${escapeHtml(row.documento)}</td>
                <td>${row.fecha_registro}</td>
                <td>${row.fecha_inicio}</td>
                <td>${row.fecha_fin}</td>
                <td><span class="badge ${row.status.class}">${row.status.label}</span></td>
                <td>${escapeHtml(row.registered_by || '')}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" type="button" onclick="openEditMaquirentaFormatos(${row.id})"><i class="fa-solid fa-pen"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="openViewMaquirentaFormatos(${row.id})"><i class="fa-solid fa-eye"></i></button>
                    <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteMaquirentaFormatos(${row.id})"><i class="fa-solid fa-trash"></i></button>
                    ${download}
                </td>
            </tr>
        `);
    });
}

function openAddMaquirentaFormatos() {
    if (!currentMaquirentaFormatosCompanyId) return Swal.fire('Atención', 'Seleccione una empresa Maquirenta.', 'warning');
    maquirentaFormatosReadOnly = false;
    const form = document.getElementById('maquirentaFormatosForm');
    form.reset();
    form.classList.remove('was-validated');
    setMaquirentaFormatosReadonly(false);
    document.getElementById('maquirentaFormatosModalTitle').textContent = 'Agregar documentos';
    document.getElementById('maquirentaFormatosId').value = '';
    document.getElementById('maquirentaFormatosCompanyId').value = currentMaquirentaFormatosCompanyId;
    document.getElementById('maquirentaFormatosRegistration').value = localDateValue();
    $('#maquirentaFormatosSelect').val(null).trigger('change');
    renderMaquirentaFormatosFile(null);
    maquirentaFormatosModal.show();
}

async function fillMaquirentaFormatos(id) {
    const data = await maquirentaFormatosRequest(`${BASE_URL}/servicios/empresa_maquirenta/formatos.php?action=get&id=${id}`);
    const row = data.row;
    const form = document.getElementById('maquirentaFormatosForm');
    form.reset();
    document.getElementById('maquirentaFormatosId').value = row.id;
    document.getElementById('maquirentaFormatosCompanyId').value = row.empresa_maquirenta_id;
    document.getElementById('maquirentaFormatosRegistration').value = row.fecha_registro;
    document.getElementById('maquirentaFormatosStart').value = row.fecha_inicio;
    document.getElementById('maquirentaFormatosEnd').value = row.fecha_fin;
    document.getElementById('maquirentaFormatosObservations').value = row.observaciones || '';
    $('#maquirentaFormatosSelect').append(new Option(row.documento, row.documento_id, true, true)).trigger('change');
    renderMaquirentaFormatosFile(row);
}

window.openEditMaquirentaFormatos = async id => {
    try {
        maquirentaFormatosReadOnly = false;
        await fillMaquirentaFormatos(id);
        setMaquirentaFormatosReadonly(false);
        document.getElementById('maquirentaFormatosModalTitle').textContent = 'Editar documentos';
        maquirentaFormatosModal.show();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

window.openViewMaquirentaFormatos = async id => {
    try {
        maquirentaFormatosReadOnly = true;
        await fillMaquirentaFormatos(id);
        setMaquirentaFormatosReadonly(true);
        document.getElementById('maquirentaFormatosModalTitle').textContent = 'Visualizar documentos';
        maquirentaFormatosModal.show();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

function setMaquirentaFormatosReadonly(state) {
    document.querySelectorAll('#maquirentaFormatosForm input,#maquirentaFormatosForm textarea,#maquirentaFormatosForm select').forEach(element => {
        if (element.type !== 'hidden') element.disabled = state;
    });
    document.querySelector('#maquirentaFormatosForm button[type="submit"]')?.classList.toggle('d-none', state);
    document.getElementById('newMaquirentaFormatosCatalogBtn')?.classList.toggle('d-none', state);
    document.getElementById('deleteMaquirentaFormatosCatalogBtn')?.classList.toggle('d-none', state);
}

function renderMaquirentaFormatosFile(row) {
    const box = document.getElementById('maquirentaFormatosCurrentFile');
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
            <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteMaquirentaFormatosFile(${row.id})"><i class="fa-solid fa-trash me-1"></i>Eliminar</button>
        </div>
    `;
}

async function saveMaquirentaFormatos(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (maquirentaFormatosReadOnly || !form.checkValidity()) {
        form.classList.add('was-validated');
        return;
    }
    const button = form.querySelector('[type="submit"]');
    const progress = document.getElementById('maquirentaFormatosProgress');
    const bar = progress.querySelector('.progress-bar');
    const label = progress.querySelector('small');
    button.disabled = true;

    try {
        const data = await postFormWithProgress(`${BASE_URL}/servicios/empresa_maquirenta/formatos.php?action=save`, new FormData(form), percent => {
            progress.classList.remove('d-none');
            bar.style.width = `${percent}%`;
            label.textContent = percent < 100 ? `Subiendo archivo: ${percent}%` : 'Procesando archivo...';
        });
        if (!data.ok) throw new Error(data.message || 'No se pudo guardar.');
        maquirentaFormatosModal.hide();
        await loadMaquirentaFormatosRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    } finally {
        button.disabled = false;
        progress.classList.add('d-none');
        bar.style.width = '0%';
    }
}

window.deleteMaquirentaFormatos = async id => {
    if (!await confirmAction('¿Eliminar documento?')) return;
    try {
        await maquirentaFormatosPost('delete', { id });
        await loadMaquirentaFormatosRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

window.deleteMaquirentaFormatosFile = async id => {
    if (!await confirmAction('¿Eliminar archivo adjunto?')) return;
    try {
        await maquirentaFormatosPost('delete_file', { id });
        renderMaquirentaFormatosFile(null);
        await loadMaquirentaFormatosRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

async function addMaquirentaFormatosCatalog() {
    const focusTrap = maquirentaFormatosModal?._focustrap;
    focusTrap?.deactivate?.();
    let value = '';
    try {
        const result = await Swal.fire({
            title: 'Nuevo documento de formatos',
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
        const data = await maquirentaFormatosPost('catalog_save', { nombre: value });
        $('#maquirentaFormatosSelect').append(new Option(data.text, data.id, true, true)).trigger('change');
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
}

async function deleteMaquirentaFormatosCatalog() {
    const select = $('#maquirentaFormatosSelect');
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
        const data = await maquirentaFormatosPost('catalog_delete', { id });
        select.find(`option[value="${id}"]`).remove();
        select.val(null).trigger('change');
        Swal.fire('Eliminado', data.message, 'success');
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
}

async function uploadMaquirentaFormatosPhoto(event) {
    const input = event.currentTarget;
    if (!input.files?.[0] || !currentMaquirentaFormatosCompanyId) return;
    const body = new FormData();
    body.append('csrf_token', csrf);
    body.append('empresa_maquirenta_id', currentMaquirentaFormatosCompanyId);
    body.append('foto', input.files[0]);
    try {
        const data = await maquirentaFormatosRequest(`${BASE_URL}/servicios/empresa_maquirenta/formatos.php?action=upload_photo`, { method: 'POST', body });
        document.getElementById('maquirentaFormatosPhoto').src = `${data.path}?v=${Date.now()}`;
        Swal.fire('Actualizado', 'Foto actualizada.', 'success');
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    } finally {
        input.value = '';
    }
}

async function downloadSelectedMaquirentaFormatos() {
    const ids = Array.from(document.querySelectorAll('.maquirenta-formatos-check:checked')).map(item => item.value);
    if (!ids.length) return Swal.fire('Atención', 'Seleccione al menos un documento.', 'warning');
    await downloadMaquirentaFormatos(ids);
}

async function downloadMaquirentaFormatos(ids = []) {
    if (!currentMaquirentaFormatosCompanyId) return Swal.fire('Atención', 'Seleccione una empresa Maquirenta.', 'warning');
    const params = new URLSearchParams({
        action: 'download',
        empresa_maquirenta_id: currentMaquirentaFormatosCompanyId
    });
    if (ids.length) params.set('ids', ids.join(','));
    const response = await fetch(`${BASE_URL}/servicios/empresa_maquirenta/formatos.php?${params}`);
    if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'No se pudo generar la descarga.' }));
        return Swal.fire('Atención', data.message, 'warning');
    }
    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = match?.[1] || 'formatos_empresa_maquirenta.zip';
    document.body.appendChild(link);
    link.click();
    URL.revokeObjectURL(link.href);
    link.remove();
}
