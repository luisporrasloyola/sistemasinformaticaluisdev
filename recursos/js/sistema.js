const BASE_URL = window.APP_URL || window.location.origin;
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
let currentWorkerId = null;
let currentPositionId = null;
let requirementModal = null;
let readOnlyMode = false;

function localDateValue(date = new Date()) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.needs-validation').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const puestosValidos = validarPuestosTrabajo(form);
            if (!form.checkValidity() || !puestosValidos) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
    document.getElementById('sidebarToggle')?.addEventListener('click', () => {
        const sidebar = document.getElementById('sidebar');
        if (window.innerWidth <= 991) {
            sidebar?.classList.toggle('show');
            const isOpen = sidebar?.classList.contains('show');
            document.body.classList.toggle('sidebar-mobile-open', isOpen);
            if (isOpen && sidebar) {
                sidebar.scrollTop = 0;
            }
            return;
        }

        document.body.classList.remove('sidebar-expanding');
        document.body.classList.toggle('sidebar-collapsed');
    });

    document.getElementById('sidebarBackdrop')?.addEventListener('click', () => {
        document.getElementById('sidebar')?.classList.remove('show');
        document.body.classList.remove('sidebar-mobile-open');
    });

    bindPuestosTrabajo();
    initProgresoPersonal();
    bindProgresoPersonalDelegado();
    bindWorkerFileDelete();
    initPersonalList();
    initPmiMasivo();
    initRequirementsModule();
    initMaquinariaDatos();
    initMaquinariaDocumentos();
    initDashboardEjecutivo();
    initUsuariosModule();
    initAttendanceControl();
    initNotifications();

    if (window.jQuery && $.fn.DataTable) {
        $('.data-table').DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json' },
            responsive: true
        });
    }

    if (window.jQuery && $.fn.select2) {
        $('.select2-tags').each(function () {
            const $field = $(this);
            const $modal = $field.closest('.modal');
            $field.select2({
                theme: 'bootstrap4',
                tags: true,
                width: '100%',
                dropdownParent: $modal.length ? $modal : $(document.body),
                placeholder: function () {
                    return $(this).data('placeholder') || 'Seleccione o agregue';
                }
            });
        });
    }
});

function bindWorkerFileDelete() {
    document.querySelectorAll('.js-delete-worker-file').forEach((button) => {
        button.addEventListener('click', async () => {
            const ok = await confirmAction('¿Eliminar archivo?');
            if (!ok) return;
            const form = new FormData();
            form.append('csrf_token', csrf);
            form.append('id', button.dataset.id);
            form.append('type', button.dataset.type);
            const response = await fetch(`${BASE_URL}/servicios/eliminar_archivo_personal.php`, { method: 'POST', body: form });
            const data = await response.json();
            if (data.ok) {
                window.location.reload();
            }
        });
    });
}

function initPersonalList() {
    const table = document.getElementById('personalTable');
    if (!table) return;

    table.querySelectorAll('.js-eliminar-personal').forEach((button) => {
        button.addEventListener('click', async () => {
            const ok = await confirmAction('¿Eliminar personal?');
            if (!ok) return;

            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('id', button.dataset.id);

            const response = await fetch(`${BASE_URL}/servicios/eliminar_personal.php`, { method: 'POST', body });
            const data = await response.json();
            if (data.ok) {
                window.location.reload();
                return;
            }
            Swal.fire('Atención', data.message || 'No se pudo eliminar el personal.', 'warning');
        });
    });
}
function bindProgresoPersonalDelegado() {
    document.addEventListener('input', (event) => {
        const form = event.target.closest?.('#personalForm');
        if (form) actualizarProgresoPersonal(form);
    });

    document.addEventListener('change', (event) => {
        const form = event.target.closest?.('#personalForm');
        if (form) actualizarProgresoPersonal(form);
    });
}
function initProgresoPersonal() {
    const form = document.getElementById('personalForm');
    if (!form) return;

    const watched = form.querySelectorAll('input[name], select[name], .puesto-check, .personal-file');
    watched.forEach((field) => {
        field.addEventListener('input', () => actualizarProgresoPersonal(form));
        field.addEventListener('change', () => actualizarProgresoPersonal(form));
    });

    if (window.jQuery) {
        $(form).find('select[name="company_id"]').on('select2:select select2:clear change', () => actualizarProgresoPersonal(form));
    }

    actualizarProgresoPersonal(form);
}

function actualizarProgresoPersonal(form) {
    if (!form || form.id !== 'personalForm') return;

    const fields = [
        'company_id', 'full_name', 'document_number', 'blood_type',
        'address', 'phone', 'email', 'birth_date'
    ];
    let done = 0;

    fields.forEach((name) => {
        const field = form.querySelector(`[name="${name}"]`);
        if (field && String(field.value || '').trim() !== '') {
            done++;
        }
    });

    const recordId = String(form.querySelector('input[name="id"]')?.value || '0');
    const documentType = form.querySelector('[name="document_type"]');
    const documentNumber = form.querySelector('[name="document_number"]');
    if (documentType && String(documentType.value || '').trim() !== '' && (recordId !== '0' || String(documentNumber?.value || '').trim() !== '')) {
        done++;
    }

    if (tieneArchivoPersonal(form, 'photo')) done++;
    if (tieneArchivoPersonal(form, 'signature')) done++;
    if (form.querySelectorAll('.puesto-check:checked').length > 0) done++;

    const total = fields.length + 4;
    const progress = Math.round((done / total) * 100);
    const active = progress === 100;
    const box = form.querySelector('.estado-calculado-box');
    const badge = box?.querySelector('.badge');
    const label = box?.querySelector('small');
    const status = form.querySelector('input[name="status"]');

    box?.classList.toggle('estado-activo', active);
    box?.classList.toggle('estado-inactivo', !active);
    badge?.classList.toggle('text-bg-success', active);
    badge?.classList.toggle('text-bg-danger', !active);
    if (badge) badge.textContent = active ? 'Activo' : 'Inactivo';
    if (label) label.textContent = `Progreso: ${progress}%`;
    if (status) status.value = active ? '1' : '0';
}

function tieneArchivoPersonal(form, name) {
    const field = form.querySelector(`input[type="file"][name="${name}"]`);
    if (!field) return false;
    return field.files.length > 0 || field.dataset.existing === '1';
}
function bindPuestosTrabajo() {
    const control = document.querySelector('.puestos-control');
    if (!control) return;

    const input = document.getElementById('nuevoPuestoInput');
    const button = document.getElementById('agregarPuestoBtn');
    const grid = control.querySelector('.puestos-grid');

    control.addEventListener('change', () => {
        const form = control.closest('form');
        validarPuestosTrabajo(form);
        actualizarProgresoPersonal(form);
    });
    button?.addEventListener('click', () => agregarPuestoTrabajo(input, grid, control));
    control.addEventListener('click', (event) => {
        const deleteButton = event.target.closest?.('.js-eliminar-puesto');
        if (deleteButton) {
            event.preventDefault();
            event.stopPropagation();
            eliminarPuestoTrabajo(deleteButton, control);
        }
    });
    input?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            agregarPuestoTrabajo(input, grid, control);
        }
    });
}

async function agregarPuestoTrabajo(input, grid, control) {
    const nombre = (input?.value || '').trim();
    if (!nombre) return;

    const existe = Array.from(grid.querySelectorAll('.puesto-chip span')).some((span) => {
        return span.textContent.trim().toLowerCase() === nombre.toLowerCase();
    });

    if (existe) {
        Swal.fire('Atención', 'El puesto ya existe.', 'warning');
        return;
    }

    const form = control.closest('form');
    const body = new FormData();
    body.append('csrf_token', form?.querySelector('input[name="csrf_token"]')?.value || csrf);
    body.append('name', nombre);

    const button = document.getElementById('agregarPuestoBtn');
    if (button) button.disabled = true;

    try {
        const response = await fetch(`${BASE_URL}/servicios/guardar_puesto.php`, { method: 'POST', body });
        const data = await response.json();
        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo guardar el puesto.', 'warning');
            return;
        }

        const id = `puesto_${data.id}`;
        if (document.getElementById(id)) {
            document.getElementById(id).checked = true;
        } else {
            grid.insertAdjacentHTML('beforeend', `
                <div class="puesto-item" data-position-id="${data.id}">
                    <input class="btn-check puesto-check" type="checkbox" name="positions[]" id="${id}" value="${data.id}" checked>
                    <label class="puesto-chip" for="${id}">
                        <i class="fa-solid fa-check"></i>
                        <span>${escapeHtml(data.text)}</span>
                    </label>
                    <button class="btn btn-sm btn-outline-danger puesto-delete-btn js-eliminar-puesto" type="button" data-id="${data.id}" data-name="${escapeHtml(data.text)}" title="Eliminar puesto">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            `);
        }

        input.value = '';
        validarPuestosTrabajo(form);
        actualizarProgresoPersonal(form);
        Swal.fire({ icon: 'success', title: 'Puesto guardado', timer: 1000, showConfirmButton: false });
    } catch (error) {
        Swal.fire('Atención', 'No se pudo guardar el puesto.', 'warning');
    } finally {
        if (button) button.disabled = false;
    }
}

