let currentParadaPlantaCompanyId = null;
let paradaPlantaModal = null;
let paradaPlantaReadOnly = false;

document.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('paradaPlantaCompanySearch');
    if (!search) return;

    paradaPlantaModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('paradaPlantaModal'));

    if (window.jQuery && $.fn.select2) {
        $('#paradaPlantaCompanySearch').select2({
            theme: 'bootstrap4',
            width: '100%',
            placeholder: 'Escriba razón social o RUC'
        });
        $('#paradaPlantaCompanySearch').on('select2:select', event => loadParadaPlantaCompany(event.params.data.id));
        $('#paradaPlantaSelect').select2({
            theme: 'bootstrap4',
            width: '100%',
            dropdownParent: $('#paradaPlantaModal'),
            placeholder: 'Buscar documento',
            ajax: {
                url: `${BASE_URL}/servicios/empresa_maquirenta/parada_planta.php?action=catalog`,
                dataType: 'json',
                delay: 200,
                data: params => ({ q: params.term || '' })
            }
        });
    }

    search.addEventListener('change', event => {
        if (event.target.value) loadParadaPlantaCompany(event.target.value);
    });

    document.getElementById('addParadaPlantaBtn')?.addEventListener('click', openAddParadaPlanta);
    document.getElementById('paradaPlantaForm')?.addEventListener('submit', saveParadaPlanta);
    document.getElementById('newParadaPlantaCatalogBtn')?.addEventListener('click', addParadaPlantaCatalog);
    document.getElementById('deleteParadaPlantaCatalogBtn')?.addEventListener('click', deleteParadaPlantaCatalog);
    document.getElementById('downloadParadaPlantaBtn')?.addEventListener('click', () => downloadParadaPlanta());
    document.getElementById('downloadSelectedParadaPlantaBtn')?.addEventListener('click', downloadSelectedParadaPlanta);
    document.getElementById('changeParadaPlantaPhotoBtn')?.addEventListener('click', () => document.getElementById('paradaPlantaPhotoInput')?.click());
    document.getElementById('paradaPlantaPhotoInput')?.addEventListener('change', uploadParadaPlantaPhoto);
});

async function paradaPlantaRequest(url, options = {}) {
    const response = await fetch(url, options);
    const data = await response.json();
    if (!response.ok || !data.ok) {
        throw new Error(data.message || 'No se pudo completar la operación.');
    }
    return data;
}

async function paradaPlantaPost(action, values) {
    const body = new FormData();
    body.append('csrf_token', csrf);
    Object.entries(values).forEach(([key, value]) => body.append(key, value));
    return paradaPlantaRequest(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta.php?action=${action}`, { method: 'POST', body });
}

async function loadParadaPlantaCompany(id) {
    try {
        const data = await paradaPlantaRequest(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta.php?action=profile&id=${encodeURIComponent(id)}`);
        const company = data.empresa;
        currentParadaPlantaCompanyId = String(id);
        document.getElementById('paradaPlantaWorkspace').classList.remove('d-none');
        document.getElementById('paradaPlantaPhoto').src = company.foto_path ? `${BASE_URL}/${company.foto_path}` : `${BASE_URL}/recursos/imagen_referencial.php`;
        document.getElementById('paradaPlantaName').textContent = company.razon_social || '';
        document.getElementById('paradaPlantaRuc').textContent = company.ruc || '';
        document.getElementById('paradaPlantaAddress').textContent = company.direccion || '';
        await loadParadaPlantaRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
}

