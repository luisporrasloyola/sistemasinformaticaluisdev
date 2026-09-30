let currentSantaRosaParadaCompanyId = null;
let santaRosaParadaModal = null;
let santaRosaParadaReadOnly = false;

document.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('santaRosaParadaCompanySearch');
    if (!search) return;

    santaRosaParadaModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('santaRosaParadaModal'));

    if (window.jQuery && $.fn.select2) {
        $('#santaRosaParadaCompanySearch').select2({
            theme: 'bootstrap4',
            width: '100%',
            placeholder: 'Escriba razón social o RUC'
        });
        $('#santaRosaParadaCompanySearch').on('select2:select', event => loadSantaRosaParadaCompany(event.params.data.id));
        $('#santaRosaParadaSelect').select2({
            theme: 'bootstrap4',
            width: '100%',
            dropdownParent: $('#santaRosaParadaModal'),
            placeholder: 'Buscar documento',
            ajax: {
                url: `${BASE_URL}/servicios/empresa_maquirenta/parada_planta_santa_rosa.php?action=catalog`,
                dataType: 'json',
                delay: 200,
                data: params => ({ q: params.term || '' })
            }
        });
    }

    search.addEventListener('change', event => {
        if (event.target.value) loadSantaRosaParadaCompany(event.target.value);
    });

    document.getElementById('addSantaRosaParadaBtn')?.addEventListener('click', openAddSantaRosaParada);
    document.getElementById('santaRosaParadaForm')?.addEventListener('submit', saveSantaRosaParada);
    document.getElementById('newSantaRosaParadaCatalogBtn')?.addEventListener('click', addSantaRosaParadaCatalog);
    document.getElementById('deleteSantaRosaParadaCatalogBtn')?.addEventListener('click', deleteSantaRosaParadaCatalog);
    document.getElementById('downloadSantaRosaParadaBtn')?.addEventListener('click', () => downloadSantaRosaParada());
    document.getElementById('downloadSelectedSantaRosaParadaBtn')?.addEventListener('click', downloadSelectedSantaRosaParada);
    document.getElementById('changeSantaRosaParadaPhotoBtn')?.addEventListener('click', () => document.getElementById('santaRosaParadaPhotoInput')?.click());
    document.getElementById('santaRosaParadaPhotoInput')?.addEventListener('change', uploadSantaRosaParadaPhoto);
});

async function santaRosaParadaRequest(url, options = {}) {
    const response = await fetch(url, options);
    const data = await response.json();
    if (!response.ok || !data.ok) {
        throw new Error(data.message || 'No se pudo completar la operación.');
    }
    return data;
}

async function santaRosaParadaPost(action, values) {
    const body = new FormData();
    body.append('csrf_token', csrf);
    Object.entries(values).forEach(([key, value]) => body.append(key, value));
    return santaRosaParadaRequest(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta_santa_rosa.php?action=${action}`, { method: 'POST', body });
}

async function loadSantaRosaParadaCompany(id) {
    try {
        const data = await santaRosaParadaRequest(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta_santa_rosa.php?action=profile&id=${encodeURIComponent(id)}`);
        const company = data.empresa;
        currentSantaRosaParadaCompanyId = String(id);
        document.getElementById('santaRosaParadaWorkspace').classList.remove('d-none');
        document.getElementById('santaRosaParadaPhoto').src = company.foto_path ? `${BASE_URL}/${company.foto_path}` : `${BASE_URL}/recursos/imagen_referencial.php`;
        document.getElementById('santaRosaParadaName').textContent = company.razon_social || '';
        document.getElementById('santaRosaParadaRuc').textContent = company.ruc || '';
        document.getElementById('santaRosaParadaAddress').textContent = company.direccion || '';
        await loadSantaRosaParadaRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
}

