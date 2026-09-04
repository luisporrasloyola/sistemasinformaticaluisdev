const BASE_URL = window.APP_URL || window.location.origin;
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
const personalServiceUrl = (file) => `${window.personalServiceBase || BASE_URL + "/servicios"}/${file}`;

const attendanceReportNoteForm = document.getElementById('attendanceReportNoteForm');
if (attendanceReportNoteForm) {
    attendanceReportNoteForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = attendanceReportNoteForm.querySelector('button[type="submit"]');
        const body = new FormData(attendanceReportNoteForm);
        body.append('csrf_token', csrf);
        button.disabled = true;
        try {
            const response = await fetch(`${window.APP_URL}/servicios/control_personal/guardar_observacion_reporte_asistencia.php`, { method: 'POST', body });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo guardar la observación.');
            await Swal.fire({ icon: 'success', title: 'Observación guardada', text: 'También se mostrará en el PDF.', timer: 1600, showConfirmButton: false });
        } catch (error) {
            Swal.fire('Atención', error.message || 'No se pudo guardar la observación.', 'warning');
        } finally {
            button.disabled = false;
        }
    });
}
let currentWorkerId = null;
let currentPositionId = null;
let requirementModal = null;
let readOnlyMode = false;
let canObserveCurrentRequirement = true;
let currentWorkerPositions = [];
let replicateRequirementModal = null;

function localDateValue(date = new Date()) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
}

function documentAttachmentHeader(row) {
    const path = String(row?.archivo_path || '');
    const fileName = String(row?.archivo_nombre_original || path || 'archivo');
    const isImage = /\.(?:jpe?g|png|webp)$/i.test(fileName);
    const preview = isImage
        ? `<a class="requirement-image-preview" target="_blank" href="${BASE_URL}/${path}"><img src="${BASE_URL}/${path}" alt="Vista previa del adjunto"></a>`
        : '';
    const icon = isImage ? 'fa-file-image text-primary' : 'fa-file-pdf text-danger';
    return `${preview}<i class="fa-solid ${icon} me-2"></i><strong>${escapeHtml(fileName)}</strong>`;
}

function initAttendanceMatrixDetail() {
    const modalElement = document.getElementById('attendanceMatrixDetailModal');
    if (!modalElement) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const fields = {
        date: document.getElementById('matrixDetailDate'),
        worker: document.getElementById('matrixDetailWorker'),
        company: document.getElementById('matrixDetailCompany'),
        badge: document.getElementById('matrixDetailBadge'),
        status: document.getElementById('matrixDetailStatus'),
        entry: document.getElementById('matrixDetailEntry'),
        exit: document.getElementById('matrixDetailExit'),
        location: document.getElementById('matrixDetailLocation'),
        incidents: document.getElementById('matrixDetailIncidents'),
        manualWorkerId: document.getElementById('matrixManualWorkerId'),
        manualDate: document.getElementById('matrixManualDate'),
        manualEntry: document.getElementById('matrixManualEntry'),
        manualExit: document.getElementById('matrixManualExit'),
        manualResultPunctual: document.getElementById('matrixManualResultPunctual'),
        manualResultLate: document.getElementById('matrixManualResultLate'),
        manualResultAbsent: document.getElementById('matrixManualResultAbsent'),
        manualLocation: document.getElementById('matrixManualLocation'),
        manualReason: document.getElementById('matrixManualReason'),
        manualAudit: document.getElementById('matrixManualAudit'),
        manualAuditUser: document.getElementById('matrixManualAuditUser'),
        manualAuditDate: document.getElementById('matrixManualAuditDate'),
        manualLocked: document.getElementById('matrixManualLocked'),
        manualLockedMessage: document.getElementById('matrixManualLockedMessage')
    };

    const setManualAbsenceMode = (isAbsent) => {
        modalElement.querySelectorAll('.attendance-mark-input').forEach((container) => {
            container.classList.toggle('attendance-mark-input-disabled', isAbsent);
            container.querySelectorAll('input, select').forEach((control) => { control.disabled = isAbsent; });
        });
        if (fields.manualLocation && window.jQuery && jQuery.fn.select2 && jQuery(fields.manualLocation).hasClass('select2-hidden-accessible')) {
            jQuery(fields.manualLocation).trigger('change.select2');
        }
    };
    const openDetail = (cell) => {
        fields.date.textContent = cell.dataset.date || '';
        fields.worker.textContent = cell.dataset.worker || '';
        fields.company.textContent = cell.dataset.company || 'Sin empresa';
        fields.status.textContent = cell.dataset.status || 'Sin marcaciones';
        fields.entry.textContent = cell.dataset.entry || '-';
        fields.exit.textContent = cell.dataset.exit || '-';
        fields.location.textContent = cell.dataset.location || '-';
        fields.incidents.textContent = cell.dataset.incidents || 'Sin incidencias';
        if (fields.manualWorkerId) {
            fields.manualWorkerId.value = cell.dataset.workerId || '';
            fields.manualDate.value = cell.dataset.dateIso || '';
            fields.manualEntry.value = cell.dataset.entry && cell.dataset.entry !== '-' ? cell.dataset.entry : '';
            fields.manualExit.value = cell.dataset.exit && cell.dataset.exit !== '-' ? cell.dataset.exit : '';
            const isAbsentResult = cell.dataset.code === 'F';
            const isLateResult = cell.dataset.code === 'T' || cell.dataset.code === 'ATSA';
            fields.manualResultPunctual.checked = !isLateResult && !isAbsentResult;
            fields.manualResultLate.checked = isLateResult;
            fields.manualResultAbsent.checked = isAbsentResult;
            setManualAbsenceMode(isAbsentResult);
            fields.manualLocation.value = cell.dataset.locationId || '';
            if (window.jQuery && jQuery.fn.select2 && jQuery(fields.manualLocation).hasClass('select2-hidden-accessible')) {
                jQuery(fields.manualLocation).trigger('change.select2');
            }
            fields.manualReason.value = cell.dataset.manualReason || '';
            const adjustedBy = (cell.dataset.adjustedBy || '').trim();
            const adjustedAt = (cell.dataset.adjustedAt || '').trim();
            if (fields.manualAudit) {
                fields.manualAudit.classList.toggle('d-none', adjustedBy === '');
                fields.manualAuditUser.textContent = adjustedBy;
                fields.manualAuditDate.textContent = adjustedAt ? 'Actualizado el ' + adjustedAt : '';
            }
        }

        const manualAllowed = cell.dataset.manualEnabled === '1';
        if (manualForm) manualForm.classList.toggle('d-none', !manualAllowed);
        if (fields.manualLocked) {
            fields.manualLocked.classList.toggle('d-none', manualAllowed);
            if (!manualAllowed && fields.manualLockedMessage) {
                fields.manualLockedMessage.textContent = cell.dataset.manualLock === 'future'
                    ? 'No se pueden registrar correcciones en fechas futuras.'
                    : 'La jornada actual todavía está en curso.';
            }
        }
        const code = cell.dataset.code || '-';
        fields.badge.textContent = code;
        fields.badge.className = 'badge attendance-detail-badge';
        if (code === 'A') fields.badge.classList.add('detail-badge-ok');
        else if (code === 'T') fields.badge.classList.add('detail-badge-warning');
        else if (code === 'ASA') fields.badge.classList.add('detail-badge-early-exit');
        else if (code === 'ATSA' || code === 'F') fields.badge.classList.add('detail-badge-danger');
        else fields.badge.classList.add('detail-badge-neutral');

        modal.show();
    };

    fields.manualResultPunctual?.addEventListener('change', () => setManualAbsenceMode(false));
    fields.manualResultLate?.addEventListener('change', () => setManualAbsenceMode(false));
    fields.manualResultAbsent?.addEventListener('change', () => setManualAbsenceMode(true));
    const manualForm = document.getElementById('attendanceManualCorrectionForm');
    if (manualForm && manualForm.dataset.bound !== '1') {
        manualForm.dataset.bound = '1';
        manualForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = manualForm.querySelector('button[type="submit"]');
            const body = new FormData(manualForm);
            body.append('csrf_token', csrf);
            button.disabled = true;
            try {
                const response = await fetch(`${window.APP_URL}/servicios/control_personal/guardar_marcacion_manual.php`, { method: 'POST', body });
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo guardar la corrección.');
                await Swal.fire({ icon: 'success', title: 'Asistencia actualizada', text: data.message, timer: 1800, showConfirmButton: false });
                window.location.reload();
            } catch (error) {
                Swal.fire('Atención', error.message || 'No se pudo guardar la corrección.', 'warning');
            } finally {
                button.disabled = false;
            }
        });
    }
    document.querySelectorAll('.js-attendance-matrix-cell').forEach((cell) => {
        cell.addEventListener('click', () => openDetail(cell));
        cell.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            openDetail(cell);
        });
    });
}

