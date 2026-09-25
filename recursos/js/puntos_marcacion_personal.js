(() => {
    'use strict';

    const initializeLocationPersonnel = () => {
        const modalElement = document.getElementById('locationPersonnelModal');
        const grid = document.getElementById('locationPersonnelGrid');
        if (!modalElement || !grid || !window.bootstrap) return;

        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const placeLabel = document.getElementById('locationPersonnelModalPlace');
        const guide = document.getElementById('locationPersonnelGuide');
        const search = document.getElementById('locationPersonnelSearch');
        const countLabel = document.getElementById('locationPersonnelSelectedCount');
        const protectionSummary = document.getElementById('locationPersonnelProtectionSummary');
        const empty = document.getElementById('locationPersonnelEmpty');
        const selectAll = document.getElementById('locationPersonnelSelectAll');
        const clearAll = document.getElementById('locationPersonnelClearAll');
        const saveButton = document.getElementById('locationPersonnelSave');
        const csrfToken = document.querySelector('#locationForm [name="csrf_token"]')?.value || '';
        const options = Array.from(grid.querySelectorAll('.location-personnel-option'));
        const checks = options.map((option) => option.querySelector('.location-personnel-check')).filter(Boolean);
        const allWorkerIds = checks.map((check) => Number(check.value)).filter((id) => id > 0);
        const states = window.LOCATION_PERSONNEL_STATE || {};
        let currentLocationId = 0;

        const idSet = (values) => new Set((Array.isArray(values) ? values : []).map(Number).filter((id) => id > 0));
        const normalize = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

        function updateSelection() {
            const selected = checks.filter((check) => check.checked).length;
            countLabel.textContent = `${selected} de ${checks.length} seleccionado${selected === 1 ? '' : 's'}`;
            options.forEach((option) => option.classList.toggle('is-selected', !!option.querySelector('.location-personnel-check')?.checked));
        }

        function filterWorkers() {
            const needle = normalize(search?.value);
            let visible = 0;
            options.forEach((option) => {
                const matches = !needle || normalize(option.dataset.search).includes(needle);
                option.classList.toggle('d-none', !matches);
                if (matches) visible += 1;
            });
            empty?.classList.toggle('d-none', visible > 0);
        }

        function configureRelations(state) {
            const assigned = idSet(state.assigned);
            const marked = idSet(state.marked);
            const open = idSet(state.open);
            const protectedWorkers = new Set([...open]);
            options.forEach((option) => {
                const workerId = Number(option.dataset.workerId || 0);
                const isProtected = protectedWorkers.has(workerId);
                const check = option.querySelector('.location-personnel-check');
                option.querySelector('[data-relation="assigned"]')?.classList.toggle('d-none', !assigned.has(workerId));
                option.querySelector('[data-relation="marked"]')?.classList.toggle('d-none', !marked.has(workerId));
                option.querySelector('[data-relation="open"]')?.classList.toggle('d-none', !open.has(workerId));
                option.querySelector('[data-protection-lock]')?.classList.toggle('d-none', !isProtected);
                option.classList.toggle('is-protected', isProtected);
                option.setAttribute('aria-disabled', isProtected ? 'true' : 'false');
                if (check) check.disabled = isProtected;
            });
            if (protectionSummary) {
                protectionSummary.innerHTML = protectedWorkers.size
                    ? `<i class="fa-solid fa-lock"></i>${protectedWorkers.size} selección(es) obligatorias por jornada abierta.`
                    : '<i class="fa-solid fa-circle-info"></i>No existen selecciones obligatorias para este lugar.';
            }
            return { assigned, marked, open, protectedWorkers };
        }

        function openPersonnelModal(button) {
            const locationId = Number(button.dataset.locationId || 0);
            currentLocationId = locationId;
            const state = states[String(locationId)] || {};
            const relations = configureRelations(state);
            let selected;

            if (state.configured) {
                selected = state.mode === 'all' ? new Set(allWorkerIds) : idSet(state.selected);
                guide.className = `location-personnel-guide ${state.mode === 'all' ? 'is-all' : 'is-configured'}`;
                guide.innerHTML = state.mode === 'all'
                    ? '<i class="fa-solid fa-users"></i><span><strong>Todo el personal</strong><small>Este lugar está configurado para todos los trabajadores.</small></span>'
                    : '<i class="fa-solid fa-user-check"></i><span><strong>Selección guardada</strong><small>Se muestra el personal configurado actualmente para este lugar.</small></span>';
            } else {
                selected = new Set([...relations.assigned, ...relations.marked]);
                const selectedByHistory = selected.size > 0;
                if (!selectedByHistory) selected = new Set(allWorkerIds);
                guide.className = `location-personnel-guide ${selectedByHistory ? 'is-suggested' : 'is-all'}`;
                guide.innerHTML = selectedByHistory
                    ? '<i class="fa-solid fa-wand-magic-sparkles"></i><span><strong>Selección automática</strong><small>Este personal ya puede utilizar el lugar. Puede modificar la selección y guardarla, o dejarla como está.</small></span>'
                    : '<i class="fa-solid fa-users"></i><span><strong>Todo el personal</strong><small>Este lugar está disponible para todos por defecto. Puede modificar la selección y guardarla, o dejarla como está.</small></span>';
            }

            relations.protectedWorkers.forEach((workerId) => selected.add(workerId));
            checks.forEach((check) => { check.checked = selected.has(Number(check.value)); });
            if (placeLabel) placeLabel.textContent = button.dataset.locationName || 'Lugar de marcación';
            if (search) search.value = '';
            filterWorkers();
            updateSelection();
            modal.show();
            setTimeout(() => search?.focus(), 250);
        }

        const locationsTable = document.getElementById('locationsTable');
        locationsTable?.addEventListener('click', (event) => {
            const button = event.target.closest('.js-location-personnel');
            if (!button || !locationsTable.contains(button)) return;

            event.preventDefault();
            openPersonnelModal(button);
        });
        search?.addEventListener('input', filterWorkers);
        checks.forEach((check) => check.addEventListener('change', updateSelection));
        selectAll?.addEventListener('click', () => {
            checks.forEach((check) => { check.checked = true; });
            updateSelection();
        });
        clearAll?.addEventListener('click', () => {
            checks.forEach((check) => { if (!check.disabled) check.checked = false; });
            updateSelection();
        });
        saveButton?.addEventListener('click', async () => {
            if (currentLocationId <= 0) return;
            const selectedChecks = checks.filter((check) => check.checked);
            if (selectedChecks.length === 0) {
                const confirmation = await Swal.fire({
                    icon: 'warning',
                    title: '¿Dejar este lugar sin personal?',
                    text: 'Ningún trabajador podrá seleccionar este lugar para registrar asistencia.',
                    showCancelButton: true,
                    confirmButtonText: 'Sí, guardar sin personal',
                    cancelButtonText: 'Revisar selección',
                    confirmButtonColor: '#dc3545'
                });
                if (!confirmation.isConfirmed) return;
            }
            const body = new FormData();
            body.append('csrf_token', csrfToken);
            body.append('location_id', String(currentLocationId));
            selectedChecks.forEach((check) => body.append('worker_ids[]', check.value));

            saveButton.disabled = true;
            const originalHtml = saveButton.innerHTML;
            saveButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Guardando...';
            try {
                const response = await fetch(`${BASE_URL}/servicios/control_personal/guardar_personal_lugar_marcacion.php`, {
                    method: 'POST',
                    body,
                    headers: { Accept: 'application/json' }
                });
                const raw = await response.text();
                let data;
                try {
                    data = JSON.parse(raw);
                } catch (_) {
                    throw new Error(response.redirected || /<!doctype|<html/i.test(raw)
                        ? 'La sesión venció o el servidor devolvió una página de error. Actualice la página e inténtelo nuevamente.'
                        : `El servidor devolvió una respuesta inválida (HTTP ${response.status}).`);
                }
                if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo guardar la selección.');
                modal.hide();
                await Swal.fire({
                    icon: 'success',
                    title: 'Personal autorizado actualizado',
                    text: data.message || 'La selección se guardó correctamente.',
                    confirmButtonText: 'Aceptar'
                });
                window.location.reload();
            } catch (error) {
                await Swal.fire('No se pudo guardar', error.message || 'Revise la selección e inténtelo nuevamente.', 'warning');
            } finally {
                saveButton.disabled = false;
                saveButton.innerHTML = originalHtml;
            }
        });
    };

    if (document.readyState === 'complete') initializeLocationPersonnel();
    else window.addEventListener('load', initializeLocationPersonnel, { once: true });
})();