async function eliminarPuestoTrabajo(button, control) {
    const item = button.closest('.puesto-item');
    const id = String(button.dataset.id || '').trim();
    const name = String(button.dataset.name || item?.querySelector('.puesto-chip span')?.textContent || 'puesto').trim();

    if (!id || !/^\d+$/.test(id)) {
        item?.remove();
        const form = control.closest('form');
        validarPuestosTrabajo(form);
        actualizarProgresoPersonal(form);
        return;
    }

    const result = await Swal.fire({
        title: '¿Eliminar puesto?',
        text: `Solo se eliminará "${name}" si no está asignado ni configurado en requisitos.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    });
    if (!result.isConfirmed) return;

    const body = new FormData();
    body.append('csrf_token', csrf);
    body.append('id', id);

    const response = await fetch(`${BASE_URL}/servicios/eliminar_puesto.php`, { method: 'POST', body });
    const data = await response.json();
    if (!data.ok) {
        Swal.fire('Atención', data.message || 'No se pudo eliminar el puesto.', 'warning');
        return;
    }

    item?.remove();
    const form = control.closest('form');
    validarPuestosTrabajo(form);
    actualizarProgresoPersonal(form);
    Swal.fire({ icon: 'success', title: 'Puesto eliminado', timer: 1200, showConfirmButton: false });
}
function validarPuestosTrabajo(form) {
    const control = form?.querySelector('.puestos-control');
    if (!control) return true;

    const ok = control.querySelectorAll('.puesto-check:checked').length > 0;
    control.classList.toggle('is-invalid', !ok);
    control.querySelector('.puestos-error')?.classList.toggle('d-none', ok);
    return ok;
}

function initPmiMasivo() {
    const form = document.getElementById('pmiMasivoForm');
    if (!form) return;

    const rows = Array.from(form.querySelectorAll('tbody tr'));
    const filtro = document.getElementById('filtroMasivo');
    rows.forEach((row) => bindMasivoRow(row));
    bindBulkRequirements(form, rows);

    filtro?.addEventListener('input', () => filtrarPmiMasivo(rows, filtro.value, form));

    document.getElementById('seleccionarTodosMasivo')?.addEventListener('click', () => {
        const visibleRows = rows.filter((row) => !row.classList.contains('d-none'));
        if (!visibleRows.length) {
            Swal.fire('Atención', 'No hay registros visibles para seleccionar.', 'warning');
            return;
        }

        const shouldCheck = visibleRows.some((row) => !row.querySelector('.masivo-check')?.checked);
        visibleRows.forEach((row) => {
            const check = row.querySelector('.masivo-check');
            if (check) {
                check.checked = shouldCheck;
                toggleMasivoRow(row, shouldCheck);
            }
        });
        updateBulkMode(form, rows);
    });

    form.addEventListener('submit', guardarPmiMasivo);
}


function bindBulkRequirements(form, rows) {
    form.querySelectorAll('.bulk-requirement-check').forEach((check) => {
        check.addEventListener('change', () => {
            const file = check.closest('.bulk-requirement-item')?.querySelector('.bulk-requirement-file');
            if (file) {
                file.disabled = !check.checked;
                file.required = check.checked;
                if (!check.checked) file.value = '';
            }
            updateBulkMode(form, rows);
        });
    });
}

function bulkRequirementsActive(form) {
    return Array.from(form.querySelectorAll('.bulk-requirement-check')).some((check) => check.checked);
}

function updateBulkMode(form, rows) {
    const bulkActive = bulkRequirementsActive(form);
    rows.forEach((row) => {
        const selected = !!row.querySelector('.masivo-check')?.checked;
        row.querySelectorAll('.masivo-bypass-bulk').forEach((field) => {
            field.disabled = bulkActive || !selected;
            field.required = !bulkActive;
            if (bulkActive && field.type === 'file') {
                field.value = '';
                row.querySelector('.quitar-documento-masivo')?.classList.add('d-none');
            }
        });
    });
}

function validateBulkRequirements(form) {
    const checked = Array.from(form.querySelectorAll('.bulk-requirement-check:checked'));
    if (!checked.length) {
        Swal.fire('Atención', 'Seleccione SCTR o VIDA LEY en la aplicación masiva.', 'warning');
        return false;
    }

    for (const check of checked) {
        const file = check.closest('.bulk-requirement-item')?.querySelector('.bulk-requirement-file');
        const label = check.closest('.bulk-requirement-item')?.querySelector('.form-check-label')?.textContent?.trim() || 'requisito';
        if (!file || !file.files.length) {
            Swal.fire('Atención', `Adjunte el documento PDF para ${label}.`, 'warning');
            return false;
        }
    }
    return true;
}
function validateBulkDates(form) {
    const registrationDate = form.querySelector('[name="registration_date"]')?.value || '';
    const startDate = form.querySelector('[name="start_date"]')?.value || '';
    const endDate = form.querySelector('[name="end_date"]')?.value || '';

    if (!registrationDate || !startDate || !endDate) {
        Swal.fire('Atención', 'Complete F. Registro, F. Inicio y F. Fin en la aplicación masiva.', 'warning');
        return false;
    }

    if (endDate < startDate) {
        Swal.fire('Atención', 'F. Fin no puede ser menor a F. Inicio.', 'warning');
        return false;
    }

    return true;
}
function filtrarPmiMasivo(rows, value, form = null) {
    const terminos = normalizarTexto(value).split(/\s+/).filter(Boolean);

    rows.forEach((row) => {
        const texto = normalizarTexto(row.dataset.filter || row.textContent || '');
        const visible = terminos.every((term) => texto.includes(term));
        row.classList.toggle('d-none', !visible);

        if (!visible) {
            const check = row.querySelector('.masivo-check');
            if (check?.checked) {
                check.checked = false;
                toggleMasivoRow(row, false);
            }
        }
    });

    if (form) updateBulkMode(form, rows);
}

function normalizarTexto(value) {
    return String(value || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim();
}
function bindMasivoRow(row) {
    const check = row.querySelector('.masivo-check');
    const file = row.querySelector('.masivo-file');
    const removeFile = row.querySelector('.quitar-documento-masivo');

    check?.addEventListener('change', () => {
        toggleMasivoRow(row, check.checked);
        const form = row.closest('form');
        if (form) updateBulkMode(form, Array.from(form.querySelectorAll('tbody tr')));
    });
    file?.addEventListener('change', () => {
        removeFile?.classList.toggle('d-none', !file.files.length);
    });
    removeFile?.addEventListener('click', () => {
        file.value = '';
        removeFile.classList.add('d-none');
    });
}

function toggleMasivoRow(row, enabled) {
    row.classList.toggle('table-active', enabled);
    row.querySelectorAll('.masivo-required').forEach((field) => {
        field.disabled = !enabled;
        if (!enabled && field.type === 'file') {
            field.value = '';
            row.querySelector('.quitar-documento-masivo')?.classList.add('d-none');
        }
    });
}


function resetPmiMasivoForm(form) {
    form.reset();
    form.classList.remove('was-validated');
    form.querySelectorAll('.is-valid, .is-invalid').forEach((field) => {
        field.classList.remove('is-valid', 'is-invalid');
    });

    const today = localDateValue();
    const registrationDate = document.getElementById('bulkRegistrationDate');
    const startDate = document.getElementById('bulkStartDate');
    const endDate = document.getElementById('bulkEndDate');
    if (registrationDate) registrationDate.value = today;
    if (startDate) startDate.value = today;
    if (endDate) endDate.value = '';

    const filtro = document.getElementById('filtroMasivo');
    if (filtro) filtro.value = '';

    const rows = Array.from(form.querySelectorAll('tbody tr'));
    rows.forEach((row) => {
        row.classList.remove('d-none', 'table-active');
        row.querySelector('.masivo-check').checked = false;
        row.querySelectorAll('.masivo-required').forEach((field) => {
            field.disabled = true;
            field.required = true;
            if (field.type === 'file') field.value = '';
        });
        row.querySelector('.quitar-documento-masivo')?.classList.add('d-none');
    });

    form.querySelectorAll('.bulk-requirement-check').forEach((check) => {
        check.checked = false;
    });
    form.querySelectorAll('.bulk-requirement-file').forEach((field) => {
        field.value = '';
        field.disabled = true;
        field.required = false;
    });

    updateBulkMode(form, rows);
}
async function guardarPmiMasivo(event) {
    event.preventDefault();

    const form = event.currentTarget;
    const selectedRows = Array.from(form.querySelectorAll('.masivo-check:checked')).map((check) => check.closest('tr'));
    if (!selectedRows.length) {
        Swal.fire('Atención', 'Seleccione al menos un registro.', 'warning');
        return;
    }

    if (!validateBulkRequirements(form)) return;
    if (!validateBulkDates(form)) return;

    for (const row of selectedRows) {
        const invalid = Array.from(row.querySelectorAll(bulkRequirementsActive(form) ? '.masivo-required:not(.masivo-bypass-bulk)' : '.masivo-required')).some((field) => !field.value);
        if (invalid) {
            Swal.fire('Atención', 'Complete todos los campos obligatorios de los registros seleccionados.', 'warning');
            return;
        }
    }

    const response = await fetch(`${BASE_URL}/servicios/guardar_requisitos_masivos.php`, {
        method: 'POST',
        body: new FormData(form)
    });
    const data = await response.json();

    if (!data.ok) {
        Swal.fire('Atención', data.message || 'No se pudo guardar la carga masiva.', 'warning');
        return;
    }

    const extra = data.errors?.length ? `<br><small>${data.errors.join('<br>')}</small>` : '';
    const selectedCount = selectedRows.length;
    await Swal.fire('Guardado', `Se guardaron ${selectedCount} registro(s).${extra}`, 'success');
    resetPmiMasivoForm(form);
}

function initRequirementsModule() {
    const workerSearch = $('#workerSearch');
    if (!workerSearch.length) return;

    requirementModal = new bootstrap.Modal(document.getElementById('requirementModal'));

    workerSearch.select2({
        theme: 'bootstrap4',
        width: '100%',
        placeholder: 'Escriba nombre o documento',
        ajax: {
            url: `${BASE_URL}/servicios/buscar_personal.php`,
            dataType: 'json',
            delay: 250,
            data: (params) => ({ q: params.term || '' })
        }
    });

    workerSearch.on('select2:select', (event) => loadWorker(event.params.data.id));
    document.getElementById('positionSelect').addEventListener('change', (event) => {
        currentPositionId = event.target.value;
        loadRequirements();
    });

    $('#requirementSelect').select2({
        theme: 'bootstrap4',
        dropdownParent: $('#requirementModal'),
        width: '100%',
        placeholder: 'Buscar requisito',
        ajax: {
            url: `${BASE_URL}/servicios/catalogo_requisitos.php`,
            dataType: 'json',
            delay: 200,
            data: (params) => ({ q: params.term || '', puesto_id: currentPositionId || 0 })
        }
    });

    document.getElementById('addRequirementBtn').addEventListener('click', openAddRequirement);
    document.getElementById('downloadRequirementsBtn').addEventListener('click', downloadRequirementsBundle);
    document.getElementById('downloadSelectedRequirementsBtn')?.addEventListener('click', downloadSelectedRequirementsBundle);
    document.getElementById('requirementForm').addEventListener('submit', saveRequirement);
    document.getElementById('newCatalogRequirementBtn').addEventListener('click', addCatalogRequirement);
    document.getElementById('deleteCatalogRequirementBtn').addEventListener('click', deleteCatalogRequirement);
    document.getElementById('quickPhotoInput').addEventListener('change', uploadQuickPhoto);
}

async function loadWorker(id) {
    currentWorkerId = id;
    const response = await fetch(`${BASE_URL}/servicios/perfil_personal.php?id=${id}`);
    const data = await response.json();
    if (!data.ok) return;

    const worker = data.worker;
    document.getElementById('requirementsWorkspace').classList.remove('d-none');
    document.getElementById('workerPhoto').src = worker.photo_path ? `${BASE_URL}/${worker.photo_path}` : `${BASE_URL}/recursos/imagen_referencial.php`;
    document.getElementById('workerDocument').textContent = `${worker.document_type}: ${worker.document_number}`;
    document.getElementById('workerName').textContent = worker.full_name;
    document.getElementById('workerCompany').textContent = worker.company || '';
    document.getElementById('workerPositions').textContent = data.positions.map((p) => p.name).join(', ');
    document.getElementById('workerActive').checked = Number(worker.status) === 1;

    const select = document.getElementById('positionSelect');
    select.innerHTML = data.positions.map((p) => `<option value="${p.id}">${escapeHtml(p.name)}</option>`).join('');
    currentPositionId = select.value || null;
    loadRequirements();
}

async function loadRequirements() {
    if (!currentWorkerId || !currentPositionId) return;
    const response = await fetch(`${BASE_URL}/servicios/listar_requisitos.php?trabajador_id=${currentWorkerId}&puesto_id=${currentPositionId}`);
    const data = await response.json();
    const tbody = document.querySelector('#requirementsTable tbody');
    tbody.innerHTML = '';
    data.rows.forEach((row) => {
        const hasPdf = !!row.file_path;
        const downloadName = escapeHtml(row.original_file_name || `${row.requirement}.pdf`);
        const downloadButton = hasPdf
            ? `<a class="btn btn-sm btn-outline-success" href="${BASE_URL}/${row.file_path}" download="${downloadName}" title="Descargar documento"><i class="fa-solid fa-download"></i></a>`
            : '';
        tbody.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="text-center">
                    <input class="form-check-input requirement-download-check" type="checkbox" value="${row.id}" ${hasPdf ? '' : 'disabled'} title="${hasPdf ? 'Seleccionar documento' : 'Sin PDF adjunto'}">
                </td>
                <td>${escapeHtml(row.requirement)}</td>
                <td>${row.registration_date}</td>
                <td>${row.start_date}</td>
                <td>${row.end_date}</td>
                <td><span class="badge ${row.status.class}">${row.status.label}</span></td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" onclick="openEditRequirement(${row.id})"><i class="fa-solid fa-pen"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="openViewRequirement(${row.id})"><i class="fa-solid fa-eye"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteRequirement(${row.id})"><i class="fa-solid fa-trash"></i></button>
                    ${downloadButton}
                </td>
            </tr>
        `);
    });
}

