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
    initEmpresaModuloDatos();
    initEmpresaModuloDocumentos();
    initEmpresaSeguridadDocumentos();
    initEmpresaGenericModules();
    initDashboardEjecutivo();
    initUsuariosModule();
    initAttendanceControl();
    initControlPersonalSchedules();
    initControlPersonalLocations();
    initControlPersonalAssignments();
    initControlPersonalMarking();
    initNotifications();
    initObservationNotifications();
    initDevelopmentPhaseLinks();

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

function initDevelopmentPhaseLinks() {
    const adminControlMenu = document.querySelector('#controlPersonalMenu a[href*="/modulos/control_personal/dashboard_asistencia.php"]');
    if (!adminControlMenu) return;

    document.querySelectorAll('#controlPersonalMenu a[href*="/modulos/control_personal/"]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            Swal.fire({
                icon: 'info',
                title: 'Módulo en desarrollo',
                text: 'Esta opción estará disponible en la siguiente fase.',
                confirmButtonText: 'Entendido'
            });
        });
    });
}

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
    document.getElementById('newCatalogRequirementBtn')?.addEventListener('click', addCatalogRequirement);
    document.getElementById('deleteCatalogRequirementBtn')?.addEventListener('click', deleteCatalogRequirement);
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
                <td>${escapeHtml(row.registered_by || '')}</td>
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
    setRequirementObservationVisibility(false);
    document.getElementById('requirementModalTitle').textContent = 'Agregar Requisito';
    document.getElementById('requirementId').value = '';
    document.getElementById('requirementWorkerId').value = currentWorkerId;
    document.getElementById('requirementPositionId').value = currentPositionId;
    document.getElementById('registrationDate').value = localDateValue();
    $('#requirementSelect').val(null).trigger('change');
    renderCurrentPdf(null);
    renderRequirementAudit(null, []);
    requirementModal.show();
}

async function openEditRequirement(id) {
    readOnlyMode = false;
    await fillRequirementModal(id);
    setRequirementReadonly(false);
    setRequirementObservationVisibility(false);
    document.getElementById('requirementModalTitle').textContent = 'Editar Requisito';
    requirementModal.show();
}

async function openViewRequirement(id) {
    readOnlyMode = true;
    await fillRequirementModal(id);
    setRequirementReadonly(true);
    setRequirementObservationVisibility(true);
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
    renderRequirementAudit(row, data.activity || []);
}

function renderRequirementAudit(row, activity) {
    const box = document.getElementById('requirementAuditBox');
    const list = document.getElementById('requirementAuditList');
    if (!box || !list) return;

    const hasObservationContext = row?.observation_status && row.observation_status !== 'none' && row?.observation_at;
    if (!hasObservationContext) {
        box.classList.add('d-none');
        list.innerHTML = '';
        return;
    }

    const items = [];
    items.push({
        title: row.observation_status === 'corrected' ? 'Corregido por revisar' : (row.observation_status === 'approved' ? 'Conforme' : 'Observado'),
        body: `${row.observation_by || 'Administrador'} - ${formatAuditDate(row.observation_at)}`
    });

    if (row?.observation_resolved_at) {
        items.push({
            title: 'Conformidad registrada',
            body: `${row.observation_resolved_by || 'Administrador'} - ${formatAuditDate(row.observation_resolved_at)}`
        });
    }
    const observationTime = parseAuditDate(row.observation_at);
    (activity || []).filter((entry) => {
        if (['observacion', 'observacion_retirada', 'conformidad'].includes(entry.action_type || '')) {
            return true;
        }
        const entryTime = parseAuditDate(entry.created_at);
        return observationTime && entryTime && entryTime >= observationTime;
    }).forEach((entry) => {
        const userName = entry.user_name || 'Sistema';
        items.push({
            title: `${userName} hizo modificaciones: ${normalizeAuditActivityText(entry.description || 'actividad registrada')}`,
            body: formatAuditDate(entry.created_at)
        });
    });

    if (!items.length) {
        box.classList.add('d-none');
        list.innerHTML = '';
        return;
    }

    box.classList.remove('d-none');
    list.innerHTML = items.map((item) => `
        <div class="requirement-audit-item">
            <strong>${escapeHtml(item.title)}</strong>
            <span>${escapeHtml(item.body)}</span>
        </div>
    `).join('');
}

function formatAuditDate(value) {
    if (!value) return '';
    const date = parseAuditDate(value);
    if (!date) return value;
    return date.toLocaleString('es-PE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function parseAuditDate(value) {
    if (!value) return null;
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? null : date;
}

function normalizeAuditActivityText(value) {
    let text = String(value || '').trim();
    if (!text) return 'actividad registrada.';
    text = text
        .replace(/^modificó observaciones;\s*/i, '')
        .replace(/;\s*modificó observaciones\.?$/i, '.')
        .replace(/;\s*modificó observaciones;\s*/i, '; ');
    return text.charAt(0).toLowerCase() + text.slice(1);
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
    document.getElementById('newCatalogRequirementBtn')?.classList.toggle('d-none', state);
    document.getElementById('deleteCatalogRequirementBtn')?.classList.toggle('d-none', state);
}

function setRequirementObservationVisibility(viewMode) {
    const block = document.getElementById('requirementObservationBlock');
    const observations = document.getElementById('observations');
    const label = document.getElementById('requirementObservationLabel');
    if (!block || !observations) return;

    const canManage = window.canManageRequirementObservations === true;
    const hasContent = String(observations.value || '').trim() !== '';
    const visible = canManage || viewMode || hasContent;
    block.classList.toggle('d-none', !visible);
    observations.disabled = readOnlyMode || !canManage || !visible;
    if (label) {
        label.textContent = canManage ? 'Observaciones' : 'Observación del administrador';
    }
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
                <td>${escapeHtml(row.registered_by || '')}</td>
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

let currentCompanyModuleId = null;
let companyDocumentModal = null;

function initEmpresaModuloDatos() {
    const table = document.getElementById('empresaModuloTable');
    if (!table) return;

    const form = document.getElementById('empresaModuloForm');
    const modal = new bootstrap.Modal(document.getElementById('empresaModuloModal'));
    const photoModal = new bootstrap.Modal(document.getElementById('empresaModuloFotoModal'));

    document.getElementById('nuevaEmpresaModuloBtn')?.addEventListener('click', () => {
        form.reset();
        form.classList.remove('was-validated');
        document.getElementById('empresaModuloId').value = '';
        document.getElementById('empresaModuloRazonSocial').value = 'Life Maquinarias';
        document.getElementById('empresaModuloModalTitle').textContent = 'Nueva empresa';
        renderEmpresaModuloFotoActual(null);
        modal.show();
    });

    document.querySelectorAll('.js-editar-empresa-modulo').forEach((button) => {
        button.addEventListener('click', () => {
            form.reset();
            form.classList.remove('was-validated');
            document.getElementById('empresaModuloId').value = button.dataset.id || '';
            document.getElementById('empresaModuloRazonSocial').value = button.dataset.razonSocial || '';
            document.getElementById('empresaModuloRuc').value = button.dataset.ruc || '';
            document.getElementById('empresaModuloDireccion').value = button.dataset.direccion || '';
            document.getElementById('empresaModuloModalTitle').textContent = 'Editar empresa';
            renderEmpresaModuloFotoActual(button.dataset.foto || null);
            modal.show();
        });
    });

    document.querySelectorAll('.js-ver-foto-empresa-modulo').forEach((button) => {
        button.addEventListener('click', () => {
            if (!button.dataset.foto) return;
            document.getElementById('empresaModuloFotoModalImg').src = `${BASE_URL}/${button.dataset.foto}`;
            photoModal.show();
        });
    });

    document.querySelectorAll('.js-eliminar-empresa-modulo').forEach((button) => {
        button.addEventListener('click', async () => {
            const ok = await confirmAction('¿Eliminar empresa?');
            if (!ok) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('id', button.dataset.id || '');
            const response = await fetch(`${BASE_URL}/servicios/empresa/eliminar_empresa_modulo.php`, { method: 'POST', body });
            const data = await response.json();
            if (data.ok) window.location.reload();
            else Swal.fire('Atención', data.message || 'No se pudo eliminar la empresa.', 'warning');
        });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            return;
        }
        const response = await fetch(`${BASE_URL}/servicios/empresa/guardar_empresa_modulo.php`, { method: 'POST', body: new FormData(form) });
        const data = await response.json();
        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo guardar la empresa.', 'warning');
            return;
        }
        modal.hide();
        window.location.reload();
    });
}

function renderEmpresaModuloFotoActual(path) {
    const box = document.getElementById('empresaModuloFotoActual');
    if (!box) return;
    if (!path) {
        box.classList.add('d-none');
        box.innerHTML = '';
        return;
    }
    box.classList.remove('d-none');
    box.innerHTML = `<i class="fa-solid fa-image text-primary me-2"></i><a target="_blank" href="${BASE_URL}/${path}">Ver foto actual</a>`;
}

function initEmpresaModuloDocumentos() {
    const companySearchElement = document.getElementById('companyModuleSearch');
    if (!companySearchElement) return;

    companyDocumentModal = new bootstrap.Modal(document.getElementById('companyDocumentModal'));

    if (window.jQuery && $.fn.select2) {
        $('#companyModuleSearch').select2({
            theme: 'bootstrap4',
            width: '100%',
            placeholder: 'Escriba razon social o RUC'
        });
        $('#companyModuleSearch').on('select2:select', (event) => loadCompanyModule(event.params.data.id));
        $('#companyDocumentSelect').select2({
            theme: 'bootstrap4',
            dropdownParent: $('#companyDocumentModal'),
            width: '100%',
            placeholder: 'Buscar documento'
        });
    }

    companySearchElement.addEventListener('change', (event) => {
        if (event.target.value) loadCompanyModule(event.target.value);
    });

    document.getElementById('downloadCompanyDocumentsBtn')?.addEventListener('click', downloadCompanyDocumentsBundle);
    document.getElementById('downloadSelectedCompanyDocumentsBtn')?.addEventListener('click', downloadSelectedCompanyDocumentsBundle);
    document.getElementById('addCompanyDocumentBtn')?.addEventListener('click', openAddCompanyDocument);
    document.getElementById('companyDocumentForm')?.addEventListener('submit', saveCompanyDocument);
    document.getElementById('newCompanyCatalogDocumentBtn')?.addEventListener('click', addCompanyCatalogDocument);
    document.getElementById('deleteCompanyCatalogDocumentBtn')?.addEventListener('click', deleteCompanyCatalogDocument);
    document.getElementById('changeCompanyModulePhotoBtn')?.addEventListener('click', () => {
        if (!currentCompanyModuleId) {
            Swal.fire('Atención', 'Seleccione una empresa.', 'warning');
            return;
        }
        document.getElementById('companyModulePhotoInput')?.click();
    });
    document.getElementById('companyModulePhotoInput')?.addEventListener('change', uploadCompanyModulePhoto);
}