async function loadParadaPlantaRows() {
    if (!currentParadaPlantaCompanyId) return;
    const data = await paradaPlantaRequest(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta.php?action=list&empresa_maquirenta_id=${encodeURIComponent(currentParadaPlantaCompanyId)}&t=${Date.now()}`);
    const tbody = document.querySelector('#paradaPlantaTable tbody');
    tbody.innerHTML = '';

    (data.rows || []).forEach(row => {
        const hasFile = !!row.archivo_path;
        const download = hasFile ? `<a class="btn btn-sm btn-outline-success" href="${BASE_URL}/${row.archivo_path}" download="${escapeHtml(row.archivo_nombre_original || row.documento)}" title="Descargar"><i class="fa-solid fa-download"></i></a>` : '';
        tbody.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="text-center">
                    <input class="form-check-input parada-planta-check" type="checkbox" value="${row.id}" ${hasFile ? '' : 'disabled'} title="${hasFile ? 'Seleccionar archivo' : 'Sin archivo adjunto'}">
                </td>
                <td>${escapeHtml(row.documento)}</td>
                <td>${row.fecha_registro}</td>
                <td>${row.fecha_inicio}</td>
                <td>${row.fecha_fin}</td>
                <td><span class="badge ${row.status.class}">${row.status.label}</span></td>
                <td>${escapeHtml(row.registered_by || '')}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" type="button" onclick="openEditParadaPlanta(${row.id})"><i class="fa-solid fa-pen"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="openViewParadaPlanta(${row.id})"><i class="fa-solid fa-eye"></i></button>
                    <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteParadaPlanta(${row.id})"><i class="fa-solid fa-trash"></i></button>
                    ${download}
                </td>
            </tr>
        `);
    });
}

function openAddParadaPlanta() {
    if (!currentParadaPlantaCompanyId) return Swal.fire('Atención', 'Seleccione una empresa Maquirenta.', 'warning');
    paradaPlantaReadOnly = false;
    const form = document.getElementById('paradaPlantaForm');
    form.reset();
    form.classList.remove('was-validated');
    setParadaPlantaReadonly(false);
    document.getElementById('paradaPlantaModalTitle').textContent = 'Agregar documentos';
    document.getElementById('paradaPlantaId').value = '';
    document.getElementById('paradaPlantaCompanyId').value = currentParadaPlantaCompanyId;
    document.getElementById('paradaPlantaRegistration').value = localDateValue();
    $('#paradaPlantaSelect').val(null).trigger('change');
    renderParadaPlantaFile(null);
    paradaPlantaModal.show();
}