function openAddRequirement() {
    readOnlyMode = false;
    const form = document.getElementById('requirementForm');
    form.reset();
    form.classList.remove('was-validated');
    setRequirementReadonly(false);
    document.getElementById('requirementModalTitle').textContent = 'Agregar Requisito';
    document.getElementById('requirementId').value = '';
    document.getElementById('requirementWorkerId').value = currentWorkerId;
    document.getElementById('requirementPositionId').value = currentPositionId;
    document.getElementById('registrationDate').value = localDateValue();
    $('#requirementSelect').val(null).trigger('change');
    renderCurrentPdf(null);
    requirementModal.show();
}

async function openEditRequirement(id) {
    readOnlyMode = false;
    await fillRequirementModal(id);
    setRequirementReadonly(false);
    document.getElementById('requirementModalTitle').textContent = 'Editar Requisito';
    requirementModal.show();
}

async function openViewRequirement(id) {
    readOnlyMode = true;
    await fillRequirementModal(id);
    setRequirementReadonly(true);
    document.getElementById('requirementModalTitle').textContent = 'Visualizar Requisito';
    requirementModal.show();
}

async function fillRequirementModal(id) {
    const response = await fetch(`${BASE_URL}/servicios/obtener_requisito.php?id=${id}`);
    const data = await response.json();
    const row = data.row;
    document.getElementById('requirementId').value = row.id;
    document.getElementById('requirementWorkerId').value = row.worker_id;
    document.getElementById('requirementPositionId').value = row.position_id;
    document.getElementById('registrationDate').value = row.registration_date;
    document.getElementById('startDate').value = row.start_date;
    document.getElementById('endDate').value = row.end_date;
    document.getElementById('observations').value = row.observations || '';
    const option = new Option(row.requirement, row.requirement_id, true, true);
    $('#requirementSelect').append(option).trigger('change');
    renderCurrentPdf(row);
}

function renderCurrentPdf(row) {
    const box = document.getElementById('currentPdf');
    if (!row || !row.file_path) {
        box.classList.add('d-none');
        box.innerHTML = '';
        return;
    }
    box.classList.remove('d-none');
    box.innerHTML = `
        <i class="fa-solid fa-file-pdf text-danger me-2"></i>
        <strong>${escapeHtml(row.original_file_name || 'archivo.pdf')}</strong>
        <div class="d-flex gap-2 mt-2">
            <a class="btn btn-sm btn-outline-primary" target="_blank" href="${BASE_URL}/${row.file_path}"><i class="fa-solid fa-up-right-from-square me-1"></i>Abrir</a>
            <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteRequirementPdf(${row.id})"><i class="fa-solid fa-trash me-1"></i>Eliminar</button>
        </div>`;
}

function setRequirementReadonly(state) {
    document.querySelectorAll('#requirementForm input, #requirementForm textarea, #requirementForm select').forEach((el) => {
        if (el.name === 'csrf_token' || el.type === 'hidden') return;
        el.disabled = state;
    });
    document.querySelector('#requirementForm button[type="submit"]').classList.toggle('d-none', state);
    document.getElementById('pdfInput').classList.toggle('d-none', state);
    document.getElementById('newCatalogRequirementBtn').classList.toggle('d-none', state);
    document.getElementById('deleteCatalogRequirementBtn').classList.toggle('d-none', state);
}

async function saveRequirementLegacy(event) {
    event.preventDefault();
    if (readOnlyMode || !event.currentTarget.checkValidity()) return;
    const response = await fetch(`${BASE_URL}/servicios/guardar_requisito.php`, {
        method: 'POST',
        body: new FormData(event.currentTarget)
    });
    const data = await response.json();
    if (!data.ok) {
        Swal.fire('Atención', data.message || 'No se pudo guardar.', 'warning');
        return;
    }
    requirementModal.hide();
    loadRequirements();
}

async function saveRequirement(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (readOnlyMode || !form.checkValidity()) return;

    const submitButton = form.querySelector('button[type="submit"]');
    const progressBox = document.getElementById('requirementUploadProgress');
    const progressBar = progressBox?.querySelector('.progress-bar');
    const progressLabel = progressBox?.querySelector('small');

    function renderProgress(percent) {
        if (!progressBox || !progressBar || !progressLabel) return;
        progressBox.classList.remove('d-none');
        progressBar.style.width = `${percent}%`;
        progressBar.setAttribute('aria-valuenow', String(percent));
        progressLabel.textContent = percent < 100 ? `Subiendo archivo: ${percent}%` : 'Procesando archivo...';
    }

    submitButton.disabled = true;
    renderProgress(0);

    try {
        const data = await postFormWithProgress(
            `${BASE_URL}/servicios/guardar_requisito.php`,
            new FormData(form),
            renderProgress
        );

        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo guardar.', 'warning');
            return;
        }
        requirementModal.hide();
        loadRequirements();
    } catch (error) {
        Swal.fire('Atención', error.message || 'No se pudo guardar.', 'warning');
    } finally {
        submitButton.disabled = false;
        progressBox?.classList.add('d-none');
        if (progressBar) progressBar.style.width = '0%';
        if (progressLabel) progressLabel.textContent = 'Subiendo archivo: 0%';
    }
}

async function deleteRequirement(id) {
    const ok = await confirmAction('¿Eliminar requisito?');
    if (!ok) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('id', id);
    const response = await fetch(`${BASE_URL}/servicios/eliminar_requisito.php`, { method: 'POST', body: form });
    const data = await response.json();
    if (data.ok) loadRequirements();
}