async function uploadCompanyModulePhoto(event) {
    const input = event.currentTarget;
    const file = input.files?.[0];
    if (!file || !currentCompanyModuleId) return;

    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('empresa_id', currentCompanyModuleId);
    form.append('foto', file);

    const response = await fetch(`${BASE_URL}/servicios/empresa/subir_foto_empresa.php`, { method: 'POST', body: form });
    const data = await response.json();
    input.value = '';

    if (!data.ok) {
        Swal.fire('Atención', data.message || 'No se pudo cambiar la foto.', 'warning');
        return;
    }

    document.getElementById('companyModulePhoto').src = `${data.path}?v=${Date.now()}`;
    Swal.fire('Actualizado', 'Foto de empresa actualizada.', 'success');
}

async function loadCompanyModule(id) {
    currentCompanyModuleId = id;
    const response = await fetch(`${BASE_URL}/servicios/empresa/perfil_empresa.php?id=${id}`);
    const data = await response.json();
    if (!data.ok) return;

    const company = data.empresa;
    document.getElementById('companyDocumentsWorkspace').classList.remove('d-none');
    document.getElementById('companyModulePhoto').src = company.foto_path ? `${BASE_URL}/${company.foto_path}` : `${BASE_URL}/recursos/imagen_referencial.php`;
    document.getElementById('companyModuleName').textContent = company.razon_social || '';
    document.getElementById('companyModuleRuc').textContent = company.ruc || '';
    document.getElementById('companyModuleAddress').textContent = company.direccion || '';
    loadCompanyDocuments();
}

async function loadCompanyDocuments() {
    if (!currentCompanyModuleId) return;
    const response = await fetch(`${BASE_URL}/servicios/empresa/listar_documentos_empresa.php?empresa_id=${currentCompanyModuleId}`);
    const data = await response.json();
    const tbody = document.querySelector('#companyDocumentsTable tbody');
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
                    <input class="form-check-input company-document-download-check" type="checkbox" value="${row.id}" ${hasPdf ? '' : 'disabled'} title="${hasPdf ? 'Seleccionar documento' : 'Sin PDF adjunto'}">
                </td>
                <td>${escapeHtml(row.documento)}</td>
                <td>${row.fecha_registro}</td>
                <td>${row.fecha_inicio}</td>
                <td>${row.fecha_fin}</td>
                <td><span class="badge ${row.status.class}">${row.status.label}</span></td>
                <td>${escapeHtml(row.registered_by || '')}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" type="button" onclick="openEditCompanyDocument(${row.id})"><i class="fa-solid fa-pen"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="openViewCompanyDocument(${row.id})"><i class="fa-solid fa-eye"></i></button>
                    <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteCompanyDocument(${row.id})"><i class="fa-solid fa-trash"></i></button>
                    ${downloadButton}
                </td>
            </tr>
        `);
    });
}

function openAddCompanyDocument() {
    if (!currentCompanyModuleId) {
        Swal.fire('Atención', 'Seleccione una empresa.', 'warning');
        return;
    }
    const form = document.getElementById('companyDocumentForm');
    form.reset();
    form.classList.remove('was-validated');
    setCompanyDocumentReadonly(false);
    document.getElementById('companyDocumentModalTitle').textContent = 'Agregar documentos';
    document.getElementById('companyDocumentId').value = '';
    document.getElementById('companyDocumentCompanyId').value = currentCompanyModuleId;
    document.getElementById('companyRegistrationDate').value = localDateValue();
    if (window.jQuery && $.fn.select2) {
        $('#companyDocumentSelect').val('').trigger('change');
    }
    renderCompanyCurrentPdf(null);
    companyDocumentModal.show();
}

async function openEditCompanyDocument(id) {
    await fillCompanyDocumentModal(id);
    setCompanyDocumentReadonly(false);
    document.getElementById('companyDocumentModalTitle').textContent = 'Editar documentos';
    companyDocumentModal.show();
}

async function openViewCompanyDocument(id) {
    await fillCompanyDocumentModal(id);
    setCompanyDocumentReadonly(true);
    document.getElementById('companyDocumentModalTitle').textContent = 'Visualizar documentos';
    companyDocumentModal.show();
}

async function fillCompanyDocumentModal(id) {
    const response = await fetch(`${BASE_URL}/servicios/empresa/obtener_documento_empresa.php?id=${id}`);
    const data = await response.json();
    const row = data.row;
    document.getElementById('companyDocumentId').value = row.id;
    document.getElementById('companyDocumentCompanyId').value = row.empresa_id;
    document.getElementById('companyDocumentSelect').value = row.documento_id;
    if (window.jQuery && $.fn.select2) {
        $('#companyDocumentSelect').trigger('change');
    }
    document.getElementById('companyRegistrationDate').value = row.fecha_registro;
    document.getElementById('companyStartDate').value = row.fecha_inicio;
    document.getElementById('companyEndDate').value = row.fecha_fin;
    document.getElementById('companyObservations').value = row.observaciones || '';
    renderCompanyCurrentPdf(row);
}

function renderCompanyCurrentPdf(row) {
    const box = document.getElementById('companyCurrentPdf');
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
            <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteCompanyDocumentPdf(${row.id})"><i class="fa-solid fa-trash me-1"></i>Eliminar</button>
        </div>`;
}

function setCompanyDocumentReadonly(state) {
    document.querySelectorAll('#companyDocumentForm input, #companyDocumentForm textarea, #companyDocumentForm select').forEach((el) => {
        if (el.name === 'csrf_token' || el.type === 'hidden') return;
        el.disabled = state;
    });
    document.querySelector('#companyDocumentForm button[type="submit"]')?.classList.toggle('d-none', state);
    document.getElementById('companyPdfInput')?.classList.toggle('d-none', state);
    document.getElementById('newCompanyCatalogDocumentBtn')?.classList.toggle('d-none', state);
    document.getElementById('deleteCompanyCatalogDocumentBtn')?.classList.toggle('d-none', state);
}

async function addCompanyCatalogDocument() {
    const focusTrap = companyDocumentModal?._focustrap;
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

    const response = await fetch(`${BASE_URL}/servicios/empresa/guardar_catalogo_documento_empresa.php`, { method: 'POST', body: form });
    const data = await response.json();

    if (!data.ok) {
        Swal.fire('Atención', data.message || 'No se pudo agregar el documento.', 'warning');
        return;
    }

    const select = document.getElementById('companyDocumentSelect');
    const existing = Array.from(select.options).find((option) => option.value === String(data.id));
    if (existing) {
        existing.textContent = data.text;
    } else {
        select.append(new Option(data.text, data.id, false, false));
    }
    select.value = String(data.id);
    if (window.jQuery && $.fn.select2) {
        $('#companyDocumentSelect').trigger('change');
    }
}

async function deleteCompanyCatalogDocument() {
    const select = document.getElementById('companyDocumentSelect');
    const documentId = select?.value || '';
    const documentText = select?.selectedOptions?.[0]?.textContent?.trim() || '';

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

    const response = await fetch(`${BASE_URL}/servicios/empresa/eliminar_catalogo_documento_empresa.php`, { method: 'POST', body: form });
    const data = await response.json();

    if (!data.ok) {
        Swal.fire('Atención', data.message || 'No se pudo eliminar el documento.', 'warning');
        return;
    }

    Array.from(select.options).find((option) => option.value === String(documentId))?.remove();
    select.value = '';
    if (window.jQuery && $.fn.select2) {
        $('#companyDocumentSelect').trigger('change');
    }
    Swal.fire('Eliminado', data.message || 'Documento eliminado.', 'success');
}

async function saveCompanyDocument(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.checkValidity()) {
        form.classList.add('was-validated');
        return;
    }
    const submitButton = form.querySelector('button[type="submit"]');
    const progressBox = document.getElementById('companyUploadProgress');
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
        const data = await postFormWithProgress(`${BASE_URL}/servicios/empresa/guardar_documento_empresa.php`, new FormData(form), renderProgress);
        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo guardar.', 'warning');
            return;
        }
        companyDocumentModal.hide();
        loadCompanyDocuments();
    } catch (error) {
        Swal.fire('Atención', error.message || 'No se pudo guardar.', 'warning');
    } finally {
        submitButton.disabled = false;
        progressBox?.classList.add('d-none');
        if (progressBar) progressBar.style.width = '0%';
        if (progressLabel) progressLabel.textContent = 'Subiendo archivo: 0%';
    }
}

async function deleteCompanyDocument(id) {
    const ok = await confirmAction('¿Eliminar documento?');
    if (!ok) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('id', id);
    const response = await fetch(`${BASE_URL}/servicios/empresa/eliminar_documento_empresa.php`, { method: 'POST', body: form });
    const data = await response.json();
    if (data.ok) loadCompanyDocuments();
}

async function deleteCompanyDocumentPdf(id) {
    const ok = await confirmAction('¿Eliminar PDF?');
    if (!ok) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('id', id);
    const response = await fetch(`${BASE_URL}/servicios/empresa/eliminar_pdf_documento_empresa.php`, { method: 'POST', body: form });
    const data = await response.json();
    if (data.ok) {
        renderCompanyCurrentPdf(null);
        loadCompanyDocuments();
    }
}

async function downloadSelectedCompanyDocumentsBundle() {
    if (!currentCompanyModuleId) {
        Swal.fire('Atención', 'Seleccione una empresa.', 'warning');
        return;
    }
    const selectedIds = Array.from(document.querySelectorAll('.company-document-download-check:checked')).map((check) => check.value);
    if (!selectedIds.length) {
        Swal.fire('Atención', 'Seleccione al menos un documento para descargar.', 'warning');
        return;
    }
    await downloadCompanyDocumentsZip(selectedIds);
}

async function downloadCompanyDocumentsBundle() {
    if (!currentCompanyModuleId) {
        Swal.fire('Atención', 'Seleccione una empresa.', 'warning');
        return;
    }
    await downloadCompanyDocumentsZip();
}

async function downloadCompanyDocumentsZip(selectedIds = []) {
    const params = new URLSearchParams({ empresa_id: currentCompanyModuleId });
    if (selectedIds.length) {
        params.set('ids', selectedIds.join(','));
    }
    const response = await fetch(`${BASE_URL}/servicios/empresa/descargar_documentos_empresa.php?${params.toString()}`);
    if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'No se pudo generar la descarga.' }));
        Swal.fire('Atención', data.message || 'No se pudo generar la descarga.', 'warning');
        return;
    }
    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const fileName = match ? match[1] : 'documentos_empresa.zip';
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);

    if (selectedIds.length) {
        document.querySelectorAll('.company-document-download-check:checked').forEach((check) => {
            check.checked = false;
        });
    }
}

let currentCompanySecurityId = null;
let companySecurityModal = null;