async function loadSantaRosaParadaRows() {
    if (!currentSantaRosaParadaCompanyId) return;
    const data = await santaRosaParadaRequest(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta_santa_rosa.php?action=list&empresa_maquirenta_id=${encodeURIComponent(currentSantaRosaParadaCompanyId)}&t=${Date.now()}`);
    const tbody = document.querySelector('#santaRosaParadaTable tbody');
    tbody.innerHTML = '';

    (data.rows || []).forEach(row => {
        const hasFile = !!row.archivo_path;
        const download = hasFile ? `<a class="btn btn-sm btn-outline-success" href="${BASE_URL}/${row.archivo_path}" download="${escapeHtml(row.archivo_nombre_original || row.documento)}" title="Descargar"><i class="fa-solid fa-download"></i></a>` : '';
        tbody.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="text-center">
                    <input class="form-check-input santa-rosa-parada-check" type="checkbox" value="${row.id}" ${hasFile ? '' : 'disabled'} title="${hasFile ? 'Seleccionar archivo' : 'Sin archivo adjunto'}">
                </td>
                <td>${escapeHtml(row.documento)}</td>
                <td>${row.fecha_registro}</td>
                <td>${row.fecha_inicio}</td>
                <td>${row.fecha_fin}</td>
                <td><span class="badge ${row.status.class}">${row.status.label}</span></td>
                <td>${escapeHtml(row.registered_by || '')}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" type="button" onclick="openEditSantaRosaParada(${row.id})"><i class="fa-solid fa-pen"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="openViewSantaRosaParada(${row.id})"><i class="fa-solid fa-eye"></i></button>
                    <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteSantaRosaParada(${row.id})"><i class="fa-solid fa-trash"></i></button>
                    ${download}
                </td>
            </tr>
        `);
    });
}

function openAddSantaRosaParada() {
    if (!currentSantaRosaParadaCompanyId) return Swal.fire('Atención', 'Seleccione una empresa Maquirenta.', 'warning');
    santaRosaParadaReadOnly = false;
    const form = document.getElementById('santaRosaParadaForm');
    form.reset();
    form.classList.remove('was-validated');
    setSantaRosaParadaReadonly(false);
    document.getElementById('santaRosaParadaModalTitle').textContent = 'Agregar documentos';
    document.getElementById('santaRosaParadaId').value = '';
    document.getElementById('santaRosaParadaCompanyId').value = currentSantaRosaParadaCompanyId;
    document.getElementById('santaRosaParadaRegistration').value = localDateValue();
    $('#santaRosaParadaSelect').val(null).trigger('change');
    renderSantaRosaParadaFile(null);
    santaRosaParadaModal.show();
}