async function deleteRequirementPdf(id) {
    const ok = await confirmAction('¿Eliminar PDF?');
    if (!ok) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('id', id);
    const response = await fetch(`${BASE_URL}/servicios/eliminar_pdf_requisito.php`, { method: 'POST', body: form });
    const data = await response.json();
    if (data.ok) {
        renderCurrentPdf(null);
        loadRequirements();
    }
}

async function addCatalogRequirement() {
    const focusTrap = requirementModal?._focustrap;
    focusTrap?.deactivate?.();

    let value = null;
    try {
        const result = await Swal.fire({
            title: 'Nuevo requisito',
            input: 'text',
            inputPlaceholder: 'Nombre del requisito',
            showCancelButton: true,
            confirmButtonText: 'Agregar',
            cancelButtonText: 'Cancelar',
            didOpen: () => Swal.getInput()?.focus()
        });
        value = result.value;
    } finally {
        setTimeout(() => focusTrap?.activate?.(), 0);
    }

    if (!value) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('name', value);
    form.append('position_id', currentPositionId || 0);
    const response = await fetch(`${BASE_URL}/servicios/guardar_catalogo_requisito.php`, { method: 'POST', body: form });
    const data = await response.json();
    if (!data.ok) {
        Swal.fire('Atención', data.message || 'No se pudo agregar.', 'warning');
        return;
    }
    const option = new Option(data.text, data.id, true, true);
    $('#requirementSelect').append(option).trigger('change');
}
async function deleteCatalogRequirement() {
    const select = $('#requirementSelect');
    const requirementId = select.val();
    const requirementText = select.find('option:selected').text().trim();

    if (!requirementId) {
        Swal.fire('Atención', 'Seleccione un requisito para eliminar.', 'warning');
        return;
    }

    const result = await Swal.fire({
        title: '¿Eliminar requisito?',
        text: `Se quitará "${requirementText}" del catálogo si no tiene documentos registrados.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    });

    if (!result.isConfirmed) return;

    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('id', requirementId);

    const response = await fetch(`${BASE_URL}/servicios/eliminar_catalogo_requisito.php`, { method: 'POST', body: form });
    const data = await response.json();

    if (!data.ok) {
        Swal.fire('Atención', data.message || 'No se pudo eliminar el requisito.', 'warning');
        return;
    }

    select.find(`option[value="${requirementId}"]`).remove();
    select.val(null).trigger('change');
    Swal.fire('Eliminado', data.message || 'Requisito eliminado.', 'success');
}
async function downloadSelectedRequirementsBundle() {
    if (!currentWorkerId || !currentPositionId) {
        Swal.fire('Atención', 'Seleccione un trabajador y un puesto de trabajo.', 'warning');
        return;
    }

    const selectedIds = Array.from(document.querySelectorAll('.requirement-download-check:checked')).map((check) => check.value);
    if (!selectedIds.length) {
        Swal.fire('Atención', 'Seleccione al menos un documento para descargar.', 'warning');
        return;
    }

    await downloadRequirementsZip(selectedIds);
}
async function downloadRequirementsBundle() {
    if (!currentWorkerId || !currentPositionId) {
        Swal.fire('Atención', 'Seleccione un trabajador y un puesto de trabajo.', 'warning');
        return;
    }

    await downloadRequirementsZip();
}

async function downloadRequirementsZip(selectedIds = []) {
    const params = new URLSearchParams({
        trabajador_id: currentWorkerId,
        puesto_id: currentPositionId,
    });

    if (selectedIds.length) {
        params.set('ids', selectedIds.join(','));
    }

    const response = await fetch(`${BASE_URL}/servicios/descargar_requisitos.php?${params.toString()}`);

    if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'No se pudo generar la descarga.' }));
        Swal.fire('Atención', data.message || 'No se pudo generar la descarga.', 'warning');
        return;
    }

    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const fileName = match ? match[1] : 'documentos_requisitos.zip';
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);

    if (selectedIds.length) {
        document.querySelectorAll('.requirement-download-check:checked').forEach((check) => {
            check.checked = false;
        });
    }
}

async function uploadQuickPhoto(event) {
    const file = event.target.files[0];
    if (!file || !currentWorkerId) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('worker_id', currentWorkerId);
    form.append('photo', file);
    const response = await fetch(`${BASE_URL}/servicios/subir_foto_personal.php`, { method: 'POST', body: form });
    const data = await response.json();
    if (data.ok) {
        document.getElementById('workerPhoto').src = data.path;
    } else {
        Swal.fire('Atención', data.message || 'No se pudo cambiar la foto.', 'warning');
    }
}

async function confirmAction(title) {
    const result = await Swal.fire({
        title,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí',
        cancelButtonText: 'Cancelar'
    });
    return result.isConfirmed;
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
}

function postFormWithProgress(url, formData, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.responseType = 'json';

        xhr.upload.addEventListener('progress', (event) => {
            if (!event.lengthComputable || typeof onProgress !== 'function') return;
            onProgress(Math.round((event.loaded / event.total) * 100));
        });

        xhr.addEventListener('load', () => {
            const data = xhr.response || {};
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(data);
                return;
            }
            resolve(data.ok === false ? data : { ok: false, message: 'No se pudo procesar la solicitud.' });
        });

        xhr.addEventListener('error', () => reject(new Error('No se pudo conectar con el servidor.')));
        xhr.addEventListener('abort', () => reject(new Error('La subida fue cancelada.')));
        xhr.send(formData);
    });
}


let currentMachineId = null;
let machineDocumentModal = null;
let machineReadOnlyMode = false;

function initMaquinariaDatos() {
    const table = document.getElementById('maquinariaTable');
    if (!table) return;

    const form = document.getElementById('maquinariaForm');
    const modal = new bootstrap.Modal(document.getElementById('maquinariaModal'));
    const photoModal = new bootstrap.Modal(document.getElementById('maquinariaFotoModal'));

    document.getElementById('nuevoMaquinariaBtn')?.addEventListener('click', () => {
        form.reset();
        form.classList.remove('was-validated');
        document.getElementById('maquinariaId').value = '';
        setMachineCompanyValue('');
        document.getElementById('maquinariaModalTitle').textContent = 'Nueva maquinaria';
        renderMaquinariaFotoActual(null);
        modal.show();
    });

    document.querySelectorAll('.js-editar-maquinaria').forEach((button) => {
        button.addEventListener('click', () => {
            form.reset();
            form.classList.remove('was-validated');
            document.getElementById('maquinariaId').value = button.dataset.id || '';
            setMachineCompanyValue(button.dataset.companyId || '');
            document.getElementById('maquinariaEquipo').value = button.dataset.equipo || '';
            document.getElementById('maquinariaSerie').value = button.dataset.serie || '';
            document.getElementById('maquinariaAnio').value = button.dataset.anio || '';
            document.getElementById('maquinariaModalTitle').textContent = 'Editar maquinaria';
            renderMaquinariaFotoActual(button.dataset.foto || null);
            modal.show();
        });
    });

    document.querySelectorAll('.js-eliminar-maquinaria').forEach((button) => {
        button.addEventListener('click', async () => {
            const ok = await confirmAction('\u00bfEliminar maquinaria?');
            if (!ok) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('id', button.dataset.id);
            const response = await fetch(`${BASE_URL}/servicios/eliminar_maquinaria.php`, { method: 'POST', body });
            const data = await response.json();
            if (data.ok) window.location.reload();
            else Swal.fire('Atenci\u00f3n', data.message || 'No se pudo eliminar.', 'warning');
        });
    });

    document.querySelectorAll('.js-ver-foto-maquinaria').forEach((button) => {
        button.addEventListener('click', () => {
            if (!button.dataset.foto) return;
            document.getElementById('maquinariaFotoModalImg').src = `${BASE_URL}/${button.dataset.foto}`;
            photoModal.show();
        });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.checkValidity()) return;
        const response = await fetch(`${BASE_URL}/servicios/guardar_maquinaria.php`, { method: 'POST', body: new FormData(form) });
        const data = await response.json();
        if (!data.ok) {
            Swal.fire('Atenci\u00f3n', data.message || 'No se pudo guardar.', 'warning');
            return;
        }
        modal.hide();
        window.location.reload();
    });

    initMachineCompanyQuickActions();
}

function setMachineCompanyValue(value) {
    const companySelect = document.getElementById('maquinariaEmpresa');
    if (!companySelect) return;
    if (window.jQuery && $.fn.select2) {
        jQuery(companySelect).val(value || null).trigger('change');
        return;
    }
    companySelect.value = value;
    companySelect.dispatchEvent(new Event('change', { bubbles: true }));
}

function initMachineCompanyQuickActions() {
    const companySelect = document.getElementById('maquinariaEmpresa');
    const quickModalElement = document.getElementById('machineCompanyQuickModal');
    const quickForm = document.getElementById('machineCompanyQuickForm');
    const quickName = document.getElementById('machineCompanyQuickName');
    const openButton = document.getElementById('openMachineCompanyModalBtn');
    const deleteButton = document.getElementById('deleteMachineCompanyBtn');
    const quickModal = quickModalElement ? bootstrap.Modal.getOrCreateInstance(quickModalElement) : null;

    if (!companySelect || !quickModal || !quickForm) return;

    openButton?.addEventListener('click', () => {
        quickForm.reset();
        quickForm.classList.remove('was-validated');
        quickModal.show();
        setTimeout(() => quickName?.focus(), 180);
    });

    quickForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!quickForm.checkValidity()) {
            quickForm.classList.add('was-validated');
            return;
        }

        const submitButton = quickForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        try {
            const response = await fetch(`${BASE_URL}/servicios/guardar_empresa.php`, {
                method: 'POST',
                body: new FormData(quickForm)
            });
            const data = await response.json();
            if (!data.ok) {
                Swal.fire('Atenci\u00f3n', data.message || 'No se pudo guardar la empresa.', 'warning');
                return;
            }

            const existing = Array.from(companySelect.options).find((option) => option.value === String(data.id));
            if (existing) {
                existing.textContent = data.text;
            } else {
                companySelect.append(new Option(data.text, data.id, false, false));
            }
            setMachineCompanyValue(String(data.id));
            quickModal.hide();
            Swal.fire({ icon: 'success', title: 'Empresa agregada', timer: 1200, showConfirmButton: false });
        } catch (error) {
            Swal.fire('Atenci\u00f3n', 'No se pudo guardar la empresa.', 'warning');
        } finally {
            submitButton.disabled = false;
        }
    });

    deleteButton?.addEventListener('click', async () => {
        const value = String(companySelect.value || '');
        if (!value || !/^\d+$/.test(value)) {
            Swal.fire('Atenci\u00f3n', 'Seleccione una empresa guardada para eliminar.', 'warning');
            return;
        }

        const result = await Swal.fire({
            title: '\u00bfEliminar empresa?',
            text: 'Solo se eliminara si no esta asignada a ningun personal ni maquinaria.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Si, eliminar',
            cancelButtonText: 'Cancelar'
        });
        if (!result.isConfirmed) return;

        const body = new FormData();
        body.append('csrf_token', csrf);
        body.append('id', value);

        const response = await fetch(`${BASE_URL}/servicios/eliminar_empresa.php`, { method: 'POST', body });
        const data = await response.json();
        if (!data.ok) {
            Swal.fire('Atenci\u00f3n', data.message || 'No se pudo eliminar la empresa.', 'warning');
            return;
        }

        Array.from(companySelect.options).find((option) => option.value === value)?.remove();
        setMachineCompanyValue('');
        Swal.fire({ icon: 'success', title: 'Empresa eliminada', timer: 1200, showConfirmButton: false });
    });
}

function renderMaquinariaFotoActual(path) {
    const box = document.getElementById('maquinariaFotoActual');
    if (!box) return;
    if (!path) {
        box.classList.add('d-none');
        box.innerHTML = '';
        return;
    }
    box.classList.remove('d-none');
    box.innerHTML = `<i class="fa-solid fa-image text-primary me-2"></i><a target="_blank" href="${BASE_URL}/${path}">Ver foto actual</a>`;
}

function initMaquinariaDocumentos() {
    const machineSearchElement = document.getElementById('machineSearch');
    if (!machineSearchElement) return;

    machineDocumentModal = new bootstrap.Modal(document.getElementById('machineDocumentModal'));

    if (window.jQuery && $.fn.select2) {
        $('#machineSearch').select2({
            theme: 'bootstrap4',
            width: '100%',
            placeholder: 'Escriba equipo, serie o placa',
            ajax: {
                url: `${BASE_URL}/servicios/buscar_maquinaria.php`,
                dataType: 'json',
                delay: 250,
                data: (params) => ({ q: params.term || '' })
            }
        });

        $('#machineSearch').on('select2:select', (event) => loadMachine(event.params.data.id));

        $('#machineDocumentSelect').select2({
            theme: 'bootstrap4',
            dropdownParent: $('#machineDocumentModal'),
            width: '100%',
            placeholder: 'Buscar documento',
            ajax: {
                url: `${BASE_URL}/servicios/catalogo_documentos_maquinaria.php`,
                dataType: 'json',
                delay: 200,
                data: (params) => ({ q: params.term || '' })
            }
        });
    }

    machineSearchElement.addEventListener('change', (event) => {
        if (event.target.value) loadMachine(event.target.value);
    });

    document.getElementById('downloadMachineDocumentsBtn')?.addEventListener('click', downloadMachineDocumentsBundle);
    document.getElementById('downloadSelectedMachineDocumentsBtn')?.addEventListener('click', downloadSelectedMachineDocumentsBundle);
    document.getElementById('addMachineDocumentBtn')?.addEventListener('click', openAddMachineDocument);
    document.getElementById('changeMachinePhotoBtn')?.addEventListener('click', () => {
        if (!currentMachineId) {
            Swal.fire('Atención', 'Seleccione una maquinaria.', 'warning');
            return;
        }
        document.getElementById('machinePhotoInput')?.click();
    });
    document.getElementById('machinePhotoInput')?.addEventListener('change', uploadMachinePhoto);
    document.getElementById('machineDocumentForm')?.addEventListener('submit', saveMachineDocument);
    document.getElementById('newMachineCatalogDocumentBtn')?.addEventListener('click', addMachineCatalogDocument);
    document.getElementById('deleteMachineCatalogDocumentBtn')?.addEventListener('click', deleteMachineCatalogDocument);
}
async function uploadMachinePhoto(event) {
    const input = event.currentTarget;
    const file = input.files?.[0];
    if (!file || !currentMachineId) return;

    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('maquinaria_id', currentMachineId);
    form.append('foto', file);

    const response = await fetch(`${BASE_URL}/servicios/subir_foto_maquinaria.php`, { method: 'POST', body: form });
    const data = await response.json();
    input.value = '';

    if (!data.ok) {
        Swal.fire('Atención', data.message || 'No se pudo cambiar la foto.', 'warning');
        return;
    }

    document.getElementById('machinePhoto').src = `${data.path}?v=${Date.now()}`;
    Swal.fire('Actualizado', 'Foto de maquinaria actualizada.', 'success');
}
async function loadMachine(id) {
    currentMachineId = id;
    const response = await fetch(`${BASE_URL}/servicios/perfil_maquinaria.php?id=${id}`);
    const data = await response.json();
    if (!data.ok) return;

    const machine = data.maquinaria;
    document.getElementById('machineDocumentsWorkspace').classList.remove('d-none');
    document.getElementById('machinePhoto').src = machine.foto_path ? `${BASE_URL}/${machine.foto_path}` : `${BASE_URL}/recursos/imagen_referencial.php`;
    document.getElementById('machineEquipo').textContent = machine.equipo || '';
    document.getElementById('machineEmpresa').textContent = machine.empresa || '';
    document.getElementById('machineSerie').textContent = machine.serie_placa || '';
    document.getElementById('machineAnio').textContent = machine.anio_equipo || '';
    loadMachineDocuments();
}

async function loadMachineDocuments() {
    if (!currentMachineId) return;
    const response = await fetch(`${BASE_URL}/servicios/listar_documentos_maquinaria.php?maquinaria_id=${currentMachineId}`);
    const data = await response.json();
    const tbody = document.querySelector('#machineDocumentsTable tbody');
    tbody.innerHTML = '';
    (data.rows || []).forEach((row) => {
        const hasPdf = !!row.archivo_path;
        const downloadName = escapeHtml(row.archivo_nombre_original || `${row.documento}.pdf`);
        const downloadButton = hasPdf
            ? `<a class="btn btn-sm btn-outline-success" href="${BASE_URL}/${row.archivo_path}" download="${downloadName}" title="Descargar documento"><i class="fa-solid fa-download"></i></a>`
            : '';
        tbody.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="text-center">
                    <input class="form-check-input machine-document-download-check" type="checkbox" value="${row.id}" ${hasPdf ? '' : 'disabled'} title="${hasPdf ? 'Seleccionar documento' : 'Sin PDF adjunto'}">
                </td>
                <td>${escapeHtml(row.documento)}</td>
                <td>${row.fecha_registro}</td>
                <td>${row.fecha_inicio}</td>
                <td>${row.fecha_fin}</td>
                <td><span class="badge ${row.status.class}">${row.status.label}</span></td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" type="button" onclick="openEditMachineDocument(${row.id})"><i class="fa-solid fa-pen"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="openViewMachineDocument(${row.id})"><i class="fa-solid fa-eye"></i></button>
                    <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteMachineDocument(${row.id})"><i class="fa-solid fa-trash"></i></button>
                    ${downloadButton}
                </td>
            </tr>
        `);
    });
}