async function fillParadaPlanta(id) {
    const data = await paradaPlantaRequest(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta.php?action=get&id=${id}`);
    const row = data.row;
    const form = document.getElementById('paradaPlantaForm');
    form.reset();
    document.getElementById('paradaPlantaId').value = row.id;
    document.getElementById('paradaPlantaCompanyId').value = row.empresa_maquirenta_id;
    document.getElementById('paradaPlantaRegistration').value = row.fecha_registro;
    document.getElementById('paradaPlantaStart').value = row.fecha_inicio;
    document.getElementById('paradaPlantaEnd').value = row.fecha_fin;
    document.getElementById('paradaPlantaObservations').value = row.observaciones || '';
    $('#paradaPlantaSelect').append(new Option(row.documento, row.documento_id, true, true)).trigger('change');
    renderParadaPlantaFile(row);
}

window.openEditParadaPlanta = async id => {
    try {
        paradaPlantaReadOnly = false;
        await fillParadaPlanta(id);
        setParadaPlantaReadonly(false);
        document.getElementById('paradaPlantaModalTitle').textContent = 'Editar documentos';
        paradaPlantaModal.show();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

window.openViewParadaPlanta = async id => {
    try {
        paradaPlantaReadOnly = true;
        await fillParadaPlanta(id);
        setParadaPlantaReadonly(true);
        document.getElementById('paradaPlantaModalTitle').textContent = 'Visualizar documentos';
        paradaPlantaModal.show();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

function setParadaPlantaReadonly(state) {
    document.querySelectorAll('#paradaPlantaForm input,#paradaPlantaForm textarea,#paradaPlantaForm select').forEach(element => {
        if (element.type !== 'hidden') element.disabled = state;
    });
    document.querySelector('#paradaPlantaForm button[type="submit"]')?.classList.toggle('d-none', state);
    document.getElementById('newParadaPlantaCatalogBtn')?.classList.toggle('d-none', state);
    document.getElementById('deleteParadaPlantaCatalogBtn')?.classList.toggle('d-none', state);
}

function renderParadaPlantaFile(row) {
    const box = document.getElementById('paradaPlantaCurrentFile');
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
            <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteParadaPlantaFile(${row.id})"><i class="fa-solid fa-trash me-1"></i>Eliminar</button>
        </div>
    `;
}

async function saveParadaPlanta(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (paradaPlantaReadOnly || !form.checkValidity()) {
        form.classList.add('was-validated');
        return;
    }
    const button = form.querySelector('[type="submit"]');
    const progress = document.getElementById('paradaPlantaProgress');
    const bar = progress.querySelector('.progress-bar');
    const label = progress.querySelector('small');
    button.disabled = true;

    try {
        const data = await postFormWithProgress(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta.php?action=save`, new FormData(form), percent => {
            progress.classList.remove('d-none');
            bar.style.width = `${percent}%`;
            label.textContent = percent < 100 ? `Subiendo archivo: ${percent}%` : 'Procesando archivo...';
        });
        if (!data.ok) throw new Error(data.message || 'No se pudo guardar.');
        paradaPlantaModal.hide();
        await loadParadaPlantaRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    } finally {
        button.disabled = false;
        progress.classList.add('d-none');
        bar.style.width = '0%';
    }
}

window.deleteParadaPlanta = async id => {
    if (!await confirmAction('¿Eliminar documento?')) return;
    try {
        await paradaPlantaPost('delete', { id });
        await loadParadaPlantaRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

window.deleteParadaPlantaFile = async id => {
    if (!await confirmAction('¿Eliminar archivo adjunto?')) return;
    try {
        await paradaPlantaPost('delete_file', { id });
        renderParadaPlantaFile(null);
        await loadParadaPlantaRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

async function addParadaPlantaCatalog() {
    const focusTrap = paradaPlantaModal?._focustrap;
    focusTrap?.deactivate?.();
    let value = '';
    try {
        const result = await Swal.fire({
            title: 'Nuevo documento de Parada de Planta',
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
        const data = await paradaPlantaPost('catalog_save', { nombre: value });
        $('#paradaPlantaSelect').append(new Option(data.text, data.id, true, true)).trigger('change');
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
}

async function deleteParadaPlantaCatalog() {
    const select = $('#paradaPlantaSelect');
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
        const data = await paradaPlantaPost('catalog_delete', { id });
        select.find(`option[value="${id}"]`).remove();
        select.val(null).trigger('change');
        Swal.fire('Eliminado', data.message, 'success');
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
}

async function uploadParadaPlantaPhoto(event) {
    const input = event.currentTarget;
    if (!input.files?.[0] || !currentParadaPlantaCompanyId) return;
    const body = new FormData();
    body.append('csrf_token', csrf);
    body.append('empresa_maquirenta_id', currentParadaPlantaCompanyId);
    body.append('foto', input.files[0]);
    try {
        const data = await paradaPlantaRequest(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta.php?action=upload_photo`, { method: 'POST', body });
        document.getElementById('paradaPlantaPhoto').src = `${data.path}?v=${Date.now()}`;
        Swal.fire('Actualizado', 'Foto actualizada.', 'success');
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    } finally {
        input.value = '';
    }
}

async function downloadSelectedParadaPlanta() {
    const ids = Array.from(document.querySelectorAll('.parada-planta-check:checked')).map(item => item.value);
    if (!ids.length) return Swal.fire('Atención', 'Seleccione al menos un documento.', 'warning');
    await downloadParadaPlanta(ids);
}

async function downloadParadaPlanta(ids = []) {
    if (!currentParadaPlantaCompanyId) return Swal.fire('Atención', 'Seleccione una empresa Maquirenta.', 'warning');
    const params = new URLSearchParams({
        action: 'download',
        empresa_maquirenta_id: currentParadaPlantaCompanyId
    });
    if (ids.length) params.set('ids', ids.join(','));
    const response = await fetch(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta.php?${params}`);
    if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'No se pudo generar la descarga.' }));
        return Swal.fire('Atención', data.message, 'warning');
    }
    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = match?.[1] || 'parada_planta_empresa_maquirenta.zip';
    document.body.appendChild(link);
    link.click();
    URL.revokeObjectURL(link.href);
    link.remove();
}