async function fillSantaRosaParada(id) {
    const data = await santaRosaParadaRequest(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta_santa_rosa.php?action=get&id=${id}`);
    const row = data.row;
    const form = document.getElementById('santaRosaParadaForm');
    form.reset();
    document.getElementById('santaRosaParadaId').value = row.id;
    document.getElementById('santaRosaParadaCompanyId').value = row.empresa_maquirenta_id;
    document.getElementById('santaRosaParadaRegistration').value = row.fecha_registro;
    document.getElementById('santaRosaParadaStart').value = row.fecha_inicio;
    document.getElementById('santaRosaParadaEnd').value = row.fecha_fin;
    document.getElementById('santaRosaParadaObservations').value = row.observaciones || '';
    $('#santaRosaParadaSelect').append(new Option(row.documento, row.documento_id, true, true)).trigger('change');
    renderSantaRosaParadaFile(row);
}

window.openEditSantaRosaParada = async id => {
    try {
        santaRosaParadaReadOnly = false;
        await fillSantaRosaParada(id);
        setSantaRosaParadaReadonly(false);
        document.getElementById('santaRosaParadaModalTitle').textContent = 'Editar documentos';
        santaRosaParadaModal.show();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

window.openViewSantaRosaParada = async id => {
    try {
        santaRosaParadaReadOnly = true;
        await fillSantaRosaParada(id);
        setSantaRosaParadaReadonly(true);
        document.getElementById('santaRosaParadaModalTitle').textContent = 'Visualizar documentos';
        santaRosaParadaModal.show();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

function setSantaRosaParadaReadonly(state) {
    document.querySelectorAll('#santaRosaParadaForm input,#santaRosaParadaForm textarea,#santaRosaParadaForm select').forEach(element => {
        if (element.type !== 'hidden') element.disabled = state;
    });
    document.querySelector('#santaRosaParadaForm button[type="submit"]')?.classList.toggle('d-none', state);
    document.getElementById('newSantaRosaParadaCatalogBtn')?.classList.toggle('d-none', state);
    document.getElementById('deleteSantaRosaParadaCatalogBtn')?.classList.toggle('d-none', state);
}

function renderSantaRosaParadaFile(row) {
    const box = document.getElementById('santaRosaParadaCurrentFile');
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
            <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteSantaRosaParadaFile(${row.id})"><i class="fa-solid fa-trash me-1"></i>Eliminar</button>
        </div>
    `;
}

async function saveSantaRosaParada(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (santaRosaParadaReadOnly || !form.checkValidity()) {
        form.classList.add('was-validated');
        return;
    }
    const button = form.querySelector('[type="submit"]');
    const progress = document.getElementById('santaRosaParadaProgress');
    const bar = progress.querySelector('.progress-bar');
    const label = progress.querySelector('small');
    button.disabled = true;

    try {
        const data = await postFormWithProgress(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta_santa_rosa.php?action=save`, new FormData(form), percent => {
            progress.classList.remove('d-none');
            bar.style.width = `${percent}%`;
            label.textContent = percent < 100 ? `Subiendo archivo: ${percent}%` : 'Procesando archivo...';
        });
        if (!data.ok) throw new Error(data.message || 'No se pudo guardar.');
        santaRosaParadaModal.hide();
        await loadSantaRosaParadaRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    } finally {
        button.disabled = false;
        progress.classList.add('d-none');
        bar.style.width = '0%';
    }
}

window.deleteSantaRosaParada = async id => {
    if (!await confirmAction('¿Eliminar documento?')) return;
    try {
        await santaRosaParadaPost('delete', { id });
        await loadSantaRosaParadaRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

window.deleteSantaRosaParadaFile = async id => {
    if (!await confirmAction('¿Eliminar archivo adjunto?')) return;
    try {
        await santaRosaParadaPost('delete_file', { id });
        renderSantaRosaParadaFile(null);
        await loadSantaRosaParadaRows();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
};

async function addSantaRosaParadaCatalog() {
    const focusTrap = santaRosaParadaModal?._focustrap;
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
        const data = await santaRosaParadaPost('catalog_save', { nombre: value });
        $('#santaRosaParadaSelect').append(new Option(data.text, data.id, true, true)).trigger('change');
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
}

async function deleteSantaRosaParadaCatalog() {
    const select = $('#santaRosaParadaSelect');
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
        const data = await santaRosaParadaPost('catalog_delete', { id });
        select.find(`option[value="${id}"]`).remove();
        select.val(null).trigger('change');
        Swal.fire('Eliminado', data.message, 'success');
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
}

async function uploadSantaRosaParadaPhoto(event) {
    const input = event.currentTarget;
    if (!input.files?.[0] || !currentSantaRosaParadaCompanyId) return;
    const body = new FormData();
    body.append('csrf_token', csrf);
    body.append('empresa_maquirenta_id', currentSantaRosaParadaCompanyId);
    body.append('foto', input.files[0]);
    try {
        const data = await santaRosaParadaRequest(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta_santa_rosa.php?action=upload_photo`, { method: 'POST', body });
        document.getElementById('santaRosaParadaPhoto').src = `${data.path}?v=${Date.now()}`;
        Swal.fire('Actualizado', 'Foto actualizada.', 'success');
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    } finally {
        input.value = '';
    }
}

async function downloadSelectedSantaRosaParada() {
    const ids = Array.from(document.querySelectorAll('.santa-rosa-parada-check:checked')).map(item => item.value);
    if (!ids.length) return Swal.fire('Atención', 'Seleccione al menos un documento.', 'warning');
    await downloadSantaRosaParada(ids);
}

async function downloadSantaRosaParada(ids = []) {
    if (!currentSantaRosaParadaCompanyId) return Swal.fire('Atención', 'Seleccione una empresa Maquirenta.', 'warning');
    const params = new URLSearchParams({
        action: 'download',
        empresa_maquirenta_id: currentSantaRosaParadaCompanyId
    });
    if (ids.length) params.set('ids', ids.join(','));
    const response = await fetch(`${BASE_URL}/servicios/empresa_maquirenta/parada_planta_santa_rosa.php?${params}`);
    if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'No se pudo generar la descarga.' }));
        return Swal.fire('Atención', data.message, 'warning');
    }
    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = match?.[1] || 'parada_planta_santa_rosa_empresa_maquirenta.zip';
    document.body.appendChild(link);
    link.click();
    URL.revokeObjectURL(link.href);
    link.remove();
}