function initEmpresaSeguridadDocumentos() {
    const companySearchElement = document.getElementById('companySecuritySearch');
    if (!companySearchElement) return;

    companySecurityModal = new bootstrap.Modal(document.getElementById('companySecurityModal'));

    if (window.jQuery && $.fn.select2) {
        $('#companySecuritySearch').select2({ theme: 'bootstrap4', width: '100%', placeholder: 'Escriba razon social o RUC' });
        $('#companySecuritySearch').on('select2:select', (event) => loadCompanySecurity(event.params.data.id));
        $('#companySecuritySelect').select2({
            theme: 'bootstrap4',
            dropdownParent: $('#companySecurityModal'),
            width: '100%',
            placeholder: 'Buscar documento'
        });
    }

    companySearchElement.addEventListener('change', (event) => {
        if (event.target.value) loadCompanySecurity(event.target.value);
    });
    document.getElementById('downloadCompanySecurityBtn')?.addEventListener('click', downloadCompanySecurityBundle);
    document.getElementById('downloadSelectedCompanySecurityBtn')?.addEventListener('click', downloadSelectedCompanySecurityBundle);
    document.getElementById('addCompanySecurityBtn')?.addEventListener('click', openAddCompanySecurity);
    document.getElementById('companySecurityForm')?.addEventListener('submit', saveCompanySecurity);
    document.getElementById('newCompanySecurityCatalogBtn')?.addEventListener('click', addCompanySecurityCatalog);
    document.getElementById('deleteCompanySecurityCatalogBtn')?.addEventListener('click', deleteCompanySecurityCatalog);
    document.getElementById('changeCompanySecurityPhotoBtn')?.addEventListener('click', () => {
        if (!currentCompanySecurityId) {
            Swal.fire('Atención', 'Seleccione una empresa.', 'warning');
            return;
        }
        document.getElementById('companySecurityPhotoInput')?.click();
    });
    document.getElementById('companySecurityPhotoInput')?.addEventListener('change', uploadCompanySecurityPhoto);
}

async function loadCompanySecurity(id) {
    currentCompanySecurityId = id;
    const response = await fetch(`${BASE_URL}/servicios/empresa/perfil_empresa.php?id=${id}`);
    const data = await response.json();
    if (!data.ok) return;
    const company = data.empresa;
    document.getElementById('companySecurityWorkspace').classList.remove('d-none');
    document.getElementById('companySecurityPhoto').src = company.foto_path ? `${BASE_URL}/${company.foto_path}` : `${BASE_URL}/recursos/imagen_referencial.php`;
    document.getElementById('companySecurityName').textContent = company.razon_social || '';
    document.getElementById('companySecurityRuc').textContent = company.ruc || '';
    document.getElementById('companySecurityAddress').textContent = company.direccion || '';
    loadCompanySecurityRows();
}

async function uploadCompanySecurityPhoto(event) {
    const input = event.currentTarget;
    const file = input.files?.[0];
    if (!file || !currentCompanySecurityId) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('empresa_id', currentCompanySecurityId);
    form.append('foto', file);
    const response = await fetch(`${BASE_URL}/servicios/empresa/subir_foto_empresa.php`, { method: 'POST', body: form });
    const data = await response.json();
    input.value = '';
    if (!data.ok) {
        Swal.fire('Atención', data.message || 'No se pudo cambiar la foto.', 'warning');
        return;
    }
    document.getElementById('companySecurityPhoto').src = `${data.path}?v=${Date.now()}`;
    Swal.fire('Actualizado', 'Foto de empresa actualizada.', 'success');
}

async function loadCompanySecurityRows() {
    if (!currentCompanySecurityId) return;
    const response = await fetch(`${BASE_URL}/servicios/empresa/listar_seguridad_empresa.php?empresa_id=${currentCompanySecurityId}`);
    const data = await response.json();
    const tbody = document.querySelector('#companySecurityTable tbody');
    tbody.innerHTML = '';
    (data.rows || []).forEach((row) => {
        const hasPdf = !!row.archivo_path;
        const downloadName = escapeHtml(row.archivo_nombre_original || `${row.documento}.pdf`);
        const downloadButton = hasPdf ? `<a class="btn btn-sm btn-outline-success" href="${BASE_URL}/${row.archivo_path}" download="${downloadName}" title="Descargar documento"><i class="fa-solid fa-download"></i></a>` : '';
        tbody.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="text-center"><input class="form-check-input company-security-download-check" type="checkbox" value="${row.id}" ${hasPdf ? '' : 'disabled'}></td>
                <td>${escapeHtml(row.documento)}</td>
                <td>${row.fecha_registro}</td>
                <td>${row.fecha_inicio}</td>
                <td>${row.fecha_fin}</td>
                <td><span class="badge ${row.status.class}">${row.status.label}</span></td>
                <td>${escapeHtml(row.registered_by || '')}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" type="button" onclick="openEditCompanySecurity(${row.id})"><i class="fa-solid fa-pen"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="openViewCompanySecurity(${row.id})"><i class="fa-solid fa-eye"></i></button>
                    <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteCompanySecurity(${row.id})"><i class="fa-solid fa-trash"></i></button>
                    ${downloadButton}
                </td>
            </tr>
        `);
    });
}

function openAddCompanySecurity() {
    if (!currentCompanySecurityId) {
        Swal.fire('Atención', 'Seleccione una empresa.', 'warning');
        return;
    }
    const form = document.getElementById('companySecurityForm');
    form.reset();
    form.classList.remove('was-validated');
    setCompanySecurityReadonly(false);
    document.getElementById('companySecurityModalTitle').textContent = 'Agregar documentos';
    document.getElementById('companySecurityId').value = '';
    document.getElementById('companySecurityCompanyId').value = currentCompanySecurityId;
    document.getElementById('companySecurityRegistrationDate').value = localDateValue();
    if (window.jQuery && $.fn.select2) {
        $('#companySecuritySelect').val('').trigger('change');
    }
    renderCompanySecurityPdf(null);
    companySecurityModal.show();
}

async function openEditCompanySecurity(id) {
    await fillCompanySecurityModal(id);
    setCompanySecurityReadonly(false);
    document.getElementById('companySecurityModalTitle').textContent = 'Editar documentos';
    companySecurityModal.show();
}

async function openViewCompanySecurity(id) {
    await fillCompanySecurityModal(id);
    setCompanySecurityReadonly(true);
    document.getElementById('companySecurityModalTitle').textContent = 'Visualizar documentos';
    companySecurityModal.show();
}

async function fillCompanySecurityModal(id) {
    const response = await fetch(`${BASE_URL}/servicios/empresa/obtener_seguridad_empresa.php?id=${id}`);
    const data = await response.json();
    const row = data.row;
    document.getElementById('companySecurityId').value = row.id;
    document.getElementById('companySecurityCompanyId').value = row.empresa_id;
    document.getElementById('companySecuritySelect').value = row.documento_id;
    if (window.jQuery && $.fn.select2) {
        $('#companySecuritySelect').trigger('change');
    }
    document.getElementById('companySecurityRegistrationDate').value = row.fecha_registro;
    document.getElementById('companySecurityStartDate').value = row.fecha_inicio;
    document.getElementById('companySecurityEndDate').value = row.fecha_fin;
    document.getElementById('companySecurityObservations').value = row.observaciones || '';
    renderCompanySecurityPdf(row);
}

function renderCompanySecurityPdf(row) {
    const box = document.getElementById('companySecurityCurrentPdf');
    if (!box) return;
    if (!row || !row.archivo_path) {
        box.classList.add('d-none');
        box.innerHTML = '';
        return;
    }
    box.classList.remove('d-none');
    box.innerHTML = `<i class="fa-solid fa-file-pdf text-danger me-2"></i><strong>${escapeHtml(row.archivo_nombre_original || 'archivo.pdf')}</strong><div class="d-flex gap-2 mt-2"><a class="btn btn-sm btn-outline-primary" target="_blank" href="${BASE_URL}/${row.archivo_path}"><i class="fa-solid fa-up-right-from-square me-1"></i>Abrir</a><button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteCompanySecurityPdf(${row.id})"><i class="fa-solid fa-trash me-1"></i>Eliminar</button></div>`;
}

function setCompanySecurityReadonly(state) {
    document.querySelectorAll('#companySecurityForm input, #companySecurityForm textarea, #companySecurityForm select').forEach((el) => {
        if (el.name === 'csrf_token' || el.type === 'hidden') return;
        el.disabled = state;
    });
    document.querySelector('#companySecurityForm button[type="submit"]')?.classList.toggle('d-none', state);
    document.getElementById('companySecurityPdfInput')?.classList.toggle('d-none', state);
    document.getElementById('newCompanySecurityCatalogBtn')?.classList.toggle('d-none', state);
    document.getElementById('deleteCompanySecurityCatalogBtn')?.classList.toggle('d-none', state);
}

async function saveCompanySecurity(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.checkValidity()) {
        form.classList.add('was-validated');
        return;
    }
    const submitButton = form.querySelector('button[type="submit"]');
    const progressBox = document.getElementById('companySecurityUploadProgress');
    const progressBar = progressBox?.querySelector('.progress-bar');
    const progressLabel = progressBox?.querySelector('small');
    function renderProgress(percent) {
        if (!progressBox || !progressBar || !progressLabel) return;
        progressBox.classList.remove('d-none');
        progressBar.style.width = `${percent}%`;
        progressLabel.textContent = percent < 100 ? `Subiendo archivo: ${percent}%` : 'Procesando archivo...';
    }
    submitButton.disabled = true;
    renderProgress(0);
    try {
        const data = await postFormWithProgress(`${BASE_URL}/servicios/empresa/guardar_seguridad_empresa.php`, new FormData(form), renderProgress);
        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo guardar.', 'warning');
            return;
        }
        companySecurityModal.hide();
        loadCompanySecurityRows();
    } catch (error) {
        Swal.fire('Atención', error.message || 'No se pudo guardar.', 'warning');
    } finally {
        submitButton.disabled = false;
        progressBox?.classList.add('d-none');
        if (progressBar) progressBar.style.width = '0%';
        if (progressLabel) progressLabel.textContent = 'Subiendo archivo: 0%';
    }
}

async function addCompanySecurityCatalog() {
    const result = await Swal.fire({ title: 'Nuevo documento', input: 'text', inputPlaceholder: 'Nombre del documento', showCancelButton: true, confirmButtonText: 'Agregar', cancelButtonText: 'Cancelar' });
    if (!result.value) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('nombre', result.value);
    const response = await fetch(`${BASE_URL}/servicios/empresa/guardar_catalogo_seguridad_empresa.php`, { method: 'POST', body: form });
    const data = await response.json();
    if (!data.ok) return Swal.fire('Atención', data.message || 'No se pudo agregar el documento.', 'warning');
    const select = document.getElementById('companySecuritySelect');
    const existing = Array.from(select.options).find((option) => option.value === String(data.id));
    if (existing) existing.textContent = data.text;
    else select.append(new Option(data.text, data.id, false, false));
    select.value = String(data.id);
    if (window.jQuery && $.fn.select2) {
        $('#companySecuritySelect').trigger('change');
    }
}