function openAddMachineDocument() {
    if (!currentMachineId) {
        Swal.fire('Atenci\u00f3n', 'Seleccione una maquinaria.', 'warning');
        return;
    }
    machineReadOnlyMode = false;
    const form = document.getElementById('machineDocumentForm');
    form.reset();
    form.classList.remove('was-validated');
    setMachineDocumentReadonly(false);
    document.getElementById('machineDocumentModalTitle').textContent = 'Agregar documentos';
    document.getElementById('machineDocumentId').value = '';
    document.getElementById('machineDocumentMachineId').value = currentMachineId;
    const today = localDateValue();
    document.getElementById('machineRegistrationDate').value = today;
    document.getElementById('machineStartDate').value = '';
    $('#machineDocumentSelect').val(null).trigger('change');
    renderMachineCurrentPdf(null);
    machineDocumentModal.show();
}

async function openEditMachineDocument(id) {
    machineReadOnlyMode = false;
    await fillMachineDocumentModal(id);
    setMachineDocumentReadonly(false);
    document.getElementById('machineDocumentModalTitle').textContent = 'Editar documentos';
    machineDocumentModal.show();
}

async function openViewMachineDocument(id) {
    machineReadOnlyMode = true;
    await fillMachineDocumentModal(id);
    setMachineDocumentReadonly(true);
    document.getElementById('machineDocumentModalTitle').textContent = 'Visualizar documentos';
    machineDocumentModal.show();
}