function initAttendanceDashboardLiveUpdates() {
    const versionElement = document.getElementById('attendanceLiveKpis');
    if (!versionElement) return;

    const liveSectionIds = ['attendanceLiveKpis', 'attendanceLiveMatrix', 'attendanceLiveSummary'];
    let lastVersion = Number(versionElement.dataset.liveVersion || 0);
    let refreshing = false;

    const refreshDashboardSections = async (detectedVersion) => {
        if (refreshing) return;
        refreshing = true;

        const matrixScrollLeft = document.querySelector('#attendanceLiveMatrix .attendance-matrix-wrap')?.scrollLeft || 0;
        const summaryScrollLeft = document.querySelector('#attendanceLiveSummary .attendance-monthly-summary-wrap')?.scrollLeft || 0;
        const pageScrollY = window.scrollY;

        try {
            const response = await fetch(window.location.href, {
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) throw new Error('No se pudo actualizar el dashboard.');

            const html = await response.text();
            const freshDocument = new DOMParser().parseFromString(html, 'text/html');
            const replacements = liveSectionIds.map((id) => ({
                current: document.getElementById(id),
                fresh: freshDocument.getElementById(id)
            }));
            if (replacements.some(({ current, fresh }) => !current || !fresh)) {
                throw new Error('La respuesta del dashboard está incompleta.');
            }

            replacements.forEach(({ current, fresh }) => current.replaceWith(fresh));

            const freshVersion = Number(document.getElementById('attendanceLiveKpis')?.dataset.liveVersion || detectedVersion);
            lastVersion = Math.max(detectedVersion, freshVersion);
            const matrixWrap = document.querySelector('#attendanceLiveMatrix .attendance-matrix-wrap');
            const summaryWrap = document.querySelector('#attendanceLiveSummary .attendance-monthly-summary-wrap');
            if (matrixWrap) matrixWrap.scrollLeft = matrixScrollLeft;
            if (summaryWrap) summaryWrap.scrollLeft = summaryScrollLeft;
            window.scrollTo({ top: pageScrollY, behavior: 'auto' });
            initAttendanceMatrixDetail();
        } catch (error) {
            console.warn(error.message || error);
        } finally {
            refreshing = false;
        }
    };

    window.addEventListener('storage', (event) => {
        if (event.key === 'attendance-marks-updated-at' && event.newValue) {
            refreshDashboardSections(lastVersion);
        }
    });
}

function initSidebarActiveLink() {
    const currentPath = decodeURIComponent(window.location.pathname).replace(/\/+$/, '').toLowerCase();

    document.querySelectorAll('#sidebar a.nav-link[href]').forEach((link) => {
        const linkPath = decodeURIComponent(new URL(link.href, window.location.origin).pathname)
            .replace(/\/+$/, '')
            .toLowerCase();
        if (linkPath !== currentPath) return;

        link.classList.add('active');
        link.setAttribute('aria-current', 'page');

        const collapse = link.closest('.collapse');
        if (!collapse?.id) return;
        collapse.classList.add('show');

        const parent = document.querySelector(`#sidebar [data-bs-target="#${collapse.id}"]`);
        parent?.classList.add('active');
        parent?.classList.remove('collapsed');
        parent?.setAttribute('aria-expanded', 'true');
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initSidebarActiveLink();
    initDevelopmentPhaseLinks();
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
    initEmpresaMaquirentaDatos();
    initEmpresaModuloDocumentos();
    initEmpresaSeguridadDocumentos();
    initEmpresaGenericModules();
    initDashboardEjecutivo();
    initUsuariosModule();
    initAttendanceControl();
    initAttendanceMatrixDetail();
    initAttendanceDashboardLiveUpdates();
    initControlPersonalSchedules();
    initPersonnelProgramming();
    initScheduleJourneysCalendar();
    initControlPersonalCalendar();
    initControlPersonalLocations();
    initControlPersonalAssignments();
    initControlPersonalMarking();
    initNotifications();
    initRouteNotifications();
    initObservationNotifications();

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

        $('.select2-searchable').each(function () {
            const $field = $(this);
            if ($field.hasClass('select2-hidden-accessible')) return;
            const $modal = $field.closest('.modal');
            const $manualLocation = $field.attr('id') === 'matrixManualLocation'
                ? $field.closest('.attendance-manual-location')
                : $();
            $field.select2({
                theme: 'bootstrap4',
                width: '100%',
                dropdownParent: $manualLocation.length ? $manualLocation : ($modal.length ? $modal : $(document.body)),
                placeholder: $field.data('placeholder') || 'Buscar',
                minimumResultsForSearch: 0,
                templateSelection: (option) => {
                    if ($field.attr('id') !== 'personnelProgramAssignment' || !option.element) return option.text;
                    return $(option.element).data('selection-label') || option.text;
                },
                language: {
                    noResults: () => $field.data('no-results') || 'No se encontraron resultados',
                    searching: () => 'Buscando...'
                }
            });
            $field.on('select2:open', () => {
                document.querySelector('.select2-container--open .select2-search__field')?.focus();
            });
        });
    }
});

function initDevelopmentPhaseLinks() {
    document.querySelectorAll('.js-development-link').forEach((link) => {
        link.setAttribute('aria-disabled', 'true');
        link.addEventListener('click', (event) => {
            event.preventDefault();
            Swal.fire({
                icon: 'info',
                title: 'Funcionalidad en desarrollo',
                text: 'Este submódulo se encuentra en preparación y estará disponible en una próxima fase del sistema.',
                confirmButtonText: 'Entendido'
            });
        });
    });
}

function initPersonnelProgramming() {
    const calendarElement = document.getElementById('personnelProgramCalendar');
    if (!calendarElement || !window.FullCalendar) return;

    const modalElement = document.getElementById('personnelProgramModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const form = document.getElementById('personnelProgramForm');
    const idField = document.getElementById('personnelProgramId');
    const assignmentField = document.getElementById('personnelProgramAssignment');
    const singleAssignmentField = document.getElementById('personnelProgramSingleAssignment');
    const singleWorkerField = document.getElementById('personnelProgramSingleWorkerField');
    const routeWorkersField = document.getElementById('personnelProgramRouteWorkersField');
    const locationField = document.getElementById('personnelProgramLocation');
    const scheduleField = document.getElementById('personnelProgramSchedule');
    const locationFieldContainer = document.getElementById('personnelProgramLocationField');
    const scheduleFieldContainer = document.getElementById('personnelProgramScheduleField');
    const dateField = document.getElementById('personnelProgramDate');
    const activityField = document.getElementById('personnelProgramActivity');
    const activityFieldContainer = document.getElementById('personnelProgramActivityField');
    const notesField = document.getElementById('personnelProgramNotes');
    const extraEntry = document.getElementById('programExtraEntry');
    const extraAdvance = document.getElementById('programExtraAdvance');
    const extraTolerance = document.getElementById('programExtraTolerance');
    const extraExit = document.getElementById('programExtraExit');
    const scheduleSourceField = document.getElementById('personnelProgramScheduleSource');
    const specialScheduleFields = document.getElementById('extraordinaryScheduleFields');
    const entryRulePreview = document.getElementById('programEntryRulePreview');
    const exitRulePreview = document.getElementById('programExitRulePreview');
    const cancelButton = document.getElementById('cancelProgramBtn');
    const workerFilter = document.getElementById('programWorkerFilter');
    const stopsContainer = document.getElementById('programStopsContainer');
    const stopsEmpty = document.getElementById('programStopsEmpty');
    const addStopButton = document.getElementById('addProgramStopBtn');
    const routeField = document.getElementById('personnelProgramRouteField');
    const scheduleModeField = document.getElementById('personnelProgramScheduleModeField');
    const workersLabel = document.getElementById('personnelProgramWorkersLabel');
    const workersHelp = document.getElementById('personnelProgramWorkersHelp');
    const workersCount = document.getElementById('personnelProgramWorkersCount');
    const notesHelp = document.getElementById('personnelProgramNotesHelp');
    const priorityNotice = document.getElementById('personnelProgramPriorityNotice');
    const modalTitle = modalElement.querySelector('.modal-title');
    const modalSubtitle = modalElement.querySelector('.modal-title + small');
    const csrf = form.querySelector('[name="csrf_token"]').value;
    let editorMode = 'special';

    function configureProgramEditor(mode) {
        editorMode = mode === 'route' ? 'route' : 'special';
        const route = editorMode === 'route';
        singleWorkerField?.classList.toggle('d-none', route);
        routeWorkersField?.classList.toggle('d-none', !route);
        if (singleAssignmentField) singleAssignmentField.required = !route;
        if (assignmentField) assignmentField.required = route;
        routeField?.classList.toggle('d-none', !route);
        locationFieldContainer?.classList.toggle('d-none', route);
        scheduleFieldContainer?.classList.toggle('d-none', route);
        if (locationField) locationField.required = !route;
        if (scheduleField) scheduleField.required = !route;
        activityFieldContainer?.classList.toggle('d-none', route);
        scheduleModeField?.classList.add('d-none');
        if (scheduleSourceField) scheduleSourceField.value = route ? 'template' : 'extraordinary';
        if (modalTitle) modalTitle.textContent = route ? 'Nuevo recorrido de trabajo' : 'Nueva programación especial';
        if (modalSubtitle) modalSubtitle.textContent = route ? 'Organice los lugares y actividades de una misma jornada.' : 'Configure un horario diferente para una fecha específica.';
        if (workersLabel) workersLabel.innerHTML = route ? '<i class="fa-solid fa-users me-1"></i>Trabajadores' : 'Trabajador';
        if (workersHelp) workersHelp.textContent = route
            ? 'Busque por nombre, documento o lugar.'
            : 'Seleccione un trabajador para esta programación especial.';
        notesHelp?.classList.toggle('d-none', route);
        priorityNotice?.classList.toggle('d-none', route);
        updateProgramScheduleMode();
    }

    function addProgramStop(stop = {}) {
        if (!stopsContainer) return;
        const row = document.createElement('div');
        row.className = 'program-stop-row border rounded-3 bg-white p-2';
        const locationOptions = (window.PERSONNEL_PROGRAM_LOCATIONS || []).map(location =>
            `<option value="${Number(location.id)}" ${Number(stop.locationId || 0) === Number(location.id) ? 'selected' : ''}>${escapeHtml(location.name)}</option>`
        ).join('');
        row.innerHTML = `<div class="row g-2 align-items-end">
            <div class="col-md-4"><label class="form-label small mb-1">Siguiente lugar</label><select class="form-select form-select-sm js-program-stop-location" name="stop_location_ids[]" required><option value="">Buscar lugar</option>${locationOptions}</select></div>
            <div class="col-md-4"><label class="form-label small mb-1">Actividad</label><input class="form-control form-control-sm" name="stop_activities[]" maxlength="255" value="${escapeHtml(stop.activity || '')}" placeholder="Ej.: Lavado del camión grúa" required></div>
            <div class="col-md-3"><label class="form-label small mb-1 program-stop-arrival-label">Llegada estimada</label><input class="form-control form-control-sm" type="time" name="stop_estimated_times[]" value="${escapeHtml(stop.estimatedTime || '')}" title="Hora estimada de llegada al lugar"></div>
            <div class="col-md-1 d-grid"><button class="btn btn-sm btn-outline-danger js-remove-program-stop" type="button" title="Quitar lugar"><i class="fa-solid fa-trash-can"></i></button></div>
        </div>`;
        const removeButton = row.querySelector('.js-remove-program-stop');
        // Toda parada cargada al abrir una programación existente ya forma
        // parte del recorrido, incluso si una respuesta antigua no incluyera su id.
        const persistedStop = Number(stop.id || 0) > 0 || (Boolean(idField.value) && Object.keys(stop).length > 0);
        removeButton?.addEventListener('click', async () => {
            const stopTripCount = Number(stop.tripCount || 0);
            const stopCompletionCount = Number(stop.completionCount || 0);
            if (persistedStop && (stopTripCount > 0 || stopCompletionCount > 0)) {
                const reasons = [
                    stopTripCount > 0 ? 'el trabajador ya inició el desplazamiento o confirmó su llegada a este lugar' : '',
                    stopCompletionCount > 0 ? 'ya finalizó un trabajo en este lugar' : ''
                ].filter(Boolean);
                await Swal.fire({
                    icon: 'info',
                    title: 'Este lugar no se puede quitar',
                    html: `<p class="mb-2">${escapeHtml(reasons.join(', '))}.</p><small class="text-muted">Puede actualizar la actividad o la llegada estimada, pero el lugar debe conservarse como parte del historial.</small>`,
                    confirmButtonText: 'Entendido'
                });
                return;
            }
            const locationSelect = row.querySelector('.js-program-stop-location');
            if (window.jQuery && locationSelect && jQuery(locationSelect).hasClass('select2-hidden-accessible')) {
                jQuery(locationSelect).select2('destroy');
            }
            row.remove();
            stopsEmpty?.classList.toggle('d-none', !!stopsContainer.children.length);
        });
        stopsContainer.appendChild(row);
        const locationSelect = row.querySelector('.js-program-stop-location');
        if (window.jQuery && jQuery.fn.select2 && locationSelect) {
            jQuery(locationSelect).select2({
                theme: 'bootstrap4',
                width: '100%',
                dropdownParent: jQuery(modalElement),
                placeholder: 'Buscar lugar',
                minimumResultsForSearch: 0,
                language: {
                    noResults: () => 'No se encontraron lugares',
                    searching: () => 'Buscando...'
                }
            });
        }
        stopsEmpty?.classList.add('d-none');
    }

    function setProgramStops(stops = []) {
        if (!stopsContainer) return;
        stopsContainer.innerHTML = '';
        (Array.isArray(stops) ? stops : []).forEach(addProgramStop);
        stopsEmpty?.classList.toggle('d-none', !!stopsContainer.children.length);
    }

    function scheduleAdvanceMinutes(entryTime, entryStart) {
        const minutes = (value) => { const [h, m] = String(value || '00:00').split(':').map(Number); return h * 60 + m; };
        return (minutes(entryTime) - minutes(entryStart) + 1440) % 1440;
    }

    function programTimeWithOffset(time, offset) {
        const [hours, minutes] = String(time || '').split(':').map(Number);
        if (![hours, minutes].every(Number.isFinite)) return '';
        const total = (((hours * 60 + minutes + offset) % 1440) + 1440) % 1440;
        return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
    }

    function updateProgramRulePreview() {
        const advance = Math.max(0, Number(extraAdvance?.value || 0));
        const tolerance = Math.max(0, Number(extraTolerance?.value || 0));
        if (entryRulePreview) {
            entryRulePreview.textContent = extraEntry?.value
                ? `Puede marcar desde ${programTimeWithOffset(extraEntry.value, -advance)}. Se considera puntual hasta ${programTimeWithOffset(extraEntry.value, tolerance)}.`
                : 'Complete la hora de entrada para calcular la ventana.';
        }
        if (exitRulePreview) {
            exitRulePreview.textContent = extraExit?.value
                ? `Antes de ${extraExit.value} será salida anticipada. Desde ${extraExit.value}, salida normal.`
                : 'Complete la hora de salida.';
        }
    }

    function updateProgramScheduleMode() {
        const special = scheduleSourceField?.value === 'extraordinary';
        specialScheduleFields?.classList.toggle('d-none', !special);
        [extraEntry, extraAdvance, extraTolerance, extraExit].forEach(input => { if (input) input.required = special; });
    }

    function resetProgram(date = '', mode = 'special') {
        form.reset();
        assignmentField?.querySelectorAll('option[data-program-temporary="1"]').forEach(option => option.remove());
        idField.value = '';
        dateField.value = date || localDateValue();
        if (extraAdvance) extraAdvance.value = '30';
        if (extraTolerance) extraTolerance.value = '0';
        configureProgramEditor(mode);
        updateProgramScheduleMode();
        updateProgramRulePreview();
        setProgramStops([]);
        cancelButton.classList.add('d-none');
        if (window.jQuery) {
            jQuery(assignmentField).val([]).trigger('change');
            jQuery(singleAssignmentField).val('').trigger('change');
            jQuery(locationField).val('').trigger('change');
            jQuery(scheduleField).val('').trigger('change');
        }
    }

    function ensureEditedProgramWorkerOption(props) {
        if (!assignmentField || !props.assignmentId) return;
        const assignmentId = String(props.assignmentId);
        assignmentField.querySelectorAll('option[data-program-temporary="1"]').forEach(option => {
            if (option.value !== assignmentId) option.remove();
        });
        if (Array.from(assignmentField.options).some(option => option.value === assignmentId)) return;

        // El recorrido puede seguir asociado a una asignación histórica que ya
        // no forma parte del listado de asignaciones activas. La representamos
        // únicamente durante la edición para conservar su vínculo original.
        const option = new Option(
            `${props.worker || 'Trabajador'} · ${props.location || 'Lugar no disponible'}`,
            assignmentId,
            false,
            false
        );
        option.dataset.workerId = String(props.workerId || '');
        option.dataset.selectionLabel = `${props.worker || 'Trabajador'} · ${props.location || 'Lugar no disponible'}`;
        option.dataset.locationId = String(props.locationId || '');
        option.dataset.scheduleId = String(props.scheduleId || '');
        option.dataset.programTemporary = '1';
        assignmentField.appendChild(option);
    }

    function openProgramEvent(event) {
        const props = event.extendedProps || {};
        configureProgramEditor((props.stops || []).length ? 'route' : 'special');
        if (modalTitle) modalTitle.textContent = editorMode === 'route' ? 'Editar recorrido de trabajo' : 'Editar programación especial';
        idField.value = event.id;
        dateField.value = event.startStr.slice(0, 10);
        activityField.value = props.activity || '';
        notesField.value = props.notes || '';
        cancelButton.dataset.hasProgramMarks = props.hasProgramMarks ? '1' : '0';
        cancelButton.dataset.hasProgramTrips = props.hasProgramTrips ? '1' : '0';
        cancelButton.dataset.hasWorkCompletions = props.hasWorkCompletions ? '1' : '0';
        cancelButton.dataset.programMarksCount = String(Number(props.programMarksCount || 0));
        cancelButton.dataset.programTripsCount = String(Number(props.programTripsCount || 0));
        cancelButton.dataset.workCompletionsCount = String(Number(props.workCompletionsCount || 0));
        setProgramStops(props.stops || []);
        if (extraEntry) extraEntry.value = props.entryTime || '';
        if (extraExit) extraExit.value = props.exitTime || '';
        if (extraTolerance) extraTolerance.value = String(props.tolerance || 0);
        if (extraAdvance) extraAdvance.value = String(scheduleAdvanceMinutes(props.entryTime, props.entryStart));
        if (scheduleSourceField) scheduleSourceField.value = props.scheduleSource || 'template';
        updateProgramScheduleMode();
        updateProgramRulePreview();
        ensureEditedProgramWorkerOption(props);
        if (window.jQuery) {
            if (editorMode === 'route') {
                // El selector individual está oculto en recorridos. No debe
                // dispararse porque una asignación histórica podría no existir
                // allí y terminaría limpiando la selección múltiple.
                jQuery(singleAssignmentField).val('').trigger('change.select2');
                const assignmentId = String(props.assignmentId || '');
                Array.from(assignmentField.options).forEach(option => {
                    option.selected = option.value === assignmentId;
                });
                // Actualiza únicamente la interfaz de Select2. Los efectos del
                // cambio se ejecutan explícitamente para evitar que otro campo
                // oculto vuelva a borrar al trabajador seleccionado.
                jQuery(assignmentField).trigger('change.select2');
                useAssignmentDefaults();
                updateSelectedWorkersCount();
            } else {
                jQuery(singleAssignmentField).val(String(props.assignmentId || '')).trigger('change');
            }
            jQuery(locationField).val(String(props.locationId || '')).trigger('change');
            jQuery(scheduleField).val(String(props.scheduleId || '')).trigger('change');
        } else {
            if (editorMode === 'route') {
                Array.from(assignmentField.options).forEach(option => { option.selected = option.value === String(props.assignmentId || ''); });
                updateSelectedWorkersCount();
            } else if (singleAssignmentField) {
                singleAssignmentField.value = String(props.assignmentId || '');
            }
            locationField.value = props.locationId || '';
            scheduleField.value = props.scheduleId || '';
        }
        cancelButton.classList.remove('d-none');
        modal.show();
    }

    const calendar = new FullCalendar.Calendar(calendarElement, {
        locale: 'es',
        initialView: 'dayGridMonth',
        firstDay: 1,
        height: 'auto',
        selectable: true,
        dayMaxEvents: true,
        displayEventTime: false,
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listMonth' },
        buttonText: { today: 'Hoy', month: 'Mes', week: 'Semana', list: 'Lista' },
        events: window.PERSONNEL_PROGRAM_EVENTS || [],
        dateClick(info) {
            // Un clic sobre una zona libre crea una programación nueva para la
            // fecha seleccionada, sin heredar al trabajador del último evento.
            resetProgram(info.dateStr, 'special');
            modal.show();
        },
        eventClick(info) {
            // Evitar que el clic sobre el nombre/evento llegue también a la celda
            // y vuelva a abrir el formulario como si fuera una programación nueva.
            info.jsEvent?.preventDefault();
            info.jsEvent?.stopPropagation();
            openProgramEvent(info.event);
        },
        eventDidMount(info) {
            const p = info.event.extendedProps || {};
            info.el.title = `${p.worker || ''}\n${p.schedule || ''} · ${p.location || ''}`;
        },
    });
    calendar.render();

    const routesCalendarElement = document.getElementById('personnelRoutesCalendar');
    let routesCalendar = null;
    if (routesCalendarElement) {
        routesCalendar = new FullCalendar.Calendar(routesCalendarElement, {
            locale: 'es', initialView: 'dayGridMonth', firstDay: 1, height: 'auto', selectable: true,
            dayMaxEvents: true, displayEventTime: false,
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listMonth' },
            buttonText: { today: 'Hoy', month: 'Mes', week: 'Semana', list: 'Lista' },
            events: window.PERSONNEL_ROUTE_EVENTS || [],
            dateClick(info) { resetProgram(info.dateStr, 'route'); modal.show(); },
            eventClick(info) { info.jsEvent?.preventDefault(); info.jsEvent?.stopPropagation(); openProgramEvent(info.event); },
            eventDidMount(info) { const p=info.event.extendedProps||{}; info.el.title=`${p.worker||''}\n${p.location||''} · ${(p.stops||[]).length} lugares adicionales`; }
        });
        routesCalendar.render();
    }

    [extraEntry, extraAdvance, extraTolerance, extraExit].forEach((input) => input?.addEventListener('input', updateProgramRulePreview));
    scheduleSourceField?.addEventListener('change', updateProgramScheduleMode);
    addStopButton?.addEventListener('click', () => addProgramStop());

    function useAssignmentDefaults() {
        const option = assignmentField?.selectedOptions?.[0];
        if (!option || !option.value) return;
        const locationId = option.dataset.locationId || '';
        const scheduleId = option.dataset.scheduleId || '';
        if (window.jQuery) {
            jQuery(locationField).val(locationId).trigger('change');
            jQuery(scheduleField).val(scheduleId).trigger('change');
        } else {
            locationField.value = locationId;
            scheduleField.value = scheduleId;
        }
    }

    function updateSelectedWorkersCount() {
        if (!workersCount) return;
        const count = Array.from(assignmentField?.selectedOptions || []).length;
        workersCount.textContent = count ? `${count} ${count === 1 ? 'seleccionado' : 'seleccionados'}` : 'Ninguno seleccionado';
    }

    // Select2 administra su propio ciclo de eventos. Vincular mediante jQuery
    // garantiza que los valores habituales se carguen tanto al buscar como al
    // seleccionar al trabajador con teclado o mouse.
    if (window.jQuery) {
        jQuery(assignmentField).off('change.personnelProgramDefaults').on('change.personnelProgramDefaults', () => { useAssignmentDefaults(); updateSelectedWorkersCount(); });
        jQuery(singleAssignmentField).off('change.personnelProgramSingle').on('change.personnelProgramSingle', () => {
            const value = singleAssignmentField.value || '';
            jQuery(assignmentField).val(value ? [value] : []).trigger('change');
        });
    } else {
        assignmentField?.addEventListener('change', () => { useAssignmentDefaults(); updateSelectedWorkersCount(); });
        singleAssignmentField?.addEventListener('change', () => {
            Array.from(assignmentField.options).forEach(option => { option.selected = option.value === singleAssignmentField.value; });
            useAssignmentDefaults();
        });
    }

    document.getElementById('newProgramBtn')?.addEventListener('click', () => { resetProgram('', 'special'); modal.show(); });
    document.getElementById('newRouteProgramBtn')?.addEventListener('click', () => { resetProgram('', 'route'); modal.show(); });
    const applyProgramWorkerFilter = () => {
        const workerId = String(workerFilter.value || '');
        let visibleCount = 0;
        calendar.getEvents().forEach(event => {
            const visible = !workerId || workerId === 'all' || String(event.extendedProps.workerId) === workerId;
            event.setProp('display', visible ? 'block' : 'none');
            if (visible) visibleCount += 1;
        });
        const count = document.querySelector('#specialProgramsCount span');
        if (count) count.textContent = `${visibleCount} ${visibleCount === 1 ? 'programación especial' : 'programaciones especiales'}`;
    };
    const routeWorkerFilter = document.getElementById('routeWorkerFilter');
    const applyRouteWorkerFilter = () => {
        const workerId=String(routeWorkerFilter?.value||'');
        let visibleCount = 0;
        routesCalendar?.getEvents().forEach(item => {
            const visible = !workerId || workerId === 'all' || String(item.extendedProps.workerId) === workerId;
            item.setProp('display', visible ? 'block' : 'none');
            if (visible) visibleCount += 1;
        });
        const count = document.querySelector('#routeProgramsCount span');
        if (count) count.textContent = `${visibleCount} ${visibleCount === 1 ? 'recorrido' : 'recorridos'}`;
    };
    if (window.jQuery) {
        jQuery(workerFilter).off('change.programWorkerFilter').on('change.programWorkerFilter', applyProgramWorkerFilter);
        jQuery(routeWorkerFilter).off('change.routeWorkerFilter').on('change.routeWorkerFilter', applyRouteWorkerFilter);
    } else {
        workerFilter?.addEventListener('change', applyProgramWorkerFilter);
        routeWorkerFilter?.addEventListener('change', applyRouteWorkerFilter);
    }
    applyProgramWorkerFilter();
    applyRouteWorkerFilter();

    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            const assignmentIds = Array.from(assignmentField.selectedOptions).map(option => option.value).filter(Boolean);
            if (!assignmentIds.length) throw new Error('Seleccione al menos un trabajador.');
            if (editorMode === 'special' && assignmentIds.length > 1) throw new Error('La programación especial se aplica a un trabajador. Para varios trabajadores utilice Recorridos de trabajo.');
            if (editorMode === 'route' && !stopsContainer?.children.length && !idField.value) throw new Error('Agregue al menos un lugar al recorrido.');
            let lastMessage = '';
            const targets = idField.value ? [assignmentIds[0]] : assignmentIds;
            for (const assignmentId of targets) {
                const body = new FormData(form);
                body.set('assignment_id', assignmentId);
                const response = await fetch(`${BASE_URL}/servicios/control_personal/guardar_programacion.php`, { method: 'POST', body });
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo guardar la programación.');
                lastMessage = data.message || '';
            }
            const message = targets.length > 1 ? `Se crearon ${targets.length} programaciones con el mismo recorrido.` : lastMessage;
            await Swal.fire('Programación guardada', message, 'success');
            window.location.reload();
        } catch (error) {
            Swal.fire('Atención', error.message || String(error), 'warning');
        } finally { button.disabled = false; }
    });

    cancelButton.addEventListener('click', async () => {
        const hasMarks = cancelButton.dataset.hasProgramMarks === '1';
        const hasTrips = cancelButton.dataset.hasProgramTrips === '1';
        const hasCompletions = cancelButton.dataset.hasWorkCompletions === '1';
        if (hasMarks || hasTrips || hasCompletions) {
            const marksCount = Number(cancelButton.dataset.programMarksCount || 0);
            const tripsCount = Number(cancelButton.dataset.programTripsCount || 0);
            const completionsCount = Number(cancelButton.dataset.workCompletionsCount || 0);
            const records = [
                hasMarks ? `<li><strong>${marksCount}</strong> ${marksCount === 1 ? 'marcación registrada' : 'marcaciones registradas'}</li>` : '',
                hasTrips ? `<li><strong>${tripsCount}</strong> ${tripsCount === 1 ? 'desplazamiento iniciado' : 'desplazamientos iniciados'}</li>` : '',
                hasCompletions ? `<li><strong>${completionsCount}</strong> ${completionsCount === 1 ? 'trabajo finalizado' : 'trabajos finalizados'}</li>` : ''
            ].filter(Boolean);
            await Swal.fire({
                icon: 'info',
                title: editorMode === 'route' ? 'No se puede eliminar el recorrido' : 'No se puede eliminar la programación',
                html: `<p class="mb-2">Este registro ya fue utilizado por el trabajador:</p><ul class="text-start mb-2">${records.join('')}</ul><small class="text-muted">Debe conservarse para no alterar su asistencia ni el historial laboral.</small>`,
                confirmButtonText: 'Entendido'
            });
            return;
        }
        const answer = await Swal.fire({
            icon: 'warning',
            title: editorMode === 'route' ? '¿Eliminar recorrido de trabajo?' : '¿Eliminar programación especial?',
            html: `<p class="mb-2">${editorMode === 'route' ? 'Este recorrido' : 'Esta programación'} todavía no tiene registros propios y será retirado del calendario.</p><small class="text-muted">Las marcaciones realizadas en el horario habitual no se modificarán.</small>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-trash-can me-2"></i>Sí, eliminar',
            cancelButtonText: 'Volver',
            confirmButtonColor: '#dc3545'
        });
        if (!answer.isConfirmed) return;
        const body = new FormData(); body.append('csrf_token', csrf); body.append('id', idField.value);
        const response = await fetch(`${BASE_URL}/servicios/control_personal/eliminar_programacion.php`, { method:'POST', body });
        const data = await response.json();
        if (!data.ok) return Swal.fire(data.title || 'Atención', data.message, 'warning');
        await Swal.fire('Programación eliminada', data.message, 'success');
        window.location.reload();
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
            const response = await fetch(personalServiceUrl('eliminar_archivo_personal.php'), { method: 'POST', body: form });
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

    if (window.jQuery && $.fn.DataTable && table.dataset.companyFilterBound !== '1') {
        table.dataset.companyFilterBound = '1';
        const hasSelectionColumn = !!table.querySelector('.js-worker-replica');
        const companyColumn = hasSelectionColumn ? 1 : 0;
        const normalizeSearchValue = (value) => String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
        const companyNames = new Set(
            [...table.tBodies[0].rows].map(row => normalizeSearchValue(row.cells[companyColumn]?.textContent))
        );

        $.fn.dataTable.ext.search.push((settings, rowData) => {
            if (settings.nTable !== table) return true;
            const query = normalizeSearchValue(settings.oPreviousSearch?.sSearch);
            if (!query || !companyNames.has(query)) return true;
            const company = normalizeSearchValue($('<div>').html(rowData[companyColumn] || '').text());
            return company === query;
        });

        $(table).on('init.dt', () => {
            $(`#${table.id}_filter input`).attr('placeholder', 'Empresa, personal o DNI');
        });
    }

    table.querySelectorAll('.js-eliminar-personal').forEach((button) => {
        button.addEventListener('click', async () => {
            const ok = await confirmAction('¿Eliminar personal?');
            if (!ok) return;

            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('id', button.dataset.id);

            const response = await fetch(personalServiceUrl('eliminar_personal.php'), { method: 'POST', body });
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
        const response = await fetch(personalServiceUrl('guardar_puesto.php'), { method: 'POST', body });
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

    const response = await fetch(personalServiceUrl('eliminar_puesto.php'), { method: 'POST', body });
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
    const replicateModalElement = document.getElementById('replicateRequirementModal');
    if (replicateModalElement) {
        replicateRequirementModal = new bootstrap.Modal(replicateModalElement);
        document.getElementById('confirmReplicateRequirementBtn')?.addEventListener('click', confirmReplicateRequirement);
    }

    workerSearch.select2({
        theme: 'bootstrap4',
        width: '100%',
        placeholder: 'Escriba nombre o documento',
        ajax: {
            url: personalServiceUrl('buscar_personal.php'),
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
            url: personalServiceUrl('catalogo_requisitos.php'),
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

    if (window.pmiPersonalWorkerId) loadWorker(window.pmiPersonalWorkerId);
}

async function loadWorker(id) {
    currentWorkerId = id;
    const response = await fetch(`${personalServiceUrl('perfil_personal.php')}?id=${id}`);
    const data = await response.json();
    if (!data.ok) return;

    const worker = data.worker;
    document.getElementById('requirementsWorkspace').classList.remove('d-none');
    document.getElementById('workerPhoto').src = worker.photo_path ? `${BASE_URL}/${worker.photo_path}` : `${BASE_URL}/recursos/imagen_referencial.php`;
    document.getElementById('workerDocument').textContent = `${worker.document_type}: ${worker.document_number}`;
    document.getElementById('workerName').textContent = worker.full_name;
    document.getElementById('workerCompany').textContent = worker.company || '';
    document.getElementById('workerPositions').textContent = data.positions.map((p) => p.name).join(', ');
    currentWorkerPositions = data.positions || [];
    document.getElementById('workerActive').checked = Number(worker.status) === 1;

    const select = document.getElementById('positionSelect');
    select.innerHTML = data.positions.map((p) => `<option value="${p.id}">${escapeHtml(p.name)}</option>`).join('');
    currentPositionId = select.value || null;
    loadRequirements();
}

async function loadRequirements() {
    if (!currentWorkerId || !currentPositionId) return;
    const response = await fetch(`${personalServiceUrl('listar_requisitos.php')}?trabajador_id=${currentWorkerId}&puesto_id=${currentPositionId}&t=${Date.now()}`);
    const data = await response.json();
    const tbody = document.querySelector('#requirementsTable tbody');
    tbody.innerHTML = '';
    data.rows.forEach((row) => {
        const hasAttachment = !!row.file_path;
        const downloadName = escapeHtml(row.original_file_name || `${row.requirement}`);
        const downloadButton = hasAttachment
            ? `<a class="btn btn-sm btn-outline-success" href="${BASE_URL}/${row.file_path}" download="${downloadName}" title="Descargar documento"><i class="fa-solid fa-download"></i></a>`
            : '';
        const personalReadOnly = window.pmiPersonalReadOnly === true;
        const editButton = personalReadOnly ? '' : `<button class="btn btn-sm btn-outline-primary" onclick="openEditRequirement(${row.id})"><i class="fa-solid fa-pen"></i></button>`;
        const viewButton = personalReadOnly ? '' : `<button class="btn btn-sm btn-outline-secondary" onclick="openViewRequirement(${row.id})"><i class="fa-solid fa-eye"></i></button>`;
        const deleteButton = personalReadOnly ? '' : `<button class="btn btn-sm btn-outline-danger" onclick="deleteRequirement(${row.id})"><i class="fa-solid fa-trash"></i></button>`;
        const replicateEnabled = !personalReadOnly && currentWorkerPositions.length > 1;
        const replicateButton = !personalReadOnly && document.getElementById('replicateRequirementModal')
            ? `<button class="btn btn-sm btn-outline-info" type="button" onclick="openReplicateRequirement(${row.id})" ${replicateEnabled ? '' : 'disabled'} title="${replicateEnabled ? 'Replicar a otro puesto' : 'El trabajador no tiene otro puesto'}"><i class="fa-solid fa-copy"></i></button>`
            : '';
        tbody.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="text-center">
                    <input class="form-check-input requirement-download-check" type="checkbox" value="${row.id}" ${hasAttachment ? '' : 'disabled'} title="${hasAttachment ? 'Seleccionar archivo' : 'Sin archivo adjunto'}">
                </td>
                <td>${escapeHtml(row.requirement)}</td>
                <td>${row.registration_date}</td>
                <td>${row.start_date}</td>
                <td>${row.end_date}</td>
                <td><span class="badge ${row.status.class}">${row.status.label}</span></td>
                <td>${escapeHtml(row.registered_by || '')}</td>
                <td class="text-nowrap">
                    ${editButton}
                    ${viewButton}
                    ${deleteButton}
                    ${downloadButton}
                    ${replicateButton}
                </td>
            </tr>
        `);
    });
}

function openAddRequirement() {
    readOnlyMode = false;
    canObserveCurrentRequirement = window.canManageRequirementObservations === true;
    const form = document.getElementById('requirementForm');
    form.reset();
    resetRequirementFileInput();
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
    renderRequirementObservationState(null);
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
    resetRequirementFileInput();
    const response = await fetch(`${personalServiceUrl('obtener_requisito.php')}?id=${id}`);
    const data = await response.json();
    const row = data.row;
    canObserveCurrentRequirement = data.can_observe === true;
    document.getElementById('requirementId').value = row.id;
    document.getElementById('requirementWorkerId').value = row.worker_id;
    document.getElementById('requirementPositionId').value = row.position_id;
    document.getElementById('registrationDate').value = row.registration_date;
    document.getElementById('startDate').value = row.start_date;
    document.getElementById('endDate').value = row.end_date;
    document.getElementById('observations').value = '';
    const option = new Option(row.requirement, row.requirement_id, true, true);
    $('#requirementSelect').append(option).trigger('change');
    renderCurrentPdf(row);
    renderRequirementAudit(row, data.activity || []);
    renderRequirementObservationState(row.observation_status);
}

function resetRequirementFileInput() {
    const input = document.getElementById('pdfInput');
    if (input) input.value = '';
}

function renderRequirementAudit(row, activity) {
    const box = document.getElementById('requirementAuditBox');
    const list = document.getElementById('requirementAuditList');
    const title = document.getElementById('requirementObservationHistoryTitle');
    if (!box || !list) return;

    const entries = (activity || [])
        .filter((entry) => entry.action_type === 'observacion_registrada')
        .map((entry) => ({
            author: entry.user_name || 'Usuario',
            role: requirementObservationRole(entry.user_role),
            date: entry.created_at,
            content: cleanRequirementObservation(entry.description)
        }))
        .filter((entry) => entry.content !== '');

    if (!entries.length && row?.observations) {
        entries.push({
            author: row.observation_by || 'Usuario',
            role: requirementObservationRole(row.observation_by_role),
            date: row.observation_at,
            content: cleanRequirementObservation(row.observations)
        });
    }

    if (!entries.length) {
        box.classList.add('d-none');
        list.innerHTML = '';
        return;
    }

    box.classList.remove('d-none');
    if (title) title.textContent = `Historial de observaciones (${entries.length})`;
    list.innerHTML = `<div class="observation-timeline">${entries.map((entry) => `
        <article class="observation-entry">
            <div class="observation-entry-header">
                <span class="observation-avatar"><i class="fa-solid fa-user"></i></span>
                <div class="observation-author">
                    <strong>${escapeHtml(entry.author)}</strong>
                    <span>${escapeHtml(entry.role)}</span>
                </div>
                <time>${escapeHtml(formatAuditDate(entry.date))}</time>
            </div>
            <p>${escapeHtml(entry.content)}</p>
        </article>
    `).join('')}</div>`;
}

function cleanRequirementObservation(value) {
    return String(value || '')
        .trim()
        .replace(/^(?:Administrador|Gestor) .+ tiene esta observaci[oó]n:\s*/iu, '')
        .trim();
}

function requirementObservationRole(role) {
    const normalized = String(role || '').trim().toLowerCase();
    if (normalized === 'admin' || normalized === 'administrador') return 'Administrador';
    if (normalized === 'gestor') return 'Gestor';
    return 'Responsable';
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
    const fileName = row.original_file_name || row.file_path || 'archivo';
    const isImage = /\.(?:jpe?g|png|webp)$/i.test(fileName);
    const icon = isImage ? 'fa-file-image text-primary' : 'fa-file-pdf text-danger';
    const preview = isImage
        ? `<a class="requirement-image-preview" target="_blank" href="${BASE_URL}/${row.file_path}"><img src="${BASE_URL}/${row.file_path}" alt="Vista previa del adjunto"></a>`
        : '';
    box.classList.remove('d-none');
    box.innerHTML = `
        ${preview}
        <i class="fa-solid ${icon} me-2"></i>
        <strong>${escapeHtml(fileName)}</strong>
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

    const canManage = window.canManageRequirementObservations === true && canObserveCurrentRequirement;
    const visible = canManage || viewMode || document.getElementById('requirementId')?.value === '';
    block.classList.toggle('d-none', !visible);
    observations.disabled = readOnlyMode || !canManage || !visible;
    observations.classList.toggle('observation-input-locked', observations.disabled);
    observations.placeholder = canManage
        ? 'Escriba una nueva observación...'
        : 'No tiene autorización para agregar observaciones a este requisito.';
    if (label) {
        label.textContent = 'Nueva observación';
    }
}

function renderRequirementObservationState(status) {
    const badge = document.getElementById('requirementObservationState');
    if (!badge) return;
    const normalized = status === 'corrected' ? 'observed' : String(status || 'none');
    badge.className = 'requirement-observation-state';
    if (normalized === 'observed') {
        badge.classList.add('is-observed');
        badge.textContent = 'Observado';
        return;
    }
    if (normalized === 'approved') {
        badge.classList.add('is-approved');
        badge.textContent = 'Conforme';
        return;
    }
    badge.classList.add('d-none');
    badge.textContent = '';
}

async function saveRequirementLegacy(event) {
    event.preventDefault();
    if (readOnlyMode || !event.currentTarget.checkValidity()) return;
    const response = await fetch(personalServiceUrl('guardar_requisito.php'), {
        method: 'POST',
        body: new FormData(event.currentTarget)
    });
    const data = await response.json();
    if (!data.ok) {
        Swal.fire('Atención', data.message || 'No se pudo guardar.', 'warning');
        return;
    }
    resetRequirementFileInput();
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
            personalServiceUrl('guardar_requisito.php'),
            new FormData(form),
            renderProgress
        );

        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo guardar.', 'warning');
            return;
        }
        resetRequirementFileInput();
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
    const response = await fetch(personalServiceUrl('eliminar_requisito.php'), { method: 'POST', body: form });
    const data = await response.json();
    if (data.ok) loadRequirements();
}

async function deleteRequirementPdf(id) {
    const ok = await confirmAction('¿Eliminar PDF?');
    if (!ok) return;
    const form = new FormData();
    form.append('csrf_token', csrf);
    form.append('id', id);
    const response = await fetch(personalServiceUrl('eliminar_pdf_requisito.php'), { method: 'POST', body: form });
    const data = await response.json();
    if (data.ok) {
        resetRequirementFileInput();
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
    const response = await fetch(personalServiceUrl('guardar_catalogo_requisito.php'), { method: 'POST', body: form });
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

    const response = await fetch(personalServiceUrl('eliminar_catalogo_requisito.php'), { method: 'POST', body: form });
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

    const response = await fetch(`${personalServiceUrl('descargar_requisitos.php')}?${params.toString()}`);

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
    const response = await fetch(personalServiceUrl('subir_foto_personal.php'), { method: 'POST', body: form });
    const data = await response.json();
    if (data.ok) {
        document.getElementById('workerPhoto').src = data.path;
    } else {
        Swal.fire('Atención', data.message || 'No se pudo cambiar la foto.', 'warning');
    }
}

async function openReplicateRequirement(id) {
    if (!replicateRequirementModal || currentWorkerPositions.length <= 1) return;
    const list = document.getElementById('replicatePositionList');
    const summary = document.getElementById('replicateRecordSummary');
    const confirm = document.getElementById('confirmReplicateRequirementBtn');
    list.innerHTML = '<div class="text-center py-3 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Cargando puestos...</div>';
    summary.innerHTML = '';
    confirm.disabled = true;
    document.getElementById('replicateRequirementId').value = id;
    replicateRequirementModal.show();
    try {
        const response = await fetch(`${BASE_URL}/servicios/obtener_destinos_replica_requisito.php?id=${id}`);
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudieron cargar los puestos.');
        summary.innerHTML = `<strong>${escapeHtml(data.record.requirement)}</strong><span class="small text-muted">Puesto actual: ${escapeHtml(data.record.source_position)}</span>`;
        if (!data.positions.length) {
            list.innerHTML = '<div class="alert alert-secondary mb-0">El trabajador no tiene otros puestos de trabajo.</div>';
            return;
        }
        list.innerHTML = data.positions.map(position => `<div class="replicate-position-option ${Number(position.already_exists) ? 'is-disabled' : ''}"><label><input class="form-check-input replicate-target-position" type="checkbox" value="${position.id}" ${Number(position.already_exists) ? 'disabled' : 'checked'}><span>${escapeHtml(position.name)}</span></label>${Number(position.already_exists) ? '<span class="badge text-bg-secondary">Ya registrado</span>' : '<span class="badge text-bg-primary">Disponible</span>'}</div>`).join('');
        const update = () => { confirm.disabled = !list.querySelector('.replicate-target-position:checked'); };
        list.onchange = update;
        update();
    } catch (error) {
        list.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(error.message)}</div>`;
    }
}

async function confirmReplicateRequirement() {
    const id = document.getElementById('replicateRequirementId')?.value;
    const positions = [...document.querySelectorAll('.replicate-target-position:checked')].map(input => input.value);
    if (!id || !positions.length) return;
    const button = document.getElementById('confirmReplicateRequirementBtn');
    const body = new FormData();
    body.append('csrf_token', csrf);
    body.append('id', id);
    positions.forEach(positionId => body.append('position_ids[]', positionId));
    button.disabled = true;
    try {
        const response = await fetch(`${BASE_URL}/servicios/replicar_requisito_puesto.php`, { method: 'POST', body });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo replicar el registro.');
        replicateRequirementModal.hide();
        await Swal.fire({ icon: 'success', title: 'Registro replicado', text: `Se replicó correctamente a ${data.created} puesto(s).`, timer: 1800, showConfirmButton: false });
        loadRequirements();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    } finally {
        button.disabled = false;
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

        xhr.upload.addEventListener('progress', (event) => {
            if (!event.lengthComputable || typeof onProgress !== 'function') return;
            onProgress(Math.round((event.loaded / event.total) * 100));
        });

        xhr.addEventListener('load', () => {
            let data = {};
            let isJson = false;
            try {
                if (xhr.responseText) {
                    data = JSON.parse(xhr.responseText);
                    isJson = true;
                }
            } catch (e) {
                // Si no es JSON, capturar el texto de error como mensaje
                data = { ok: false, message: xhr.responseText || 'No se pudo procesar la solicitud.' };
            }

            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(isJson ? data : { ok: true, data });
                return;
            }
            resolve(data.ok === false ? data : { ok: false, message: data.message || 'No se pudo procesar la solicitud.' });
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
            const response = await fetch(personalServiceUrl('guardar_empresa.php'), {
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

        const response = await fetch(personalServiceUrl('eliminar_empresa.php'), { method: 'POST', body });
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

    if (machineSearchElement.value) {
        loadMachine(machineSearchElement.value).then(() => {
            const recordId = Number(window.initialMachineDocumentRecordId || 0);
            if (recordId > 0) window.setTimeout(() => openEditMachineDocument(recordId), 150);
        });
    }

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
    const response = await fetch(`${BASE_URL}/servicios/listar_documentos_maquinaria.php?maquinaria_id=${currentMachineId}&t=${Date.now()}`);
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
                    <input class="form-check-input machine-document-download-check" type="checkbox" value="${row.id}" ${hasPdf ? '' : 'disabled'} title="${hasPdf ? 'Seleccionar archivo' : 'Sin archivo adjunto'}">
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

let machineCanObserveCurrentDocument = false;

function openAddMachineDocument() {
    if (!currentMachineId) {
        Swal.fire('Atenci\u00f3n', 'Seleccione una maquinaria.', 'warning');
        return;
    }
    machineReadOnlyMode = false;
    machineCanObserveCurrentDocument = window.canManageMachineDocumentObservations === true;
    const form = document.getElementById('machineDocumentForm');
    form.reset();
    resetMachineDocumentFileInput();
    form.classList.remove('was-validated');
    setMachineDocumentReadonly(false);
    document.getElementById('machineDocumentModalTitle').textContent = 'Agregar documentos';
    document.getElementById('machineDocumentId').value = '';
    document.getElementById('machineDocumentMachineId').value = currentMachineId;
    renderMachineObservationAudit(null, []);
    renderMachineObservationState(null);
    setMachineObservationVisibility(false);
    const today = localDateValue();
    document.getElementById('machineRegistrationDate').value = today;
    document.getElementById('machineStartDate').value = '';
    $('#machineDocumentSelect').val(null).trigger('change');
    renderMachineCurrentPdf(null);
    machineDocumentModal.show();
}

async function openEditMachineDocument(id) {
    machineReadOnlyMode = false;
    const data = await fillMachineDocumentModal(id);
    const canEdit = data?.can_edit === true;
    machineReadOnlyMode = !canEdit;
    setMachineDocumentReadonly(!canEdit);
    setMachineObservationVisibility(!canEdit);
    document.getElementById('machineDocumentModalTitle').textContent = canEdit ? 'Editar documentos' : 'Visualizar documentos';
    machineDocumentModal.show();
}
async function openViewMachineDocument(id) {
    machineReadOnlyMode = true;
    await fillMachineDocumentModal(id);
    setMachineDocumentReadonly(true);
    setMachineObservationVisibility(true);
    document.getElementById('machineDocumentModalTitle').textContent = 'Visualizar documentos';
    machineDocumentModal.show();
}

async function fillMachineDocumentModal(id) {
    resetMachineDocumentFileInput();
    const response = await fetch(`${BASE_URL}/servicios/obtener_documento_maquinaria.php?id=${id}`);
    const data = await response.json();
    const row = data.row;
    machineCanObserveCurrentDocument = data.can_observe === true;
    document.getElementById('machineDocumentId').value = row.id;
    document.getElementById('machineDocumentMachineId').value = row.maquinaria_id;
    document.getElementById('machineRegistrationDate').value = row.fecha_registro;
    document.getElementById('machineStartDate').value = row.fecha_inicio;
    document.getElementById('machineEndDate').value = row.fecha_fin;
    document.getElementById('machineObservations').value = '';
    renderMachineObservationAudit(row, data.activity || []);
    renderMachineObservationState(row.observation_status);
    const option = new Option(row.documento, row.documento_id, true, true);
    $('#machineDocumentSelect').append(option).trigger('change');
    renderMachineCurrentPdf(row);
    return data;
}

function setMachineObservationVisibility(viewMode) {
    const block = document.getElementById('machineObservationBlock');
    const input = document.getElementById('machineObservations');
    if (!block || !input) return;
    const canManage = window.canManageMachineDocumentObservations === true && machineCanObserveCurrentDocument;
    const visible = canManage || viewMode || document.getElementById('machineDocumentId')?.value === '';
    block.classList.toggle('d-none', !visible);
    input.disabled = machineReadOnlyMode || !canManage || !visible;
    input.classList.toggle('observation-input-locked', input.disabled);
    input.placeholder = canManage ? 'Escriba una nueva observación...' : 'No tiene autorización para agregar observaciones a este documento.';
}

function renderMachineObservationState(status) {
    const badge = document.getElementById('machineObservationState');
    if (!badge) return;
    const normalized = String(status || 'none');
    badge.className = 'requirement-observation-state';
    if (normalized === 'observed') { badge.classList.add('is-observed'); badge.textContent = 'Observado'; return; }
    if (normalized === 'approved') { badge.classList.add('is-approved'); badge.textContent = 'Conforme'; return; }
    badge.classList.add('d-none'); badge.textContent = '';
}

function renderMachineObservationAudit(row, activity) {
    const box = document.getElementById('machineObservationAuditBox');
    const list = document.getElementById('machineObservationAuditList');
    const title = document.getElementById('machineObservationHistoryTitle');
    if (!box || !list) return;
    const entries = (activity || []).filter(entry => entry.action_type === 'observacion_registrada').map(entry => ({author: entry.user_name || 'Usuario', role: requirementObservationRole(entry.user_role), date: entry.created_at, content: cleanRequirementObservation(entry.description)})).filter(entry => entry.content !== '');
    if (!entries.length && row?.review_observation) entries.push({author: row.observation_by || 'Usuario', role: requirementObservationRole(row.observation_by_role), date: row.observation_at, content: cleanRequirementObservation(row.review_observation)});
    if (!entries.length) { box.classList.add('d-none'); list.innerHTML = ''; return; }
    box.classList.remove('d-none');
    title.textContent = `Historial de observaciones (${entries.length})`;
    list.innerHTML = `<div class="observation-timeline">${entries.map(entry => `<article class="observation-entry"><div class="observation-entry-header"><span class="observation-avatar"><i class="fa-solid fa-user"></i></span><div class="observation-author"><strong>${escapeHtml(entry.author)}</strong><span>${escapeHtml(entry.role)}</span></div><time>${escapeHtml(formatAuditDate(entry.date))}</time></div><p>${escapeHtml(entry.content)}</p></article>`).join('')}</div>`;
}

function resetMachineDocumentFileInput() {
    const input = document.getElementById('machinePdfInput');
    if (!input) return;
    input.value = '';
    input.classList.remove('is-valid', 'is-invalid');
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
        ${documentAttachmentHeader(row)}
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

function initEmpresaMaquirentaDatos() {
    const table = document.getElementById('empresaMaquirentaTable');
    if (!table) return;

    const form = document.getElementById('empresaMaquirentaForm');
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('empresaMaquirentaModal'));
    const photoModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('empresaMaquirentaFotoModal'));
    const currentPhoto = document.getElementById('empresaMaquirentaFotoActual');

    const renderCurrentPhoto = (path) => {
        if (!path) {
            currentPhoto.classList.add('d-none');
            currentPhoto.innerHTML = '';
            return;
        }
        currentPhoto.classList.remove('d-none');
        currentPhoto.innerHTML = `<i class="fa-solid fa-image text-primary me-2"></i><a target="_blank" href="${BASE_URL}/${path}">Ver foto actual</a>`;
    };

    document.getElementById('nuevaEmpresaMaquirentaBtn')?.addEventListener('click', () => {
        form.reset();
        form.classList.remove('was-validated');
        document.getElementById('empresaMaquirentaId').value = '';
        document.getElementById('empresaMaquirentaModalTitle').textContent = 'Nueva empresa Maquirenta';
        renderCurrentPhoto(null);
        modal.show();
    });

    document.querySelectorAll('.js-editar-empresa-maquirenta').forEach((button) => {
        button.addEventListener('click', () => {
            form.reset();
            form.classList.remove('was-validated');
            document.getElementById('empresaMaquirentaId').value = button.dataset.id || '';
            document.getElementById('empresaMaquirentaRazonSocial').value = button.dataset.razonSocial || '';
            document.getElementById('empresaMaquirentaRuc').value = button.dataset.ruc || '';
            document.getElementById('empresaMaquirentaDireccion').value = button.dataset.direccion || '';
            document.getElementById('empresaMaquirentaModalTitle').textContent = 'Editar empresa Maquirenta';
            renderCurrentPhoto(button.dataset.foto || null);
            modal.show();
        });
    });

    document.querySelectorAll('.js-ver-foto-empresa-maquirenta').forEach((button) => {
        button.addEventListener('click', () => {
            if (!button.dataset.foto) return;
            document.getElementById('empresaMaquirentaFotoModalImg').src = `${BASE_URL}/${button.dataset.foto}`;
            photoModal.show();
        });
    });

    document.querySelectorAll('.js-eliminar-empresa-maquirenta').forEach((button) => {
        button.addEventListener('click', async () => {
            const ok = await confirmAction('¿Eliminar empresa Maquirenta?');
            if (!ok) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('id', button.dataset.id || '');
            const response = await fetch(`${BASE_URL}/servicios/empresa_maquirenta/eliminar_empresa.php`, { method: 'POST', body });
            const data = await response.json();
            if (data.ok) window.location.reload();
            else Swal.fire('Atención', data.message || 'No se pudo eliminar la empresa Maquirenta.', 'warning');
        });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            return;
        }
        const submitButton = form.querySelector('[type="submit"]');
        submitButton.disabled = true;
        try {
            const response = await fetch(`${BASE_URL}/servicios/empresa_maquirenta/guardar_empresa.php`, { method: 'POST', body: new FormData(form) });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo guardar la empresa Maquirenta.');
            modal.hide();
            window.location.reload();
        } catch (error) {
            Swal.fire('Atención', error.message || 'No se pudo guardar la empresa Maquirenta.', 'warning');
        } finally {
            submitButton.disabled = false;
        }
    });
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
    const response = await fetch(`${BASE_URL}/servicios/empresa/listar_documentos_empresa.php?empresa_id=${currentCompanyModuleId}&t=${Date.now()}`);
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
                    <input class="form-check-input company-document-download-check" type="checkbox" value="${row.id}" ${hasPdf ? '' : 'disabled'} title="${hasPdf ? 'Seleccionar archivo' : 'Sin archivo adjunto'}">
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
    resetCompanyDocumentFileInput();
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
    resetCompanyDocumentFileInput();
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

function resetCompanyDocumentFileInput() {
    const input = document.getElementById('companyPdfInput');
    if (!input) return;
    input.value = '';
    input.classList.remove('is-valid', 'is-invalid');
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
        ${documentAttachmentHeader(row)}
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
    const response = await fetch(`${BASE_URL}/servicios/empresa/listar_seguridad_empresa.php?empresa_id=${currentCompanySecurityId}&t=${Date.now()}`);
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
    resetCompanySecurityFileInput();
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
    resetCompanySecurityFileInput();
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

function resetCompanySecurityFileInput() {
    const input = document.getElementById('companySecurityPdfInput');
    if (!input) return;
    input.value = '';
    input.classList.remove('is-valid', 'is-invalid');
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
    box.innerHTML = `${documentAttachmentHeader(row)}<div class="d-flex gap-2 mt-2"><a class="btn btn-sm btn-outline-primary" target="_blank" href="${BASE_URL}/${row.archivo_path}"><i class="fa-solid fa-up-right-from-square me-1"></i>Abrir</a><button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteCompanySecurityPdf(${row.id})"><i class="fa-solid fa-trash me-1"></i>Eliminar</button></div>`;
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
    const focusTrap = companySecurityModal?._focustrap;
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
        const pdfInput = root.querySelector('.js-company-generic-pdf-input');
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
            const response = await fetch(`${BASE_URL}/servicios/empresa/documentos_genericos_empresa.php?action=list&module=${module}&empresa_id=${currentCompanyId}&t=${Date.now()}`);
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
            resetGenericFileInput();
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
            resetGenericFileInput();
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

        function resetGenericFileInput() {
            if (!pdfInput) return;
            pdfInput.value = '';
            pdfInput.classList.remove('is-valid', 'is-invalid');
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
            box.innerHTML = `${documentAttachmentHeader(row)}<div class="d-flex gap-2 mt-2"><a class="btn btn-sm btn-outline-primary" target="_blank" href="${BASE_URL}/${row.archivo_path}"><i class="fa-solid fa-up-right-from-square me-1"></i>Abrir</a><button class="btn btn-sm btn-outline-danger js-delete-current-pdf" type="button"><i class="fa-solid fa-trash me-1"></i>Eliminar</button></div>`;
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
            const focusTrap = modal?._focustrap;
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
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('module', module);
            body.append('nombre', value);
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

    const tbody = table.querySelector('tbody');
    const pagination = document.getElementById('dashboardPagination');
    const paginationInfo = document.getElementById('dashboardPaginationInfo');

    const filters = {
        company: document.getElementById('dashboardEmpresaFilter'),
        name: document.getElementById('dashboardNombreFilter'),
        position: document.getElementById('dashboardPuestoFilter'),
        requirement: document.getElementById('dashboardRequisitoFilter'),
        state: document.getElementById('dashboardEstadoFilter'),
        observationState: document.getElementById('dashboardObservationStateFilter'),
    };

    let currentPage = 1;
    const limit = 10;

    const fetchTableData = async (page = 1) => {
        currentPage = page;
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-muted text-center py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando registros...</td></tr>';
        }

        const params = new URLSearchParams({
            page: String(currentPage),
            limit: String(limit),
            company: filters.company?.value || '',
            name: filters.name?.value || '',
            position: filters.position?.value || '',
            requirement: filters.requirement?.value || '',
            state: filters.state?.value || '',
            observation_state: filters.observationState?.value || '',
        });

        try {
            const response = await fetch(`${BASE_URL}/servicios/control_personal/get_dashboard_table.php?${params.toString()}`);
            const data = await response.json();
            if (!data.ok) {
                if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="text-danger text-center py-4">${escapeHtml(data.message || 'Error al cargar los datos')}</td></tr>`;
                return;
            }

            if (tbody) tbody.innerHTML = data.html;

            // Render pagination info
            if (paginationInfo) {
                paginationInfo.textContent = `Mostrando ${data.start_record} a ${data.end_record} de ${data.total_records} registros`;
            }

            // Render pagination buttons
            renderPagination(data.total_pages, data.current_page);

        } catch (error) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-danger text-center py-4">No se pudo conectar con el servidor.</td></tr>';
        }
    };

    const renderPagination = (totalPages, currentPage) => {
        if (!pagination) return;
        pagination.innerHTML = '';

        if (totalPages <= 1) return;

        const maxVisiblePages = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
        let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);

        if (endPage - startPage + 1 < maxVisiblePages) {
            startPage = Math.max(1, endPage - maxVisiblePages + 1);
        }

        // Prev page button
        const prevLi = document.createElement('li');
        prevLi.className = `page-item ${currentPage === 1 ? 'disabled' : ''}`;
        prevLi.innerHTML = `<button class="page-link" type="button" aria-label="Anterior"><span aria-hidden="true">&laquo;</span></button>`;
        if (currentPage > 1) {
            prevLi.querySelector('button').addEventListener('click', () => fetchTableData(currentPage - 1));
        }
        pagination.appendChild(prevLi);

        // Page number buttons
        for (let i = startPage; i <= endPage; i++) {
            const li = document.createElement('li');
            li.className = `page-item ${i === currentPage ? 'active' : ''}`;
            li.innerHTML = `<button class="page-link" type="button">${i}</button>`;
            li.querySelector('button').addEventListener('click', () => fetchTableData(i));
            pagination.appendChild(li);
        }

        // Next page button
        const nextLi = document.createElement('li');
        nextLi.className = `page-item ${currentPage === totalPages ? 'disabled' : ''}`;
        nextLi.innerHTML = `<button class="page-link" type="button" aria-label="Siguiente"><span aria-hidden="true">&raquo;</span></button>`;
        if (currentPage < totalPages) {
            nextLi.querySelector('button').addEventListener('click', () => fetchTableData(currentPage + 1));
        }
        pagination.appendChild(nextLi);
    };

    let debounceTimeout = null;
    const triggerSearch = () => {
        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(() => fetchTableData(1), 300);
    };

    Object.values(filters).forEach((field) => {
        if (field?.tagName === 'INPUT') {
            field.addEventListener('input', triggerSearch);
        } else {
            field?.addEventListener('change', () => fetchTableData(1));
        }
    });

    if (window.jQuery) {
        [filters.company, filters.position, filters.requirement].forEach((field) => {
            if (!field) return;
            jQuery(field)
                .off('.dashboardPersonalFilters')
                .on('select2:select.dashboardPersonalFilters select2:clear.dashboardPersonalFilters change.dashboardPersonalFilters', () => fetchTableData(1));
        });
    }

    fetchTableData(1);

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

    function fillUserFromSelectedWorker(force = false) {
        if (!workerSelect || roleSelect?.value !== 'Personal') return;
        const option = workerSelect.selectedOptions?.[0];
        if (!option || !option.value) return;
        if (nameInput && (force || !nameInput.value.trim())) nameInput.value = option.dataset.name || '';
        if (emailInput && (force || !emailInput.value.trim())) emailInput.value = option.dataset.email || '';
    }

    function toggleUserWorkerField() {
        const isPersonal = roleSelect?.value === 'Personal';
        workerGroup?.classList.remove('d-none');
        if (workerSelect) {
            workerSelect.required = isPersonal;

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
    workerSelect?.addEventListener('change', () => fillUserFromSelectedWorker(true));

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
    const personalPmiModalElement = document.getElementById('personalPmiPermissionsModal');
    const personalPmiModal = personalPmiModalElement ? bootstrap.Modal.getOrCreateInstance(personalPmiModalElement) : null;
    const personalPmiList = document.getElementById('personalPmiPermissionsList');
    let reopenUserModalAfterPmi = false;

    document.querySelectorAll('.js-open-personal-pmi-permissions').forEach((button) => {
        button.addEventListener('click', () => {
            const scope = button.dataset.scope || '';
            const catalog = permissionData.catalogs?.[scope];
            if (!personalPmiModal || !personalPmiList || !catalog) return;
            document.getElementById('personalPmiPermissionsTitle').textContent = scope === 'requisitos.pmi_individual'
                ? 'PMI Individual - Requisitos visibles'
                : 'Empresa Maquirenta - PMI Individual';
            personalPmiList.innerHTML = '';
            (catalog.items || []).forEach((item) => {
                const sourceCheck = viewChecks.find((check) => check.dataset.scope === scope && check.dataset.catalogId === String(item.id));
                const row = document.createElement('label');
                row.className = 'list-group-item d-flex align-items-center gap-3 py-3';
                row.innerHTML = `<input class="form-check-input flex-shrink-0 personal-pmi-modal-check" type="checkbox" ${sourceCheck?.checked ? 'checked' : ''}><span>${escapeHtml(item.name)}</span>`;
                row.querySelector('input').addEventListener('change', (event) => {
                    if (sourceCheck) sourceCheck.checked = event.currentTarget.checked;
                    syncScopeAll(scope);
                });
                personalPmiList.appendChild(row);
            });
            reopenUserModalAfterPmi = true;
            modal.hide();
            setTimeout(() => personalPmiModal.show(), 180);
        });
    });
    personalPmiModalElement?.addEventListener('hidden.bs.modal', () => {
        if (!reopenUserModalAfterPmi) return;
        reopenUserModalAfterPmi = false;
        modal.show();
        setTimeout(() => bootstrap.Tab.getOrCreateInstance(document.getElementById('usuarioPermisosTab'))?.show(), 120);
    });

    function fillUserFromSelectedWorker(force = false) {
        if (!workerSelect || roleSelect?.value !== 'Personal') return;
        const option = workerSelect.selectedOptions?.[0];
        if (!option || !option.value) return;
        if (nameInput && (force || !nameInput.value.trim())) nameInput.value = option.dataset.name || '';
        if (emailInput && (force || !emailInput.value.trim())) emailInput.value = option.dataset.email || '';
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

    function buildPersonalDocumentPermissions() {
        const permissions = {};
        const allowedScopes = new Set(['requisitos.pmi_individual', 'empresa_maquirenta.pmi_individual']);
        const allowedNames = new Set(['contrato de trabajo', 'camo', 'dni', 'sctr', 'vida ley', 'boleta firmada']);
        viewChecks.forEach((check) => {
            if (!allowedScopes.has(check.dataset.scope)) return;
            const rowName = (check.closest('tr')?.querySelector('td')?.textContent || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLowerCase().replace(/[^a-z0-9]+/g, ' ');
            if (!allowedNames.has(rowName)) return;
            permissions[check.dataset.scope] = permissions[check.dataset.scope] || {};
            permissions[check.dataset.scope][check.dataset.catalogId] = { view: true, upload: false, manage: false };
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
            return { modules: ['control_personal', 'control_personal.control_asistencia', 'control_personal.dashboard', 'control_personal.reporte_asistencias', 'requisitos', 'control_personal.personal', 'requisitos.pmi_individual', 'empresa_maquirenta', 'empresa_maquirenta.personal', 'empresa_maquirenta.pmi_individual'], documents: buildPersonalDocumentPermissions() };
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
        moduleChecks.forEach((check) => {
            if (!check.checked || check.value === check.dataset.parent) return;
            const parent = moduleChecks.find((item) => item.value === check.dataset.parent);
            if (parent) parent.checked = true;
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
        document.querySelectorAll('#usuarioDocumentPermissions .accordion-item').forEach((item) => item.classList.remove('d-none'));
        workerGroup?.classList.remove('d-none');
        if (workerSelect) {
            workerSelect.required = isPersonal;

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
            permissionNote.textContent = 'Personal puede ver únicamente su propia información. Seleccione abajo los requisitos visibles en PMI Individual; no tendrá permisos para editar, subir ni eliminar.';
            setPermissionsEnabled(false);
            const personalModules = new Set(['control_personal.dashboard', 'control_personal.reporte_asistencias', 'control_personal.personal', 'requisitos.pmi_individual', 'empresa_maquirenta.personal', 'empresa_maquirenta.pmi_individual']);
            moduleChecks.forEach((check) => { check.disabled = !personalModules.has(check.value); });
            const personalScopes = new Set(['requisitos.pmi_individual', 'empresa_maquirenta.pmi_individual']);
            viewChecks.forEach((check) => { check.disabled = !personalScopes.has(check.dataset.scope); });
            document.querySelectorAll('#usuarioDocumentPermissions .accordion-item').forEach((item) => {
                const scope = item.querySelector('.usuario-document-view')?.dataset.scope || '';
                item.classList.toggle('d-none', !personalScopes.has(scope));
            });
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
        if (roleSelect) roleSelect.disabled = false;
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
            document.getElementById('usuarioRole').disabled = false;
            document.getElementById('usuarioRole').value = button.dataset.role || 'Administrador';
            if (workerSelect) workerSelect.value = button.dataset.workerId || '';
            document.getElementById('usuarioModalTitle').textContent = 'Editar usuario';
            password.required = false;
            applyPermissions(permissionsForUserPayload(button.dataset.id || '', button.dataset.role || 'Administrador'));
            toggleUserWorkerField();
            if (button.dataset.self === '1' && button.dataset.role === 'Administrador') roleSelect.disabled = true;
            bootstrap.Tab.getOrCreateInstance(document.getElementById('usuarioDatosTab'))?.show();
            passwordHelp.textContent = 'Dejar vacio para mantener la contrasena actual.';
            modal.show();
        });
    });

    roleSelect?.addEventListener('change', () => {
        applyPermissions(defaultPermissionsForRole(roleSelect.value));
        toggleUserWorkerField();
    });
    workerSelect?.addEventListener('change', () => fillUserFromSelectedWorker(true));
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
        const payload = new FormData(form);
        if (roleSelect?.disabled) payload.set('role', roleSelect.value);
        const response = await fetch(`${BASE_URL}/servicios/guardar_usuario.php`, { method: 'POST', body: payload });
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
    const filtersForm = document.getElementById('attendanceFiltersForm');
    let filterTimer = null;
    document.querySelectorAll('.attendance-filter').forEach((field) => {
        const isTextSearch = field.tagName === 'INPUT' && (field.type === 'text' || !field.type);
        const submitFilters = () => {
            if (!filtersForm) return;
            window.clearTimeout(filterTimer);
            filterTimer = window.setTimeout(() => filtersForm.requestSubmit(), isTextSearch ? 450 : 0);
        };
        field.addEventListener(isTextSearch ? 'input' : 'change', submitFilters);
    });
    document.getElementById('attendancePerPage')?.addEventListener('change', (event) => {
        event.currentTarget.form?.requestSubmit();
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
                const title = (notif.title || '').toLowerCase();
                const body = (notif.body || '').toLowerCase();
                return name.includes(queryLower) || missing.includes(queryLower) || title.includes(queryLower) || body.includes(queryLower);
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
            const isWorkCompletion = notif.type === 'work_location_completed';
            const iconHTML = isWorkCompletion ? '<i class="fa-solid fa-circle-check"></i>' : '<i class="fa-solid fa-bell"></i>';

            // Clean, structured styling
            const nameHTML = escapeHTML(notif.full_name || '');
            const missingHTML = escapeHTML(notif.missing_fields || '');
            
            const bodyHTML = isWorkCompletion
                ? `<strong>${escapeHTML(notif.title || 'Trabajo finalizado')}</strong><br><span>${escapeHTML(notif.body || '')}</span>`
                : `<strong>Nombre del personal:</strong> <span class="notif-worker-name">${nameHTML}</span> <span class="notif-missing-fields">(Falta: ${missingHTML})</span>`;

            return `
                <div class="notif-item unread" data-id="${notif.id}" data-notification-id="${Number(notif.notification_id || 0)}" data-worker-id="${Number(notif.worker_id || 0)}" data-type="${escapeHTML(notif.type || '')}">
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

    list.addEventListener('click', async (event) => {
        const item = event.target.closest('.notif-item[data-type="work_location_completed"]');
        if (!item) return;
        const body = new FormData();
        body.append('csrf_token', csrf);
        body.append('notification_id', item.dataset.notificationId || '');
        try {
            await fetch(`${BASE_URL}/servicios/mark_notifications_read.php`, { method:'POST', body });
        } catch (error) {
            // La navegación al control de asistencia sigue disponible aunque falle la actualización visual.
        }
        const workerId = item.dataset.workerId || '';
        window.location.href = `${BASE_URL}/modulos/control_personal/control_asistencia.php${workerId ? `?worker_id=${encodeURIComponent(workerId)}` : ''}`;
    });

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
        document.getElementById('routeNotifContainer')?.classList.remove('active');
        document.getElementById('obsNotifContainer')?.classList.remove('active');
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

function initRouteNotifications() {
    const container=document.getElementById('routeNotifContainer');
    const button=document.getElementById('routeNotifBtn');
    const badge=document.getElementById('routeNotifBadge');
    const list=document.getElementById('routeNotifList');
    const search=document.getElementById('routeNotifSearchInput');
    if(!container||!button||!badge||!list)return;
    let rows=[];
    let query='';

    const overtimeText=(minutes)=>{
        const value=Math.max(0,Number(minutes||0));
        const hours=Math.floor(value/60);
        const rest=value%60;
        return hours ? `${hours} h ${rest} min` : `${rest} min`;
    };
    const formatDateTime=(value)=>{
        if(!value)return '-';
        const parts=String(value).split(/[- :]/);
        return parts.length>=5 ? `${parts[2]}/${parts[1]}/${parts[0]} · ${parts[3]}:${parts[4]}` : value;
    };
    const setBadge=(count)=>{
        badge.textContent=count>99?'99+':String(count||0);
        badge.style.display=count>0?'flex':'none';
    };
    const render=()=>{
        const needle=query.trim().toLowerCase();
        const filtered=needle?rows.filter(row=>`${row.full_name||''} ${row.location||''} ${row.activity||''} ${row.title||''} ${row.body||''}`.toLowerCase().includes(needle)):rows;
        if(!filtered.length){
            list.innerHTML=`<div class="notif-empty"><i class="fa-solid fa-route"></i><span>${needle?'No se encontraron recorridos':'No hay alertas de recorridos pendientes'}</span></div>`;
            return;
        }
        list.innerHTML=filtered.map(row=>{
            if(row.type==='temporary_trip_exception'){
                return `<div class="notif-item unread route-notif-item" data-notification-id="${Number(row.notification_id||0)}" data-worker-id="${Number(row.worker_id||0)}" data-needs-assignment="0">
                    <div class="notif-icon-container route-notif-icon text-warning"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div class="notif-content">
                        <div class="notif-title">${escapeHtml(row.title||'Regreso con incidencia')}</div>
                        <div class="notif-body">${escapeHtml(row.body||'')}</div>
                        <div class="notif-time"><span>${escapeHtml(formatDateTime(row.completed_at))}</span></div>
                    </div>
                </div>`;
            }
            const hasSchedule=row.entry_time&&row.exit_time;
            const schedule=hasSchedule?`Jornada: ${escapeHtml(row.entry_time)} a ${escapeHtml(row.exit_time)}`:'Horario no disponible';
            const completedParts=String(row.completed_at||'').split(/[- :]/);
            const completedHour=completedParts.length>=5?`${completedParts[3]}:${completedParts[4]}`:'hora no disponible';
            const overtime=row.is_overtime
                ? `<div class="route-notif-overtime"><i class="fa-solid fa-clock"></i> Horas extras: ${escapeHtml(overtimeText(row.overtime_minutes))}</div>`
                : '';
            return `<div class="notif-item unread route-notif-item" data-notification-id="${Number(row.notification_id||0)}" data-worker-id="${Number(row.worker_id||0)}" data-needs-assignment="${row.needs_assignment?'1':'0'}">
                <div class="notif-icon-container route-notif-icon"><i class="fa-solid fa-location-dot"></i></div>
                <div class="notif-content">
                    <div class="notif-title">Trabajo finalizado</div>
                    <div class="notif-body route-notif-message"><strong class="route-notif-worker">${escapeHtml(row.full_name||'El trabajador')}</strong> terminó su trabajo en <strong class="route-notif-place">${escapeHtml(row.location||'el lugar registrado')}</strong> a las <strong class="route-notif-hour">${escapeHtml(completedHour)}</strong>.</div>
                    <div class="route-notif-activity"><span>Actividad:</span> ${escapeHtml(row.activity||'Sin actividad especificada')}</div>
                    <div class="route-notif-schedule"><i class="fa-regular fa-clock"></i> ${schedule}</div>
                    ${row.needs_assignment?'<div class="route-notif-assignment"><i class="fa-solid fa-location-arrow"></i> Asignar siguiente destino</div>':''}
                    ${overtime}
                    <div class="notif-time"><span>${escapeHtml(formatDateTime(row.completed_at))}</span></div>
                </div>
            </div>`;
        }).join('');
    };
    const load=async()=>{
        try{
            const response=await fetch(`${BASE_URL}/servicios/get_route_notifications.php?t=${Date.now()}`);
            const data=await response.json();
            if(!data.ok)return;
            rows=data.notifications||[];
            setBadge(data.unread_count||0);
            render();
        }catch(error){console.error('Error fetching route notifications:',error);}
    };
    search?.addEventListener('input',event=>{query=event.target.value||'';render();});
    search?.addEventListener('click',event=>event.stopPropagation());
    button.addEventListener('click',event=>{
        event.stopPropagation();
        document.getElementById('notifContainer')?.classList.remove('active');
        document.getElementById('obsNotifContainer')?.classList.remove('active');
        container.classList.toggle('active');
        if(container.classList.contains('active'))setTimeout(()=>search?.focus(),50);
    });
    list.addEventListener('click',async event=>{
        const item=event.target.closest('.route-notif-item');
        if(!item)return;
        const body=new FormData();
        body.append('csrf_token',csrf);
        body.append('notification_id',item.dataset.notificationId||'');
        try{await fetch(`${BASE_URL}/servicios/mark_notifications_read.php`,{method:'POST',body});}catch(error){}
        const workerId=encodeURIComponent(item.dataset.workerId||'');
        window.location.href=item.dataset.needsAssignment==='1'
            ? `${BASE_URL}/modulos/control_personal/programacion_personal.php?worker_id=${workerId}#recorridos-trabajo`
            : `${BASE_URL}/modulos/control_personal/control_asistencia.php?worker_id=${workerId}`;
    });
    document.addEventListener('click',event=>{if(!container.contains(event.target))container.classList.remove('active');});
    load();
    setInterval(load,20000);
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
    let source = 'all';
    const sourceButtons = container.querySelectorAll('[data-obs-source]');

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
        const filtered = rows.filter((row) => {
            const matchesSource = source === 'all' || row.source === source;
            const matchesTerm = !term || [row.source_label, row.full_name, row.series, row.requirement, row.observation, row.status_label, row.observed_by]
                .some((value) => String(value || '').toLowerCase().includes(term));
            return matchesSource && matchesTerm;
        });

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
            const statusClass = 'obs-status-observed';
            const observedBy = row.observed_by || row.registered_by || 'Usuario';
            return `
                <div class="notif-item unread observation-notif-item" data-id="${escapeHtml(row.id)}" data-source="${escapeHtml(row.source || 'personal')}">
                    <div class="notif-icon-container ${statusClass}">
                        <i class="fa-solid fa-comment-dots"></i>
                    </div>
                    <div class="notif-content">
                        <div class="notif-body">
                            <span class="obs-source-badge obs-source-${escapeHtml(row.source || 'personal')}">${escapeHtml(row.source_label || 'PERSONAL')}</span>
                            <strong>${row.source === 'maquinaria' ? 'Equipo:' : 'Nombre de personal:'}</strong> <span class="notif-worker-name">${escapeHtml(row.full_name || '')}</span>
                            ${row.source === 'maquinaria' ? `<span class="d-block">Serie o placa: ${escapeHtml(row.series || '')}</span>` : ''}
                            <span class="notif-missing-fields d-block">${row.source === 'maquinaria' ? 'Documento' : 'Requisito'}: ${escapeHtml(row.requirement || '')}</span>
                            <span class="notif-observation-text d-block">Observación: ${escapeHtml(row.observation || '')}</span>
                        </div>
                        <div class="notif-time">
                            <span>Observado por: ${escapeHtml(observedBy)} - ${escapeHtml(formatDate(row.created_at))}</span>
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
    sourceButtons.forEach((filterButton) => filterButton.addEventListener('click', (event) => {
        event.stopPropagation();
        source = filterButton.dataset.obsSource || 'all';
        sourceButtons.forEach((item) => item.classList.toggle('active', item === filterButton));
        render();
    }));
    list.addEventListener('click', (event) => {
        const item = event.target.closest('.observation-notif-item');
        if (!item || item.dataset.source !== 'maquinaria') return;
        window.location.href = `${BASE_URL}/modulos/maquinaria/dashboard.php?observation_id=${encodeURIComponent(item.dataset.id || '')}#detalle-maquinaria`;
    });

    button.addEventListener('click', (event) => {
        event.stopPropagation();
        document.getElementById('notifContainer')?.classList.remove('active');
        document.getElementById('routeNotifContainer')?.classList.remove('active');
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

function initScheduleJourneysCalendar() {
    const calendarView = document.getElementById('journeysCalendarView');
    const extraordinaryView = document.getElementById('extraordinaryProgrammingView');
    const routesView = document.getElementById('routesProgrammingView');
    if (!calendarView || !extraordinaryView || !routesView) return;

    const tabs = Array.from(document.querySelectorAll('[data-journey-module-view]'));
    const calendarElement = document.getElementById('scheduleJourneysCalendar');
    const scheduleFilter = document.getElementById('journeysScheduleFilter');
    const workerFilter = document.getElementById('journeysWorkerFilter');
    const detailElement = document.getElementById('scheduleJourneyDetailModal');
    const detailModal = detailElement ? bootstrap.Modal.getOrCreateInstance(detailElement) : null;
    const excludeButton = document.getElementById('excludeJourneyDateBtn');
    const restoreButton = document.getElementById('restoreJourneyDateBtn');
    let calendar = null;
    let selectedEvent = null;
    let eventsRequestSequence = 0;

    const setText = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value || '-';
    };
    const dateLabel = (value) => new Date(`${value}T12:00:00`).toLocaleDateString('es-PE', {
        weekday: 'long', day: '2-digit', month: 'long', year: 'numeric'
    });

    function activateView(view) {
        const showCalendar = view === 'calendar';
        calendarView.classList.toggle('d-none', !showCalendar);
        extraordinaryView.classList.toggle('d-none', view !== 'extraordinary');
        routesView.classList.toggle('d-none', view !== 'routes');
        tabs.forEach((tab) => {
            const active = tab.dataset.journeyModuleView === view;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        if (showCalendar) {
            window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}#calendario`);
            window.setTimeout(() => calendar?.updateSize(), 80);
        } else if (view === 'extraordinary') {
            window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}#programacion-especial`);
            window.setTimeout(() => window.dispatchEvent(new Event('resize')), 80);
        } else {
            window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}#recorridos-trabajo`);
            window.setTimeout(() => window.dispatchEvent(new Event('resize')), 80);
        }
    }
    tabs.forEach((tab) => tab.addEventListener('click', () => activateView(tab.dataset.journeyModuleView || 'calendar')));

    if (calendarElement && window.FullCalendar) {
        calendar = new FullCalendar.Calendar(calendarElement, {
            locale: 'es', initialView: 'dayGridMonth', firstDay: 1, height: 'auto', dayMaxEvents: true,
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
            buttonText: { today: 'Hoy', month: 'Mes', list: 'Lista' },
            events(info, success, failure) {
                const requestSequence = ++eventsRequestSequence;
                const url = new URL(`${BASE_URL}/servicios/control_personal/listar_calendario_jornadas.php`);
                if (scheduleFilter?.value && scheduleFilter.value !== 'all') url.searchParams.set('schedule_id', scheduleFilter.value);
                url.searchParams.set('start', info.startStr.slice(0, 10));
                url.searchParams.set('end', info.endStr.slice(0, 10));
                if (workerFilter?.value && workerFilter.value !== 'all') url.searchParams.set('worker_id', workerFilter.value);
                fetch(url, { cache: 'no-store' }).then((response) => response.json().then((data) => ({ response, data })))
                    .then(({ response, data }) => {
                        if (requestSequence !== eventsRequestSequence) return;
                        if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudieron cargar las jornadas.');
                        success(data.events || []);
                    }).catch((error) => {
                        if (requestSequence !== eventsRequestSequence) return;
                        failure(error);
                        Swal.fire('Atención', error.message || 'No se pudieron cargar las jornadas.', 'warning');
                    });
            },
            eventClick(info) {
                selectedEvent = info.event;
                const props = selectedEvent.extendedProps || {};
                const date = selectedEvent.startStr.slice(0, 10);
                const kindLabels = { regular: 'Horario habitual', program: 'Programación especial', special: 'Calendario laboral' };
                setText('journeyDetailDate', dateLabel(date));
                setText('journeyDetailWorker', props.worker);
                setText('journeyDetailSchedule', props.schedule || (props.kind === 'special' ? props.name : '-'));
                setText('journeyDetailHours', props.entry && props.exit ? `${props.entry} - ${props.exit}` : '-');
                setText('journeyDetailLocation', props.location);
                setText('journeyDetailActivity', props.activity || props.name);
                const status = document.getElementById('journeyDetailStatus');
                if (status) {
                    status.className = `journey-detail-status is-${props.kind || 'special'}`;
                    status.textContent = props.kind === 'route' ? 'Recorrido de trabajo' : (kindLabels[props.kind] || 'Jornada');
                }
                setText('journeyDetailHelp', props.kind === 'regular'
                    ? (props.canExclude
                        ? 'Puede excluir únicamente esta fecha. La plantilla semanal y los demás días permanecerán sin cambios.'
                        : 'La fecha pertenece al historial y se mantiene protegida contra modificaciones.')
                    : props.kind === 'route'
                        ? `Este recorrido mantiene la jornada laboral y comprende ${props.routePlaceCount || 1} lugares en el orden asignado.`
                    : props.kind === 'program'
                        ? 'Esta programación especial reemplaza el horario habitual únicamente en esta fecha.'
                        : 'Esta fecha está definida desde Calendario laboral y tiene prioridad sobre la plantilla semanal.');
                excludeButton?.classList.toggle('d-none', props.kind !== 'regular' || !props.canExclude);
                restoreButton?.classList.toggle('d-none', !props.canRestore);
                detailModal?.show();
            },
            eventDidMount(info) {
                const props = info.event.extendedProps || {};
                info.el.title = `${props.worker || ''}\n${props.location || props.name || ''}`;
            }
        });
        calendar.render();
        const applyCalendarFilters = () => calendar?.refetchEvents();
        scheduleFilter?.addEventListener('change', applyCalendarFilters);
        workerFilter?.addEventListener('change', applyCalendarFilters);
        if (window.jQuery) {
            jQuery(scheduleFilter).on('select2:select select2:clear', applyCalendarFilters);
            jQuery(workerFilter).on('select2:select select2:clear', applyCalendarFilters);
        }
        window.addEventListener('pageshow', () => window.setTimeout(applyCalendarFilters, 120), { once: true });
    }

    excludeButton?.addEventListener('click', async () => {
        const props = selectedEvent?.extendedProps || {};
        if (props.kind !== 'regular' || !props.canExclude) return;
        const answer = await Swal.fire({
            icon: 'warning', title: '¿Excluir esta jornada?',
            html: '<p class="mb-2">El trabajador no tendrá jornada laboral en esta fecha.</p><p class="mb-2"><strong>No podrá registrar entrada ni salida y el día se considerará descanso.</strong></p><small>Solo se excluirá esta fecha; la plantilla y las demás jornadas no serán modificadas.</small>',
            showCancelButton: true, confirmButtonText: 'Sí, excluir fecha', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc3545'
        });
        if (!answer.isConfirmed) return;
        const body = new FormData();
        body.append('csrf_token', csrf); body.append('assignment_id', String(props.assignmentId || ''));
        body.append('date', selectedEvent.startStr.slice(0, 10));
        const response = await fetch(`${BASE_URL}/servicios/control_personal/excluir_jornada_fecha.php`, { method: 'POST', body });
        const data = await response.json();
        if (!response.ok || !data.ok) return Swal.fire('Atención', data.message || 'No se pudo excluir la jornada.', 'warning');
        detailModal?.hide(); calendar?.refetchEvents();
        Swal.fire({ icon: 'success', title: 'Jornada excluida', text: data.message, timer: 1800, showConfirmButton: false });
    });

    restoreButton?.addEventListener('click', async () => {
        const props = selectedEvent?.extendedProps || {};
        if (!props.canRestore || !props.calendarId) return;
        const answer = await Swal.fire({
            icon: 'question', title: '¿Restaurar la jornada habitual?', showCancelButton: true,
            confirmButtonText: 'Sí, restaurar', cancelButtonText: 'Cancelar'
        });
        if (!answer.isConfirmed) return;
        const body = new FormData(); body.append('csrf_token', csrf); body.append('id', String(props.calendarId));
        const response = await fetch(`${BASE_URL}/servicios/control_personal/restaurar_jornada_fecha.php`, { method: 'POST', body });
        const data = await response.json();
        if (!response.ok || !data.ok) return Swal.fire('Atención', data.message || 'No se pudo restaurar la jornada.', 'warning');
        detailModal?.hide(); calendar?.refetchEvents();
        Swal.fire({ icon: 'success', title: 'Jornada restaurada', timer: 1500, showConfirmButton: false });
    });

    activateView(window.location.hash === '#programacion-especial' ? 'extraordinary' : (window.location.hash === '#recorridos-trabajo' ? 'routes' : 'calendar'));
}

function initControlPersonalSchedules() {
    const form = document.getElementById('scheduleForm');
    if (!form) return;

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('scheduleModal'));
    const dayForm = document.getElementById('scheduleDayForm');
    const dayModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('scheduleDayModal'));
    const entryTimeInput = document.getElementById('entryTime');
    const entryAdvanceInput = document.getElementById('entryAdvanceMinutes');
    const toleranceInput = document.getElementById('toleranceMinutes');
    const exitTimeInput = document.getElementById('exitTime');
    const entryRulePreview = document.getElementById('entryRulePreview');
    const exitRulePreview = document.getElementById('exitRulePreview');

    const scheduleSelector = document.getElementById('scheduleSelector');
    if (scheduleSelector && window.jQuery && jQuery.fn.select2) {
        const $scheduleSelector = jQuery(scheduleSelector);
        $scheduleSelector.select2({
            theme: 'bootstrap4',
            width: '100%',
            placeholder: scheduleSelector.dataset.placeholder || 'Buscar horario',
            minimumResultsForSearch: 0,
            language: {
                noResults: () => 'No se encontraron horarios',
                searching: () => 'Buscando...'
            }
        });
        $scheduleSelector.on('select2:open', () => {
            document.querySelector('.select2-container--open .select2-search__field')?.focus();
        });
        $scheduleSelector.on('change.scheduleSelector', () => scheduleSelector.form?.submit());
    } else {
        scheduleSelector?.addEventListener('change', () => scheduleSelector.form?.submit());
    }

    const timeWithOffset = (time, offset) => {
        const [hours, minutes] = String(time || '').split(':').map(Number);
        if (![hours, minutes].every(Number.isFinite)) return '';
        const total = (((hours * 60 + minutes + offset) % 1440) + 1440) % 1440;
        return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
    };

    const updateScheduleRulePreview = () => {
        const advance = Math.max(0, Number(entryAdvanceInput?.value || 0));
        const tolerance = Math.max(0, Number(toleranceInput?.value || 0));
        if (entryRulePreview) {
            entryRulePreview.textContent = entryTimeInput?.value
                ? `Puede marcar desde ${timeWithOffset(entryTimeInput.value, -advance)}. Se considera puntual hasta ${timeWithOffset(entryTimeInput.value, tolerance)}.`
                : 'Complete la hora de entrada para calcular la ventana.';
        }
        if (exitRulePreview) {
            exitRulePreview.textContent = exitTimeInput?.value
                ? `Antes de ${exitTimeInput.value} será salida anticipada. Desde ${exitTimeInput.value}, salida normal.`
                : 'Complete la hora de salida.';
        }
    };

    [entryTimeInput, entryAdvanceInput, toleranceInput, exitTimeInput].forEach((input) => {
        input?.addEventListener('input', updateScheduleRulePreview);
    });

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
            document.getElementById('entryTime').value = button.dataset.entryTime || '';
            document.getElementById('entryAdvanceMinutes').value = button.dataset.entryAdvance || '0';
            document.getElementById('toleranceMinutes').value = button.dataset.tolerance || '0';
            document.getElementById('exitTime').value = button.dataset.exitTime || '';
            updateScheduleRulePreview();
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

function initControlPersonalCalendar() {
    const form = document.getElementById('calendarDayForm');
    if (!form) return;

    const calendarListWorkerFilter = document.getElementById('calendarListWorkerFilter');
    const calendarWorkerRows = Array.from(document.querySelectorAll('.calendar-day-row'));
    const calendarWorkerNoResults = document.getElementById('calendarWorkerNoResults');
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('calendarDayModal'));
    const typeField = document.getElementById('calendarEventType');
    const scopeField = document.getElementById('calendarScopeType');
    const workerField = document.getElementById('calendarWorkerId');
    const startDateField = document.getElementById('calendarDate');
    const endDateField = document.getElementById('calendarEndDate');
    const nameField = document.getElementById('calendarDayName');
    const workerChecks = Array.from(document.querySelectorAll('.calendar-worker-check'));
    const workersField = document.getElementById('calendarWorkersField');
    const workersError = document.getElementById('calendarWorkersError');
    const selectAllWorkers = document.getElementById('calendarSelectAllWorkers');
    const workerSearch = document.getElementById('calendarWorkerSearch');

    calendarListWorkerFilter?.addEventListener('input', () => {
        const query = normalizarTexto(calendarListWorkerFilter.value);
        let visibleRows = 0;
        calendarWorkerRows.forEach((row) => {
            const workerName = normalizarTexto(row.dataset.workerName || '');
            const visible = query === '' || workerName.includes(query);
            row.classList.toggle('d-none', !visible);
            if (visible) visibleRows++;
        });
        calendarWorkerNoResults?.classList.toggle('d-none', visibleRows > 0);
    });

    function syncFields() {
        const defaultNames = {
            vacation: 'Vacaciones',
            permission: 'Permiso',
            rest: 'Descanso'
        };
        const automaticNames = Object.values(defaultNames);

        if (!nameField.value.trim() || automaticNames.includes(nameField.value.trim())) {
            nameField.value = defaultNames[typeField.value] || '';
        }
        if (!endDateField.value) endDateField.value = startDateField.value;
        document.getElementById('calendarDateLabel').textContent = 'Fecha inicial';
        endDateField.disabled = false;
        endDateField.required = true;
        endDateField.min = startDateField.value || '';

        const isWorker = scopeField.value === 'worker';
        const isSelected = scopeField.value === 'selected';
        document.getElementById('calendarWorkerField')?.classList.toggle('d-none', !isWorker);
        workersField?.classList.toggle('d-none', !isSelected);
        workerField.disabled = !isWorker;
        workerField.required = isWorker;
        workerChecks.forEach((check) => { check.disabled = !isSelected; });
        workersError?.classList.add('d-none');
    }

    function setValue(id, value) {
        const field = document.getElementById(id);
        if (field) field.value = value || '';
    }

    function openCalendarModal(data = {}) {
        form.reset();
        form.classList.remove('was-validated');
        setValue('calendarDayId', data.id);
        setValue('calendarDate', data.date || localDateValue());
        setValue('calendarEndDate', data.endDate || data.date || localDateValue());
        setValue('calendarEventType', data.eventType || 'holiday');
        setValue('calendarDayName', data.name);
        setValue('calendarScopeType', data.scopeType || 'all');
        setValue('calendarWorkerId', data.workerId);
        if (window.jQuery && jQuery(workerField).hasClass('select2-hidden-accessible')) {
            jQuery(workerField).val(data.workerId || '').trigger('change');
        }
        workerChecks.forEach((check) => { check.checked = false; });
        if (selectAllWorkers) selectAllWorkers.checked = false;
        document.querySelectorAll('.calendar-worker-option').forEach((option) => option.classList.remove('d-none'));
        if (workerSearch) workerSearch.value = '';
        document.getElementById('calendarDayModalTitle').textContent = data.id ? 'Editar dia especial' : 'Nuevo dia especial';
        syncFields();
        modal.show();
    }

    typeField.addEventListener('change', syncFields);
    scopeField.addEventListener('change', syncFields);
    selectAllWorkers?.addEventListener('change', () => {
        workerChecks.forEach((check) => {
            if (!check.closest('.calendar-worker-option')?.classList.contains('d-none')) check.checked = selectAllWorkers.checked;
        });
        workersError?.classList.add('d-none');
    });
    workerChecks.forEach((check) => check.addEventListener('change', () => workersError?.classList.add('d-none')));
    workerSearch?.addEventListener('input', () => {
        const query = normalizarTexto(workerSearch.value);
        document.querySelectorAll('.calendar-worker-option').forEach((option) => {
            option.classList.toggle('d-none', query !== '' && !normalizarTexto(option.dataset.search || '').includes(query));
        });
    });
    startDateField.addEventListener('change', () => {
        if (!endDateField.value || endDateField.value < startDateField.value) {
            endDateField.value = startDateField.value;
        }
        syncFields();
    });
    document.getElementById('newCalendarDayBtn')?.addEventListener('click', () => openCalendarModal());
    document.querySelectorAll('.js-edit-calendar-day').forEach((button) => {
        button.addEventListener('click', () => openCalendarModal(button.dataset));
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        syncFields();
        if (scopeField.value === 'selected' && !workerChecks.some((check) => check.checked)) {
            workersError?.classList.remove('d-none');
            return;
        }
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            return;
        }

        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        try {
            const response = await fetch(`${BASE_URL}/servicios/control_personal/guardar_calendario_laboral.php`, {
                method: 'POST',
                body: new FormData(form)
            });
            const data = await response.json();
            if (!data.ok) {
                Swal.fire('Atencion', data.message || 'No se pudo guardar el dia.', 'warning');
                return;
            }
            const month = String(document.getElementById('calendarDate').value || '').slice(0, 7);
            window.location.href = `${BASE_URL}/modulos/control_personal/calendario_laboral.php?mes=${encodeURIComponent(month)}`;
        } catch (error) {
            Swal.fire('Atencion', 'No se pudo guardar el dia.', 'warning');
        } finally {
            submitButton.disabled = false;
        }
    });

    document.querySelectorAll('.js-delete-calendar-day').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!await confirmAction('\u00bfEliminar dia del calendario?')) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('id', button.dataset.id || '');
            const response = await fetch(`${BASE_URL}/servicios/control_personal/eliminar_calendario_laboral.php`, { method: 'POST', body });
            const data = await response.json();
            if (data.ok) window.location.reload();
            else Swal.fire('Atencion', data.message || 'No se pudo eliminar el dia.', 'warning');
        });
    });

    syncFields();
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
    const referenceInput = document.getElementById('locationReference');
    let map = null;
    let marker = null;
    let circle = null;
    let reverseAddressTimer = null;
    let reverseAddressRequest = 0;
    let reverseAddressController = null;

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
        map.fitBounds(circle.getBounds(), { padding: [30, 30], maxZoom: 16 });
    }

    function normalizeCoordinate(input) {
        if (!input || String(input.value).trim() === '') return null;
        const value = Number(String(input.value).trim().replace(',', '.'));
        if (!Number.isFinite(value)) return null;
        input.value = value.toFixed(8);
        return value;
    }

    async function reverseAddress(lat, lng) {
        if (!addressInput || !Number.isFinite(lat) || !Number.isFinite(lng)
            || lat < -90 || lat > 90 || lng < -180 || lng > 180) return;
        const requestId = ++reverseAddressRequest;
        reverseAddressController?.abort();
        reverseAddressController = new AbortController();
        const previousPlaceholder = addressInput.placeholder;
        addressInput.placeholder = 'Buscando dirección para las coordenadas...';
        addressInput.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&accept-language=es&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}`, {
                signal: reverseAddressController.signal,
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) throw new Error('No se pudo consultar la dirección.');
            const data = await response.json();
            if (requestId !== reverseAddressRequest) return;
            addressInput.value = String(data.display_name || '').trim();
            if (!addressInput.value) addressInput.placeholder = 'No se encontró una dirección; puede escribirla manualmente.';
        } catch (error) {
            if (error.name !== 'AbortError' && requestId === reverseAddressRequest) {
                addressInput.placeholder = 'No se pudo obtener la dirección; puede escribirla manualmente.';
            }
        } finally {
            if (requestId === reverseAddressRequest) {
                addressInput.removeAttribute('aria-busy');
                if (addressInput.value) addressInput.placeholder = previousPlaceholder;
            }
        }
    }

    function syncCoordinatesAndAddress(delay = 500) {
        const lat = normalizeCoordinate(latInput);
        const lng = normalizeCoordinate(lngInput);
        if (lat === null || lng === null || lat < -90 || lat > 90 || lng < -180 || lng > 180) return;
        setMapPoint(lat, lng);
        clearTimeout(reverseAddressTimer);
        reverseAddressTimer = setTimeout(() => reverseAddress(lat, lng), delay);
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
        if (referenceInput) referenceInput.value = data.reference || '';
        radius.value = data.radius || '100';
        updateRadiusLabel();
        document.getElementById('locationModalTitle').textContent = data.id ? 'Editar lugar de marcación' : 'Nuevo lugar de marcación';
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
    [latInput, lngInput].forEach((input) => {
        input?.addEventListener('input', () => syncCoordinatesAndAddress(650));
        input?.addEventListener('change', () => syncCoordinatesAndAddress(0));
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        normalizeCoordinate(latInput);
        normalizeCoordinate(lngInput);
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
            if (!await confirmAction('¿Eliminar lugar de marcación?')) return;
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
    const scopeField = document.getElementById('assignmentScopeType');
    const workerField = document.getElementById('assignmentWorkerId');
    const workerFieldBox = document.getElementById('assignmentWorkerField');
    const workersFieldBox = document.getElementById('assignmentWorkersField');
    const workerChecks = Array.from(document.querySelectorAll('.assignment-worker-check'));
    const selectAllWorkers = document.getElementById('assignmentSelectAllWorkers');
    const workerSearch = document.getElementById('assignmentWorkerSearch');
    const workersError = document.getElementById('assignmentWorkersError');
    const availabilitySummary = document.getElementById('assignmentAvailabilitySummary');
    const availabilityTitle = document.getElementById('assignmentAvailabilityTitle');
    const availabilityDetail = document.getElementById('assignmentAvailabilityDetail');
    const availableWorkersBtn = document.getElementById('assignmentAvailableWorkersBtn');
    const conflictField = document.getElementById('assignmentConflictField');
    const conflictHeading = document.getElementById('assignmentConflictHeading');
    const conflictPolicy = document.getElementById('assignmentConflictPolicy');
    const conflictChoices = Array.from(document.querySelectorAll('input[name="assignment_conflict_choice"]'));
    const skipDetail = document.getElementById('assignmentSkipDetail');
    const replaceDetail = document.getElementById('assignmentReplaceDetail');
    const activeAssignmentPeriods = Array.isArray(window.assignmentActivePeriods) ? window.assignmentActivePeriods : [];
    const workerDirectory = new Map(workerChecks.map((check) => {
        const option = check.closest('.assignment-worker-option');
        return [Number(check.value), {
            id: Number(check.value),
            name: option?.dataset.name || 'Trabajador',
            document: option?.dataset.document || 'Sin documento',
            company: option?.dataset.company || 'Sin empresa registrada'
        }];
    }));
    let availableWorkers = [];
    const validFrom = document.getElementById('assignmentValidFrom');
    const validUntil = document.getElementById('assignmentValidUntil');
    const noEnd = document.getElementById('assignmentNoEnd');
    const groupSearch = document.getElementById('assignmentGroupSearch');
    const assignmentGroups = Array.from(document.querySelectorAll('.assignment-group'));
    const assignmentSearchEmpty = document.getElementById('assignmentSearchEmpty');

    function syncConflictChoice(value = 'skip') {
        if (conflictPolicy) conflictPolicy.value = value;
        conflictChoices.forEach((choice) => { choice.checked = choice.value === value; });
    }

    conflictChoices.forEach((choice) => choice.addEventListener('change', () => {
        if (choice.checked) syncConflictChoice(choice.value);
    }));
    syncConflictChoice();

    function refreshConflictVisibility() {
        if (!conflictField || document.getElementById('assignmentId')?.value) return;
        const scope = scopeField?.value || 'all';
        const allWorkerIds = workerChecks.map((check) => Number(check.value)).filter(Boolean);
        const selectedIds = scope === 'worker'
            ? [Number(workerField?.value || 0)].filter(Boolean)
            : (scope === 'selected'
                ? workerChecks.filter((check) => check.checked).map((check) => Number(check.value))
                : allWorkerIds);
        const awaitingSelection = scope !== 'all' && selectedIds.length === 0;
        const targetIds = [...new Set(awaitingSelection ? allWorkerIds : selectedIds)];
        if (targetIds.length === 0) {
            availableWorkers = [];
            availabilitySummary?.classList.add('d-none');
            conflictField.classList.add('d-none');
            syncConflictChoice('skip');
            return;
        }
        const rangeStart = validFrom?.value || localDateValue();
        const rangeEnd = noEnd?.checked ? '9999-12-31' : (validUntil?.value || rangeStart);
        const conflictingWorkers = new Set();

        activeAssignmentPeriods.forEach((assignment) => {
            const workerId = Number(assignment.worker_id || 0);
            if (!targetIds.includes(workerId)) return;
            const currentEnd = assignment.valid_until || '9999-12-31';
            if (assignment.valid_from <= rangeEnd && currentEnd >= rangeStart) conflictingWorkers.add(workerId);
        });

        const hasConflicts = conflictingWorkers.size > 0;
        const availableCount = Math.max(0, targetIds.length - conflictingWorkers.size);
        availableWorkers = targetIds.filter((workerId) => !conflictingWorkers.has(workerId)).map((workerId) => workerDirectory.get(workerId)).filter(Boolean);
        availabilitySummary?.classList.remove('d-none');
        const availabilityCard = availabilitySummary?.querySelector('.assignment-availability-card');
        availabilityCard?.classList.toggle('has-conflicts', hasConflicts && availableCount > 0);
        availabilityCard?.classList.toggle('none-available', availableCount === 0);
        availableWorkersBtn?.classList.toggle('d-none', availableCount === 0);
        if (availabilityTitle) {
            availabilityTitle.textContent = awaitingSelection && availableCount > 0
                ? `${availableCount} ${availableCount === 1 ? 'trabajador disponible' : 'trabajadores disponibles'} para seleccionar`
                : availableCount === 0
                ? 'No hay personal disponible para esta vigencia'
                : `${availableCount} ${availableCount === 1 ? 'trabajador disponible' : 'trabajadores disponibles'} para asignar`;
        }
        if (availabilityDetail) {
            const targetLabel = targetIds.length === 1 ? 'trabajador' : 'trabajadores';
            availabilityDetail.textContent = awaitingSelection
                ? 'Consulta la lista y luego selecciona el personal que deseas asignar.'
                : hasConflicts
                ? `De ${targetIds.length} ${targetLabel}, ${conflictingWorkers.size} ya ${conflictingWorkers.size === 1 ? 'tiene' : 'tienen'} una asignación.`
                : `${targetIds.length === 1 ? 'El' : 'Los'} ${targetIds.length} ${targetLabel} ${targetIds.length === 1 ? 'puede' : 'pueden'} recibir esta asignación.`;
        }
        conflictField.classList.toggle('d-none', awaitingSelection || !hasConflicts);
        if (awaitingSelection || !hasConflicts) syncConflictChoice('skip');
        if (conflictHeading && hasConflicts && !awaitingSelection) {
            const count = conflictingWorkers.size;
            conflictHeading.textContent = count === 1
                ? '1 trabajador ya tiene asignación'
                : `${count} trabajadores ya tienen asignación`;
        }
        if (skipDetail) skipDetail.textContent = `${availableCount} ${availableCount === 1 ? 'recibirá' : 'recibirán'} la nueva; ${conflictingWorkers.size} ${conflictingWorkers.size === 1 ? 'conservará' : 'conservarán'} la actual.`;
        if (replaceDetail) replaceDetail.textContent = `${targetIds.length === 1 ? 'El' : 'Los'} ${targetIds.length} ${targetIds.length === 1 ? 'trabajador recibirá' : 'trabajadores recibirán'} la nueva asignación.`;
    }

    availableWorkersBtn?.addEventListener('click', () => {
        if (!availableWorkers.length) return;
        const rows = availableWorkers.map((worker) => `
            <div class="assignment-available-worker">
                <span><i class="fa-solid fa-user-check"></i></span>
                <div><strong>${escapeHtml(worker.name)}</strong><small>${escapeHtml(worker.document)} · ${escapeHtml(worker.company)}</small></div>
            </div>
        `).join('');
        Swal.fire({
            title: `${availableWorkers.length} ${availableWorkers.length === 1 ? 'trabajador disponible' : 'trabajadores disponibles'}`,
            html: `<div class="assignment-available-workers-list">${rows}</div>`,
            icon: 'success',
            width: 560,
            confirmButtonText: 'Cerrar'
        });
    });

    groupSearch?.addEventListener('input', () => {
        const query = normalizarTexto(groupSearch.value);
        let visibleGroups = 0;

        assignmentGroups.forEach((group) => {
            const rows = Array.from(group.querySelectorAll('.assignment-member-row'));
            const matchesGroup = query !== '' && normalizarTexto(group.dataset.groupSearch || '').includes(query);
            let visibleRows = 0;

            rows.forEach((row) => {
                const matchesRow = query === '' || matchesGroup || normalizarTexto(row.dataset.search || '').includes(query);
                row.classList.toggle('d-none', !matchesRow);
                if (matchesRow) visibleRows++;
            });

            const matches = query === '' || matchesGroup || visibleRows > 0;
            group.classList.toggle('d-none', !matches);
            if (matches) visibleGroups++;

            const bulkbar = group.querySelector('.assignment-group-bulkbar');
            bulkbar?.classList.toggle('d-none', query !== '' && !matchesGroup);

            const count = group.querySelector('.assignment-group-count');
            if (count) {
                const shown = query === '' || matchesGroup ? rows.length : visibleRows;
                count.innerHTML = `<b>${shown}</b> ${shown === 1 ? 'trabajador' : 'trabajadores'}`;
            }

            if (query && matches) {
                const body = group.querySelector('.collapse');
                if (body) bootstrap.Collapse.getOrCreateInstance(body, { toggle: false }).show();
            }
        });

        assignmentSearchEmpty?.classList.toggle('d-none', visibleGroups > 0 || query === '');
    });
    document.getElementById('expandAssignmentGroups')?.addEventListener('click', () => {
        assignmentGroups.filter((group) => !group.classList.contains('d-none')).forEach((group) => {
            const body = group.querySelector('.collapse');
            if (body) bootstrap.Collapse.getOrCreateInstance(body, { toggle: false }).show();
        });
    });
    document.getElementById('collapseAssignmentGroups')?.addEventListener('click', () => {
        assignmentGroups.forEach((group) => {
            const body = group.querySelector('.collapse');
            if (body) bootstrap.Collapse.getOrCreateInstance(body, { toggle: false }).hide();
        });
    });

    const assignmentCalendarElement = document.getElementById('assignmentCalendar');
    const assignmentCalendarModalElement = document.getElementById('assignmentCalendarModal');
    const assignmentCalendarModal = assignmentCalendarModalElement
        ? bootstrap.Modal.getOrCreateInstance(assignmentCalendarModalElement)
        : null;
    const assignmentCalendarContext = document.getElementById('assignmentCalendarContext');
    const journeyDetailModalElement = document.getElementById('journeyDateDetailModal');
    const journeyDetailModal = journeyDetailModalElement ? bootstrap.Modal.getOrCreateInstance(journeyDetailModalElement) : null;
    const journeyDetailForm = document.getElementById('journeyDateDetailForm');
    const resetJourneyDetail = document.getElementById('resetJourneyDateDetail');
    let assignmentCalendar = null;
    let assignmentCalendarWorkerId = '';

    function openJourneyDateDetail(event) {
        const props = event.extendedProps || {};
        if (!['regular', 'program', 'route'].includes(props.kind) || !props.assignmentId || !props.date) return;
        journeyDetailForm?.reset();
        journeyDetailForm?.classList.remove('was-validated');
        document.getElementById('journeyDetailAssignmentId').value = props.assignmentId;
        document.getElementById('journeyDetailDate').value = props.date;
        document.getElementById('journeyDetailWorker').value = props.worker || '';
        document.getElementById('journeyDetailLocation').value = props.location || '';
        document.getElementById('journeyDetailSchedule').value = `${props.entry || '--:--'} - ${props.exit || '--:--'} · ${props.schedule || ''}`;
        document.getElementById('journeyDetailDateLabel').value = new Date(`${props.date}T12:00:00`).toLocaleDateString('es-PE', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
        document.getElementById('journeyDetailActivity').value = props.activity || '';
        document.getElementById('journeyDetailInstructions').value = props.instructions || '';
        const unifiedSummary = document.getElementById('journeyUnifiedSummary');
        const routeStops = Array.isArray(props.stops) ? props.stops : [];
        if (unifiedSummary) {
            const places = [{ destination: props.location || '-', activity: props.activity || '', initial: true }, ...routeStops];
            unifiedSummary.innerHTML = `<div class="journey-unified-schedule">
                <span><i class="fa-regular fa-clock"></i>Jornada laboral</span>
                <strong>${escapeHtml(props.entry || '--:--')} - ${escapeHtml(props.exit || '--:--')}</strong>
                <small>${escapeHtml(props.schedule || '')}</small>
            </div><div class="journey-unified-route">
                <div class="journey-unified-route-title"><i class="fa-solid fa-route"></i>${routeStops.length ? 'Lugares programados' : 'Lugar de la jornada'}</div>
                <div class="journey-unified-places">${places.map((place, index) => {
                    const activity = String(place.activity || '').trim();
                    const estimated = String(place.estimatedTime || '').trim();
                    const content = `<span class="journey-unified-place-number">${index + 1}</span><span><strong>${escapeHtml(place.destination || '-')}</strong>${activity ? `<small>${escapeHtml(activity)}</small>` : ''}${estimated ? `<small><i class="fa-regular fa-clock"></i> Llegada estimada: ${escapeHtml(estimated)}</small>` : ''}</span><i class="fa-solid fa-pen journey-unified-edit-icon"></i>`;
                    return place.initial
                        ? `<button class="journey-unified-place" type="button" data-edit-initial-place>${content}</button>`
                        : `<a class="journey-unified-place" href="${BASE_URL}/modulos/control_personal/programacion_personal.php?worker_id=${encodeURIComponent(props.workerId || '')}#recorridos-trabajo" title="Editar recorrido">${content}</a>`;
                }).join('')}</div>
            </div>`;
            unifiedSummary.classList.remove('d-none');
            unifiedSummary.querySelector('[data-edit-initial-place]')?.addEventListener('click', () => {
                const activityField = document.getElementById('journeyDetailActivity');
                activityField?.focus();
                activityField?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                activityField?.classList.add('is-editing-highlight');
                window.setTimeout(() => activityField?.classList.remove('is-editing-highlight'), 1200);
            });
        }
        const context = document.getElementById('journeyDateDetailContext');
        if (context) context.textContent = `Personalización exclusiva del ${document.getElementById('journeyDetailDateLabel').value}`;
        resetJourneyDetail?.classList.toggle('d-none', !props.customized);
        journeyDetailModal?.show();
    }

    function ensureAssignmentCalendar() {
        if (assignmentCalendar || !assignmentCalendarElement || !window.FullCalendar) return;
        assignmentCalendar = new FullCalendar.Calendar(assignmentCalendarElement, {
            locale: 'es',
            initialView: 'dayGridMonth',
            firstDay: 1,
            height: 'auto',
            dayMaxEvents: 3,
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
            buttonText: { today: 'Hoy', month: 'Mes', list: 'Lista' },
            events(info, success, failure) {
                const url = new URL(`${BASE_URL}/servicios/control_personal/listar_calendario_jornadas.php`);
                url.searchParams.set('start', info.startStr.slice(0, 10));
                url.searchParams.set('end', info.endStr.slice(0, 10));
                url.searchParams.set('worker_id', assignmentCalendarWorkerId);
                fetch(url, { cache: 'no-store' })
                    .then((response) => response.json().then((data) => ({ response, data })))
                    .then(({ response, data }) => {
                        if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudieron cargar las jornadas.');
                        success(data.events || []);
                    })
                    .catch(failure);
            },
            eventDidMount(info) {
                info.el.title = info.event.title || 'Jornada programada';
            },
            eventClick(info) { openJourneyDateDetail(info.event); }
        });
        assignmentCalendar.render();
    }

    document.querySelectorAll('.js-assignment-calendar').forEach((button) => {
        button.addEventListener('click', () => {
            assignmentCalendarWorkerId = button.dataset.workerId || '';
            if (assignmentCalendarContext) {
                assignmentCalendarContext.textContent = `${button.dataset.workerName || 'Trabajador'} · Todas sus asignaciones y jornadas`;
            }
            assignmentCalendarModal?.show();
        });
    });
    assignmentCalendarModalElement?.addEventListener('shown.bs.modal', () => {
        ensureAssignmentCalendar();
        assignmentCalendar?.refetchEvents();
        window.setTimeout(() => assignmentCalendar?.updateSize(), 80);
    });

    journeyDetailModalElement?.addEventListener('shown.bs.modal', () => {
        journeyDetailModalElement.style.zIndex = '1070';
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length) backdrops[backdrops.length - 1].style.zIndex = '1065';
    });
    journeyDetailModalElement?.addEventListener('hidden.bs.modal', () => {
        if (assignmentCalendarModalElement?.classList.contains('show')) document.body.classList.add('modal-open');
    });
    journeyDetailForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!journeyDetailForm.checkValidity()) return journeyDetailForm.classList.add('was-validated');
        try {
            const response = await fetch(`${BASE_URL}/servicios/control_personal/guardar_detalle_jornada.php`, { method: 'POST', body: new FormData(journeyDetailForm) });
            const rawResponse = await response.text();
            let data;
            try {
                data = JSON.parse(rawResponse);
            } catch (error) {
                throw new Error('El servidor no pudo procesar la solicitud.');
            }
            if (!response.ok || !data.ok) throw new Error(data.message || 'Revise los datos ingresados.');
            journeyDetailModal?.hide();
            assignmentCalendar?.refetchEvents();
            await Swal.fire('Jornada actualizada', data.message, 'success');
        } catch (error) {
            await Swal.fire('No se pudo guardar', error.message || 'Inténtelo nuevamente.', 'warning');
        }
    });
    resetJourneyDetail?.addEventListener('click', async () => {
        const confirmation = await Swal.fire({ icon: 'question', title: '¿Restablecer esta jornada?', text: 'Volverá a utilizar la actividad y las indicaciones definidas en la asignación.', showCancelButton: true, confirmButtonText: 'Sí, restablecer', cancelButtonText: 'Cancelar' });
        if (!confirmation.isConfirmed) return;
        const body = new FormData(journeyDetailForm);
        body.set('action', 'reset');
        const response = await fetch(`${BASE_URL}/servicios/control_personal/guardar_detalle_jornada.php`, { method: 'POST', body });
        const data = await response.json();
        if (!response.ok || !data.ok) return Swal.fire('No se pudo restablecer', data.message || 'Inténtelo nuevamente.', 'warning');
        journeyDetailModal?.hide();
        assignmentCalendar?.refetchEvents();
        await Swal.fire('Valores restablecidos', data.message, 'success');
    });

    const groupValidityForm = document.getElementById('groupValidityForm');
    const groupValidityModalElement = document.getElementById('groupValidityModal');
    const groupValidityModal = groupValidityModalElement
        ? bootstrap.Modal.getOrCreateInstance(groupValidityModalElement)
        : null;
    const groupValidityIds = document.getElementById('groupValidityAssignmentIds');
    const groupValidityContext = document.getElementById('groupValidityContext');
    const groupValidFrom = document.getElementById('groupValidFrom');
    const groupValidUntil = document.getElementById('groupValidUntil');
    const groupNoEnd = document.getElementById('groupNoEnd');
    let groupValidityCount = 0;

    function syncGroupNoEnd() {
        if (!groupValidUntil || !groupNoEnd) return;
        groupValidUntil.disabled = groupNoEnd.checked;
        groupValidUntil.required = !groupNoEnd.checked;
        if (groupNoEnd.checked) groupValidUntil.value = '';
    }
    groupNoEnd?.addEventListener('change', syncGroupNoEnd);
    document.querySelectorAll('.js-group-validity').forEach((button) => {
        button.addEventListener('click', () => {
            groupValidityForm?.reset();
            groupValidityForm?.classList.remove('was-validated');
            groupValidityCount = Number(button.dataset.count || 0);
            if (groupValidityIds) groupValidityIds.value = button.dataset.assignmentIds || '';
            if (groupValidityContext) {
                const mixedLabel = button.dataset.uniform === '1' ? '' : ' · Vigencias actuales diferentes';
                groupValidityContext.textContent = `${button.dataset.location || 'Lugar'} · ${button.dataset.schedule || 'Horario'} · ${groupValidityCount} trabajador(es)${mixedLabel}`;
            }
            if (groupValidFrom) groupValidFrom.value = button.dataset.validFrom || '';
            if (groupValidUntil) groupValidUntil.value = button.dataset.validUntil || '';
            if (groupNoEnd) groupNoEnd.checked = button.dataset.uniform === '1' && Boolean(button.dataset.validFrom) && !button.dataset.validUntil;
            syncGroupNoEnd();
            groupValidityModal?.show();
        });
    });
    groupValidityForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!groupValidityForm.checkValidity()) {
            groupValidityForm.classList.add('was-validated');
            return;
        }
        const confirmation = await Swal.fire({
            icon: 'question',
            title: '¿Actualizar la vigencia del grupo?',
            text: `El nuevo periodo se aplicará a ${groupValidityCount} trabajador(es). Sus marcaciones e historial se conservarán.`,
            showCancelButton: true,
            confirmButtonText: 'Sí, aplicar',
            cancelButtonText: 'Cancelar'
        });
        if (!confirmation.isConfirmed) return;
        const response = await fetch(`${BASE_URL}/servicios/control_personal/actualizar_vigencia_asignaciones.php`, {
            method: 'POST', body: new FormData(groupValidityForm)
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            Swal.fire('No se pudo actualizar', data.message || 'Revise el periodo seleccionado.', 'warning');
            return;
        }
        await Swal.fire('Vigencia actualizada', data.message || 'El periodo se aplicó correctamente.', 'success');
        window.location.reload();
    });

    const localIsoDate = (date) => {
        const offset = date.getTimezoneOffset();
        return new Date(date.getTime() - offset * 60000).toISOString().slice(0, 10);
    };
    function setValidityDefaults() {
        const start = new Date();
        validFrom.value = localIsoDate(start);
        validUntil.value = '';
        noEnd.checked = true;
        validUntil.disabled = true;
        validUntil.required = false;
    }
    function syncNoEnd() {
        validUntil.disabled = noEnd.checked;
        validUntil.required = !noEnd.checked;
        if (noEnd.checked) validUntil.value = '';
    }
    noEnd?.addEventListener('change', () => {
        if (!noEnd.checked && !validUntil.value) {
            const start = new Date(`${validFrom.value || localIsoDate(new Date())}T12:00:00`);
            validUntil.value = `${start.getFullYear()}-12-31`;
        }
        syncNoEnd();
    });
    const validityPresetButtons = Array.from(document.querySelectorAll('.js-validity-preset'));
    function selectValidityPreset(selectedButton) {
        validityPresetButtons.forEach((presetButton) => {
            const isSelected = presetButton === selectedButton;
            presetButton.classList.toggle('btn-primary', isSelected);
            presetButton.classList.toggle('btn-outline-primary', !isSelected);
            presetButton.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        });
    }
    function getValidityPresetEnd(startValue, preset) {
        if (!startValue) return '';
        const start = new Date(`${startValue}T12:00:00`);
        if (Number.isNaN(start.getTime())) return '';
        let end = new Date(start);
        if (preset === 'month') end = new Date(start.getFullYear(), start.getMonth() + 1, 0, 12);
        if (preset === 'year') end = new Date(start.getFullYear(), 11, 31, 12);
        if (preset === '6months') { end.setMonth(end.getMonth() + 6); end.setDate(end.getDate() - 1); }
        if (preset === '1year') { end.setFullYear(end.getFullYear() + 1); end.setDate(end.getDate() - 1); }
        if (preset === '2years') { end.setFullYear(end.getFullYear() + 2); end.setDate(end.getDate() - 1); }
        return localIsoDate(end);
    }
    function syncValidityPresetFromDates() {
        if (noEnd?.checked || !validFrom?.value || !validUntil?.value) {
            selectValidityPreset(null);
            return;
        }
        const matchingButton = validityPresetButtons.find((button) => (
            getValidityPresetEnd(validFrom.value, button.dataset.preset) === validUntil.value
        ));
        selectValidityPreset(matchingButton || null);
    }
    validityPresetButtons.forEach((button) => button.addEventListener('click', () => {
        const startValue = validFrom.value || localIsoDate(new Date());
        noEnd.checked = false;
        validUntil.value = getValidityPresetEnd(startValue, button.dataset.preset);
        syncNoEnd();
        selectValidityPreset(button);
        refreshConflictVisibility();
    }));
    validFrom?.addEventListener('input', () => { syncValidityPresetFromDates(); refreshConflictVisibility(); });
    validUntil?.addEventListener('input', () => { syncValidityPresetFromDates(); refreshConflictVisibility(); });
    noEnd?.addEventListener('change', () => { syncValidityPresetFromDates(); refreshConflictVisibility(); });

    function syncAssignmentScope() {
        const isWorker = scopeField.value === 'worker';
        const isSelected = scopeField.value === 'selected';
        workerFieldBox?.classList.toggle('d-none', !isWorker);
        workersFieldBox?.classList.toggle('d-none', !isSelected);
        workerField.disabled = !isWorker;
        workerField.required = isWorker;
        workerChecks.forEach((check) => {
            check.disabled = !isSelected;
        });
        workersError?.classList.add('d-none');
        refreshConflictVisibility();
    }

    function updateAssignmentSelectAll() {
        const visibleChecks = workerChecks.filter((check) => !check.closest('.assignment-worker-option')?.classList.contains('d-none'));
        selectAllWorkers.checked = visibleChecks.length > 0 && visibleChecks.every((check) => check.checked);
        selectAllWorkers.indeterminate = visibleChecks.some((check) => check.checked) && !selectAllWorkers.checked;
    }

    scopeField.addEventListener('change', syncAssignmentScope);
    selectAllWorkers?.addEventListener('change', () => {
        workerChecks.forEach((check) => {
            if (!check.closest('.assignment-worker-option')?.classList.contains('d-none')) {
                check.checked = selectAllWorkers.checked;
            }
        });
        workersError?.classList.add('d-none');
        refreshConflictVisibility();
    });
    workerChecks.forEach((check) => check.addEventListener('change', () => {
        updateAssignmentSelectAll();
        workersError?.classList.add('d-none');
        refreshConflictVisibility();
    }));
    workerField?.addEventListener('change', refreshConflictVisibility);
    workerSearch?.addEventListener('input', () => {
        const query = normalizarTexto(workerSearch.value);
        document.querySelectorAll('.assignment-worker-option').forEach((option) => {
            option.classList.toggle('d-none', query !== '' && !normalizarTexto(option.dataset.search).includes(query));
        });
        updateAssignmentSelectAll();
    });
    syncAssignmentScope();

    document.getElementById('newAssignmentBtn')?.addEventListener('click', () => {
        form.reset();
        setValidityDefaults();
        selectValidityPreset(null);
        if (window.jQuery && jQuery(workerField).hasClass('select2-hidden-accessible')) {
            jQuery(workerField).val('').trigger('change');
        }
        form.classList.remove('was-validated');
        document.getElementById('assignmentId').value = '';
        scopeField.disabled = false;
        scopeField.value = 'all';
        syncConflictChoice('skip');
        conflictField?.classList.add('d-none');
        workerChecks.forEach((check) => { check.checked = false; });
        if (selectAllWorkers) {
            selectAllWorkers.checked = false;
            selectAllWorkers.indeterminate = false;
        }
        if (workerSearch) workerSearch.value = '';
        document.querySelectorAll('.assignment-worker-option').forEach((option) => option.classList.remove('d-none'));
        syncAssignmentScope();
        refreshConflictVisibility();
        document.getElementById('assignmentModalTitle').textContent = 'Nueva asignación';
        modal.show();
    });

    document.querySelectorAll('.js-edit-assignment').forEach((button) => {
        button.addEventListener('click', () => {
            form.reset();
            form.classList.remove('was-validated');
            document.getElementById('assignmentId').value = button.dataset.id || '';
            scopeField.value = 'worker';
            scopeField.disabled = true;
            conflictField?.classList.add('d-none');
            workerField.value = button.dataset.workerId || '';
            if (window.jQuery && jQuery(workerField).hasClass('select2-hidden-accessible')) {
                jQuery(workerField).val(button.dataset.workerId || '').trigger('change');
            }
            document.getElementById('assignmentLocationId').value = button.dataset.locationId || '';
            document.getElementById('assignmentScheduleId').value = button.dataset.scheduleId || '';
            document.getElementById('assignmentActivity').value = button.dataset.activity || '';
            document.getElementById('assignmentInstructions').value = button.dataset.instructions || '';
            validFrom.value = button.dataset.validFrom || localIsoDate(new Date());
            validUntil.value = button.dataset.validUntil || '';
            noEnd.checked = !button.dataset.validUntil;
            syncNoEnd();
            syncValidityPresetFromDates();
            syncAssignmentScope();
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
        if (!noEnd.checked && validUntil.value < validFrom.value) {
            Swal.fire('Revise la vigencia', 'La fecha de finalización no puede ser anterior a la fecha de inicio.', 'warning');
            return;
        }
        if (scopeField.value === 'selected' && !workerChecks.some((check) => check.checked)) {
            workersError?.classList.remove('d-none');
            workersFieldBox?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }
        const isMultipleAssignment = !document.getElementById('assignmentId').value && ['all', 'selected'].includes(scopeField.value);
        if (isMultipleAssignment) {
            const selectedCount = workerChecks.filter((check) => check.checked).length;
            const willReplace = conflictPolicy?.value === 'replace';
            const confirmed = await Swal.fire({
                title: willReplace ? 'Confirmar cambio de asignaciones' : 'Confirmar asignación segura',
                text: willReplace
                    ? 'Las asignaciones activas involucradas se finalizarán y se crearán nuevas. El historial anterior permanecerá disponible.'
                    : (scopeField.value === 'all'
                        ? 'La nueva asignación se aplicará al personal disponible. Quienes ya tengan otra asignación dentro de las fechas elegidas serán omitidos sin modificar sus datos actuales.'
                        : `Se revisarán ${selectedCount} trabajador(es). La nueva asignación se aplicará solo a quienes estén disponibles en esas fechas; los demás conservarán su asignación actual.`),
                icon: willReplace ? 'warning' : 'question',
                showCancelButton: true,
                confirmButtonText: willReplace ? 'Sí, finalizar y crear' : 'Sí, asignar',
                cancelButtonText: 'Cancelar'
            });
            if (!confirmed.isConfirmed) return;
        }
        const response = await fetch(`${BASE_URL}/servicios/control_personal/guardar_asignacion.php`, { method: 'POST', body: new FormData(form) });
        const data = await response.json();
        if (!data.ok) {
            Swal.fire('Atención', data.message || 'No se pudo guardar la asignación.', 'warning');
            return;
        }

        if ((data.skipped_count || 0) > 0 && Array.isArray(data.skipped_conflicts) && data.skipped_conflicts.length > 0) {
            const requested = data.requested_assignment || {};
            const formatDateOnly = (value) => {
                if (!value) return 'Sin fecha final';
                const parts = String(value).slice(0, 10).split('-');
                return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : String(value);
            };
            const requestedPeriod = `${formatDateOnly(requested.valid_from)} - ${formatDateOnly(requested.valid_until)}`;
            const comparisons = data.skipped_conflicts.map((conflict) => `
                <article class="assignment-conflict-comparison">
                    <header>
                        <i class="fa-solid fa-user-clock"></i>
                        <div><strong>${escapeHtml(conflict.full_name || 'Trabajador')}</strong><small>Documento: ${escapeHtml(conflict.document_number || 'No registrado')}</small></div>
                    </header>
                    <div class="assignment-conflict-columns">
                        <section class="is-current">
                            <span class="assignment-conflict-label">Asignación que ya tiene</span>
                            <b>${escapeHtml(conflict.schedule_name || 'Sin horario')}</b>
                            <small><i class="fa-solid fa-location-dot"></i>${escapeHtml(conflict.location_name || 'Sin lugar')}</small>
                            <small><i class="fa-regular fa-calendar"></i>${formatDateOnly(conflict.valid_from)} - ${formatDateOnly(conflict.valid_until)}</small>
                            <small><i class="fa-solid fa-briefcase"></i>${escapeHtml(conflict.activity || 'Sin actividad especificada')}</small>
                        </section>
                        <span class="assignment-conflict-versus"><i class="fa-solid fa-arrow-right"></i></span>
                        <section class="is-requested">
                            <span class="assignment-conflict-label">Nueva asignación solicitada</span>
                            <b>${escapeHtml(requested.schedule_name || 'Sin horario')}</b>
                            <small><i class="fa-solid fa-location-dot"></i>${escapeHtml(requested.location_name || 'Sin lugar')}</small>
                            <small><i class="fa-regular fa-calendar"></i>${requestedPeriod}</small>
                            <small><i class="fa-solid fa-briefcase"></i>${escapeHtml(requested.activity || 'Sin actividad especificada')}</small>
                        </section>
                    </div>
                    <p><i class="fa-solid fa-circle-info"></i>No se aplicó la nueva porque ambas asignaciones coinciden dentro del periodo seleccionado.</p>
                </article>
            `).join('');
            const assignedCount = Number(data.assigned_count || 0);
            await Swal.fire({
                title: assignedCount > 0 ? 'Asignación aplicada parcialmente' : 'No se creó la nueva asignación',
                html: `<div class="assignment-conflict-summary">${assignedCount > 0
                    ? `<p><strong>${assignedCount}</strong> trabajador(es) recibieron la nueva asignación. Los siguientes no fueron modificados:</p>`
                    : '<p>El trabajador ya tiene una asignación dentro de las mismas fechas. Compare los datos:</p>'}${comparisons}</div>`,
                icon: 'info',
                width: 780,
                confirmButtonText: 'Entendido'
            });
            window.location.reload();
            return;
        }

        const details = [`${data.assigned_count || 0} asignación(es) creada(s).`];
        if ((data.skipped_count || 0) > 0) {
            details.push(`${data.skipped_count} trabajador(es) no recibieron la nueva asignación porque ya tenían otra dentro de las fechas seleccionadas; sus datos actuales no fueron modificados.`);
        }
        if ((data.replaced_count || 0) > 0) {
            details.push(`${data.replaced_count} asignación(es) anterior(es) conservada(s) en el historial.`);
        }
        await Swal.fire('Asignaciones actualizadas', details.join(' '), 'success');
        window.location.reload();
    });

    document.querySelectorAll('.js-delete-assignment').forEach((button) => {
        button.addEventListener('click', async () => {
            const confirmation = await Swal.fire({
                title: '¿Desactivar asignación?',
                text: 'Se finalizará la asignación actual conservando el horario, lugar y todas sus marcaciones. El trabajador podrá recibir una nueva asignación.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, desactivar',
                cancelButtonText: 'Cancelar'
            });
            if (!confirmation.isConfirmed) return;
            const body = new FormData();
            body.append('csrf_token', csrf);
            body.append('id', button.dataset.id || '');
            const response = await fetch(`${BASE_URL}/servicios/control_personal/eliminar_asignacion.php`, { method: 'POST', body });
            const data = await response.json();
            if (data.ok) {
                await Swal.fire('Asignación desactivada', data.message || 'El historial se conservó correctamente.', 'success');
                window.location.reload();
            }
            else Swal.fire('Atención', data.message || 'No se pudo desactivar la asignación.', 'warning');
        });
    });

    const historyModalElement = document.getElementById('assignmentHistoryModal');
    const historyModal = historyModalElement ? bootstrap.Modal.getOrCreateInstance(historyModalElement) : null;
    const historyWorker = document.getElementById('assignmentHistoryWorker');
    const historyList = document.getElementById('assignmentHistoryList');
    const formatAssignmentDate = (value) => {
        if (!value) return 'No disponible';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString('es-PE', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };
    document.querySelectorAll('.js-assignment-history').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!historyModal || !historyList) return;
            if (historyWorker) historyWorker.textContent = button.dataset.workerName || '';
            historyList.innerHTML = '<div class="text-center text-muted py-4">Cargando historial...</div>';
            historyModal.show();
            try {
                const response = await fetch(`${BASE_URL}/servicios/control_personal/listar_historial_asignaciones.php?worker_id=${encodeURIComponent(button.dataset.workerId || '')}`);
                const data = await response.json();
                if (!data.ok) throw new Error(data.message || 'No se pudo cargar el historial.');
                const rows = data.history || [];
                historyList.innerHTML = rows.length ? rows.map((row) => {
                    const todayKey = localIsoDate(new Date());
                    const enabled = Number(row.status) === 1;
                    const upcoming = enabled && row.valid_from > todayKey;
                    const active = enabled && !upcoming && (!row.valid_until || row.valid_until >= todayKey);
                    const statusLabel = active ? 'Vigente' : (upcoming ? 'Próxima' : 'Finalizada');
                    return `
                        <article class="assignment-history-item ${active ? 'is-active' : 'is-finished'}">
                            <div class="assignment-history-head">
                                <div>
                                    <strong>${escapeHtml(row.schedule_name || '-')}</strong>
                                    <span><i class="fa-solid fa-location-dot"></i> ${escapeHtml(row.location_name || '-')}</span>
                                </div>
                                <span class="assignment-history-status">${statusLabel}</span>
                            </div>
                            <div class="assignment-history-meta">
                                <span><b>Actividad:</b> ${escapeHtml(row.activity || 'Sin actividad')}</span>
                                <span><b>Vigencia:</b> ${escapeHtml(row.valid_from || '-')} ${row.valid_until ? `al ${escapeHtml(row.valid_until)}` : 'sin fecha final'}</span>
                                <span><b>Inicio:</b> ${escapeHtml(formatAssignmentDate(row.created_at))}</span>
                                <span><b>Registrada por:</b> ${escapeHtml(row.created_by || 'No disponible')}</span>
                                <span><b>Finalización:</b> ${active ? '—' : escapeHtml(formatAssignmentDate(row.deactivated_at))}</span>
                                <span><b>Desactivada por:</b> ${active ? '—' : escapeHtml(row.deactivated_by || 'No disponible')}</span>
                            </div>
                        </article>
                    `;
                }).join('') : '<div class="text-center text-muted py-4">No existen asignaciones registradas.</div>';
            } catch (error) {
                historyList.innerHTML = `<div class="alert alert-warning mb-0">${escapeHtml(error.message || 'No se pudo cargar el historial.')}</div>`;
            }
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
    const observations = document.getElementById('markObservations');
    const assignmentDetails = document.getElementById('markAssignmentDetails');
    const emptyState = document.getElementById('markEmptyState');
    const cameraEmpty = document.getElementById('markCameraEmpty');
    const mapEmpty = document.getElementById('markMapEmpty');
    const permissionHelp = document.getElementById('markPermissionHelp');
    const availabilityNotice = document.getElementById('markAvailabilityNotice');
    const availabilityText = document.getElementById('markAvailabilityText');
    const programField = document.getElementById('markProgramField');
    const programSelect = document.getElementById('markProgramId');
    const currentWorkActionPanel = document.getElementById('currentWorkActionPanel');
    const mobilityActionPanel = document.getElementById('mobilityActionPanel');
    const finishLocationWorkBtn = document.getElementById('finishLocationWorkBtn');
    const startTripBtn = document.getElementById('startTripBtn');
    const addTripStopBtn = document.getElementById('addTripStopBtn');
    const finishTripBtn = document.getElementById('finishTripBtn');
    const returnWithoutArrivalBtn = document.getElementById('returnWithoutArrivalBtn');
    const activeTripPanel = document.getElementById('activeTripPanel');
    const activeTripText = document.getElementById('activeTripText');
    const recentMarksBody = document.getElementById('recentAttendanceMarks');
    const recentTripsBody = document.getElementById('recentAttendanceTrips');
    const attendancePhotoModalElement = document.getElementById('attendancePhotoModal');
    const attendancePhotoModal = attendancePhotoModalElement
        ? bootstrap.Modal.getOrCreateInstance(attendancePhotoModalElement)
        : null;
    const attendancePhotoModalImage = document.getElementById('attendancePhotoModalImage');
    const attendancePhotoModalTitle = document.getElementById('attendancePhotoModalTitle');
    const tripStopMapModalElement = document.getElementById('tripStopMapModal');
    const tripStopMapModal = tripStopMapModalElement ? bootstrap.Modal.getOrCreateInstance(tripStopMapModalElement) : null;
    let tripStopEvidenceMap = null;
    let tripStopEvidenceMarker = null;
    let tripStopEvidenceCircle = null;
    let pendingTripStopCoordinates = null;
    if (!workerField || !entryBtn || !exitBtn || !camera || !canvas || !mapElement) return;

    if (workerField.tagName === 'SELECT' && window.jQuery && jQuery.fn.select2) {
        const $workerField = jQuery(workerField);
        $workerField.select2({
            theme: 'bootstrap4',
            width: '100%',
            placeholder: workerField.dataset.placeholder || 'Buscar trabajador',
            allowClear: true,
            minimumResultsForSearch: 0,
            language: {
                noResults: () => 'No se encontraron trabajadores',
                searching: () => 'Buscando...'
            }
        });
        $workerField.on('select2:open', () => {
            document.querySelector('.select2-container--open .select2-search__field')?.focus();
        });
    }

    if (window.self !== window.top) {
        const warningDiv = document.createElement('div');
        warningDiv.className = 'alert alert-danger mb-3 p-3';
        warningDiv.innerHTML = '<i class="fa-solid fa-circle-exclamation me-2 fs-5"></i><strong>Atención importante:</strong> Estás ingresando al sistema a través de un dominio enmascarado (redirección por iframe de maquinarias.com). Por políticas estrictas de seguridad de los navegadores móviles, <b>la cámara y la ubicación están bloqueadas</b> en este modo.<br><br>Para registrar tu asistencia, debes ingresar directamente desde este enlace seguro: <a href="https://www.servidorlifemaquinarias.com/modulos/control_personal/control_asistencia.php" target="_top" class="alert-link text-decoration-underline">Click aquí para abrir el enlace directo seguro</a>';
        document.querySelector('.page-title')?.after(warningDiv);
    }

    async function runDiagnostics() {
        const diagContainer = document.createElement('div');
        diagContainer.className = 'work-panel mt-3 p-3 bg-light border rounded';
        diagContainer.innerHTML = '<h6 class="mb-2 text-primary fw-bold"><i class="fa-solid fa-square-poll-horizontal me-2"></i>Estado de Permisos en este Equipo</h6><ul id="diagList" class="mb-0 small text-muted d-flex flex-column gap-1" style="list-style:none; padding-left:0;"></ul>';
        document.querySelector('.page-title')?.after(diagContainer);
        
        const list = document.getElementById('diagList');
        if (!list) return;

        const addRow = (label, value, success) => {
            const li = document.createElement('li');
            li.innerHTML = `<strong>${label}:</strong> <span class="badge ${success ? 'text-bg-success' : 'text-bg-danger'}">${value}</span>`;
            list.appendChild(li);
        };

        addRow('Conexión Segura (HTTPS)', window.isSecureContext ? 'SÍ' : 'NO', window.isSecureContext);
        addRow('Acceso a Cámara', !!navigator.mediaDevices?.getUserMedia ? 'Soportado' : 'No soportado', !!navigator.mediaDevices?.getUserMedia);
        addRow('Acceso a GPS', !!navigator.geolocation ? 'Soportado' : 'No soportado', !!navigator.geolocation);

        if (navigator.permissions && navigator.permissions.query) {
            try {
                const camPerm = await navigator.permissions.query({ name: 'camera' });
                addRow('Permiso Cámara (Sitio)', camPerm.state.toUpperCase(), camPerm.state !== 'denied');
                camPerm.onchange = () => window.location.reload();
            } catch (e) {
                addRow('Permiso Cámara (Sitio)', 'No consultable', false);
            }

            try {
                const geoPerm = await navigator.permissions.query({ name: 'geolocation' });
                addRow('Permiso GPS (Sitio)', geoPerm.state.toUpperCase(), geoPerm.state !== 'denied');
                geoPerm.onchange = () => window.location.reload();
            } catch (e) {
                addRow('Permiso GPS (Sitio)', 'No consultable', false);
            }
        } else {
            addRow('Permissions API', 'No soportado en este navegador', false);
        }
    }
    
    runDiagnostics();

    let context = null;
    let currentActiveTrip = null;
    let map = null;
    let locationMarker = null;
    let currentMarker = null;
    let radiusCircle = null;
    let currentPosition = null;
    let cameraStream = null;
    let photoData = '';
    let recentMarksRequestId = 0;
    let recentTripsRequestId = 0;
    let availabilityTimer = null;
    const nextLocationPanel = document.getElementById('nextLocationPanel');
    const nextLocationSelect = document.getElementById('nextLocationSelect');
    const chooseNextLocationBtn = document.getElementById('chooseNextLocationBtn');

    if (nextLocationSelect && window.jQuery && jQuery.fn.select2) {
        const $nextLocationSelect = jQuery(nextLocationSelect);
        $nextLocationSelect.select2({
            theme: 'bootstrap4',
            width: '100%',
            placeholder: 'Buscar lugar de marcación',
            allowClear: true,
            minimumResultsForSearch: 0,
            dropdownParent: jQuery(nextLocationPanel),
            language: {
                noResults: () => 'No se encontraron lugares',
                searching: () => 'Buscando...'
            }
        });
        $nextLocationSelect.on('select2:open', () => {
            document.querySelector('.select2-container--open .select2-search__field')?.focus();
        });
        $nextLocationSelect.on('change.nextLocation', () => {
            if (chooseNextLocationBtn) chooseNextLocationBtn.disabled = !nextLocationSelect.value;
        });

    }

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

    function setAssignmentAvailability(available, message = '') {
        if (availabilityTimer) {
            clearTimeout(availabilityTimer);
            availabilityTimer = null;
        }
        availabilityNotice?.classList.add('d-none');
        entryBtn.disabled = true;
        exitBtn.disabled = true;
        if (!available) {
            programField?.classList.add('d-none');
            currentWorkActionPanel?.classList.add('d-none');
            mobilityActionPanel?.classList.add('d-none');
            startTripBtn?.classList.add('d-none');
            addTripStopBtn?.classList.add('d-none');
            finishTripBtn?.classList.add('d-none');
            returnWithoutArrivalBtn?.classList.add('d-none');
            activeTripPanel?.classList.add('d-none');
        }
        if (observations) {
            observations.disabled = !available;
            if (!available) observations.value = '';
        }
        assignmentDetails?.classList.toggle('d-none', !available);
        emptyState?.classList.toggle('d-none', available);
        cameraEmpty?.classList.toggle('d-none', available);
        mapEmpty?.classList.toggle('d-none', available);
        if (permissionHelp) {
            permissionHelp.textContent = available
                ? 'El navegador solicitará permisos de ubicación y cámara solo al marcar.'
                : 'La ubicación y la cámara permanecerán desactivadas hasta tener una asignación activa.';
        }
        if (!available) {
            value('markEmptyStateText', message || 'No tienes un horario ni un lugar de marcación asignados. Comunícate con el administrador para poder registrar tu asistencia.');
            ['markWorkerName', 'markLocationName', 'markScheduleName', 'markActivity', 'markWorkDate', 'markEntryOfficial', 'markEntryWindow', 'markExitOfficial', 'markExitWindow', 'markRadius'].forEach((id) => value(id, '-'));
            value('markEntryTolerance', '');
            currentPosition = null;
            photoData = '';
            photoPreview?.classList.add('d-none');
            if (cameraStream) {
                cameraStream.getTracks().forEach((track) => track.stop());
                cameraStream = null;
                camera.srcObject = null;
            }
        }
    }

    function renderRecentPhoto(mark, type) {
        if (!mark?.photo_path) {
            return `<span class="btn btn-sm btn-outline-secondary disabled" title="Sin foto de ${type}"><i class="fa-solid fa-image"></i></span>`;
        }
        const colorClass = type === 'entrada' ? 'btn-outline-success' : 'btn-outline-primary';
        const photoUrl = `${BASE_URL}/${encodeURI(mark.photo_path)}`;
        const title = `Foto de ${type}`;
        return `<button class="btn btn-sm ${colorClass} js-view-attendance-photo" type="button" data-photo-url="${escapeHtml(photoUrl)}" data-photo-title="${escapeHtml(title)}" title="Ver ${escapeHtml(title.toLowerCase())}"><i class="fa-solid fa-image"></i></button>`;
    }

    function renderRecentMarks(rows, emptyMessage = 'No hay marcaciones registradas para este trabajador.') {
        if (!recentMarksBody) return;
        const marks = rows.flatMap((row) => {
            const common = {
                date: row.date,
                worker: row.worker
            };
            return [
                row.entry ? { ...common, ...row.entry, type: 'Entrada', typeKey: 'entrada' } : null,
                row.exit ? { ...common, ...row.exit, type: 'Salida', typeKey: 'salida' } : null
            ].filter(Boolean);
        });

        if (!marks.length) {
            recentMarksBody.innerHTML = `<tr><td colspan="8" class="text-muted text-center py-4">${escapeHtml(emptyMessage)}</td></tr>`;
            return;
        }

        const statusMeta = (status) => ({
            puntual: { label: 'Puntual', class: 'text-bg-success' },
            tardanza: { label: 'Tardanza', class: 'text-bg-warning' },
            salida_valida: { label: 'Salida', class: 'text-bg-primary' },
            salida_anticipada: { label: 'Salida anticipada', class: 'text-bg-early-exit' },
            fuera_del_radio: { label: 'Fuera del radio', class: 'text-bg-danger' }
        }[status] || { label: '-', class: 'text-bg-secondary' });

        recentMarksBody.innerHTML = marks.map((mark) => {
            const status = statusMeta(mark.status);
            return `<tr>
                <td class="text-nowrap">${escapeHtml(mark.date)}</td>
                <td><strong>${escapeHtml(mark.time)}</strong></td>
                <td><span class="attendance-mark-type attendance-mark-type-${escapeHtml(mark.typeKey)}">${escapeHtml(mark.type)}</span></td>
                <td>${escapeHtml(mark.worker)}</td>
                <td>${escapeHtml(mark.location)}</td>
                <td class="text-nowrap">${escapeHtml(mark.distance)} m</td>
                <td><span class="badge ${escapeHtml(status.class)}">${escapeHtml(status.label)}</span></td>
                <td><div class="d-flex">${renderRecentPhoto(mark, mark.typeKey)}</div></td>
            </tr>`;
        }).join('');
    }

    async function loadRecentMarks(workerId) {
        if (!recentMarksBody) return;
        const requestId = ++recentMarksRequestId;
        if (!workerId) {
            renderRecentMarks([], 'Seleccione un trabajador para consultar sus registros recientes.');
            return;
        }

        recentMarksBody.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando registros...</td></tr>';
        try {
            const response = await fetch(`${BASE_URL}/servicios/control_personal/listar_marcaciones_recientes.php?worker_id=${encodeURIComponent(workerId)}`);
            const data = await response.json();
            if (requestId !== recentMarksRequestId) return;
            if (!data.ok) {
                renderRecentMarks([], data.message || 'No se pudieron cargar los registros recientes.');
                return;
            }
            renderRecentMarks(data.rows || []);
        } catch (error) {
            if (requestId !== recentMarksRequestId) return;
            renderRecentMarks([], 'No se pudieron cargar los registros recientes.');
        }
    }

    function formatTripDuration(totalSeconds, startedAt = '', endedAt = '') {
        let minutes;
        if (startedAt && endedAt) {
            const minuteTimestamp = value => {
                const normalized = String(value).replace(' ', 'T');
                const date = new Date(normalized);
                if (Number.isNaN(date.getTime())) return null;
                date.setSeconds(0, 0);
                return date.getTime();
            };
            const start = minuteTimestamp(startedAt);
            const end = minuteTimestamp(endedAt);
            minutes = start !== null && end !== null ? Math.max(0, Math.round((end - start) / 60000)) : null;
        }
        if (!Number.isFinite(minutes)) minutes = Math.max(0, Math.floor(Number(totalSeconds || 0) / 60));
        const hours = Math.floor(minutes / 60);
        const remaining = minutes % 60;
        return hours > 0 ? `${hours} h ${remaining} min` : `${minutes} min`;
    }

    function renderRecentTrips(rows, emptyMessage = 'No hay desplazamientos laborales registrados para este trabajador.') {
        if (!recentTripsBody) return;
        if (!rows.length) {
            recentTripsBody.innerHTML = `<tr><td colspan="8" class="text-muted text-center py-4">${escapeHtml(emptyMessage)}</td></tr>`;
            return;
        }
        recentTripsBody.innerHTML = rows.map((trip) => {
            const inProgress = trip.status === 'en_ruta';
            return `<tr${inProgress ? ' class="table-warning"' : ''}>
                <td class="text-nowrap">${escapeHtml(trip.date)}</td>
                <td><strong>${escapeHtml(trip.started_at ? String(trip.started_at).slice(11, 16) : '-')}</strong></td>
                <td><strong>${escapeHtml(trip.ended_at ? String(trip.ended_at).slice(11, 16) : '-')}</strong></td>
                <td class="text-nowrap">${escapeHtml(formatTripDuration(trip.duration_seconds, trip.started_at, trip.ended_at))}</td>
                <td>${escapeHtml(trip.origin || '-')}</td>
                <td><strong>${escapeHtml(trip.first_destination || '-')}</strong></td>
                <td>${escapeHtml(trip.arrival?.activity || (inProgress ? 'Pendiente' : '-'))}</td>
                <td><span class="badge ${inProgress || trip.completion_type === 'returned_without_arrival' ? 'text-bg-warning' : 'text-bg-success'}"><i class="fa-solid ${inProgress ? 'fa-route' : (trip.completion_type === 'returned_without_arrival' ? 'fa-triangle-exclamation' : 'fa-circle-check')} me-1"></i>${inProgress ? 'En curso' : (trip.completion_type === 'returned_without_arrival' ? 'Regreso con incidencia' : 'Finalizado')}</span></td>
            </tr>`;
        }).join('');
    }

    recentTripsBody?.addEventListener('click', event => {
        const button = event.target.closest('.js-trip-stop-evidence');
        if (!button) return;
        const latitude = Number(button.dataset.latitude);
        const longitude = Number(button.dataset.longitude);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;
        document.getElementById('tripStopMapDestination').textContent = button.dataset.destination || '-';
        document.getElementById('tripStopMapTitle').textContent = button.dataset.activity === 'Regreso al lugar de trabajo' ? 'Evidencia de regreso' : (button.dataset.kind === 'arrival' ? 'Evidencia de llegada' : 'Evidencia de la visita');
        document.getElementById('tripStopMapActivity').textContent = button.dataset.activity || '-';
        document.getElementById('tripStopMapDateTime').textContent = `${button.dataset.date || '-'} · ${button.dataset.time || '-'}`;
        document.getElementById('tripStopMapCoordinates').textContent = `${latitude.toFixed(6)}, ${longitude.toFixed(6)}`;
        const distance = button.dataset.distance;
        const radius = Number(button.dataset.radius || 0);
        document.getElementById('tripStopMapDistance').textContent = distance !== '' ? `${Number(distance).toFixed(1)} m del punto · radio ${radius} m` : '-';
        document.getElementById('tripStopMapCompletion').textContent = button.dataset.completionDate ? `${button.dataset.completionDate} · ${button.dataset.completionTime || '-'}` : 'Pendiente';
        document.getElementById('tripStopMapAddress').textContent = button.dataset.address || '-';
        document.getElementById('tripStopMapObservation').textContent = button.dataset.observations || 'Sin observaciones';
        const isArrival = button.dataset.kind === 'arrival';
        document.getElementById('tripStopMapDistanceBox')?.classList.toggle('d-none', !isArrival);
        document.getElementById('tripStopMapCompletionBox')?.classList.toggle('d-none', !isArrival);
        document.getElementById('tripStopMapAddressBox')?.classList.toggle('d-none', !isArrival);
        document.getElementById('tripStopMapObservationBox')?.classList.toggle('d-none', !isArrival);
        pendingTripStopCoordinates = { latitude, longitude, destination: button.dataset.destination || 'Punto visitado', locationLatitude:button.dataset.locationLatitude !== '' ? Number(button.dataset.locationLatitude) : NaN, locationLongitude:button.dataset.locationLongitude !== '' ? Number(button.dataset.locationLongitude) : NaN, radius };
        tripStopMapModal?.show();
    });

    tripStopMapModalElement?.addEventListener('shown.bs.modal', () => {
        if (!pendingTripStopCoordinates || !window.L) return;
        const point = [pendingTripStopCoordinates.latitude, pendingTripStopCoordinates.longitude];
        if (!tripStopEvidenceMap) {
            tripStopEvidenceMap = L.map('tripStopEvidenceMap');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(tripStopEvidenceMap);
        }
        tripStopEvidenceMap.invalidateSize();
        if (!tripStopEvidenceMarker) tripStopEvidenceMarker = L.marker(point);
        if (!tripStopEvidenceMap.hasLayer(tripStopEvidenceMarker)) tripStopEvidenceMarker.addTo(tripStopEvidenceMap);
        tripStopEvidenceMarker.setLatLng(point).bindPopup(pendingTripStopCoordinates.destination).openPopup();
        const officialPoint = [pendingTripStopCoordinates.locationLatitude, pendingTripStopCoordinates.locationLongitude];
        if (Number.isFinite(officialPoint[0]) && Number.isFinite(officialPoint[1]) && pendingTripStopCoordinates.radius > 0) {
            if (!tripStopEvidenceCircle) tripStopEvidenceCircle = L.circle(officialPoint, { color:'#2563eb',fillColor:'#3b82f6',fillOpacity:.1 });
            if (!tripStopEvidenceMap.hasLayer(tripStopEvidenceCircle)) tripStopEvidenceCircle.addTo(tripStopEvidenceMap);
            tripStopEvidenceCircle.setLatLng(officialPoint).setRadius(pendingTripStopCoordinates.radius);
            tripStopEvidenceMap.fitBounds(L.latLngBounds([point,officialPoint]), { padding:[30,30],maxZoom:17 });
        } else {
            if (tripStopEvidenceCircle) { tripStopEvidenceCircle.remove(); tripStopEvidenceCircle = null; }
            tripStopEvidenceMap.setView(point, 17);
        }
        setTimeout(() => tripStopEvidenceMap?.invalidateSize({pan:false}), 100);
    });

    async function loadRecentTrips(workerId) {
        if (!recentTripsBody) return;
        const requestId = ++recentTripsRequestId;
        if (!workerId) {
            renderRecentTrips([], 'Seleccione un trabajador para consultar sus desplazamientos.');
            return;
        }
        recentTripsBody.innerHTML = '<tr><td colspan="9" class="text-muted text-center py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando desplazamientos...</td></tr>';
        try {
            const response = await fetch(`${BASE_URL}/servicios/control_personal/listar_desplazamientos_recientes.php?worker_id=${encodeURIComponent(workerId)}`);
            const data = await response.json();
            if (requestId !== recentTripsRequestId) return;
            if (!data.ok) {
                renderRecentTrips([], data.message || 'No se pudieron cargar los desplazamientos.');
                return;
            }
            renderRecentTrips(data.rows || []);
        } catch (error) {
            if (requestId !== recentTripsRequestId) return;
            renderRecentTrips([], 'No se pudieron cargar los desplazamientos.');
        }
    }

    recentMarksBody?.addEventListener('click', (event) => {
        const button = event.target.closest('.js-view-attendance-photo');
        if (!button || !attendancePhotoModal || !attendancePhotoModalImage) return;
        const title = button.dataset.photoTitle || 'Foto de marcación';
        attendancePhotoModalImage.src = button.dataset.photoUrl || '';
        attendancePhotoModalImage.alt = title;
        if (attendancePhotoModalTitle) attendancePhotoModalTitle.textContent = title;
        attendancePhotoModal.show();
    });

    attendancePhotoModalElement?.addEventListener('hidden.bs.modal', () => {
        if (attendancePhotoModalImage) attendancePhotoModalImage.src = '';
    });

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

        const assignment = context.exit_location || context.assignment;
        const temporaryLocation = context.exit_location?.is_temporary_location == 1;
        const locationLat = Number(assignment.latitude);
        const locationLng = Number(assignment.longitude);
        const radius = Number(assignment.radius_meters || 100);
        const locationPoint = [locationLat, locationLng];

        if (!locationMarker) locationMarker = L.marker(locationPoint).addTo(map);
        locationMarker.setLatLng(locationPoint).bindPopup(temporaryLocation ? `Destino temporal: ${assignment.name || ''}` : (context.exit_location ? 'Lugar de salida' : 'Lugar de entrada'));

        if (temporaryLocation) {
            if (radiusCircle) { radiusCircle.remove(); radiusCircle = null; }
        } else {
            if (!radiusCircle) radiusCircle = L.circle(locationPoint, { radius, color: '#1457d9', fillColor: '#1457d9', fillOpacity: 0.12 }).addTo(map);
            radiusCircle.setLatLng(locationPoint);
            radiusCircle.setRadius(radius);
        }

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
        currentActiveTrip = null;
        if (!workerId) {
            context = null;
            setAssignmentAvailability(false, 'Seleccione un trabajador para consultar su asignación y registrar asistencia.');
            renderStatuses([{ text: 'Seleccione trabajador', className: 'text-bg-secondary' }]);
            loadRecentMarks('');
            loadRecentTrips('');
            return;
        }

        loadRecentMarks(workerId);
        loadRecentTrips(workerId);

        const selectedProgramId = programSelect?.value || '';
        const response = await fetch(`${BASE_URL}/servicios/control_personal/contexto_marcacion.php?worker_id=${encodeURIComponent(workerId)}&program_id=${encodeURIComponent(selectedProgramId)}`);
        const data = await response.json();
        if (!data.ok) {
            context = null;
            setAssignmentAvailability(false, 'No tienes un horario ni un lugar de marcación asignados. Comunícate con el administrador para poder registrar tu asistencia.');
            renderStatuses([{ text: data.message || 'Sin asignación activa', className: 'text-bg-warning' }]);
            return;
        }

        context = data;
        const programs = data.programs || [];
        if (programSelect) {
            const currentId = String(data.program?.id || '');
            programSelect.innerHTML = programs.map(program => `<option value="${program.id}">${escapeHtml(program.time)} · ${escapeHtml(program.location)} · ${escapeHtml(program.schedule)}</option>`).join('');
            programSelect.value = currentId;
            programField?.classList.toggle('d-none', programs.length <= 1);
        }
        setAssignmentAvailability(true);
        const assignment = data.assignment;
        const day = data.schedule_day || {};
        const calendarEvent = data.calendar_event || null;
        const hasSchedule = !!day.id;
        const finalPlannedStop = data.final_planned_stop || null;
        const nextPlannedStop = data.next_planned_stop || null;
        const hasPendingRoute = !!nextPlannedStop;
        const routeCompleted = !!finalPlannedStop && !hasPendingRoute && data.waiting_next_destination === true;
        const currentLocationId = Number(data.exit_location?.id || assignment.location_id || 0);
        const baseLocationId = Number(assignment.location_id || 0);
        const isAwayFromBase = !finalPlannedStop && (data.exit_location?.is_temporary_location == 1 || (currentLocationId > 0 && baseLocationId > 0 && currentLocationId !== baseLocationId));
        const currentPlannedStop = (data.planned_stops || []).find(stop => Number(stop.location_id || 0) === currentLocationId);
        value('markWorkerName', `${assignment.full_name} - ${assignment.document_number}`);
        value('markLocationName', assignment.location_name);
        const exitLocationLabel = document.getElementById('markExitLocationLabel');
        if (exitLocationLabel) exitLocationLabel.textContent = finalPlannedStop ? 'Lugar final del recorrido' : (isAwayFromBase ? 'Ubicación actual' : 'Lugar de salida');
        value('markExitLocationName', finalPlannedStop
            ? `${finalPlannedStop.destination}${routeCompleted ? ' · habilitado' : ' · pendiente'}`
            : `${data.exit_location?.name || assignment.location_name}`);
        value('markScheduleName', assignment.schedule_name);
        value('markActivity', assignment.activity || '-');
        value('markWorkDate', data.work_date_formatted || '-');
        value('markEntryOfficial', formatTime(day.entry_time || day.entry_start));
        value('markEntryWindow', `${formatTime(day.entry_start)}-${formatTime(day.entry_end)}`);
        value('markEntryTolerance', `(${Number(day.tolerance_minutes || 0)} min tolerancia)`);
        value('markExitOfficial', formatTime(day.exit_time || day.exit_start));
        value('markExitWindow', formatTime(day.exit_time || day.exit_start));
        value('markRadius', `${assignment.radius_meters} metros`);
        const hasEntryMark = data.marks.some((mark) => mark.mark_type === 'entrada');
        const hasExitMark = data.marks.some((mark) => mark.mark_type === 'salida');
        const activeTrip = data.active_trip || null;
        const waitingNextDestination = data.waiting_next_destination === true;
        const canChooseNextLocation = data.can_self_mark === true && waitingNextDestination && !activeTrip && !finalPlannedStop;
        currentActiveTrip = activeTrip;
        nextLocationPanel?.classList.toggle('d-none', !canChooseNextLocation);
        if (nextLocationSelect) {
            Array.from(nextLocationSelect.options).forEach(option => {
                option.disabled = Number(option.value || 0) === currentLocationId;
            });
            if (!canChooseNextLocation) {
                nextLocationSelect.value = '';
                if (window.jQuery && jQuery.fn.select2) jQuery(nextLocationSelect).trigger('change.select2');
            }
        }
        if (chooseNextLocationBtn) chooseNextLocationBtn.disabled = !canChooseNextLocation || !nextLocationSelect?.value;
        const destinationField = document.getElementById('tripDestination');
        if (destinationField && !currentActiveTrip) {
            if (data.next_planned_stop?.location_id) destinationField.value = String(data.next_planned_stop.location_id);
        }
        const entryAvailability = data.entry_availability || {};
        const entryTooEarly = hasSchedule && !hasEntryMark && entryAvailability.available === false;
        const canFinishCurrentWork = hasEntryMark && !hasExitMark && !activeTrip && !waitingNextDestination;
        currentWorkActionPanel?.classList.toggle('d-none', !canFinishCurrentWork);
        mobilityActionPanel?.classList.toggle('d-none', !hasEntryMark || hasExitMark);
        value('currentWorkLocation', data.exit_location?.name || assignment.location_name || '-');
        value('currentWorkActivity', currentPlannedStop?.activity || assignment.activity || 'Actividad del lugar');
        entryBtn.disabled = !hasSchedule || hasEntryMark || entryTooEarly;
        const canFinishJourneyHere = waitingNextDestination && !activeTrip && !finalPlannedStop && data.exit_location?.is_temporary_location != 1;
        exitBtn.disabled = !hasSchedule || hasExitMark || !hasEntryMark || !!activeTrip || (isAwayFromBase && !canFinishJourneyHere) || (!!finalPlannedStop && !routeCompleted);
        finishLocationWorkBtn?.classList.toggle('d-none', !canFinishCurrentWork);
        const canStartRouteTrip = !!finalPlannedStop && waitingNextDestination && !routeCompleted && !activeTrip;
        startTripBtn?.classList.toggle('d-none', !canStartRouteTrip);
        if (startTripBtn) startTripBtn.innerHTML = nextPlannedStop?.destination
            ? `<i class="fa-solid fa-route me-2"></i>Ir a ${escapeHtml(nextPlannedStop.destination)}`
            : (isAwayFromBase
                ? `<i class="fa-solid fa-arrow-rotate-left me-2"></i>Registrar regreso a ${escapeHtml(assignment.location_name)}`
                : '<i class="fa-solid fa-person-walking-arrow-right me-2"></i>Salida temporal');
        const isFreeTemporaryTrip = !!activeTrip && !finalPlannedStop && !activeTrip.first_destination_location_id;
        const isReturningToBase = !!activeTrip && !finalPlannedStop && isAwayFromBase
            && Number(activeTrip.first_destination_location_id || 0) === baseLocationId;
        // En un recorrido programado el destino ya está definido. No se ofrecen
        // acciones adicionales que puedan confundirse con la llegada real.
        addTripStopBtn?.classList.add('d-none');
        finishTripBtn?.classList.toggle('d-none', !activeTrip || isFreeTemporaryTrip);
        returnWithoutArrivalBtn?.classList.toggle('d-none', !isFreeTemporaryTrip);
        if (finishTripBtn && activeTrip) {
            const arrivalDestination = isReturningToBase ? assignment.location_name : (activeTrip.first_destination || 'Destino seleccionado');
            finishTripBtn.innerHTML = `<span class="attendance-confirm-arrival-icon"><i class="fa-solid ${isReturningToBase ? 'fa-house-circle-check' : 'fa-location-crosshairs'}"></i></span><span><strong>${isReturningToBase ? 'Confirmar regreso' : 'Confirmar llegada'}</strong><small>${escapeHtml(arrivalDestination)}</small></span>`;
        }
        activeTripPanel?.classList.toggle('d-none', !activeTrip);
        if (activeTripText && activeTrip) activeTripText.textContent = `${activeTrip.first_destination} · iniciado ${formatTime(String(activeTrip.started_at).slice(11))}`;
        if (entryTooEarly) {
            const availableFrom = entryAvailability.available_from || formatTime(day.entry_start);
            const officialEntry = formatTime(day.entry_time || day.entry_start);
            if (availabilityText) availabilityText.textContent = `Tu hora de entrada es ${officialEntry}. Podrás registrar tu entrada desde las ${availableFrom}.`;
            availabilityNotice?.classList.remove('d-none');
            const delay = Math.max(1, Number(entryAvailability.seconds_remaining || 1)) * 1000 + 500;
            availabilityTimer = setTimeout(loadMarkContext, Math.min(delay, 2147483647));
        }
        renderStatuses([
            { text: hasSchedule ? (calendarEvent?.name || 'Horario disponible') : (calendarEvent?.name || 'Sin horario para hoy'), className: hasSchedule ? 'text-bg-success' : 'text-bg-warning' },
            { text: hasEntryMark ? 'Entrada registrada' : (entryTooEarly ? `Entrada desde ${entryAvailability.available_from}` : 'Entrada no registrada'), className: hasEntryMark ? 'text-bg-primary' : (entryTooEarly ? 'text-bg-warning' : 'text-bg-secondary') },
            { text: hasExitMark ? 'Salida registrada' : 'Salida no registrada', className: hasExitMark ? 'text-bg-primary' : 'text-bg-secondary' },
            ...(activeTrip ? [{ text: 'Desplazamiento en curso', className: 'text-bg-warning' }] : []),
            ...(isAwayFromBase ? [{ text: 'Fuera del lugar habitual', className: 'text-bg-info' }] : []),
            ...(waitingNextDestination ? [{
                text: routeCompleted ? 'Recorrido completado' : (nextPlannedStop?.destination ? `Siguiente destino: ${nextPlannedStop.destination}` : 'Selecciona tu siguiente lugar'),
                className: routeCompleted ? 'text-bg-success' : (nextPlannedStop?.destination ? 'text-bg-info' : 'text-bg-warning')
            }] : []),
        ]);
        if (permissionHelp && finalPlannedStop) {
            permissionHelp.textContent = routeCompleted
                ? `Recorrido completado. La salida está habilitada en ${finalPlannedStop.destination}.`
                : `La salida se habilitará en ${finalPlannedStop.destination} después de completar todos los lugares y finalizar el último trabajo.`;
        } else if (permissionHelp && isAwayFromBase && !waitingNextDestination) {
            permissionHelp.textContent = `Finaliza el trabajo de ${data.exit_location?.name || assignment.location_name} para elegir otro destino o terminar tu jornada.`;
        } else if (permissionHelp && waitingNextDestination) {
            permissionHelp.textContent = 'Trabajo finalizado. Puedes elegir otro destino o marcar tu salida en este lugar.';
        }
        updateMap();
    }

    function requestPosition() {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                reject(new Error('El navegador no soporta ubicacion GPS.'));
                return;
            }
            navigator.geolocation.getCurrentPosition(resolve, (error) => {
                let msg = 'Error en GPS: ';
                switch(error.code) {
                    case 1: // PERMISSION_DENIED
                        msg += 'Permiso de ubicación denegado en el navegador o GPS desactivado.';
                        break;
                    case 2: // POSITION_UNAVAILABLE
                        msg += 'Señal de ubicación no disponible en esta zona.';
                        break;
                    case 3: // TIMEOUT
                        msg += 'Tiempo de espera agotado al obtener ubicación.';
                        break;
                    default:
                        msg += error.message || 'Error desconocido.';
                }
                reject(new Error(msg));
            }, {
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
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
        } catch (err) {
            console.warn('Fallo facingMode user, intentando video generico...', err);
            try {
                cameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            } catch (fallbackErr) {
                let msg = 'Error en Cámara: ';
                if (fallbackErr.name === 'NotAllowedError' || fallbackErr.name === 'PermissionDeniedError') {
                    msg += 'Permiso de cámara denegado.';
                } else if (fallbackErr.name === 'NotFoundError' || fallbackErr.name === 'DevicesNotFoundError') {
                    msg += 'No se encontró cámara frontal.';
                } else {
                    msg += fallbackErr.message || 'No se pudo acceder a la cámara.';
                }
                throw new Error(msg);
            }
        }
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
        if (window.self !== window.top) {
            throw new Error('El sistema está cargado dentro de un marco (iframe) de redirección. Por seguridad de tu navegador, los accesos a cámara y GPS están bloqueados. Por favor, ingresa desde: https://www.servidorlifemaquinarias.com');
        }
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

        const within = distance <= Number(assignment.radius_meters || 0);
        renderStatuses([
            { text: within ? 'Dentro del radio' : 'Fuera del radio', className: within ? 'text-bg-success' : 'text-bg-danger' },
        ]);

        updateMap();
        const address = await reverseCurrentAddress(currentPosition.latitude, currentPosition.longitude);
        return { distance, address };
    }

    async function mark(type) {
        if (type === 'salida') {
            const confirmation = await Swal.fire({
                icon: 'question',
                title: '¿Finalizar tu jornada?',
                html: '<p class="mb-2">Al marcar tu salida finalizarás la jornada laboral y ya no podrás iniciar nuevos desplazamientos.</p><p class="small text-muted mb-0"><i class="fa-solid fa-location-crosshairs me-1"></i>La salida se validará con tu ubicación GPS actual.</p>',
                showCancelButton: true,
                confirmButtonText: '<i class="fa-solid fa-right-from-bracket me-2"></i>Marcar mi salida',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#2563eb',
                reverseButtons: true,
                focusCancel: true
            });
            if (!confirmation.isConfirmed) return;
        }
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
            form.append('program_id', String(context?.program?.id || ''));
            form.append('latitude', String(currentPosition.latitude));
            form.append('longitude', String(currentPosition.longitude));
            form.append('accuracy', String(currentPosition.accuracy));
            form.append('address', prepared.address || '');
            form.append('observations', document.getElementById('markObservations')?.value || '');
            form.append('photo_data', image);

            const response = await fetch(`${BASE_URL}/servicios/control_personal/registrar_marcacion.php`, { method: 'POST', body: form });
            const data = await response.json();
            if (!data.ok) {
                Swal.fire(data.title || 'Atención', data.message || 'No se pudo registrar la marcación.', 'warning');
                return;
            }
            localStorage.setItem('attendance-marks-updated-at', String(Date.now()));
            await Swal.fire({
                icon: 'success',
                title: 'Registrado',
                html: `${escapeHtml(data.message)}<br>Distancia: <strong>${escapeHtml(data.distance_meters)} m</strong><br>Estado: <strong>${escapeHtml(data.status_label || '-')}</strong>`,
            });
            window.location.reload();
        } catch (error) {
            console.error('Error al marcar:', error);
            Swal.fire({
                icon: 'warning',
                title: 'Atención',
                html: `<strong>No se pudo registrar la marcación:</strong><br><br>` +
                      `<div class="alert alert-danger text-start small mb-0">` +
                      `${escapeHtml(error.message || String(error))}` +
                      `</div><br>` +
                      `Asegúrese de otorgar permisos de cámara y ubicación en su navegador.`,
            });
        } finally {
            button.disabled = true;
            button.innerHTML = originalText;
            loadMarkContext();
        }
    }

    if (workerField.tagName === 'SELECT' && window.jQuery && jQuery.fn.select2) {
        jQuery(workerField).on('change.marking', loadMarkContext);
    } else {
        workerField.addEventListener('change', loadMarkContext);
    }

    const scheduleModalElement = document.getElementById('myScheduleModal');
    const scheduleArrivalMapModalElement = document.getElementById('scheduleArrivalMapModal');
    const scheduleArrivalMapModal = scheduleArrivalMapModalElement ? bootstrap.Modal.getOrCreateInstance(scheduleArrivalMapModalElement) : null;
    let scheduleArrivalMap = null;
    let scheduleArrivalMarker = null;
    let scheduleArrivalCircle = null;
    let pendingScheduleArrival = null;
    const scheduleModal = scheduleModalElement ? bootstrap.Modal.getOrCreateInstance(scheduleModalElement) : null;
    let workerScheduleCalendar = null;
    document.getElementById('viewMyScheduleBtn')?.addEventListener('click', async () => {
        const workerId = workerField.value || '';
        if (!workerId) return Swal.fire('Seleccione un trabajador', 'Primero seleccione el trabajador cuya programación desea consultar.', 'info');
        const content = document.getElementById('myScheduleContent');
        const loading = document.getElementById('myScheduleLoading');
        const legend = document.getElementById('myScheduleLegend');
        const calendarElement = document.getElementById('myScheduleCalendar');
        const detail = document.getElementById('myScheduleDetail');
        if (content) content.scrollTop = 0;
        loading?.classList.remove('d-none');
        legend?.classList.add('d-none');
        calendarElement?.classList.add('d-none');
        detail?.classList.add('d-none');
        scheduleModal?.show();
        scheduleModalElement?.addEventListener('shown.bs.modal', () => {
            if (content) content.scrollTop = 0;
        }, { once: true });
        try {
            const response = await fetch(`${BASE_URL}/servicios/control_personal/listar_programacion_trabajador.php?worker_id=${encodeURIComponent(workerId)}`);
            const data = await response.json();
            if (!data.ok) throw new Error(data.message || 'No se pudo consultar la programación.');
            const rows = data.programs || [];
            const calendarRows = data.calendar_events || [];
            const regularSchedules = data.regular_schedules || [];
            const journeyOverrides = new Map((data.journey_overrides || []).map((item) => [`${item.assignment_id}-${item.journey_date}`, item]));
            const localNow = new Date();
            const today = `${localNow.getFullYear()}-${String(localNow.getMonth() + 1).padStart(2, '0')}-${String(localNow.getDate()).padStart(2, '0')}`;
            const programmedDates = new Set(rows.map((program) => program.program_date));
            const specialDates = new Set();
            calendarRows.forEach((item) => {
                const cursor = new Date(`${item.start_date}T12:00:00`);
                const end = new Date(`${item.end_date}T12:00:00`);
                while (cursor <= end) {
                    specialDates.add(`${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}-${String(cursor.getDate()).padStart(2, '0')}`);
                    cursor.setDate(cursor.getDate() + 1);
                }
            });
            const events = rows.filter((program) => !specialDates.has(program.program_date)).map((program) => {
                const hasRoute = Array.isArray(program.stops) && program.stops.length > 0;
                const routePlaceCount = hasRoute ? program.stops.length + 1 : 0;
                let state = 'Programado';
                let color = '#f97316';
                if (program.has_entry && program.has_exit) { state = 'Completada'; color = '#16a34a'; }
                else if (program.has_entry) { state = 'En jornada'; color = '#d97706'; }
                else if (program.program_date < today) { state = 'Sin marcaciones'; color = '#64748b'; }
                const stateColor = color;
                if (hasRoute) color = '#0f766e';
                return {
                    id: String(program.id),
                    title: `${formatTime(program.entry_time)} - ${formatTime(program.exit_time)} · ${hasRoute ? `Recorrido · ${routePlaceCount} ${routePlaceCount === 1 ? 'lugar' : 'lugares'}` : program.location_name}${!hasRoute && program.activity ? ` · ${program.activity}` : ''}`,
                    start: program.program_date,
                    allDay: true,
                    backgroundColor: color,
                    borderColor: color,
                    textColor: '#ffffff',
                    display: 'block',
                    extendedProps: { ...program, eventKind: 'program', state, stateColor, routeColor: hasRoute ? '#0f766e' : '', routePlaceCount }
                };
            });
            const specialMeta = {
                vacation: { color: '#2563eb' }, permission: { color: '#7c3aed' },
                rest: { color: '#334155' }, holiday: { color: '#0891b2' }, non_working: { color: '#78716c' }
            };
            calendarRows.forEach((item) => {
                const endExclusive = new Date(`${item.end_date}T12:00:00`);
                endExclusive.setDate(endExclusive.getDate() + 1);
                const endKey = `${endExclusive.getFullYear()}-${String(endExclusive.getMonth() + 1).padStart(2, '0')}-${String(endExclusive.getDate()).padStart(2, '0')}`;
                const color = specialMeta[item.type]?.color || '#64748b';
                events.push({
                    id: `calendar-${item.id}`,
                    title: `${item.code} · ${item.label}${item.name ? ` · ${item.name}` : ''}`,
                    start: item.start_date,
                    end: endKey,
                    allDay: true,
                    backgroundColor: color,
                    borderColor: color,
                    extendedProps: { ...item, eventKind: 'calendar', stateColor: color }
                });
            });
            // Mantener el mismo rango histórico y futuro que entrega el servicio.
            // Si se comienza únicamente en "hoy", una jornada habitual desaparece
            // del calendario apenas cambia el día, aunque la asignación siga vigente.
            const regularStart = new Date(`${today}T12:00:00`);
            regularStart.setMonth(regularStart.getMonth() - 3);
            const regularEnd = new Date(`${today}T12:00:00`);
            regularEnd.setMonth(regularEnd.getMonth() + 12);
            for (const cursor = new Date(regularStart); cursor <= regularEnd; cursor.setDate(cursor.getDate() + 1)) {
                const dateKey = `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}-${String(cursor.getDate()).padStart(2, '0')}`;
                if (specialDates.has(dateKey) || programmedDates.has(dateKey)) continue;
                const dayOfWeek = cursor.getDay() === 0 ? 7 : cursor.getDay();
                regularSchedules.filter((schedule) => Number(schedule.day_of_week) === dayOfWeek).forEach((schedule) => {
                    if (dateKey < schedule.valid_from || (schedule.valid_until && dateKey > schedule.valid_until)) return;
                    const override = journeyOverrides.get(`${schedule.assignment_id}-${dateKey}`);
                    const effectiveSchedule = override ? { ...schedule, activity: override.activity || '', instructions: override.instructions || '' } : schedule;
                    events.push({
                        id: `regular-${schedule.assignment_id}-${dateKey}`,
                        title: `${formatTime(schedule.entry_time)} - ${formatTime(schedule.exit_time)} · ${schedule.location_name}`,
                        start: dateKey,
                        allDay: true,
                        backgroundColor: '#16a34a',
                        borderColor: '#15803d',
                        textColor: '#ffffff',
                        display: 'block',
                        extendedProps: { ...effectiveSchedule, eventKind: 'regular', work_date: dateKey, stateColor: '#16a34a' }
                    });
                });
            }

            workerScheduleCalendar?.destroy();
            loading?.classList.add('d-none');
            legend?.classList.remove('d-none');
            calendarElement?.classList.remove('d-none');
            if (!window.FullCalendar) throw new Error('No se pudo cargar el calendario. Actualice la página e inténtelo nuevamente.');
            workerScheduleCalendar = new FullCalendar.Calendar(calendarElement, {
                locale: 'es',
                initialView: window.innerWidth < 768 ? 'listMonth' : 'dayGridMonth',
                firstDay: 1,
                height: 'auto',
                dayMaxEvents: 3,
                displayEventTime: false,
                noEventsContent: 'No hay jornadas programadas en este periodo.',
                headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
                buttonText: { today: 'Hoy', month: 'Mes', list: 'Lista' },
                events,
                eventDidMount(info) {
                    const program = info.event.extendedProps;
                    if (program.eventKind === 'calendar') {
                        info.el.title = `${program.label}: ${program.name}`;
                        return;
                    }
                    const scheduleType = program.schedule_source === 'extraordinary' ? 'Horario especial' : program.schedule_name;
                    const routeStops = Array.isArray(program.stops) ? program.stops : [];
                    info.el.title = routeStops.length
                        ? `${formatTime(program.entry_time)} - ${formatTime(program.exit_time)} | Recorrido de trabajo | ${routeStops.length + 1} lugares`
                        : `${formatTime(program.entry_time)} - ${formatTime(program.exit_time)} | ${program.location_name} | ${scheduleType}${program.activity ? ` | ${program.activity}` : ''}`;
                },
                eventClick(info) {
                    const program = info.event.extendedProps;
                    if (program.eventKind === 'regular') {
                        const date = new Date(`${program.work_date}T12:00:00`).toLocaleDateString('es-PE', { weekday:'long', day:'2-digit', month:'long', year:'numeric' });
                        detail.innerHTML = `<div class="my-schedule-detail-header">
                            <div><div class="my-schedule-detail-date">${escapeHtml(date)}</div><h5 class="my-schedule-detail-title">Detalle de la jornada habitual</h5></div>
                            <span class="my-schedule-detail-state" style="background:${escapeHtml(program.stateColor)}"><i class="fa-solid fa-calendar-days"></i>Horario habitual</span>
                        </div>
                        <div class="my-schedule-detail-body"><div class="my-schedule-detail-grid">
                            <div class="my-schedule-detail-item"><span class="my-schedule-detail-label">Horario</span><span class="my-schedule-detail-value"><i class="fa-regular fa-clock"></i>${formatTime(program.entry_time)} - ${formatTime(program.exit_time)}</span></div>
                            <div class="my-schedule-detail-item"><span class="my-schedule-detail-label">Plantilla</span><span class="my-schedule-detail-value"><i class="fa-solid fa-clock-rotate-left"></i>${escapeHtml(program.schedule_name)}</span></div>
                            <div class="my-schedule-detail-item"><span class="my-schedule-detail-label">Lugar de marcación</span><span class="my-schedule-detail-value"><i class="fa-solid fa-location-dot"></i>${escapeHtml(program.location_name)}</span></div>
                            ${program.address ? `<div class="my-schedule-detail-item wide"><span class="my-schedule-detail-label">Dirección</span><span class="my-schedule-detail-value"><i class="fa-solid fa-map-location-dot"></i>${escapeHtml(program.address)}</span></div>` : ''}
                            ${program.reference ? `<div class="my-schedule-detail-item"><span class="my-schedule-detail-label">Referencia</span><span class="my-schedule-detail-value"><i class="fa-solid fa-signs-post"></i>${escapeHtml(program.reference)}</span></div>` : ''}
                            ${program.activity ? `<div class="my-schedule-detail-item wide"><span class="my-schedule-detail-label">Actividad</span><span class="my-schedule-detail-value"><i class="fa-solid fa-briefcase"></i>${escapeHtml(program.activity)}</span></div>` : ''}
                        </div>${program.instructions ? `<div class="my-schedule-indications"><div class="my-schedule-indications-title"><i class="fa-solid fa-list-check"></i>Indicaciones</div><div class="my-schedule-detail-value">${escapeHtml(program.instructions)}</div></div>` : ''}</div>`;
                        detail.classList.remove('d-none');
                        content?.scrollTo({ top: Math.max(0, detail.offsetTop - 16), behavior: 'smooth' });
                        return;
                    }
                    if (program.eventKind === 'calendar') {
                        const start = new Date(`${program.start_date}T12:00:00`).toLocaleDateString('es-PE', { day:'2-digit', month:'long', year:'numeric' });
                        const end = new Date(`${program.end_date}T12:00:00`).toLocaleDateString('es-PE', { day:'2-digit', month:'long', year:'numeric' });
                        const period = program.start_date === program.end_date ? start : `${start} al ${end}`;
                        const scope = program.scope === 'all' ? 'Todo el personal' : (program.scope === 'company' ? 'Empresa del trabajador' : 'Trabajador');
                        detail.innerHTML = `<div class="my-schedule-detail-header">
                            <div><div class="my-schedule-detail-date">${escapeHtml(period)}</div><h5 class="my-schedule-detail-title">Detalle del calendario laboral</h5></div>
                            <span class="my-schedule-detail-state" style="background:${escapeHtml(program.stateColor)}"><i class="fa-solid fa-calendar-check"></i>${escapeHtml(program.code)} · ${escapeHtml(program.label)}</span>
                        </div>
                        <div class="my-schedule-detail-body"><div class="my-schedule-detail-grid">
                            <div class="my-schedule-detail-item"><span class="my-schedule-detail-label">Tipo de día</span><span class="my-schedule-detail-value"><i class="fa-solid fa-tag"></i>${escapeHtml(program.label)}</span></div>
                            <div class="my-schedule-detail-item"><span class="my-schedule-detail-label">Aplica a</span><span class="my-schedule-detail-value"><i class="fa-solid fa-users"></i>${escapeHtml(scope)}</span></div>
                            <div class="my-schedule-detail-item"><span class="my-schedule-detail-label">Periodo</span><span class="my-schedule-detail-value"><i class="fa-regular fa-calendar"></i>${escapeHtml(period)}</span></div>
                            <div class="my-schedule-detail-item wide"><span class="my-schedule-detail-label">Motivo</span><span class="my-schedule-detail-value"><i class="fa-solid fa-circle-info"></i>${escapeHtml(program.name || '-')}</span></div>
                        </div></div>`;
                        detail.classList.remove('d-none');
                        content?.scrollTo({ top: Math.max(0, detail.offsetTop - 16), behavior: 'smooth' });
                        return;
                    }
                    const date = new Date(`${program.program_date}T12:00:00`).toLocaleDateString('es-PE', { weekday:'long', day:'2-digit', month:'long', year:'numeric' });
                    const indicationText = String(program.notes || program.instructions || '').trim();
                    const routeStops = Array.isArray(program.stops) ? program.stops : [];
                    const hasRoute = routeStops.length > 0;
                    const workCompletions = Array.isArray(program.work_completions) ? program.work_completions : [];
                    const routeArrivals = Array.isArray(program.route_arrivals) ? program.route_arrivals : [];
                    const routeItems = hasRoute ? [{ location_id: program.location_id, destination: program.location_name, activity: program.activity || '', address: program.address || '', estimated_time: '', isOrigin: true }, ...routeStops] : [];
                    const route = routeItems.map((stop, index) => {
                        const activity = String(stop.activity || '').trim();
                        const address = String(stop.address || '').trim();
                        const estimatedTime = String(stop.estimated_time || '').trim();
                        const completion = workCompletions.find(item => Number(item.location_id) === Number(stop.location_id));
                        const arrival = routeArrivals.find(item => Number(item.location_id) === Number(stop.location_id));
                        const arrivalText = arrival
                            ? `<button type="button" class="my-schedule-route-arrived js-schedule-arrival-map" title="Ver ubicación de llegada" data-place="${escapeHtml(stop.destination || '-')}" data-time="${escapeHtml(arrival.arrived_time || '--:--')}" data-date="${escapeHtml(program.program_date || '')}" data-latitude="${arrival.latitude ?? ''}" data-longitude="${arrival.longitude ?? ''}" data-location-latitude="${arrival.location_latitude ?? ''}" data-location-longitude="${arrival.location_longitude ?? ''}" data-radius="${arrival.radius_meters ?? ''}"><i class="fa-solid fa-location-dot"></i>Llegó ${escapeHtml(arrival.arrived_time || '--:--')}</button>`
                            : '';
                        const completionText = completion
                            ? `<span class="my-schedule-route-completed"><i class="fa-solid fa-circle-check"></i>Finalizado ${escapeHtml(completion.completed_time || '--:--')} · ${escapeHtml(completion.activity || 'Actividad registrada')}</span>`
                            : '';
                        return `<li class="my-schedule-route-stop"><span class="my-schedule-route-number">${index + 1}</span><div><div class="my-schedule-route-place">${escapeHtml(stop.destination || '-')}${arrivalText}${completionText}</div><div class="my-schedule-route-meta">${stop.isOrigin ? '<span><i class="fa-solid fa-location-dot"></i>Lugar inicial</span>' : ''}${estimatedTime ? `<span><i class="fa-regular fa-clock"></i>Llegada estimada: ${formatTime(estimatedTime)}</span>` : ''}${activity ? `<span><i class="fa-solid fa-briefcase"></i>${escapeHtml(activity)}</span>` : ''}${address ? `<span><i class="fa-solid fa-map-location-dot"></i>${escapeHtml(address)}</span>` : ''}</div></div></li>`;
                    }).join('');
                    const scheduleType = program.schedule_source === 'extraordinary' ? 'Horario especial' : `Plantilla: ${escapeHtml(program.schedule_name)}`;
                    detail.innerHTML = `<div class="my-schedule-detail-header">
                        <div><div class="my-schedule-detail-date">${escapeHtml(date)}</div><h5 class="my-schedule-detail-title">${hasRoute ? 'Recorrido de trabajo' : 'Detalle de la jornada'}</h5></div>
                        <span class="my-schedule-detail-state" style="background:${escapeHtml(hasRoute ? (program.routeColor || '#0f766e') : program.stateColor)}"><i class="fa-solid ${hasRoute ? 'fa-route' : 'fa-calendar-check'}"></i>${hasRoute ? `Recorrido de trabajo (${routeItems.length} ${routeItems.length === 1 ? 'lugar' : 'lugares'})` : escapeHtml(program.state)}</span>
                    </div>
                    <div class="my-schedule-detail-body">
                        <div class="my-schedule-detail-grid">
                            <div class="my-schedule-detail-item"><span class="my-schedule-detail-label">Horario</span><span class="my-schedule-detail-value"><i class="fa-regular fa-clock"></i>${formatTime(program.entry_time)} - ${formatTime(program.exit_time)}</span></div>
                            <div class="my-schedule-detail-item"><span class="my-schedule-detail-label">Tipo de horario</span><span class="my-schedule-detail-value"><i class="fa-solid fa-clock-rotate-left"></i>${scheduleType}</span></div>
                            <div class="my-schedule-detail-item"><span class="my-schedule-detail-label">${hasRoute ? 'Lugar inicial' : 'Lugar de marcación'}</span><span class="my-schedule-detail-value"><i class="fa-solid fa-location-dot"></i>${escapeHtml(program.location_name)}</span></div>
                            ${program.address ? `<div class="my-schedule-detail-item wide"><span class="my-schedule-detail-label">Dirección</span><span class="my-schedule-detail-value"><i class="fa-solid fa-map-location-dot"></i>${escapeHtml(program.address)}</span></div>` : ''}
                            ${program.reference ? `<div class="my-schedule-detail-item"><span class="my-schedule-detail-label">Referencia</span><span class="my-schedule-detail-value"><i class="fa-solid fa-signs-post"></i>${escapeHtml(program.reference)}</span></div>` : ''}
                            ${program.activity ? `<div class="my-schedule-detail-item wide"><span class="my-schedule-detail-label">Actividad</span><span class="my-schedule-detail-value"><i class="fa-solid fa-briefcase"></i>${escapeHtml(program.activity)}</span></div>` : ''}
                        </div>
                        ${hasRoute ? `<div class="my-schedule-route"><div class="my-schedule-route-title"><i class="fa-solid fa-route"></i>Lugares a recorrer</div><ol class="my-schedule-route-list">${route}</ol></div>` : ''}
                        ${indicationText ? `<div class="my-schedule-indications"><div class="my-schedule-indications-title"><i class="fa-solid fa-list-check"></i>Indicaciones</div><div class="my-schedule-detail-value">${escapeHtml(indicationText).replace(/\r?\n/g, '<br>')}</div></div>` : ''}
                    </div>`;
                    detail.classList.remove('d-none');
                    content?.scrollTo({ top: Math.max(0, detail.offsetTop - 16), behavior: 'smooth' });
                }
            });
            workerScheduleCalendar.render();
            setTimeout(() => workerScheduleCalendar?.updateSize(), 150);
        } catch (error) {
            loading?.classList.add('d-none');
            legend?.classList.add('d-none');
            calendarElement?.classList.add('d-none');
            detail.classList.remove('d-none');
            detail.innerHTML = `<div class="alert alert-warning mb-0">${escapeHtml(error.message)}</div>`;
        }
    });

    document.getElementById('myScheduleDetail')?.addEventListener('click', event => {
        const button = event.target.closest('.js-schedule-arrival-map');
        if (!button) return;
        const latitude = Number(button.dataset.latitude);
        const longitude = Number(button.dataset.longitude);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            Swal.fire('Ubicación no disponible', 'Esta llegada no tiene coordenadas GPS registradas.', 'info');
            return;
        }
        const dateText = button.dataset.date
            ? new Date(`${button.dataset.date}T12:00:00`).toLocaleDateString('es-PE', { day:'2-digit', month:'long', year:'numeric' })
            : '';
        document.getElementById('scheduleArrivalMapTitle').textContent = button.dataset.place || 'Llegada registrada';
        document.getElementById('scheduleArrivalMapMessage').textContent = `Llegó a las ${button.dataset.time || '--:--'}${dateText ? ` · ${dateText}` : ''}. Ubicación validada mediante GPS.`;
        pendingScheduleArrival = {
            latitude,
            longitude,
            place: button.dataset.place || 'Lugar de llegada',
            locationLatitude: button.dataset.locationLatitude !== '' ? Number(button.dataset.locationLatitude) : NaN,
            locationLongitude: button.dataset.locationLongitude !== '' ? Number(button.dataset.locationLongitude) : NaN,
            radius: Number(button.dataset.radius || 0),
        };
        scheduleArrivalMapModal?.show();
    });

    scheduleArrivalMapModalElement?.addEventListener('shown.bs.modal', () => {
        if (!pendingScheduleArrival || !window.L) return;
        const point = [pendingScheduleArrival.latitude, pendingScheduleArrival.longitude];
        if (!scheduleArrivalMap) {
            scheduleArrivalMap = L.map('scheduleArrivalEvidenceMap');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom:19, attribution:'&copy; OpenStreetMap' }).addTo(scheduleArrivalMap);
        }
        scheduleArrivalMap.invalidateSize();
        if (!scheduleArrivalMarker) scheduleArrivalMarker = L.marker(point);
        if (!scheduleArrivalMap.hasLayer(scheduleArrivalMarker)) scheduleArrivalMarker.addTo(scheduleArrivalMap);
        scheduleArrivalMarker.setLatLng(point).bindPopup(pendingScheduleArrival.place).openPopup();
        const officialPoint = [pendingScheduleArrival.locationLatitude, pendingScheduleArrival.locationLongitude];
        if (Number.isFinite(officialPoint[0]) && Number.isFinite(officialPoint[1]) && pendingScheduleArrival.radius > 0) {
            if (!scheduleArrivalCircle) scheduleArrivalCircle = L.circle(officialPoint, { color:'#2563eb', fillColor:'#3b82f6', fillOpacity:.1 });
            if (!scheduleArrivalMap.hasLayer(scheduleArrivalCircle)) scheduleArrivalCircle.addTo(scheduleArrivalMap);
            scheduleArrivalCircle.setLatLng(officialPoint).setRadius(pendingScheduleArrival.radius);
            scheduleArrivalMap.fitBounds(L.latLngBounds([point, officialPoint]), { padding:[28,28], maxZoom:18 });
        } else {
            if (scheduleArrivalCircle) { scheduleArrivalCircle.remove(); scheduleArrivalCircle = null; }
            scheduleArrivalMap.setView(point, 17);
        }
        setTimeout(() => scheduleArrivalMap?.invalidateSize({ pan:false }), 100);
    });

    const finishWorkModalElement = document.getElementById('finishLocationWorkModal');
    const finishWorkModal = finishWorkModalElement ? bootstrap.Modal.getOrCreateInstance(finishWorkModalElement) : null;
    const finishWorkForm = document.getElementById('finishLocationWorkForm');
    finishLocationWorkBtn?.addEventListener('click', () => {
        finishWorkForm?.reset();
        const currentLocation = document.getElementById('currentWorkLocation')?.textContent || context?.exit_location?.name || context?.assignment?.location_name || '-';
        const displayedActivity = document.getElementById('currentWorkActivity')?.textContent || '';
        const currentActivity = displayedActivity === 'Actividad del lugar' ? '' : displayedActivity;
        const locationField = document.getElementById('finishWorkLocation');
        const activityField = document.getElementById('finishWorkActivity');
        if (locationField) locationField.value = currentLocation;
        if (activityField) activityField.value = currentActivity;
        finishWorkModal?.show();
    });
    finishWorkForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = finishWorkForm.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            const position = await requestPosition();
            const fields = new FormData(finishWorkForm);
            fields.append('csrf_token', csrf);
            fields.append('worker_id', workerField.value || '');
            fields.append('assignment_id', String(context?.assignment?.assignment_id || ''));
            fields.append('program_id', String(context?.program?.id || ''));
            fields.append('latitude', String(position.coords.latitude));
            fields.append('longitude', String(position.coords.longitude));
            const response = await fetch(`${BASE_URL}/servicios/control_personal/finalizar_trabajo_lugar.php`, { method: 'POST', body: fields });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo finalizar el trabajo.');
            finishWorkModal?.hide();
            await Swal.fire({ icon:'success', title:'Trabajo finalizado', text:data.message, confirmButtonText:'Entendido' });
            await loadMarkContext();
        } catch (error) {
            Swal.fire('Atención', error.message || String(error), 'warning');
        } finally {
            button.disabled = false;
        }
    });

    const tripModalElement = document.getElementById('tripModal');
    const tripModal = tripModalElement ? bootstrap.Modal.getOrCreateInstance(tripModalElement) : null;
    const tripForm = document.getElementById('tripForm');
    function openTripModal(action) {
        tripForm.reset(); document.getElementById('tripAction').value = action;
        const tripOrigin = document.getElementById('tripOrigin');
        const isStop = action === 'parada';
        const hasPlannedRoute = !!context?.final_planned_stop;
        const currentLocationId = Number(context?.exit_location?.id || context?.assignment?.location_id || 0);
        const baseLocationId = Number(context?.assignment?.location_id || 0);
        const isTemporaryReturn = action === 'iniciar' && !hasPlannedRoute && baseLocationId > 0
            && (context?.exit_location?.is_temporary_location == 1 || (currentLocationId > 0 && currentLocationId !== baseLocationId));
        const isFreeTemporaryOutbound = action === 'iniciar' && !hasPlannedRoute && !isTemporaryReturn;
        if (tripOrigin) tripOrigin.value = currentActiveTrip?.origin || context?.exit_location?.name || context?.assignment?.location_name || '-';
        const mainDestination = document.getElementById('tripMainDestination');
        if (mainDestination) mainDestination.value = currentActiveTrip?.first_destination || '-';
        document.getElementById('tripMainDestinationField')?.classList.toggle('d-none', !isStop);
        document.getElementById('tripModalTitle').textContent = isStop ? 'Registrar visita del recorrido' : (isTemporaryReturn ? `Regreso a ${context?.assignment?.location_name || 'lugar habitual'}` : (hasPlannedRoute ? 'Iniciar desplazamiento del recorrido' : 'Salida temporal'));
        document.getElementById('tripModalDescription').textContent = isStop ? 'Agregue una visita y la actividad realizada sin finalizar el desplazamiento.' : (isTemporaryReturn ? 'Registra el retorno al lugar habitual. Tu jornada laboral continuará activa.' : 'Esta acción no finaliza tu jornada laboral.');
        document.getElementById('tripDestinationLabel').textContent = isStop ? 'Punto visitado' : 'Destino';
        const destinationField = document.getElementById('tripDestination');
        const destinationTextField = document.getElementById('tripDestinationText');
        document.getElementById('tripRegisteredDestinationField')?.classList.toggle('d-none', isFreeTemporaryOutbound);
        document.getElementById('tripFreeDestinationField')?.classList.toggle('d-none', !isFreeTemporaryOutbound);
        if (destinationField) {
            const planned = context?.next_planned_stop || (isTemporaryReturn ? { location_id:baseLocationId } : null);
            destinationField.value = planned ? String(planned.location_id) : '';
            destinationField.disabled = !!planned && action === 'iniciar';
            destinationField.classList.toggle('bg-light', destinationField.disabled);
            destinationField.required = !isFreeTemporaryOutbound;
        }
        if (destinationTextField) destinationTextField.required = isFreeTemporaryOutbound;
        document.getElementById('tripReasonField').classList.toggle('d-none', action !== 'iniciar');
        document.getElementById('tripActivityField').classList.toggle('d-none', !isStop);
        tripForm.querySelector('[name="reason"]').required = action === 'iniciar';
        tripForm.querySelector('[name="activity"]').required = isStop;
        if (isTemporaryReturn) tripForm.querySelector('[name="reason"]').value = `Regreso a ${context?.assignment?.location_name || 'lugar habitual'}`;
        tripForm.dataset.forcedDestinationId = isTemporaryReturn ? String(baseLocationId) : '';
        tripModal?.show();
    }
    startTripBtn?.addEventListener('click', () => openTripModal('iniciar'));
    addTripStopBtn?.addEventListener('click', () => openTripModal('parada'));

    async function submitTrip(action, extra = {}) {
        const position = await requestPosition();
        const body = new FormData();
        body.append('csrf_token', csrf); body.append('action', action); body.append('worker_id', workerField.value || '');
        body.append('assignment_id', String(context?.assignment?.assignment_id || '')); body.append('program_id', String(context?.program?.id || ''));
        body.append('latitude', String(position.coords.latitude)); body.append('longitude', String(position.coords.longitude));
        body.append('accuracy', String(position.coords.accuracy || 0));
        Object.entries(extra).forEach(([key,value]) => body.append(key,String(value || '')));
        const response = await fetch(`${BASE_URL}/servicios/control_personal/registrar_desplazamiento.php`,{method:'POST',body});
        const data = await response.json();
        if (!data.ok) throw new Error(data.message || 'No se pudo registrar el desplazamiento.');
        return data;
    }
    nextLocationSelect?.addEventListener('change', () => {
        if (chooseNextLocationBtn) chooseNextLocationBtn.disabled = !nextLocationSelect.value;
    });
    chooseNextLocationBtn?.addEventListener('click', async () => {
        const locationId = Number(nextLocationSelect?.value || 0);
        const destination = nextLocationSelect?.selectedOptions?.[0]?.textContent?.trim() || '';
        if (!locationId) return;
        chooseNextLocationBtn.disabled = true;
        try {
            const data = await submitTrip('iniciar', {
                destination_location_id: locationId,
                reason: `Traslado al siguiente lugar de marcación: ${destination}`
            });
            await Swal.fire('Desplazamiento iniciado', data.message, 'success');
            await loadMarkContext();
        } catch (error) {
            Swal.fire('Atención', error.message || String(error), 'warning');
        } finally {
            if (chooseNextLocationBtn && !context?.active_trip) chooseNextLocationBtn.disabled = !nextLocationSelect?.value;
        }
    });
    tripForm?.addEventListener('submit', async event => {
        event.preventDefault(); const button=tripForm.querySelector('[type="submit"]'); button.disabled=true;
        try { const fields=new FormData(tripForm); const data=await submitTrip(fields.get('action'),{destination_location_id:context?.next_planned_stop?.location_id || tripForm.dataset.forcedDestinationId || fields.get('destination_location_id'),destination:fields.get('destination'),reason:fields.get('reason'),activity:fields.get('activity')}); tripModal?.hide(); await Swal.fire('Registro exitoso',data.message,'success'); await loadMarkContext(); }
        catch(error){ Swal.fire('Atención',error.message || String(error),'warning'); } finally { button.disabled=false; }
    });
    finishTripBtn?.addEventListener('click', async () => {
        const destination = currentActiveTrip?.first_destination || 'el destino seleccionado';
        const baseLocationId = Number(context?.assignment?.location_id || 0);
        const isPlannedRoute = !!context?.final_planned_stop;
        const isReturningToBase = Number(currentActiveTrip?.first_destination_location_id || 0) === baseLocationId
            && (context?.exit_location?.is_temporary_location == 1 || Number(context?.exit_location?.id || 0) !== baseLocationId);
        const answer=await Swal.fire({
            icon:'question',
            title:isReturningToBase ? `¿Confirmar regreso a ${destination}?` : `¿Confirmar llegada a ${destination}?`,
            text:isReturningToBase
                ? `Se validará tu ubicación en ${destination}. Al confirmar, registrarás tu regreso al lugar de trabajo y tu jornada continuará activa.`
                : (isPlannedRoute
                    ? `Se validará mediante GPS que ya te encuentras en ${destination}. Tu jornada continuará activa para realizar el trabajo asignado en este lugar.`
                    : `Se registrará tu llegada a ${destination} con tu ubicación actual. Tu jornada continuará activa; después deberás registrar el regreso a tu lugar de trabajo.`),
            showCancelButton:true,
            confirmButtonText:isReturningToBase ? 'Sí, confirmar regreso' : 'Sí, confirmar llegada',
            cancelButtonText:'Cancelar'
        });
        if(!answer.isConfirmed)return;
        try{const data=await submitTrip('finalizar');await Swal.fire('Llegada confirmada',data.message,'success');await loadMarkContext();}catch(error){Swal.fire('Atención',error.message || String(error),'warning');}
    });
    returnWithoutArrivalBtn?.addEventListener('click', async () => {
        const baseName = context?.assignment?.location_name || 'tu lugar de trabajo';
        const destination = currentActiveTrip?.first_destination || 'el destino temporal';
        const answer = await Swal.fire({
            icon:'question',
            title:`¿Confirmar regreso a ${baseName}?`,
            html:`<p class="mb-2">Se cerrará tu salida temporal a <strong>${escapeHtml(destination)}</strong>.</p><p class="small text-muted mb-0">Validaremos mediante GPS que ya regresaste a ${escapeHtml(baseName)}. Tu jornada laboral continuará activa.</p>`,
            showCancelButton:true,
            confirmButtonText:'Sí, confirmar regreso',
            cancelButtonText:'Cancelar',
            confirmButtonColor:'#16a34a'
        });
        if (!answer.isConfirmed) return;
        returnWithoutArrivalBtn.disabled = true;
        try {
            const data = await submitTrip('regresar_lugar_trabajo');
            await Swal.fire('Regreso registrado',data.message,'success');
            await loadMarkContext();
        } catch (error) {
            Swal.fire('No se pudo registrar el regreso',error.message || String(error),'warning');
        } finally {
            returnWithoutArrivalBtn.disabled = false;
        }
    });
    entryBtn.addEventListener('click', () => mark('entrada'));
    exitBtn.addEventListener('click', () => mark('salida'));
    programSelect?.addEventListener('change', loadMarkContext);
    setAssignmentAvailability(false, workerField.value ? 'Cargando la asignación activa...' : 'Seleccione un trabajador para consultar su asignación y registrar asistencia.');
    if (workerField.value) loadMarkContext();
}






