async function deleteCompanySecurityCatalog() {
    const select = document.getElementById('companySecuritySelect');
    const documentId = select?.value || '';
    const documentText = select?.selectedOptions?.[0]?.textContent?.trim() || '';
    if (!documentId) return Swal.fire('Atención', 'Seleccione un documento para eliminar.', 'warning');
    const result = await Swal.fire({ title: '¿Eliminar documento?', text: `Se quitará "${documentText}" del catálogo si no tiene registros asociados.`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar' });
    if (!result.isConfirmed) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('id', documentId);
    const response = await fetch(`${BASE_URL}/servicios/empresa/eliminar_catalogo_seguridad_empresa.php`, { method: 'POST', body: form });
    const data = await response.json();
    if (!data.ok) return Swal.fire('Atención', data.message || 'No se pudo eliminar el documento.', 'warning');
    Array.from(select.options).find((option) => option.value === String(documentId))?.remove();
    select.value = '';
    if (window.jQuery && $.fn.select2) {
        $('#companySecuritySelect').trigger('change');
    }
    Swal.fire('Eliminado', data.message || 'Documento eliminado.', 'success');
}

async function deleteCompanySecurity(id) {
    const ok = await confirmAction('¿Eliminar documento?');
    if (!ok) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('id', id);
    const response = await fetch(`${BASE_URL}/servicios/empresa/eliminar_seguridad_empresa.php`, { method: 'POST', body: form });
    const data = await response.json();
    if (data.ok) loadCompanySecurityRows();
}

async function deleteCompanySecurityPdf(id) {
    const ok = await confirmAction('¿Eliminar PDF?');
    if (!ok) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('id', id);
    const response = await fetch(`${BASE_URL}/servicios/empresa/eliminar_pdf_seguridad_empresa.php`, { method: 'POST', body: form });
    const data = await response.json();
    if (data.ok) {
        renderCompanySecurityPdf(null);
        loadCompanySecurityRows();
    }
}

async function downloadSelectedCompanySecurityBundle() {
    if (!currentCompanySecurityId) return Swal.fire('Atención', 'Seleccione una empresa.', 'warning');
    const selectedIds = Array.from(document.querySelectorAll('.company-security-download-check:checked')).map((check) => check.value);
    if (!selectedIds.length) return Swal.fire('Atención', 'Seleccione al menos un documento para descargar.', 'warning');
    await downloadCompanySecurityZip(selectedIds);
}

async function downloadCompanySecurityBundle() {
    if (!currentCompanySecurityId) return Swal.fire('Atención', 'Seleccione una empresa.', 'warning');
    await downloadCompanySecurityZip();
}

async function downloadCompanySecurityZip(selectedIds = []) {
    const params = new URLSearchParams({ empresa_id: currentCompanySecurityId });
    if (selectedIds.length) params.set('ids', selectedIds.join(','));
    const response = await fetch(`${BASE_URL}/servicios/empresa/descargar_seguridad_empresa.php?${params.toString()}`);
    if (!response.ok) {
        const data = await response.json().catch(() => ({ message: 'No se pudo generar la descarga.' }));
        return Swal.fire('Atención', data.message || 'No se pudo generar la descarga.', 'warning');
    }
    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const fileName = match ? match[1] : 'seguridad_empresa.zip';
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
    if (selectedIds.length) {
        document.querySelectorAll('.company-security-download-check:checked').forEach((check) => { check.checked = false; });
    }
}

function initEmpresaGenericModules() {
    document.querySelectorAll('.company-generic-module').forEach((root) => {
        const module = root.dataset.companyModule;
        const moduleTitle = root.dataset.moduleTitle || 'Documentos';
        const search = root.querySelector('.js-company-generic-search');
        const workspace = root.querySelector('.js-company-generic-workspace');
        const photo = root.querySelector('.js-company-generic-photo');
        const modalElement = root.querySelector('.js-company-generic-modal');
        const modal = modalElement ? new bootstrap.Modal(modalElement) : null;
        const form = root.querySelector('.js-company-generic-form');
        const select = root.querySelector('.js-company-generic-select');
        let currentCompanyId = null;

        if (!module || !search || !modal || !form || !select) return;

        if (window.jQuery && $.fn.select2) {
            $(search).select2({ theme: 'bootstrap4', width: '100%', placeholder: 'Escriba razon social o RUC' });
            $(search).on('select2:select', (event) => loadCompany(event.params.data.id));
            $(select).select2({ theme: 'bootstrap4', dropdownParent: $(modalElement), width: '100%', placeholder: 'Buscar documento' });
        }

        search.addEventListener('change', (event) => {
            if (event.target.value) loadCompany(event.target.value);
        });

        root.querySelector('.js-company-generic-photo-btn')?.addEventListener('click', () => {
            if (!currentCompanyId) return Swal.fire('Atención', 'Seleccione una empresa.', 'warning');
            root.querySelector('.js-company-generic-photo-input')?.click();
        });
        root.querySelector('.js-company-generic-photo-input')?.addEventListener('change', uploadPhoto);
        root.querySelector('.js-company-generic-add')?.addEventListener('click', openAdd);
        root.querySelector('.js-company-generic-download-all')?.addEventListener('click', () => downloadZip());
        root.querySelector('.js-company-generic-download-selected')?.addEventListener('click', downloadSelected);
        root.querySelector('.js-company-generic-new-catalog')?.addEventListener('click', addCatalog);
        root.querySelector('.js-company-generic-delete-catalog')?.addEventListener('click', deleteCatalog);
        form.addEventListener('submit', saveDocument);

        async function loadCompany(id) {
            currentCompanyId = id;
            const response = await fetch(`${BASE_URL}/servicios/empresa/perfil_empresa.php?id=${id}`);
            const data = await response.json();
            if (!data.ok) return;
            const company = data.empresa;
            workspace?.classList.remove('d-none');
            if (photo) photo.src = company.foto_path ? `${BASE_URL}/${company.foto_path}` : `${BASE_URL}/recursos/imagen_referencial.php`;
            root.querySelector('.js-company-generic-name').textContent = company.razon_social || '';
            root.querySelector('.js-company-generic-ruc').textContent = company.ruc || '';
            root.querySelector('.js-company-generic-address').textContent = company.direccion || '';
            loadRows();
        }

        async function uploadPhoto(event) {
            const input = event.currentTarget;
            const file = input.files?.[0];
            if (!file || !currentCompanyId) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('empresa_id', currentCompanyId);
            body.append('foto', file);
            const response = await fetch(`${BASE_URL}/servicios/empresa/subir_foto_empresa.php`, { method: 'POST', body });
            const data = await response.json();
            input.value = '';
            if (!data.ok) return Swal.fire('Atención', data.message || 'No se pudo cambiar la foto.', 'warning');
            if (photo) photo.src = `${data.path}?v=${Date.now()}`;
            Swal.fire('Actualizado', 'Foto de empresa actualizada.', 'success');
        }

        async function loadRows() {
            const response = await fetch(`${BASE_URL}/servicios/empresa/documentos_genericos_empresa.php?action=list&module=${module}&empresa_id=${currentCompanyId}`);
            const data = await response.json();
            const tbody = root.querySelector('.js-company-generic-tbody');
            tbody.innerHTML = '';
            (data.rows || []).forEach((row) => {
                const hasPdf = !!row.archivo_path;
                const downloadName = escapeHtml(row.archivo_nombre_original || `${row.documento}.pdf`);
                const downloadButton = hasPdf ? `<a class="btn btn-sm btn-outline-success" href="${BASE_URL}/${row.archivo_path}" download="${downloadName}" title="Descargar documento"><i class="fa-solid fa-download"></i></a>` : '';
                tbody.insertAdjacentHTML('beforeend', `
                    <tr>
                        <td class="text-center"><input class="form-check-input js-company-generic-check" type="checkbox" value="${row.id}" ${hasPdf ? '' : 'disabled'}></td>
                        <td>${escapeHtml(row.documento)}</td>
                        <td>${row.fecha_registro}</td>
                        <td>${row.fecha_inicio}</td>
                        <td>${row.fecha_fin}</td>
                        <td><span class="badge ${row.status.class}">${row.status.label}</span></td>
                        <td>${escapeHtml(row.registered_by || '')}</td>
                        <td class="text-nowrap">
                            <button class="btn btn-sm btn-outline-primary js-generic-edit" type="button" data-id="${row.id}"><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-sm btn-outline-secondary js-generic-view" type="button" data-id="${row.id}"><i class="fa-solid fa-eye"></i></button>
                            <button class="btn btn-sm btn-outline-danger js-generic-delete" type="button" data-id="${row.id}"><i class="fa-solid fa-trash"></i></button>
                            ${downloadButton}
                        </td>
                    </tr>`);
            });
            tbody.querySelectorAll('.js-generic-edit').forEach((button) => button.addEventListener('click', () => openEdit(button.dataset.id)));
            tbody.querySelectorAll('.js-generic-view').forEach((button) => button.addEventListener('click', () => openView(button.dataset.id)));
            tbody.querySelectorAll('.js-generic-delete').forEach((button) => button.addEventListener('click', () => deleteDocument(button.dataset.id)));
        }

        function openAdd() {
            if (!currentCompanyId) return Swal.fire('Atención', 'Seleccione una empresa.', 'warning');
            form.reset();
            form.classList.remove('was-validated');
            setReadonly(false);
            root.querySelector('.js-company-generic-modal-title').textContent = 'Agregar documentos';
            root.querySelector('.js-company-generic-id').value = '';
            root.querySelector('.js-company-generic-company-id').value = currentCompanyId;
            root.querySelector('.js-company-generic-registration').value = localDateValue();
            $(select).val('').trigger('change');
            renderPdf(null);
            modal.show();
        }

        async function openEdit(id) {
            await fillModal(id);
            setReadonly(false);
            root.querySelector('.js-company-generic-modal-title').textContent = 'Editar documentos';
            modal.show();
        }

        async function openView(id) {
            await fillModal(id);
            setReadonly(true);
            root.querySelector('.js-company-generic-modal-title').textContent = 'Visualizar documentos';
            modal.show();
        }

        async function fillModal(id) {
            const response = await fetch(`${BASE_URL}/servicios/empresa/documentos_genericos_empresa.php?action=get&module=${module}&id=${id}`);
            const data = await response.json();
            const row = data.row;
            root.querySelector('.js-company-generic-id').value = row.id;
            root.querySelector('.js-company-generic-company-id').value = row.empresa_id;
            select.value = row.documento_id;
            $(select).trigger('change');
            root.querySelector('.js-company-generic-registration').value = row.fecha_registro;
            root.querySelector('.js-company-generic-start').value = row.fecha_inicio;
            root.querySelector('.js-company-generic-end').value = row.fecha_fin;
            root.querySelector('.js-company-generic-observations').value = row.observaciones || '';
            renderPdf(row);
        }

        function renderPdf(row) {
            const box = root.querySelector('.js-company-generic-current-pdf');
            if (!box) return;
            if (!row || !row.archivo_path) {
                box.classList.add('d-none');
                box.innerHTML = '';
                return;
            }
            box.classList.remove('d-none');
            box.innerHTML = `<i class="fa-solid fa-file-pdf text-danger me-2"></i><strong>${escapeHtml(row.archivo_nombre_original || 'archivo.pdf')}</strong><div class="d-flex gap-2 mt-2"><a class="btn btn-sm btn-outline-primary" target="_blank" href="${BASE_URL}/${row.archivo_path}"><i class="fa-solid fa-up-right-from-square me-1"></i>Abrir</a><button class="btn btn-sm btn-outline-danger js-delete-current-pdf" type="button"><i class="fa-solid fa-trash me-1"></i>Eliminar</button></div>`;
            box.querySelector('.js-delete-current-pdf')?.addEventListener('click', () => deletePdf(row.id));
        }

        function setReadonly(state) {
            form.querySelectorAll('input, textarea, select').forEach((el) => {
                if (el.name === 'csrf_token' || el.type === 'hidden') return;
                el.disabled = state;
            });
            form.querySelector('button[type="submit"]')?.classList.toggle('d-none', state);
            root.querySelector('.js-company-generic-pdf-input')?.classList.toggle('d-none', state);
            root.querySelector('.js-company-generic-new-catalog')?.classList.toggle('d-none', state);
            root.querySelector('.js-company-generic-delete-catalog')?.classList.toggle('d-none', state);
        }

        async function saveDocument(event) {
            event.preventDefault();
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                return;
            }
            const submitButton = form.querySelector('button[type="submit"]');
            const progressBox = root.querySelector('.js-company-generic-progress');
            const progressBar = progressBox?.querySelector('.progress-bar');
            const progressLabel = progressBox?.querySelector('small');
            const renderProgress = (percent) => {
                if (!progressBox || !progressBar || !progressLabel) return;
                progressBox.classList.remove('d-none');
                progressBar.style.width = `${percent}%`;
                progressLabel.textContent = percent < 100 ? `Subiendo archivo: ${percent}%` : 'Procesando archivo...';
            };
            submitButton.disabled = true;
            renderProgress(0);
            try {
                const data = await postFormWithProgress(`${BASE_URL}/servicios/empresa/documentos_genericos_empresa.php?action=save`, new FormData(form), renderProgress);
                if (!data.ok) return Swal.fire('Atención', data.message || 'No se pudo guardar.', 'warning');
                modal.hide();
                loadRows();
            } catch (error) {
                Swal.fire('Atención', error.message || 'No se pudo guardar.', 'warning');
            } finally {
                submitButton.disabled = false;
                progressBox?.classList.add('d-none');
                if (progressBar) progressBar.style.width = '0%';
                if (progressLabel) progressLabel.textContent = 'Subiendo archivo: 0%';
            }
        }

        async function deleteDocument(id) {
            const ok = await confirmAction('¿Eliminar documento?');
            if (!ok) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('module', module);
            body.append('id', id);
            const response = await fetch(`${BASE_URL}/servicios/empresa/documentos_genericos_empresa.php?action=delete`, { method: 'POST', body });
            const data = await response.json();
            if (data.ok) loadRows();
        }

        async function deletePdf(id) {
            const ok = await confirmAction('¿Eliminar PDF?');
            if (!ok) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('module', module);
            body.append('id', id);
            const response = await fetch(`${BASE_URL}/servicios/empresa/documentos_genericos_empresa.php?action=delete_pdf`, { method: 'POST', body });
            const data = await response.json();
            if (data.ok) {
                renderPdf(null);
                loadRows();
            }
        }

        async function addCatalog() {
            const result = await Swal.fire({ title: 'Nuevo documento', input: 'text', inputPlaceholder: 'Nombre del documento', showCancelButton: true, confirmButtonText: 'Agregar', cancelButtonText: 'Cancelar' });
            if (!result.value) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('module', module);
            body.append('nombre', result.value);
            const response = await fetch(`${BASE_URL}/servicios/empresa/documentos_genericos_empresa.php?action=catalog_save`, { method: 'POST', body });
            const data = await response.json();
            if (!data.ok) return Swal.fire('Atención', data.message || 'No se pudo agregar el documento.', 'warning');
            const existing = Array.from(select.options).find((option) => option.value === String(data.id));
            if (existing) existing.textContent = data.text;
            else select.append(new Option(data.text, data.id, false, false));
            $(select).val(String(data.id)).trigger('change');
        }

        async function deleteCatalog() {
            const documentId = select.value || '';
            const documentText = select.selectedOptions?.[0]?.textContent?.trim() || '';
            if (!documentId) return Swal.fire('Atención', 'Seleccione un documento para eliminar.', 'warning');
            const result = await Swal.fire({ title: '¿Eliminar documento?', text: `Se quitará "${documentText}" del catálogo si no tiene registros asociados.`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar' });
            if (!result.isConfirmed) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('module', module);
            body.append('id', documentId);
            const response = await fetch(`${BASE_URL}/servicios/empresa/documentos_genericos_empresa.php?action=catalog_delete`, { method: 'POST', body });
            const data = await response.json();
            if (!data.ok) return Swal.fire('Atención', data.message || 'No se pudo eliminar el documento.', 'warning');
            Array.from(select.options).find((option) => option.value === String(documentId))?.remove();
            $(select).val('').trigger('change');
            Swal.fire('Eliminado', data.message || 'Documento eliminado.', 'success');
        }

        async function downloadSelected() {
            if (!currentCompanyId) return Swal.fire('Atención', 'Seleccione una empresa.', 'warning');
            const selectedIds = Array.from(root.querySelectorAll('.js-company-generic-check:checked')).map((check) => check.value);
            if (!selectedIds.length) return Swal.fire('Atención', 'Seleccione al menos un documento para descargar.', 'warning');
            await downloadZip(selectedIds);
        }

        async function downloadZip(selectedIds = []) {
            if (!currentCompanyId) return Swal.fire('Atención', 'Seleccione una empresa.', 'warning');
            const params = new URLSearchParams({ action: 'download', module, empresa_id: currentCompanyId });
            if (selectedIds.length) params.set('ids', selectedIds.join(','));
            const response = await fetch(`${BASE_URL}/servicios/empresa/documentos_genericos_empresa.php?${params.toString()}`);
            if (!response.ok) {
                const data = await response.json().catch(() => ({ message: 'No se pudo generar la descarga.' }));
                return Swal.fire('Atención', data.message || 'No se pudo generar la descarga.', 'warning');
            }
            const blob = await response.blob();
            const disposition = response.headers.get('Content-Disposition') || '';
            const match = disposition.match(/filename="([^"]+)"/);
            const fileName = match ? match[1] : `${moduleTitle}_empresa.zip`;
            const objectUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = objectUrl;
            link.download = fileName;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(objectUrl);
            if (selectedIds.length) {
                root.querySelectorAll('.js-company-generic-check:checked').forEach((check) => { check.checked = false; });
            }
        }
    });
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
    const roleSelect = document.getElementById('usuarioRole');
    const workerGroup = document.getElementById('usuarioWorkerGroup');
    const workerSelect = document.getElementById('usuarioWorkerId');
    const nameInput = document.getElementById('usuarioName');
    const emailInput = document.getElementById('usuarioEmail');

    function fillUserFromSelectedWorker() {
        if (!workerSelect || roleSelect?.value !== 'Personal') return;
        const option = workerSelect.selectedOptions?.[0];
        if (!option || !option.value) return;
        if (nameInput) nameInput.value = option.dataset.name || '';
        if (emailInput) emailInput.value = option.dataset.email || '';
    }

    function toggleUserWorkerField() {
        const isPersonal = roleSelect?.value === 'Personal';
        workerGroup?.classList.toggle('d-none', !isPersonal);
        if (workerSelect) {
            workerSelect.required = isPersonal;
            if (!isPersonal) workerSelect.value = '';
            if (isPersonal) fillUserFromSelectedWorker();
        }
    }

    document.getElementById('nuevoUsuarioBtn')?.addEventListener('click', () => {
        form.reset();
        form.classList.remove('was-validated');
        document.getElementById('usuarioId').value = '';
        document.getElementById('usuarioModalTitle').textContent = 'Nuevo usuario';
        password.required = true;
        toggleUserWorkerField();
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
            document.getElementById('usuarioRole').value = button.dataset.role || 'Administrador';
            if (workerSelect) workerSelect.value = button.dataset.workerId || '';
            document.getElementById('usuarioModalTitle').textContent = 'Editar usuario';
            password.required = false;
            toggleUserWorkerField();
            passwordHelp.textContent = 'Dejar vacío para mantener la contraseña actual.';
            modal.show();
        });
    });

    roleSelect?.addEventListener('change', toggleUserWorkerField);
    workerSelect?.addEventListener('change', fillUserFromSelectedWorker);

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

function initUsuariosModule() {
    const table = document.getElementById('usuariosTable');
    if (!table) return;

    const form = document.getElementById('usuarioForm');
    const modal = new bootstrap.Modal(document.getElementById('usuarioModal'));
    const password = document.getElementById('usuarioPassword');
    const passwordHelp = document.getElementById('usuarioPasswordHelp');
    const roleSelect = document.getElementById('usuarioRole');
    const workerGroup = document.getElementById('usuarioWorkerGroup');
    const workerSelect = document.getElementById('usuarioWorkerId');
    const nameInput = document.getElementById('usuarioName');
    const emailInput = document.getElementById('usuarioEmail');
    const selectAllModules = document.getElementById('usuarioSelectAllModules');
    const permissionNote = document.getElementById('usuarioPermissionNote');
    const moduleChecks = Array.from(document.querySelectorAll('.usuario-module-permission'));
    const viewChecks = Array.from(document.querySelectorAll('.usuario-document-view'));
    const uploadChecks = Array.from(document.querySelectorAll('.usuario-document-upload'));
    const manageChecks = Array.from(document.querySelectorAll('.usuario-document-manage'));
    const scopeAllChecks = Array.from(document.querySelectorAll('.usuario-document-scope-all'));
    const permissionData = window.usuarioPermisos || { users: {} };
    const allModuleKeys = moduleChecks.map((check) => check.value);

    function fillUserFromSelectedWorker() {
        if (!workerSelect || roleSelect?.value !== 'Personal') return;
        const option = workerSelect.selectedOptions?.[0];
        if (!option || !option.value) return;
        if (nameInput) nameInput.value = option.dataset.name || '';
        if (emailInput) emailInput.value = option.dataset.email || '';
    }

    function buildAllDocumentPermissions() {
        const permissions = {};
        viewChecks.forEach((check) => {
            const scope = check.dataset.scope;
            const id = check.dataset.catalogId;
            permissions[scope] = permissions[scope] || {};
            permissions[scope][id] = { view: true, upload: true, manage: true };
        });
        return permissions;
    }

    function permissionsForUserPayload(userId, role) {
        if (role === 'Administrador') {
            return defaultPermissionsForRole('Administrador');
        }
        return permissionData.users?.[String(userId)] || defaultPermissionsForRole(role);
    }

    function defaultPermissionsForRole(role) {
        if (role === 'Administrador') {
            return { modules: allModuleKeys, documents: buildAllDocumentPermissions() };
        }
        if (role === 'Personal') {
            return { modules: ['control_personal', 'control_personal.control_asistencia'], documents: {} };
        }
        return { modules: [], documents: {} };
    }

    function setPermissionsEnabled(enabled) {
        moduleChecks.forEach((check) => { check.disabled = !enabled; });
        viewChecks.forEach((check) => { check.disabled = !enabled; });
        uploadChecks.forEach((check) => { check.disabled = !enabled; });
        manageChecks.forEach((check) => { check.disabled = !enabled; });
        scopeAllChecks.forEach((check) => { check.disabled = !enabled; });
        if (selectAllModules) selectAllModules.disabled = !enabled;
    }

    function syncSelectAllModules() {
        if (!selectAllModules) return;
        selectAllModules.checked = moduleChecks.length > 0 && moduleChecks.every((check) => check.checked);
    }

    function syncScopeAll(scope) {
        const scopeViews = viewChecks.filter((check) => check.dataset.scope === scope);
        const scopeUploads = uploadChecks.filter((check) => check.dataset.scope === scope);
        const scopeManages = manageChecks.filter((check) => check.dataset.scope === scope);
        const scopeAll = scopeAllChecks.find((check) => check.dataset.scope === scope);
        if (!scopeAll) return;
        const allChecks = scopeViews.concat(scopeUploads, scopeManages);
        scopeAll.checked = allChecks.length > 0 && allChecks.every((check) => check.checked);
    }

    function syncAllScopeToggles() {
        scopeAllChecks.forEach((check) => syncScopeAll(check.dataset.scope));
    }

    function applyPermissions(payload) {
        const selectedModules = new Set(payload?.modules || []);
        moduleChecks.forEach((check) => {
            check.checked = selectedModules.has(check.value);
        });
        viewChecks.forEach((check) => {
            const scope = check.dataset.scope;
            const id = check.dataset.catalogId;
            check.checked = !!payload?.documents?.[scope]?.[id]?.view;
        });
        uploadChecks.forEach((check) => {
            const scope = check.dataset.scope;
            const id = check.dataset.catalogId;
            check.checked = !!payload?.documents?.[scope]?.[id]?.upload;
            if (check.checked) {
                const view = viewChecks.find((item) => item.dataset.scope === scope && item.dataset.catalogId === id);
                if (view) view.checked = true;
            }
        });
        manageChecks.forEach((check) => {
            const scope = check.dataset.scope;
            const id = check.dataset.catalogId;
            check.checked = !!payload?.documents?.[scope]?.[id]?.manage;
            if (check.checked) {
                const view = viewChecks.find((item) => item.dataset.scope === scope && item.dataset.catalogId === id);
                const upload = uploadChecks.find((item) => item.dataset.scope === scope && item.dataset.catalogId === id);
                if (view) view.checked = true;
                if (upload) upload.checked = true;
            }
        });
        syncSelectAllModules();
        syncAllScopeToggles();
    }

    function syncParentModules(changedCheck) {
        const parentKey = changedCheck.dataset.parent;
        const parent = moduleChecks.find((check) => check.value === parentKey);
        if (parent && changedCheck.value !== parentKey && changedCheck.checked) {
            parent.checked = true;
        }
        if (parent && changedCheck.value === parentKey && changedCheck.checked) {
            moduleChecks.filter((check) => check.dataset.parent === parentKey && check.value !== parentKey).forEach((child) => {
                child.checked = true;
            });
        }
        if (parent && changedCheck.value === parentKey && !changedCheck.checked) {
            moduleChecks.filter((check) => check.dataset.parent === parentKey && check.value !== parentKey).forEach((child) => {
                child.checked = false;
            });
        }
        syncSelectAllModules();
    }

    function toggleUserWorkerField() {
        const role = roleSelect?.value || 'Administrador';
        const isPersonal = role === 'Personal';
        workerGroup?.classList.toggle('d-none', !isPersonal);
        if (workerSelect) {
            workerSelect.required = isPersonal;
            if (!isPersonal) workerSelect.value = '';
            if (isPersonal) fillUserFromSelectedWorker();
        }
        if (!permissionNote) return;
        if (role === 'Administrador') {
            permissionNote.className = 'permission-role-note mb-3 alert alert-primary';
            permissionNote.textContent = 'Administrador tiene acceso total al sistema. Los permisos quedan seleccionados por defecto.';
            setPermissionsEnabled(false);
            return;
        }
        if (role === 'Personal') {
            permissionNote.className = 'permission-role-note mb-3 alert alert-info';
            permissionNote.textContent = 'Personal solo tiene acceso a Control de personal - Control de asistencia.';
            setPermissionsEnabled(false);
            return;
        }
        permissionNote.className = 'permission-role-note mb-3 alert alert-warning';
        permissionNote.textContent = 'Gestor usa permisos personalizados por modulo y por tipo de requisito/documento.';
        setPermissionsEnabled(true);
    }

    document.getElementById('nuevoUsuarioBtn')?.addEventListener('click', () => {
        form.reset();
        form.classList.remove('was-validated');
        document.getElementById('usuarioId').value = '';
        document.getElementById('usuarioModalTitle').textContent = 'Nuevo usuario';
        password.required = true;
        applyPermissions(defaultPermissionsForRole(roleSelect?.value || 'Administrador'));
        toggleUserWorkerField();
        bootstrap.Tab.getOrCreateInstance(document.getElementById('usuarioDatosTab'))?.show();
        passwordHelp.textContent = 'Minimo 8 caracteres.';
        modal.show();
    });

    document.querySelectorAll('.js-editar-usuario').forEach((button) => {
        button.addEventListener('click', () => {
            form.reset();
            form.classList.remove('was-validated');
            document.getElementById('usuarioId').value = button.dataset.id || '';
            document.getElementById('usuarioName').value = button.dataset.name || '';
            document.getElementById('usuarioEmail').value = button.dataset.email || '';
            document.getElementById('usuarioRole').value = button.dataset.role || 'Administrador';
            if (workerSelect) workerSelect.value = button.dataset.workerId || '';
            document.getElementById('usuarioModalTitle').textContent = 'Editar usuario';
            password.required = false;
            applyPermissions(permissionsForUserPayload(button.dataset.id || '', button.dataset.role || 'Administrador'));
            toggleUserWorkerField();
            bootstrap.Tab.getOrCreateInstance(document.getElementById('usuarioDatosTab'))?.show();
            passwordHelp.textContent = 'Dejar vacio para mantener la contrasena actual.';
            modal.show();
        });
    });

    roleSelect?.addEventListener('change', () => {
        applyPermissions(defaultPermissionsForRole(roleSelect.value));
        toggleUserWorkerField();
    });
    workerSelect?.addEventListener('change', fillUserFromSelectedWorker);
    selectAllModules?.addEventListener('change', () => {
        moduleChecks.forEach((check) => { check.checked = selectAllModules.checked; });
    });
    moduleChecks.forEach((check) => {
        check.addEventListener('change', () => syncParentModules(check));
    });
    uploadChecks.forEach((check) => {
        check.addEventListener('change', () => {
            const view = viewChecks.find((item) => item.dataset.scope === check.dataset.scope && item.dataset.catalogId === check.dataset.catalogId);
            if (check.checked && view) view.checked = true;
            if (!check.checked) {
                const manage = manageChecks.find((item) => item.dataset.scope === check.dataset.scope && item.dataset.catalogId === check.dataset.catalogId);
                if (manage) manage.checked = false;
            }
            syncScopeAll(check.dataset.scope);
        });
    });
    viewChecks.forEach((check) => {
        check.addEventListener('change', () => {
            const upload = uploadChecks.find((item) => item.dataset.scope === check.dataset.scope && item.dataset.catalogId === check.dataset.catalogId);
            if (!check.checked && upload) upload.checked = false;
            if (!check.checked) {
                const manage = manageChecks.find((item) => item.dataset.scope === check.dataset.scope && item.dataset.catalogId === check.dataset.catalogId);
                if (manage) manage.checked = false;
            }
            syncScopeAll(check.dataset.scope);
        });
    });
    manageChecks.forEach((check) => {
        check.addEventListener('change', () => {
            const view = viewChecks.find((item) => item.dataset.scope === check.dataset.scope && item.dataset.catalogId === check.dataset.catalogId);
            const upload = uploadChecks.find((item) => item.dataset.scope === check.dataset.scope && item.dataset.catalogId === check.dataset.catalogId);
            if (check.checked) {
                if (view) view.checked = true;
                if (upload) upload.checked = true;
            }
            syncScopeAll(check.dataset.scope);
        });
    });
    scopeAllChecks.forEach((check) => {
        check.addEventListener('change', () => {
            const scope = check.dataset.scope;
            viewChecks.filter((item) => item.dataset.scope === scope).forEach((item) => { item.checked = check.checked; });
            uploadChecks.filter((item) => item.dataset.scope === scope).forEach((item) => { item.checked = check.checked; });
            manageChecks.filter((item) => item.dataset.scope === scope).forEach((item) => { item.checked = check.checked; });
        });
    });

    document.querySelectorAll('.js-eliminar-usuario').forEach((button) => {
        button.addEventListener('click', async () => {
            const ok = await confirmAction('Eliminar usuario?');
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
            Swal.fire('Atencion', data.message || 'No se pudo eliminar el usuario.', 'warning');
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
            Swal.fire('Atencion', data.message || 'No se pudo guardar el usuario.', 'warning');
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

    // Helper to format date: DD/MM/YYYY HH:mm
    function formatNotifDate(dateStr) {
        if (!dateStr) return '';
        const t = dateStr.split(/[- :]/);
        if (t.length < 3) return dateStr;
        const time = t.length >= 5 ? ` - ${t[3]}:${t[4]}` : '';
        return `${t[2]}/${t[1]}/${t[0]}${time}`;
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

function initObservationNotifications() {
    const container = document.getElementById('obsNotifContainer');
    const button = document.getElementById('obsNotifBtn');
    const badge = document.getElementById('obsNotifBadge');
    const list = document.getElementById('obsNotifList');
    const searchInput = document.getElementById('obsNotifSearchInput');

    if (!container || !button || !badge || !list) return;

    let rows = [];
    let query = '';

    function formatDate(dateStr) {
        if (!dateStr) return '';
        const parts = String(dateStr).split(/[- :]/);
        if (parts.length < 3) return dateStr;
        const time = parts.length >= 5 ? ` - ${parts[3]}:${parts[4]}` : '';
        return `${parts[2]}/${parts[1]}/${parts[0]}${time}`;
    }

    function setBadge(count) {
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.style.display = 'flex';
            return;
        }
        badge.style.display = 'none';
    }

    function render() {
        const term = query.toLowerCase().trim();
        const filtered = term
            ? rows.filter((row) => {
                return [row.full_name, row.requirement, row.observation, row.status_label, row.observed_by]
                    .some((value) => String(value || '').toLowerCase().includes(term));
            })
            : rows;

        if (!filtered.length) {
            list.innerHTML = `
                <div class="notif-empty">
                    <i class="fa-regular fa-comment-dots"></i>
                    <span>${term ? 'No se encontraron observaciones' : 'No hay observaciones pendientes'}</span>
                </div>
            `;
            return;
        }

        list.innerHTML = filtered.map((row) => {
            const statusClass = row.status === 'corrected' ? 'obs-status-corrected' : 'obs-status-observed';
            return `
                <div class="notif-item unread observation-notif-item" data-id="${escapeHtml(row.id)}">
                    <div class="notif-icon-container ${statusClass}">
                        <i class="fa-solid fa-comment-dots"></i>
                    </div>
                    <div class="notif-content">
                        <div class="notif-body">
                            <strong>Nombre de personal:</strong> <span class="notif-worker-name">${escapeHtml(row.full_name || '')}</span>
                            <span class="notif-missing-fields d-block">Requisito: ${escapeHtml(row.requirement || '')}</span>
                            <span class="notif-observation-text d-block">Observación: ${escapeHtml(row.observation || '')}</span>
                        </div>
                        <div class="notif-time">
                            <span>${escapeHtml(row.status_label || '')}${row.observed_by ? ' por ' + escapeHtml(row.observed_by) : ''} - ${escapeHtml(formatDate(row.created_at))}</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    async function load() {
        try {
            const response = await fetch(`${BASE_URL}/servicios/get_observation_notifications.php?t=${Date.now()}`);
            const data = await response.json();
            if (!data.ok) return;
            rows = data.notifications || [];
            setBadge(data.unread_count || 0);
            render();
        } catch (error) {
            console.error('Error fetching observation notifications:', error);
        }
    }

    searchInput?.addEventListener('input', (event) => {
        query = event.target.value || '';
        render();
    });
    searchInput?.addEventListener('click', (event) => event.stopPropagation());

    button.addEventListener('click', (event) => {
        event.stopPropagation();
        document.getElementById('notifContainer')?.classList.remove('active');
        container.classList.toggle('active');
        if (container.classList.contains('active')) {
            setTimeout(() => searchInput?.focus(), 50);
        }
    });

    document.addEventListener('click', (event) => {
        if (!container.contains(event.target)) {
            container.classList.remove('active');
        }
    });

    load();
    setInterval(load, 20000);
}

function initControlPersonalSchedules() {
    const form = document.getElementById('scheduleForm');
    if (!form) return;

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('scheduleModal'));
    const dayForm = document.getElementById('scheduleDayForm');
    const dayModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('scheduleDayModal'));

    document.getElementById('newScheduleBtn')?.addEventListener('click', () => {
        form.reset();
        form.classList.remove('was-validated');
        document.getElementById('scheduleId').value = '';
        document.getElementById('scheduleModalTitle').textContent = 'Nuevo horario';
        modal.show();
    });

    document.querySelectorAll('.js-edit-schedule').forEach((button) => {
        button.addEventListener('click', () => {
            form.reset();
            form.classList.remove('was-validated');
            document.getElementById('scheduleId').value = button.dataset.id || '';
            document.getElementById('scheduleName').value = button.dataset.name || '';
            document.getElementById('scheduleModalTitle').textContent = 'Editar horario';
            modal.show();
        });
    });

    document.querySelectorAll('.js-delete-schedule').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!await confirmAction('¿Eliminar horario?')) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('id', button.dataset.id || '');
            const response = await fetch(`${BASE_URL}/servicios/control_personal/eliminar_horario.php`, { method: 'POST', body });
            const data = await response.json();
            if (data.ok) window.location.href = `${BASE_URL}/modulos/control_personal/horarios.php`;
            else Swal.fire('Atención', data.message || 'No se pudo eliminar el horario.', 'warning');
        });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            return;
        }
        const response = await fetch(`${BASE_URL}/servicios/control_personal/guardar_horario.php`, { method: 'POST', body: new FormData(form) });
        const data = await response.json();
        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo guardar el horario.', 'warning');
            return;
        }
        window.location.href = `${BASE_URL}/modulos/control_personal/horarios.php?id=${data.id}`;
    });

    document.querySelectorAll('.js-config-schedule-day').forEach((button) => {
        button.addEventListener('click', () => {
            dayForm.reset();
            dayForm.classList.remove('was-validated');
            document.getElementById('scheduleDayModalTitle').textContent = `Configurar ${button.dataset.dayLabel || 'día'}`;
            document.getElementById('scheduleDayScheduleId').value = button.dataset.scheduleId || '';
            document.getElementById('scheduleDayNumber').value = button.dataset.day || '';
            document.getElementById('entryStart').value = button.dataset.entryStart || '';
            document.getElementById('entryEnd').value = button.dataset.entryEnd || '';
            document.getElementById('breakStart').value = button.dataset.breakStart || '';
            document.getElementById('breakEnd').value = button.dataset.breakEnd || '';
            document.getElementById('exitStart').value = button.dataset.exitStart || '';
            document.getElementById('exitEnd').value = button.dataset.exitEnd || '';
            document.getElementById('toleranceMinutes').value = button.dataset.tolerance || '0';
            dayModal.show();
        });
    });

    dayForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!dayForm.checkValidity()) {
            dayForm.classList.add('was-validated');
            return;
        }
        const response = await fetch(`${BASE_URL}/servicios/control_personal/guardar_horario_dia.php`, { method: 'POST', body: new FormData(dayForm) });
        const data = await response.json();
        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo guardar el día.', 'warning');
            return;
        }
        window.location.reload();
    });

    document.getElementById('clearScheduleDayBtn')?.addEventListener('click', async () => {
        if (!await confirmAction('¿Quitar horario de este día?')) return;
        const body = new FormData();
        body.append('csrf_token', csrf);
        body.append('schedule_id', document.getElementById('scheduleDayScheduleId')?.value || '');
        body.append('day_of_week', document.getElementById('scheduleDayNumber')?.value || '');
        const response = await fetch(`${BASE_URL}/servicios/control_personal/eliminar_horario_dia.php`, { method: 'POST', body });
        const data = await response.json();
        if (data.ok) window.location.reload();
        else Swal.fire('Atención', data.message || 'No se pudo quitar el día.', 'warning');
    });
}

function initControlPersonalLocations() {
    const form = document.getElementById('locationForm');
    if (!form) return;

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('locationModal'));
    const radius = document.getElementById('locationRadius');
    const radiusLabel = document.getElementById('locationRadiusLabel');
    const latInput = document.getElementById('locationLatitude');
    const lngInput = document.getElementById('locationLongitude');
    const addressInput = document.getElementById('locationAddress');
    let map = null;
    let marker = null;
    let circle = null;

    function updateRadiusLabel() {
        if (radiusLabel && radius) radiusLabel.textContent = `${radius.value} metros`;
    }

    function setMapPoint(lat, lng) {
        if (!window.L || !map || !Number.isFinite(lat) || !Number.isFinite(lng)) return;
        const point = [lat, lng];
        if (!marker) marker = L.marker(point).addTo(map);
        marker.setLatLng(point);
        if (!circle) circle = L.circle(point, { radius: Number(radius?.value || 100), color: '#1457d9', fillColor: '#1457d9', fillOpacity: 0.12 }).addTo(map);
        circle.setLatLng(point);
        circle.setRadius(Number(radius?.value || 100));
        map.setView(point, 16);
    }

    async function reverseAddress(lat, lng) {
        if (!addressInput || addressInput.value.trim() !== '') return;
        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`);
            const data = await response.json();
            if (data.display_name) addressInput.value = data.display_name;
        } catch (error) {
            // La dirección puede ingresarse manualmente si el servicio externo no responde.
        }
    }

    function initMap() {
        if (!window.L) return;
        if (!map) {
            map = L.map('locationMap').setView([-12.0464, -77.0428], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);
            map.on('click', (event) => {
                const lat = Number(event.latlng.lat.toFixed(8));
                const lng = Number(event.latlng.lng.toFixed(8));
                latInput.value = lat;
                lngInput.value = lng;
                addressInput.value = '';
                setMapPoint(lat, lng);
                reverseAddress(lat, lng);
            });
        }
        setTimeout(() => {
            map.invalidateSize();
            const lat = Number(latInput.value || -12.0464);
            const lng = Number(lngInput.value || -77.0428);
            setMapPoint(lat, lng);
        }, 250);
    }

    function openLocationModal(data = {}) {
        form.reset();
        form.classList.remove('was-validated');
        document.getElementById('locationId').value = data.id || '';
        document.getElementById('locationName').value = data.name || '';
        latInput.value = data.latitude || '';
        lngInput.value = data.longitude || '';
        addressInput.value = data.address || '';
        radius.value = data.radius || '100';
        updateRadiusLabel();
        document.getElementById('locationModalTitle').textContent = data.id ? 'Editar punto de marcación' : 'Nuevo punto de marcación';
        modal.show();
        initMap();
    }

    document.getElementById('newLocationBtn')?.addEventListener('click', () => openLocationModal());
    document.querySelectorAll('.js-edit-location').forEach((button) => {
        button.addEventListener('click', () => openLocationModal(button.dataset));
    });

    radius?.addEventListener('input', () => {
        updateRadiusLabel();
        setMapPoint(Number(latInput.value), Number(lngInput.value));
    });
    [latInput, lngInput].forEach((input) => input?.addEventListener('change', () => {
        const lat = Number(latInput.value);
        const lng = Number(lngInput.value);
        setMapPoint(lat, lng);
        reverseAddress(lat, lng);
    }));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            return;
        }
        const response = await fetch(`${BASE_URL}/servicios/control_personal/guardar_punto_marcacion.php`, { method: 'POST', body: new FormData(form) });
        const data = await response.json();
        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo guardar el punto.', 'warning');
            return;
        }
        window.location.reload();
    });

    document.querySelectorAll('.js-delete-location').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!await confirmAction('¿Eliminar punto de marcación?')) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('id', button.dataset.id || '');
            const response = await fetch(`${BASE_URL}/servicios/control_personal/eliminar_punto_marcacion.php`, { method: 'POST', body });
            const data = await response.json();
            if (data.ok) window.location.reload();
            else Swal.fire('Atención', data.message || 'No se pudo eliminar el punto.', 'warning');
        });
    });
}