async function fillMachineDocumentModal(id) {
    const response = await fetch(`${BASE_URL}/servicios/obtener_documento_maquinaria.php?id=${id}`);
    const data = await response.json();
    const row = data.row;
    document.getElementById('machineDocumentId').value = row.id;
    document.getElementById('machineDocumentMachineId').value = row.maquinaria_id;
    document.getElementById('machineRegistrationDate').value = row.fecha_registro;
    document.getElementById('machineStartDate').value = row.fecha_inicio;
    document.getElementById('machineEndDate').value = row.fecha_fin;
    document.getElementById('machineObservations').value = row.observaciones || '';
    const option = new Option(row.documento, row.documento_id, true, true);
    $('#machineDocumentSelect').append(option).trigger('change');
    renderMachineCurrentPdf(row);
}

function renderMachineCurrentPdf(row) {
    const box = document.getElementById('machineCurrentPdf');
    if (!box) return;
    if (!row || !row.archivo_path) {
        box.classList.add('d-none');
        box.innerHTML = '';
        return;
    }
    box.classList.remove('d-none');
    box.innerHTML = `
        <i class="fa-solid fa-file-pdf text-danger me-2"></i>
        <strong>${escapeHtml(row.archivo_nombre_original || 'archivo.pdf')}</strong>
        <div class="d-flex gap-2 mt-2">
            <a class="btn btn-sm btn-outline-primary" target="_blank" href="${BASE_URL}/${row.archivo_path}"><i class="fa-solid fa-up-right-from-square me-1"></i>Abrir</a>
            <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteMachineDocumentPdf(${row.id})"><i class="fa-solid fa-trash me-1"></i>Eliminar</button>
        </div>`;
}

function setMachineDocumentReadonly(state) {
    document.querySelectorAll('#machineDocumentForm input, #machineDocumentForm textarea, #machineDocumentForm select').forEach((el) => {
        if (el.name === 'csrf_token' || el.type === 'hidden') return;
        el.disabled = state;
    });
    document.querySelector('#machineDocumentForm button[type="submit"]')?.classList.toggle('d-none', state);
    document.getElementById('machinePdfInput')?.classList.toggle('d-none', state);
    document.getElementById('newMachineCatalogDocumentBtn')?.classList.toggle('d-none', state);
    document.getElementById('deleteMachineCatalogDocumentBtn')?.classList.toggle('d-none', state);
}

async function addMachineCatalogDocument() {
    const focusTrap = machineDocumentModal?._focustrap;
    focusTrap?.deactivate?.();

    let value = null;
    try {
        const result = await Swal.fire({
            title: 'Nuevo documento',
            input: 'text',
            inputPlaceholder: 'Nombre del documento',
            showCancelButton: true,
            confirmButtonText: 'Agregar',
            cancelButtonText: 'Cancelar',
            didOpen: () => Swal.getInput()?.focus()
        });
        value = result.value;
    } finally {
        setTimeout(() => focusTrap?.activate?.(), 0);
    }

    if (!value) return;

    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('nombre', value);

    const response = await fetch(`${BASE_URL}/servicios/guardar_catalogo_documento_maquinaria.php`, { method: 'POST', body: form });
    const data = await response.json();

    if (!data.ok) {
        Swal.fire('Atención', data.message || 'No se pudo agregar el documento.', 'warning');
        return;
    }

    const option = new Option(data.text, data.id, true, true);
    $('#machineDocumentSelect').append(option).trigger('change');
}

async function deleteMachineCatalogDocument() {
    const select = $('#machineDocumentSelect');
    const documentId = select.val();
    const documentText = select.find('option:selected').text().trim();

    if (!documentId) {
        Swal.fire('Atención', 'Seleccione un documento para eliminar.', 'warning');
        return;
    }

    const result = await Swal.fire({
        title: '¿Eliminar documento?',
        text: `Se quitará "${documentText}" del catálogo si no tiene registros asociados.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    });

    if (!result.isConfirmed) return;

    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('id', documentId);

    const response = await fetch(`${BASE_URL}/servicios/eliminar_catalogo_documento_maquinaria.php`, { method: 'POST', body: form });
    const data = await response.json();

    if (!data.ok) {
        Swal.fire('Atención', data.message || 'No se pudo eliminar el documento.', 'warning');
        return;
    }

    select.find(`option[value="${documentId}"]`).remove();
    select.val(null).trigger('change');
    Swal.fire('Eliminado', data.message || 'Documento eliminado.', 'success');
}
async function saveMachineDocument(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (machineReadOnlyMode || !form.checkValidity()) return;

    const submitButton = form.querySelector('button[type="submit"]');
    const progressBox = document.getElementById('machineUploadProgress');
    const progressBar = progressBox?.querySelector('.progress-bar');
    const progressLabel = progressBox?.querySelector('small');

    function renderProgress(percent) {
        if (!progressBox || !progressBar || !progressLabel) return;
        progressBox.classList.remove('d-none');
        progressBar.style.width = `${percent}%`;
        progressBar.setAttribute('aria-valuenow', String(percent));
        progressLabel.textContent = percent < 100 ? `Subiendo archivo: ${percent}%` : 'Procesando archivo...';
    }

    submitButton.disabled = true;
    renderProgress(0);

    try {
        const data = await postFormWithProgress(
            `${BASE_URL}/servicios/guardar_documento_maquinaria.php`,
            new FormData(form),
            renderProgress
        );

        if (!data.ok) {
            Swal.fire('Atenci\u00f3n', data.message || 'No se pudo guardar.', 'warning');
            return;
        }
        machineDocumentModal.hide();
        loadMachineDocuments();
    } catch (error) {
        Swal.fire('Atenci\u00f3n', error.message || 'No se pudo guardar.', 'warning');
    } finally {
        submitButton.disabled = false;
        progressBox?.classList.add('d-none');
        if (progressBar) progressBar.style.width = '0%';
        if (progressLabel) progressLabel.textContent = 'Subiendo archivo: 0%';
    }
}

async function deleteMachineDocument(id) {
    const ok = await confirmAction('\u00bfEliminar documento?');
    if (!ok) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('id', id);
    const response = await fetch(`${BASE_URL}/servicios/eliminar_documento_maquinaria.php`, { method: 'POST', body: form });
    const data = await response.json();
    if (data.ok) loadMachineDocuments();
}

async function deleteMachineDocumentPdf(id) {
    const ok = await confirmAction('\u00bfEliminar PDF?');
    if (!ok) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('id', id);
    const response = await fetch(`${BASE_URL}/servicios/eliminar_pdf_documento_maquinaria.php`, { method: 'POST', body: form });
    const data = await response.json();
    if (data.ok) {
        renderMachineCurrentPdf(null);
        loadMachineDocuments();
    }
}

async function downloadSelectedMachineDocumentsBundle() {
    if (!currentMachineId) {
        Swal.fire('Atención', 'Seleccione una maquinaria.', 'warning');
        return;
    }

    const selectedIds = Array.from(document.querySelectorAll('.machine-document-download-check:checked')).map((check) => check.value);
    if (!selectedIds.length) {
        Swal.fire('Atención', 'Seleccione al menos un documento para descargar.', 'warning');
        return;
    }

    await downloadMachineDocumentsZip(selectedIds);
}

async function downloadMachineDocumentsBundle() {
    if (!currentMachineId) {
        Swal.fire('Atención', 'Seleccione una maquinaria.', 'warning');
        return;
    }

    await downloadMachineDocumentsZip();
}

async function downloadMachineDocumentsZip(selectedIds = []) {
    const params = new URLSearchParams({ maquinaria_id: currentMachineId });

    if (selectedIds.length) {
        params.set('ids', selectedIds.join(','));
    }

    const response = await fetch(`${BASE_URL}/servicios/descargar_documentos_maquinaria.php?${params.toString()}`);

    if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'No se pudo generar la descarga.' }));
        Swal.fire('Atención', data.message || 'No se pudo generar la descarga.', 'warning');
        return;
    }

    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const fileName = match ? match[1] : 'documentos_maquinaria.zip';
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);

    if (selectedIds.length) {
        document.querySelectorAll('.machine-document-download-check:checked').forEach((check) => {
            check.checked = false;
        });
    }
}
function initDashboardEjecutivo() {
    const table = document.getElementById('dashboardPersonalTable');
    if (!table) return;

    const filters = {
        company: document.getElementById('dashboardEmpresaFilter'),
        name: document.getElementById('dashboardNombreFilter'),
        position: document.getElementById('dashboardPuestoFilter'),
        requirement: document.getElementById('dashboardRequisitoFilter'),
        state: document.getElementById('dashboardEstadoFilter'),
    };
    const rows = Array.from(table.querySelectorAll('tbody tr'));

    const applyFilters = () => {
        const company = normalizarTexto(filters.company?.value || '');
        const name = normalizarTexto(filters.name?.value || '');
        const position = normalizarTexto(filters.position?.value || '');
        const requirement = normalizarTexto(filters.requirement?.value || '');
        const state = filters.state?.value || '';

        rows.forEach((row) => {
            const visible = (!company || normalizarTexto(row.dataset.company).includes(company))
                && (!name || normalizarTexto(row.dataset.name).includes(name))
                && (!position || normalizarTexto(row.dataset.position).includes(position))
                && (!requirement || normalizarTexto(row.dataset.requirement).includes(requirement))
                && (!state || row.dataset.state === state);
            row.classList.toggle('d-none', !visible);
        });
    };

    Object.values(filters).forEach((field) => {
        field?.addEventListener('input', applyFilters);
        field?.addEventListener('change', applyFilters);
    });

    if (!window.Chart || !window.dashboardEjecutivoData) return;

    // Configuración global de fuentes y colores de Chart.js para look serio corporativo
    Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
    Chart.defaults.color = '#334155';
    Chart.defaults.font.weight = '500';

    const data = window.dashboardEjecutivoData;
    const colors = {
        green: '#198754',      // Verde original
        yellow: '#ffc107',     // Amarillo original
        red: '#dc3545',        // Rojo original
        blue: '#2563eb',       // Azul corporativo principal
        blueHover: '#1d4ed8',  // Hover de azul
        blueLight: '#3b82f6',  // Azul claro corporativo para puestos principales
        gridLine: 'rgba(148, 163, 184, 0.12)' // Rejilla muy sutil
    };

    // Colores corporativos únicos por cada empresa (serio y premium)
    const companyColors = [
        '#2563eb', // Azul
        '#7c3aed', // Púrpura
        '#0ea5e9', // Celeste
        '#10b981', // Verde esmeralda
        '#f59e0b', // Ámbar/Naranja
        '#6366f1'  // Índigo
    ];

    // Colores corporativos únicos por cada puesto principal (serio y premium)
    const positionColors = [
        '#3b82f6', // Celeste
        '#6366f1', // Índigo
        '#0d9488', // Verde azulado / Teal
        '#7c3aed', // Violeta
        '#0ea5e9', // Azul brillante
        '#10b981', // Verde
        '#f59e0b', // Naranja/Ámbar
        '#ef4444'  // Rojo
    ];

    const statusCanvas = document.getElementById('statusChart');
    if (statusCanvas) {
        new Chart(statusCanvas, {
            type: 'doughnut',
            data: {
                labels: data.status.labels,
                datasets: [{
                    data: data.status.values,
                    backgroundColor: [colors.green, colors.yellow, colors.red],
                    borderColor: '#ffffff',
                    borderWidth: 2,
                }]
            },
            plugins: [{
                id: 'centerText',
                beforeDraw(chart) {
                    const { ctx } = chart;
                    ctx.save();
                    
                    const dataset = chart.data.datasets[0];
                    const values = dataset.data;
                    const total = values.reduce((sum, val) => sum + Number(val || 0), 0);
                    const greenValue = Number(values[0] || 0);
                    const greenPercent = total ? Math.round((greenValue / total) * 100) : 0;
                    
                    const meta = chart.getDatasetMeta(0);
                    const chartArea = chart.chartArea;
                    const centerX = meta.data[0] ? meta.data[0].x : (chartArea ? (chartArea.left + chartArea.right) / 2 : chart.width / 2);
                    const centerY = meta.data[0] ? meta.data[0].y : (chartArea ? (chartArea.top + chartArea.bottom) / 2 : chart.height / 2);
                    
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    
                    // Draw "%" (bold, 34px, Inter)
                    ctx.font = 'bold 34px Inter, sans-serif';
                    ctx.fillStyle = '#0f172a';
                    ctx.fillText(`${greenPercent}%`, centerX, centerY - 10);
                    
                    // Draw "Documentos" (bold, 11px, Inter)
                    ctx.font = 'bold 11px Inter, sans-serif';
                    ctx.fillStyle = '#475569';
                    ctx.fillText('Documentos', centerX, centerY + 16);
                    
                    // Draw "aptos"
                    ctx.fillText('aptos', centerX, centerY + 28);
                    
                    ctx.restore();
                }
            }],
            options: {
                cutout: '72%',
                rotation: 0,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 10,
                            boxHeight: 10,
                            usePointStyle: true,
                            pointStyle: 'rect',
                            padding: 16,
                            color: '#1e293b',
                            font: { size: 11, weight: '700' },
                            generateLabels(chart) {
                                const values = chart.data.datasets[0].data;
                                const total = values.reduce((sum, value) => sum + Number(value || 0), 0);
                                return chart.data.labels.map((label, index) => {
                                    const value = Number(values[index] || 0);
                                    const percent = total ? Math.round((value / total) * 100) : 0;
                                    const meta = chart.getDatasetMeta(0);
                                    const style = meta.controller.getStyle(index);
                                    return {
                                        text: `${label}: ${percent}%`,
                                        fillStyle: style.backgroundColor,
                                        strokeStyle: style.backgroundColor,
                                        lineWidth: 0,
                                        hidden: !chart.getDataVisibility(index),
                                        index,
                                        fontColor: '#1e293b',
                                        pointStyle: 'rect'
                                    };
                                });
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        bodyFont: { weight: '600' },
                        padding: 10,
                        cornerRadius: 6,
                        callbacks: {
                            label(context) {
                                const values = context.dataset.data;
                                const total = values.reduce((sum, value) => sum + Number(value || 0), 0);
                                const value = Number(context.raw || 0);
                                const percent = total ? Math.round((value / total) * 100) : 0;
                                return ` ${context.label}: ${percent}% (${value})`;
                            }
                        }
                    }
                },
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }

    const companyCanvas = document.getElementById('companyChart');
    if (companyCanvas) {
        new Chart(companyCanvas, {
            type: 'bar',
            data: {
                labels: data.companies.labels,
                datasets: [{
                    label: 'Personal',
                    data: data.companies.values,
                    backgroundColor: companyColors,
                    hoverBackgroundColor: companyColors,
                    borderRadius: 6,
                    barPercentage: 0.55,
                    maxBarThickness: 50
                }]
            },
            plugins: [{
                id: 'companyBarLabels',
                afterDatasetsDraw(chart) {
                    const { ctx, data } = chart;
                    ctx.save();
                    ctx.font = 'bold 12px Inter, sans-serif';
                    ctx.fillStyle = '#0f172a';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    
                    chart.getDatasetMeta(0).data.forEach((bar, index) => {
                        const value = data.datasets[0].data[index];
                        if (value > 0) {
                            ctx.fillText(value, bar.x, bar.y - 6);
                        }
                    });
                    ctx.restore();
                }
            }],
            options: {
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        padding: 10,
                        cornerRadius: 6
                    }
                },
                scales: { 
                    y: { 
                        beginAtZero: true,
                        suggestedMax: Math.max(...data.companies.values) + 2,
                        ticks: { 
                            precision: 0,
                            stepSize: 2,
                            color: '#000000', 
                            font: { size: 11, weight: '600' } 
                        },
                        grid: { color: colors.gridLine, drawBorder: false }
                    },
                    x: {
                        ticks: { color: '#000000', font: { size: 10, weight: '600' } },
                        grid: { display: false }
                    }
                },
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }

    const positionCanvas = document.getElementById('positionChart');
    if (positionCanvas) {
        const positionWrapper = positionCanvas.closest('.dashboard-chart-wrapper');
        const positionPanel = positionCanvas.closest('.dashboard-chart-panel');
        const dynamicHeight = Math.max(220, (data.positions.labels.length || 1) * 34);
        if (positionWrapper) positionWrapper.style.setProperty('height', `${dynamicHeight}px`, 'important');
        if (positionPanel) positionPanel.style.setProperty('height', 'auto', 'important');

        const mappedPositionColors = data.positions.labels.map((_, i) => {
            const colorsList = [
                '#2563eb', // Azul
                '#7c3aed', // Púrpura
                '#10b981', // Verde
                '#3b82f6', // Índigo/Azul claro
                '#0ea5e9', // Celeste
                '#f59e0b', // Ámbar
                '#ef4444'  // Rojo
            ];
            return colorsList[i % colorsList.length];
        });

        new Chart(positionCanvas, {
            type: 'bar',
            data: {
                labels: data.positions.labels.map(label => {
                    if (label.includes(' de ')) {
                        const parts = label.split(' de ');
                        return [parts[0] + ' de', parts.slice(1).join(' de ')];
                    }
                    return label;
                }),
                datasets: [{
                    label: 'Personal',
                    data: data.positions.values,
                    backgroundColor: mappedPositionColors,
                    hoverBackgroundColor: mappedPositionColors,
                    borderRadius: 6,
                    barPercentage: 0.55,
                    maxBarThickness: 24
                }]
            },
            plugins: [{
                id: 'positionBarLabels',
                afterDatasetsDraw(chart) {
                    const { ctx, data } = chart;
                    ctx.save();
                    ctx.font = 'bold 12px Inter, sans-serif';
                    ctx.fillStyle = '#0f172a';
                    ctx.textAlign = 'left';
                    ctx.textBaseline = 'middle';
                    
                    chart.getDatasetMeta(0).data.forEach((bar, index) => {
                        const value = data.datasets[0].data[index];
                        if (value > 0) {
                            ctx.fillText(value, bar.x + 8, bar.y);
                        }
                    });
                    ctx.restore();
                }
            }],
            options: {
                indexAxis: 'y',
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        padding: 10,
                        cornerRadius: 6,
                        callbacks: {
                            title(items) {
                                const index = items?.[0]?.dataIndex ?? 0;
                                return data.positions.labels[index] || '';
                            },
                            label(item) {
                                return `Personal: ${item.parsed.x}`;
                            }
                        }
                    }
                },
                scales: { 
                    x: { 
                        beginAtZero: true, 
                        suggestedMax: Math.max(...data.positions.values) + 1,
                        ticks: { 
                            precision: 0,
                            stepSize: 2,
                            color: '#000000', 
                            font: { size: 11, weight: '600' } 
                        },
                        grid: { color: colors.gridLine, drawBorder: false }
                    },
                    y: {
                        ticks: { color: '#000000', font: { size: 10, weight: '600' } },
                        grid: { display: false }
                    }
                },
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }
}
function initUsuariosModule() {
    const table = document.getElementById('usuariosTable');
    if (!table) return;

    const form = document.getElementById('usuarioForm');
    const modal = new bootstrap.Modal(document.getElementById('usuarioModal'));
    const password = document.getElementById('usuarioPassword');
    const passwordHelp = document.getElementById('usuarioPasswordHelp');

    document.getElementById('nuevoUsuarioBtn')?.addEventListener('click', () => {
        form.reset();
        form.classList.remove('was-validated');
        document.getElementById('usuarioId').value = '';
        document.getElementById('usuarioModalTitle').textContent = 'Nuevo usuario';
        password.required = true;
        passwordHelp.textContent = 'Mínimo 8 caracteres.';
        modal.show();
    });

    document.querySelectorAll('.js-editar-usuario').forEach((button) => {
        button.addEventListener('click', () => {
            form.reset();
            form.classList.remove('was-validated');
            document.getElementById('usuarioId').value = button.dataset.id || '';
            document.getElementById('usuarioName').value = button.dataset.name || '';
            document.getElementById('usuarioEmail').value = button.dataset.email || '';
            document.getElementById('usuarioRole').value = button.dataset.role || 'Usuario';
            document.getElementById('usuarioModalTitle').textContent = 'Editar usuario';
            password.required = false;
            passwordHelp.textContent = 'Dejar vacío para mantener la contraseña actual.';
            modal.show();
        });
    });

    document.querySelectorAll('.js-eliminar-usuario').forEach((button) => {
        button.addEventListener('click', async () => {
            const ok = await confirmAction('¿Eliminar usuario?');
            if (!ok) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('id', button.dataset.id);
            const response = await fetch(`${BASE_URL}/servicios/eliminar_usuario.php`, { method: 'POST', body });
            const data = await response.json();
            if (data.ok) {
                window.location.reload();
                return;
            }
            Swal.fire('Atención', data.message || 'No se pudo eliminar el usuario.', 'warning');
        });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            return;
        }
        const response = await fetch(`${BASE_URL}/servicios/guardar_usuario.php`, { method: 'POST', body: new FormData(form) });
        const data = await response.json();
        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo guardar el usuario.', 'warning');
            return;
        }
        modal.hide();
        window.location.reload();
    });
}

function initAttendanceControl() {
    const table = document.getElementById('attendanceTable');
    if (!table) return;

    const attendanceModal = new bootstrap.Modal(document.getElementById('attendanceModal'));
    const importModal = new bootstrap.Modal(document.getElementById('attendanceImportModal'));
    const form = document.getElementById('attendanceForm');
    const importForm = document.getElementById('attendanceImportForm');
    const rows = Array.from(table.querySelectorAll('tbody tr'));

    const applyFilters = () => {
        const date = document.getElementById('attendanceFilterDate')?.value || '';
        const month = document.getElementById('attendanceFilterMonth')?.value || '';
        const name = normalizarTexto(document.getElementById('attendanceFilterName')?.value || '');
        const activity = normalizarTexto(document.getElementById('attendanceFilterActivity')?.value || '');
        const company = normalizarTexto(document.getElementById('attendanceFilterCompany')?.value || '');
        const position = normalizarTexto(document.getElementById('attendanceFilterPosition')?.value || '');
        const rating = document.getElementById('attendanceFilterRating')?.value || '';

        rows.forEach((row) => {
            const visible = (!date || row.dataset.date === date)
                && (!month || row.dataset.month === month)
                && (!name || normalizarTexto(row.dataset.name).includes(name))
                && (!activity || normalizarTexto(row.dataset.activity).includes(activity))
                && (!company || normalizarTexto(row.dataset.company).includes(company))
                && (!position || normalizarTexto(row.dataset.position).includes(position))
                && (!rating || row.dataset.rating === rating);
            row.classList.toggle('d-none', !visible);
        });
    };

    document.querySelectorAll('.attendance-filter').forEach((field) => {
        field.addEventListener('input', applyFilters);
        field.addEventListener('change', applyFilters);
    });

    document.getElementById('newAttendanceBtn')?.addEventListener('click', () => {
        form.reset();
        form.classList.remove('was-validated');
        document.getElementById('attendanceId').value = '';
        document.getElementById('attendanceModalTitle').textContent = 'Nuevo registro';
        document.getElementById('attendanceDate').value = localDateValue();
        attendanceModal.show();
    });

    document.getElementById('importAttendanceBtn')?.addEventListener('click', () => {
        importForm.reset();
        importForm.classList.remove('was-validated');
        importModal.show();
    });

    document.querySelectorAll('.js-edit-attendance').forEach((button) => {
        button.addEventListener('click', () => {
            form.reset();
            form.classList.remove('was-validated');
            document.getElementById('attendanceId').value = button.dataset.id || '';
            document.getElementById('attendanceDate').value = button.dataset.fecha || '';
            document.getElementById('attendanceName').value = button.dataset.nombre || '';
            document.getElementById('attendanceActivity').value = button.dataset.actividad || '';
            document.getElementById('attendanceCompany').value = button.dataset.empresa || '';
            document.getElementById('attendancePosition').value = button.dataset.puesto || '';
            document.getElementById('attendanceModalTitle').textContent = 'Editar registro';
            attendanceModal.show();
        });
    });

    document.querySelectorAll('.js-delete-attendance').forEach((button) => {
        button.addEventListener('click', async () => {
            const ok = await confirmAction('¿Eliminar registro de asistencia?');
            if (!ok) return;

            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('id', button.dataset.id || '');

            const response = await fetch(`${BASE_URL}/servicios/eliminar_asistencia.php`, { method: 'POST', body });
            const data = await response.json();
            if (data.ok) {
                window.location.reload();
                return;
            }
            Swal.fire('Atención', data.message || 'No se pudo eliminar el registro.', 'warning');
        });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            return;
        }

        const response = await fetch(`${BASE_URL}/servicios/guardar_asistencia.php`, { method: 'POST', body: new FormData(form) });
        const data = await response.json();
        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo guardar el registro.', 'warning');
            return;
        }

        attendanceModal.hide();
        window.location.reload();
    });

    importForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!importForm.checkValidity()) {
            importForm.classList.add('was-validated');
            return;
        }

        const response = await fetch(`${BASE_URL}/servicios/importar_asistencia.php`, { method: 'POST', body: new FormData(importForm) });
        const data = await response.json();
        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo importar el archivo.', 'warning');
            return;
        }

        const errors = data.errors?.length ? `<br><small>${data.errors.slice(0, 8).join('<br>')}</small>` : '';
        await Swal.fire('Importación finalizada', `Importados: ${data.inserted || 0}<br>Omitidos: ${data.skipped || 0}${errors}`, 'success');
        importModal.hide();
        window.location.reload();
    });
}
function initNotifications() {
    const container = document.getElementById('notifContainer');
    const bellBtn = document.getElementById('notifBellBtn');
    const badge = document.getElementById('notifBadge');
    const list = document.getElementById('notifList');
    const searchInput = document.getElementById('notifSearchInput');

    if (!container || !bellBtn || !badge || !list) return;

    let notificationsData = [];
    let currentSearchQuery = '';

    // Helper to format date: DD/MM/YYYY
    function formatNotifDate(dateStr) {
        if (!dateStr) return '';
        const t = dateStr.split(/[- :]/);
        if (t.length < 3) return dateStr;
        return `${t[2]}/${t[1]}/${t[0]}`;
    }

    // Load notifications from server
    async function loadNotifications() {
        try {
            // Append timestamp parameter to force cache busting
            const response = await fetch(`${BASE_URL}/servicios/get_notifications.php?t=${Date.now()}`);
            const data = await response.json();
            if (data.ok) {
                notificationsData = data.notifications || [];
                updateBadge(data.unread_count);
                renderNotifications(currentSearchQuery);
            }
        } catch (e) {
            console.error('Error fetching notifications:', e);
        }
    }

    // Update the unread badge (always persistent)
    function updateBadge(count) {
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }

    // Render notifications in dropdown list
    function renderNotifications(query = '') {
        const queryLower = query.toLowerCase().trim();
        const filtered = queryLower
            ? notificationsData.filter(notif => {
                const name = (notif.full_name || '').toLowerCase();
                const missing = (notif.missing_fields || '').toLowerCase();
                return name.includes(queryLower) || missing.includes(queryLower);
              })
            : notificationsData;

        if (filtered.length === 0) {
            list.innerHTML = `
                <div class="notif-empty">
                    <i class="fa-regular fa-bell-slash"></i>
                    <span>${queryLower ? 'No se encontraron resultados' : 'No hay notificaciones recientes'}</span>
                </div>
            `;
            return;
        }

        list.innerHTML = filtered.map(notif => {
            // Unified smaller bell icon
            const iconHTML = '<i class="fa-solid fa-bell"></i>';

            // Clean, structured styling
            const nameHTML = escapeHTML(notif.full_name || '');
            const missingHTML = escapeHTML(notif.missing_fields || '');
            
            const bodyHTML = `<strong>Nombre del personal:</strong> <span class="notif-worker-name">${nameHTML}</span> <span class="notif-missing-fields">(Falta: ${missingHTML})</span>`;

            return `
                <div class="notif-item unread" data-id="${notif.id}">
                    <div class="notif-icon-container">
                        ${iconHTML}
                    </div>
                    <div class="notif-content">
                        <div class="notif-body">${bodyHTML}</div>
                        <div class="notif-time">
                            <span>${formatNotifDate(notif.created_at)}</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    // Helper to escape HTML to prevent XSS
    function escapeHTML(str) {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Bind search filter event
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            currentSearchQuery = e.target.value;
            renderNotifications(currentSearchQuery);
        });

        // Prevent clicking inside the search box from toggling the dropdown
        searchInput.addEventListener('click', (e) => {
            e.stopPropagation();
        });
    }

    // Toggle dropdown visibility (does not mark read, preserves count)
    bellBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        container.classList.toggle('active');
        if (container.classList.contains('active') && searchInput) {
            // Auto focus search input when opened
            setTimeout(() => searchInput.focus(), 50);
        }
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        if (!container.contains(e.target)) {
            container.classList.remove('active');
        }
    });

    // Load initially and start polling
    loadNotifications();
    setInterval(loadNotifications, 20000); // Poll every 20 seconds
}