function initControlPersonalAssignments() {
    const form = document.getElementById('assignmentForm');
    if (!form) return;

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('assignmentModal'));

    document.getElementById('newAssignmentBtn')?.addEventListener('click', () => {
        form.reset();
        form.classList.remove('was-validated');
        document.getElementById('assignmentId').value = '';
        document.getElementById('assignmentModalTitle').textContent = 'Nueva asignación';
        modal.show();
    });

    document.querySelectorAll('.js-edit-assignment').forEach((button) => {
        button.addEventListener('click', () => {
            form.reset();
            form.classList.remove('was-validated');
            document.getElementById('assignmentId').value = button.dataset.id || '';
            document.getElementById('assignmentWorkerId').value = button.dataset.workerId || '';
            document.getElementById('assignmentLocationId').value = button.dataset.locationId || '';
            document.getElementById('assignmentScheduleId').value = button.dataset.scheduleId || '';
            document.getElementById('assignmentActivity').value = button.dataset.activity || '';
            document.getElementById('assignmentModalTitle').textContent = 'Editar asignación';
            modal.show();
        });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            return;
        }
        const response = await fetch(`${BASE_URL}/servicios/control_personal/guardar_asignacion.php`, { method: 'POST', body: new FormData(form) });
        const data = await response.json();
        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo guardar la asignación.', 'warning');
            return;
        }
        window.location.reload();
    });

    document.querySelectorAll('.js-delete-assignment').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!await confirmAction('¿Eliminar asignación?')) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('id', button.dataset.id || '');
            const response = await fetch(`${BASE_URL}/servicios/control_personal/eliminar_asignacion.php`, { method: 'POST', body });
            const data = await response.json();
            if (data.ok) window.location.reload();
            else Swal.fire('Atención', data.message || 'No se pudo eliminar la asignación.', 'warning');
        });
    });
}

function initControlPersonalMarking() {
    const workerField = document.getElementById('markWorkerId');
    const entryBtn = document.getElementById('markEntryBtn');
    const exitBtn = document.getElementById('markExitBtn');
    const camera = document.getElementById('markCamera');
    const canvas = document.getElementById('markCanvas');
    const photoPreview = document.getElementById('markPhotoPreview');
    const mapElement = document.getElementById('markMap');
    if (!workerField || !entryBtn || !exitBtn || !camera || !canvas || !mapElement) return;

    let context = null;
    let map = null;
    let locationMarker = null;
    let currentMarker = null;
    let radiusCircle = null;
    let currentPosition = null;
    let cameraStream = null;
    let photoData = '';

    function value(id, text) {
        const element = document.getElementById(id);
        if (element) element.textContent = text || '-';
    }

    function renderStatuses(items) {
        const panel = document.getElementById('markStatusPanel');
        if (!panel) return;
        panel.innerHTML = items.map((item) => `<span class="badge ${item.className}">${escapeHtml(item.text)}</span>`).join('');
    }

    function formatTime(time) {
        return time ? String(time).slice(0, 5) : '-';
    }

    function metersBetween(lat1, lon1, lat2, lon2) {
        const earthRadius = 6371000;
        const toRad = (number) => number * Math.PI / 180;
        const dLat = toRad(lat2 - lat1);
        const dLon = toRad(lon2 - lon1);
        const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
        return earthRadius * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function initMarkMap() {
        if (!window.L || map) return;
        map = L.map('markMap').setView([-12.0464, -77.0428], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);
    }

    function updateMap() {
        initMarkMap();
        if (!map || !context?.assignment) return;

        const assignment = context.assignment;
        const locationLat = Number(assignment.latitude);
        const locationLng = Number(assignment.longitude);
        const radius = Number(assignment.radius_meters || 100);
        const locationPoint = [locationLat, locationLng];

        if (!locationMarker) locationMarker = L.marker(locationPoint).addTo(map);
        locationMarker.setLatLng(locationPoint).bindPopup('Punto asignado');

        if (!radiusCircle) {
            radiusCircle = L.circle(locationPoint, { radius, color: '#1457d9', fillColor: '#1457d9', fillOpacity: 0.12 }).addTo(map);
        }
        radiusCircle.setLatLng(locationPoint);
        radiusCircle.setRadius(radius);

        if (currentPosition) {
            const currentPoint = [currentPosition.latitude, currentPosition.longitude];
            if (!currentMarker) currentMarker = L.marker(currentPoint).addTo(map);
            currentMarker.setLatLng(currentPoint).bindPopup('Ubicación actual');
            map.fitBounds(L.latLngBounds([locationPoint, currentPoint]).pad(0.35));
        } else {
            map.setView(locationPoint, 16);
        }
        setTimeout(() => map.invalidateSize(), 150);
    }

    async function loadMarkContext() {
        const workerId = workerField.value || '';
        if (!workerId) {
            context = null;
            renderStatuses([{ text: 'Seleccione trabajador', className: 'text-bg-secondary' }]);
            return;
        }

        const response = await fetch(`${BASE_URL}/servicios/control_personal/contexto_marcacion.php?worker_id=${encodeURIComponent(workerId)}`);
        const data = await response.json();
        if (!data.ok) {
            context = null;
            renderStatuses([{ text: data.message || 'Sin asignación activa', className: 'text-bg-warning' }]);
            return;
        }

        context = data;
        const assignment = data.assignment;
        const day = data.schedule_day || {};
        value('markWorkerName', `${assignment.full_name} - ${assignment.document_number}`);
        value('markLocationName', assignment.location_name);
        value('markScheduleName', assignment.schedule_name);
        value('markActivity', assignment.activity || '-');
        value('markEntryWindow', `${formatTime(day.entry_start)} - ${formatTime(day.entry_end)} (${Number(day.tolerance_minutes || 0)} min tolerancia)`);
        value('markExitWindow', `${formatTime(day.exit_start)} - ${formatTime(day.exit_end)}`);
        value('markRadius', `${assignment.radius_meters} metros`);
        entryBtn.disabled = data.marks.some((mark) => mark.mark_type === 'entrada');
        exitBtn.disabled = data.marks.some((mark) => mark.mark_type === 'salida') || !data.marks.some((mark) => mark.mark_type === 'entrada');
        renderStatuses([
            { text: day.id ? 'Horario disponible' : 'Sin horario para hoy', className: day.id ? 'text-bg-success' : 'text-bg-warning' },
            { text: entryBtn.disabled ? 'Entrada registrada' : 'Entrada pendiente', className: entryBtn.disabled ? 'text-bg-primary' : 'text-bg-secondary' },
            { text: exitBtn.disabled ? 'Salida no disponible/registrada' : 'Salida disponible', className: exitBtn.disabled ? 'text-bg-secondary' : 'text-bg-primary' },
        ]);
        updateMap();
    }

    function requestPosition() {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                reject(new Error('El navegador no soporta ubicacion GPS.'));
                return;
            }
            navigator.geolocation.getCurrentPosition(resolve, reject, {
                enableHighAccuracy: true,
                timeout: 20000,
                maximumAge: 0
            });
        });
    }

    async function requestCamera() {
        if (!window.isSecureContext) {
            throw new Error('La camara del celular requiere HTTPS. En una IP local como 192.168.1.5 el navegador bloquea el acceso por seguridad.');
        }
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error('El navegador no soporta acceso a camara.');
        }
        if (cameraStream) {
            return cameraStream;
        }
        cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
        camera.srcObject = cameraStream;
        await camera.play();
        return cameraStream;
    }

    function capturePhoto() {
        const width = camera.videoWidth || 640;
        const height = camera.videoHeight || 480;
        canvas.width = width;
        canvas.height = height;
        const context2d = canvas.getContext('2d');
        context2d.drawImage(camera, 0, 0, width, height);
        photoData = canvas.toDataURL('image/jpeg', 0.86);
        if (photoPreview) {
            photoPreview.src = photoData;
            photoPreview.classList.remove('d-none');
        }
        return photoData;
    }

    async function reverseCurrentAddress(lat, lng) {
        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`);
            const data = await response.json();
            return data.display_name || '';
        } catch (error) {
            return '';
        }
    }

    async function prepareMarking() {
        if (!context) {
            await loadMarkContext();
        }
        if (!context?.assignment) {
            throw new Error('No hay asignacion activa para marcar.');
        }

        await requestCamera();
        const position = await requestPosition();
        currentPosition = {
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy,
        };

        const assignment = context.assignment;
        const distance = metersBetween(
            currentPosition.latitude,
            currentPosition.longitude,
            Number(assignment.latitude),
            Number(assignment.longitude)
        );

        value('markAccuracy', `${Math.round(currentPosition.accuracy)} metros`);
        value('markDistance', `${Math.round(distance)} metros`);

        const within = distance <= Number(assignment.radius_meters || 0);
        const precise = currentPosition.accuracy <= Number(assignment.radius_meters || 0);
        renderStatuses([
            { text: within ? 'Dentro del radio' : 'Fuera del radio', className: within ? 'text-bg-success' : 'text-bg-danger' },
            { text: precise ? 'GPS preciso' : 'GPS impreciso', className: precise ? 'text-bg-success' : 'text-bg-warning' },
        ]);

        updateMap();
        const address = await reverseCurrentAddress(currentPosition.latitude, currentPosition.longitude);
        return { distance, address };
    }

    async function mark(type) {
        const button = type === 'entrada' ? entryBtn : exitBtn;
        button.disabled = true;
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Marcando...';

        try {
            const prepared = await prepareMarking();
            const image = capturePhoto();
            const form = new FormData();
            form.append('csrf_token', csrf);
            form.append('worker_id', workerField.value || '');
            form.append('mark_type', type);
            form.append('latitude', String(currentPosition.latitude));
            form.append('longitude', String(currentPosition.longitude));
            form.append('accuracy', String(currentPosition.accuracy));
            form.append('distance_meters', String(prepared.distance));
            form.append('address', prepared.address || '');
            form.append('observations', document.getElementById('markObservations')?.value || '');
            form.append('photo_data', image);

            const response = await fetch(`${BASE_URL}/servicios/control_personal/registrar_marcacion.php`, { method: 'POST', body: form });
            const data = await response.json();
            if (!data.ok) {
                Swal.fire('Atención', data.message || 'No se pudo registrar la marcación.', 'warning');
                return;
            }
            await Swal.fire('Registrado', `${data.message}<br>Distancia: ${data.distance_meters} m<br>Estado: ${data.status}`, 'success');
            window.location.reload();
        } catch (error) {
            Swal.fire('Atención', `${error.message || 'No se pudo marcar.'} Debe habilitar ubicación y cámara para registrar asistencia.`, 'warning');
        } finally {
            button.disabled = false;
            button.innerHTML = originalText;
            loadMarkContext();
        }
    }

    workerField.addEventListener('change', loadMarkContext);
    entryBtn.addEventListener('click', () => mark('entrada'));
    exitBtn.addEventListener('click', () => mark('salida'));
    if (workerField.value) loadMarkContext();
}






















