<div class="modal fade habilitation-form-modal" id="habilitationModal" tabindex="-1" aria-labelledby="habilitationModalTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><form class="modal-content" method="post" action="<?= APP_URL ?>/modulos/clientes/pmi_habilitaciones_guardar.php" id="habilitationForm">
    <div class="modal-header"><h2 class="modal-title h5" id="habilitationModalTitle">Nueva habilitación</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="client_id" value="<?= $clientId ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="habilitationId">
        <div class="habilitation-main-grid"><div class="habilitation-main-left"><div class="habilitation-fields-card"><div class="col-12" id="habilitationAreaPanel"><label class="form-label" for="habilitationArea">Área de habilitación</label><select class="form-select" name="area" id="habilitationArea" required><option value="personal">Personal</option><option value="equipo">Equipo</option></select></div><div class="col-12" id="habilitationTypePanel"><label class="form-label" for="habilitationType">Tipo de habilitación</label><select class="form-select" name="habilitation_type" id="habilitationType" required><option value="individual">Individual</option><option value="grupal">Grupal</option></select></div>
        <div class="col-12" id="habilitationSubjectPanel"><label class="form-label" for="habilitationSubject">Nombre</label><select class="form-select" name="subject_id" id="habilitationSubject" required><option value="">Seleccione personal</option></select><small class="text-muted" id="habilitationSource">Personal de Requisitos - Personal</small></div>
        </div><div class="col-12" id="habilitationMembersPanel" hidden><div class="habilitation-positions-box"><div class="habilitation-positions-heading"><div><div class="habilitation-positions-title"><i class="fa-solid fa-people-group" aria-hidden="true"></i><strong>Integrantes</strong><span class="habilitation-positions-count" id="habilitationMembersCount">0 seleccionados</span></div><p>Seleccione una persona y marque los puestos que necesita.</p></div><label class="habilitation-positions-select-all"><input class="form-check-input" type="checkbox" id="habilitationMembersAll"><span>Seleccionar todos</span></label></div><div class="habilitation-member-tools"><input class="form-control form-control-sm" type="search" id="habilitationMembersSearch" placeholder="Buscar por nombre" aria-label="Buscar integrantes"><label class="habilitation-member-filter"><input class="form-check-input" type="checkbox" id="habilitationOnlyEligible" checked> Solo con puestos</label></div><div id="habilitationMembersList" class="habilitation-positions-list" aria-live="polite"></div><small class="habilitation-positions-error d-none" id="habilitationMembersError">Seleccione al menos un integrante con puestos para este cliente.</small></div></div><div class="col-12" id="habilitationPositionsPanel" hidden><div class="habilitation-positions-box"><div class="habilitation-positions-heading"><div><div class="habilitation-positions-title"><i class="fa-solid fa-briefcase" aria-hidden="true"></i><strong>Puestos</strong><span class="habilitation-positions-count" id="habilitationPositionsCount">0 seleccionados</span></div><p>Elige los puestos que aparecerán en esta habilitación.</p></div><label class="habilitation-positions-select-all" for="habilitationPositionsAll"><input class="form-check-input" type="checkbox" id="habilitationPositionsAll"><span>Seleccionar todo</span></label></div><div id="habilitationPositionsList" class="habilitation-positions-list" aria-live="polite">Seleccione primero un trabajador.</div><small class="habilitation-positions-error d-none" id="habilitationPositionsError"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> Seleccione al menos un puesto.</small></div></div>
        </div><div class="habilitation-main-right"><div class="col-12" id="habilitationConditionsPanel"><div class="d-flex flex-column gap-2" aria-label="Condiciones de la habilitación"><div class="d-flex align-items-center gap-3"><span class="fw-semibold small" style="min-width:125px">Tiene pase</span><label class="d-inline-flex align-items-center gap-1 mb-0">Sí <input class="form-check-input mt-0" type="radio" name="has_pass" value="1" required></label><label class="d-inline-flex align-items-center gap-1 mb-0">No <input class="form-check-input mt-0" type="radio" name="has_pass" value="0" required></label></div><div class="d-flex align-items-center gap-3"><span class="fw-semibold small" style="min-width:125px">Tienen vigencia</span><label class="d-inline-flex align-items-center gap-1 mb-0">Sí <input class="form-check-input mt-0" type="radio" name="has_validity" value="1" required></label><label class="d-inline-flex align-items-center gap-1 mb-0">No <input class="form-check-input mt-0" type="radio" name="has_validity" value="0" required></label></div></div></div>
        <div class="col-12 habilitation-dates-heading"><strong>Vigencia de habilitación</strong></div><div class="col-md-4"><label class="form-label" for="habilitationRegistration">F. Registro</label><input class="form-control" type="date" name="registration_date" id="habilitationRegistration" required></div><div class="col-md-4"><label class="form-label" for="habilitationStart">F. Inicio</label><input class="form-control" type="date" name="start_date" id="habilitationStart" required></div><div class="col-md-4"><label class="form-label" for="habilitationEnd">F. Fin</label><input class="form-control" type="date" name="end_date" id="habilitationEnd" required></div></div></div>
    </div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar habilitación</button></div>
</form></div></div>
<div class="modal fade" id="personalFormatModal" tabindex="-1" aria-labelledby="personalFormatModalTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><form class="modal-content" method="post" action="<?= APP_URL ?>/modulos/clientes/pmi_habilitaciones_formato_guardar.php" enctype="multipart/form-data" id="personalFormatForm">
    <div class="modal-header"><h2 class="modal-title h5" id="personalFormatModalTitle">Formato de personal</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="client_id" value="<?= (int) $clientId ?>"><input type="hidden" name="habilitation_id" id="personalFormatHabilitation"><input type="hidden" name="catalog_id" id="personalFormatCatalog"><input type="hidden" name="habilitation_buscar" value="<?= e($habilitationSearch) ?>"><input type="hidden" name="habilitation_pagina" value="<?= (int) $habilitationPage ?>">
        <p class="small text-muted">Las fechas y el archivo de este formato pertenecen solo a esta habilitación de personal.</p>
        <div class="row g-3"><div class="col-md-4"><label class="form-label" for="personalFormatRegistration">F. Registro</label><input class="form-control" type="date" name="registration_date" id="personalFormatRegistration" required></div><div class="col-md-4"><label class="form-label" for="personalFormatStart">F. Inicio</label><input class="form-control" type="date" name="start_date" id="personalFormatStart"></div><div class="col-md-4"><label class="form-label" for="personalFormatEnd">F. Fin</label><input class="form-control" type="date" name="end_date" id="personalFormatEnd"></div></div>
        <div class="mt-3"><label class="form-label" for="personalFormatFile">Archivo (PDF o imagen)</label><input class="form-control" type="file" name="file" id="personalFormatFile" accept="application/pdf,image/jpeg,image/png,image/webp"><small class="text-muted" id="personalFormatFileHelp">Formatos permitidos: PDF, JPG, PNG y WEBP. Máximo 10 MB.</small></div>
    </div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar formato</button></div>
</form></div></div>
<div class="modal fade" id="habilitationPreview" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="habilitationPreviewTitle">Vista previa</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body client-pmi-preview-body" id="habilitationPreviewBody"></div></div></div></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.habilitation-pass-form').forEach(passForm => {
        const file = passForm.querySelector('.habilitation-pass-file');
        const button = passForm.querySelector('.habilitation-pass-select');
        button.addEventListener('click', () => file.click());
        file.addEventListener('change', () => {
            if (!file.files.length) return;
            button.disabled = true;
            button.textContent = 'Guardando pase…';
            passForm.submit();
        });
    });
    function updateHabilitationDownloads(card) {
        const visible = [...card.querySelectorAll('.habilitation-position-row:not([hidden]) .habilitation-file-check:not(:disabled)')];
        const formats = [...card.querySelectorAll('.habilitation-format-check:not(:disabled)')];
        const selected = visible.filter(check => check.checked);
        const selectedFormats = formats.filter(check => check.checked);
        const toolbar = card.querySelector('.habilitation-download-toolbar');
        if (!toolbar) return;
        toolbar.querySelector('.habilitation-download-all').disabled = visible.length + formats.length === 0;
        toolbar.querySelector('.habilitation-download-selected').disabled = selected.length + selectedFormats.length === 0;
        const selectAll = card.querySelector('.habilitation-select-all');
        const availableCount = visible.length + formats.length;
        const selectedCount = selected.length + selectedFormats.length;
        selectAll.disabled = availableCount === 0;
        selectAll.checked = availableCount > 0 && selectedCount === availableCount;
        selectAll.indeterminate = selectedCount > 0 && selectedCount < availableCount;
        const formatsSelectAll = card.querySelector('.habilitation-formats-select-all');
        if (formatsSelectAll) {
            formatsSelectAll.disabled = formats.length === 0;
            formatsSelectAll.checked = formats.length > 0 && selectedFormats.length === formats.length;
            formatsSelectAll.indeterminate = selectedFormats.length > 0 && selectedFormats.length < formats.length;
        }
    }
    document.querySelectorAll('.habilitation-download-toolbar').forEach(toolbar => {
        const card = toolbar.closest('.border.rounded-3');
        card.querySelector('.habilitation-select-all').addEventListener('change', event => {
            card.querySelectorAll('.habilitation-position-row:not([hidden]) .habilitation-file-check:not(:disabled)').forEach(check => check.checked = event.target.checked);
            card.querySelectorAll('.habilitation-format-check:not(:disabled)').forEach(check => check.checked = event.target.checked);
            updateHabilitationDownloads(card);
        });
        card.querySelectorAll('.habilitation-file-check').forEach(check => check.addEventListener('change', () => updateHabilitationDownloads(card)));
        card.querySelector('.habilitation-formats-select-all')?.addEventListener('change', event => {
            card.querySelectorAll('.habilitation-format-check:not(:disabled)').forEach(check => check.checked = event.target.checked);
            updateHabilitationDownloads(card);
        });
        card.querySelectorAll('.habilitation-format-check').forEach(check => check.addEventListener('change', () => updateHabilitationDownloads(card)));
        toolbar.querySelectorAll('.habilitation-download-selected, .habilitation-download-all').forEach(button => button.addEventListener('click', () => {
            const selectedMode = button.classList.contains('habilitation-download-selected');
            const position = card.querySelector('.habilitation-position-tab[aria-pressed="true"]')?.dataset.positionId || '0';
            const params = new URLSearchParams({cliente_id:toolbar.dataset.clientId, habilitacion_id:toolbar.dataset.habilitationId, puesto_id:position, modo:selectedMode ? 'selected' : 'all'});
            if (selectedMode) {
                card.querySelectorAll('.habilitation-position-row:not([hidden]) .habilitation-file-check:checked').forEach(check => params.append('ids[]', check.value));
                card.querySelectorAll('.habilitation-format-check:checked').forEach(check => params.append('format_ids[]', check.value));
            }
            window.location.href = <?= json_encode(APP_URL) ?> + '/modulos/clientes/pmi_habilitaciones_descargar.php?' + params.toString();
        }));
        updateHabilitationDownloads(card);
    });
    document.querySelectorAll('.habilitation-position-tabs').forEach(group => group.addEventListener('click', event => {
        const selected = event.target.closest('.habilitation-position-tab');
        if (!selected || !group.contains(selected)) return;
        group.querySelectorAll('.habilitation-position-tab').forEach(button => {
            const active = button === selected;
            button.classList.toggle('btn-primary', active);
            button.classList.toggle('btn-outline-primary', !active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        group.closest('.border.rounded-3').querySelectorAll('.habilitation-position-row').forEach(row => {
            row.hidden = row.dataset.positionId !== selected.dataset.positionId;
        });
        group.closest('.border.rounded-3').querySelectorAll('.habilitation-progress-item[data-position-id]').forEach(item => {
            item.hidden = item.dataset.positionId !== selected.dataset.positionId;
        });
        updateHabilitationDownloads(group.closest('.border.rounded-3'));
    }));
    const modal = document.getElementById('habilitationModal'), form = document.getElementById('habilitationForm');
    const area = document.getElementById('habilitationArea'), subject = document.getElementById('habilitationSubject');
    const typePanel = document.getElementById('habilitationTypePanel');
    const conditionsPanel = document.getElementById('habilitationConditionsPanel');
    const dateInputs = ['habilitationRegistration', 'habilitationStart', 'habilitationEnd'].map(id => document.getElementById(id));
    const dateHeading = form.querySelector('.habilitation-dates-heading');
    function updateValidityFields() {
        const showDates = area.value === 'equipo' || form.querySelector('input[name="has_validity"]:checked')?.value === '1';
        dateHeading.hidden = !showDates;
        dateInputs.forEach(input => {
            input.parentElement.hidden = !showDates;
            input.disabled = !showDates;
            input.required = showDates;
        });
    }
    form.querySelectorAll('input[name="has_validity"]').forEach(input => input.addEventListener('change', updateValidityFields));
    const type = document.getElementById('habilitationType');
    const subjectPanel = document.getElementById('habilitationSubjectPanel');
    const membersPanel = document.getElementById('habilitationMembersPanel');
    const membersList = document.getElementById('habilitationMembersList');
    const membersAll = document.getElementById('habilitationMembersAll');
    const membersCount = document.getElementById('habilitationMembersCount');
    const membersSearch = document.getElementById('habilitationMembersSearch');
    const onlyEligible = document.getElementById('habilitationOnlyEligible');
    const membersError = document.getElementById('habilitationMembersError');
    let membersLoaded = false;
    function filterMembers() {
        const query = membersSearch.value.trim().toLocaleLowerCase();
        membersList.querySelectorAll('.habilitation-member-card').forEach(card => {
            card.hidden = !card.dataset.search.includes(query) || (onlyEligible.checked && card.classList.contains('habilitation-member-unavailable') && !query);
        });
    }
    async function loadMembers(selectedId = null, selectedPositions = []) {
        if (!membersLoaded) {
            membersList.textContent = 'Cargando personal…';
            try {
                const response = await fetch(<?= json_encode(APP_URL . '/modulos/clientes/pmi_habilitaciones_integrantes.php') ?> + '?client_id=<?= (int) $clientId ?>&edit_id=' + encodeURIComponent(document.getElementById('habilitationId').value || 0), {credentials: 'same-origin'});
                if (!response.ok) throw new Error('No se pudo cargar el personal.');
                const data = await response.json();
                membersList.replaceChildren();
                for (const worker of data.workers || []) {
                    const card = document.createElement('div');
                    card.className = 'habilitation-member-card';
                    card.dataset.search = worker.name.toLocaleLowerCase();
                    const label = document.createElement('label');
                    label.className = 'habilitation-member-name';
                    const check = document.createElement('input');
                    check.type = 'checkbox'; check.name = 'member_ids[]'; check.value = worker.id;
                    check.className = 'form-check-input member-check'; check.disabled = !worker.positions.length;
                    const info = document.createElement('span');
                    info.textContent = worker.name;
                    label.append(check, info);
                    card.append(label);
                    const positions = document.createElement('div');
                    positions.className = 'habilitation-member-positions';
                    if (worker.positions.length) {
                        for (const position of worker.positions) {
                            const option = document.createElement('label');
                            option.className = 'habilitation-position-option';
                            const positionCheck = document.createElement('input');
                            positionCheck.type = 'checkbox'; positionCheck.name = `member_positions[${worker.id}][]`;
                            positionCheck.value = position.id; positionCheck.className = 'form-check-input member-position-check';
                            const name = document.createElement('span'); name.textContent = position.name;
                            option.append(positionCheck, name); positions.append(option);
                        }
                    } else {
                        positions.textContent = 'Sin puestos habilitados para este cliente';
                        card.classList.add('habilitation-member-unavailable');
                    }
                    card.append(positions); membersList.append(card);
                }
                membersLoaded = true;
            } catch (error) { membersList.textContent = error.message; return; }
        }
        membersList.querySelectorAll('.habilitation-member-card').forEach(card => {
            const check = card.querySelector('.member-check');
            check.checked = selectedId !== null && String(check.value) === String(selectedId);
            card.querySelectorAll('.member-position-check').forEach(position => position.checked = check.checked && selectedPositions.map(String).includes(position.value));
        });
        membersAll.checked = false;
        membersSearch.value = '';
        onlyEligible.checked = true;
        filterMembers();
        updateMemberSelection();
    }
    function updateMemberSelection() {
        const checks = [...membersList.querySelectorAll('.member-check:not(:disabled)')];
        const selected = checks.filter(check => check.checked).length;
        membersCount.textContent = selected + (selected === 1 ? ' seleccionado' : ' seleccionados');
        membersAll.checked = checks.length > 0 && selected === checks.length;
        membersAll.indeterminate = selected > 0 && selected < checks.length;
        membersError.classList.toggle('d-none', selected > 0);
    }
    membersList.addEventListener('change', event => {
        const card = event.target.closest('.habilitation-member-card');
        if (!card) return;
        const member = card.querySelector('.member-check');
        const positions = [...card.querySelectorAll('.member-position-check')];
        if (event.target === member) positions.forEach(check => check.checked = member.checked);
        else member.checked = positions.some(check => check.checked);
        updateMemberSelection();
    });
    membersAll.addEventListener('change', () => {
        membersList.querySelectorAll('.habilitation-member-card').forEach(card => {
            const member = card.querySelector('.member-check');
            if (member.disabled) return;
            member.checked = membersAll.checked;
            card.querySelectorAll('.member-position-check').forEach(check => check.checked = membersAll.checked);
        });
        updateMemberSelection();
    });
    membersSearch.addEventListener('input', filterMembers);
    onlyEligible.addEventListener('change', filterMembers);
    function updateHabilitationMode(selectedId = null, selectedPositions = []) {
        const group = area.value === 'personal' && type.value === 'grupal';
        modal.classList.toggle('is-group', group);
        subjectPanel.hidden = group;
        membersPanel.hidden = !group;
        subject.disabled = group;
        positionsPanel.hidden = group || area.value !== 'personal';
        positionsPanel.querySelectorAll('input').forEach(input => input.disabled = group);
        if (group) loadMembers(selectedId, selectedPositions);
    }
    function updatePersonalFields() {
        const isPersonal = area.value === 'personal';
        form.querySelector('.habilitation-main-grid').classList.toggle('is-equipment', !isPersonal);
        typePanel.hidden = !isPersonal;
        conditionsPanel.hidden = !isPersonal;
        typePanel.querySelectorAll('select').forEach(input => input.disabled = !isPersonal);
        conditionsPanel.querySelectorAll('input').forEach(input => input.disabled = !isPersonal);
        updateValidityFields();
        updateHabilitationMode();
    }
    const positionsPanel = document.getElementById('habilitationPositionsPanel');
    const positionsList = document.getElementById('habilitationPositionsList');
    const positionsAll = document.getElementById('habilitationPositionsAll');
    const positionsError = document.getElementById('habilitationPositionsError');
    const positionsCount = document.getElementById('habilitationPositionsCount');
    let positionRequest = 0;
    function updatePositionSelection() {
        const checks = [...positionsList.querySelectorAll('input[name="position_ids[]"]')];
        const selected = checks.filter(check => check.checked).length;
        positionsAll.disabled = checks.length === 0;
        positionsAll.checked = checks.length > 0 && selected === checks.length;
        positionsAll.indeterminate = selected > 0 && selected < checks.length;
        positionsCount.textContent = selected + (selected === 1 ? ' seleccionado' : ' seleccionados');
        positionsError.classList.toggle('d-none', selected > 0 || !form.dataset.positionValidationAttempted);
    }
    async function loadPositions(selectedIds = []) {
        const request = ++positionRequest;
        delete form.dataset.positionValidationAttempted;
        positionsList.replaceChildren();
        positionsAll.checked = false;
        positionsAll.indeterminate = false;
        positionsCount.textContent = '0 seleccionados';
        positionsPanel.hidden = area.value !== 'personal' || type.value === 'grupal';
        positionsError.classList.add('d-none');
        if (positionsPanel.hidden) return;
        if (!subject.value) { positionsList.textContent = 'Seleccione primero un trabajador.'; positionsAll.disabled = true; return; }
        positionsList.textContent = 'Cargando puestos…';
        positionsAll.disabled = true;
        try {
            const url = <?= json_encode(APP_URL . '/modulos/clientes/pmi_habilitaciones_puestos.php') ?> + '?worker_id=' + encodeURIComponent(subject.value) + '&client_id=<?= (int) $clientId ?>';
            const response = await fetch(url, {credentials: 'same-origin'});
            if (!response.ok) throw new Error('No se pudieron cargar los puestos.');
            const data = await response.json();
            if (request !== positionRequest) return;
            positionsList.replaceChildren();
            const chosen = new Set(selectedIds.map(String));
            for (const position of data.positions || []) {
                const label = document.createElement('label');
                label.className = 'habilitation-position-option';
                const check = document.createElement('input');
                check.type = 'checkbox'; check.name = 'position_ids[]'; check.value = position.id;
                check.className = 'form-check-input'; check.checked = chosen.has(String(position.id));
                const name = document.createElement('span');
                name.textContent = position.name;
                label.append(check, name);
                positionsList.append(label);
            }
            if (!positionsList.children.length) positionsList.textContent = 'Este trabajador no tiene puestos habilitados para este cliente en Documentos generales > Personal.';
            updatePositionSelection();
        } catch (error) {
            if (request === positionRequest) positionsList.textContent = error.message;
        }
    }
    positionsAll.addEventListener('change', () => {
        positionsList.querySelectorAll('input[name="position_ids[]"]').forEach(check => check.checked = positionsAll.checked);
        updatePositionSelection();
    });
    positionsList.addEventListener('change', updatePositionSelection);
    form.addEventListener('submit', event => {
        if (area.value === 'personal' && type.value === 'grupal') {
            if (membersList.querySelector('input[name="member_ids[]"]:checked')) return;
            event.preventDefault(); membersError.classList.remove('d-none'); membersPanel.scrollIntoView({block: 'nearest'}); return;
        }
        if (area.value !== 'personal') return;
        if (positionsList.querySelector('input[name="position_ids[]"]:checked')) return;
        event.preventDefault(); form.dataset.positionValidationAttempted = '1'; positionsError.classList.remove('d-none'); positionsPanel.scrollIntoView({block: 'nearest'});
    });
    const searchableSubject = window.jQuery && jQuery.fn.select2 ? jQuery(subject) : null;
    if (searchableSubject) searchableSubject.select2({theme:'bootstrap4', width:'100%', dropdownParent:jQuery(modal), placeholder:'Buscar por nombre o documento', ajax:{url:<?= json_encode(APP_URL . '/modulos/clientes/pmi_habilitaciones_buscar.php') ?>, dataType:'json', delay:350, data:params=>({area:area.value,client_id:<?= (int) $clientId ?>,edit_id:document.getElementById('habilitationId').value||0,q:params.term||''}), processResults:data=>({results:data.results||[]})}, language:{noResults:()=> 'No hay personal disponible', searching:()=> 'Buscando…'}});
    let changingSubjectProgrammatically = false;
    const subjectChanged = () => { if (!changingSubjectProgrammatically) loadPositions(); };
    if (searchableSubject) searchableSubject.on('change', subjectChanged);
    else subject.addEventListener('change', subjectChanged);
    function populate(selected = null) {
        updatePersonalFields();
        changingSubjectProgrammatically = true;
        subject.replaceChildren(new Option(area.value === 'personal' ? 'Seleccione personal' : 'Seleccione equipo', ''));
        if (selected) subject.add(new Option(selected.subject_name + (selected.subject_detail ? ' - ' + selected.subject_detail : ''), selected.subject_id, true, true));
        searchableSubject?.trigger('change');
        changingSubjectProgrammatically = false;
        document.getElementById('habilitationSource').textContent = area.value === 'personal' ? 'Personal de Requisitos - Personal' : 'Equipo de Maquinaria - Datos generales';
        loadPositions(selected?.position_ids || []);
    }
    area.addEventListener('change', () => populate());
    type.addEventListener('change', () => { updateHabilitationMode(); loadPositions(); });
    document.getElementById('newHabilitation')?.addEventListener('click', () => {
        form.reset(); document.getElementById('habilitationId').value = ''; membersLoaded = false; document.getElementById('habilitationModalTitle').textContent = 'Nueva habilitación';
        area.value = 'personal'; populate();
        const now = new Date(), today = [now.getFullYear(), String(now.getMonth()+1).padStart(2,'0'), String(now.getDate()).padStart(2,'0')].join('-');
        document.getElementById('habilitationRegistration').value = today;
        bootstrap.Modal.getOrCreateInstance(modal).show();
    });
    document.querySelectorAll('.edit-habilitation').forEach(button => button.addEventListener('click', () => {
        const row = JSON.parse(button.dataset.record);
        form.reset(); membersLoaded = false;
        document.getElementById('habilitationId').value = row.id; area.value = row.area; populate(row);
        document.getElementById('habilitationType').value = row.habilitation_type || 'individual';
        updateHabilitationMode(row.subject_id, row.position_ids || []);
        loadPositions(row.position_ids || []);
        for (const field of ['has_pass', 'has_validity']) {
            const value = field === 'has_validity' && row[field] == null && row.registration_date && row.start_date && row.end_date ? '1' : row[field];
            const selected = form.querySelector(`input[name="${field}"][value="${value}"]`);
            if (selected) selected.checked = true;
        }
        updateValidityFields();
        document.getElementById('habilitationRegistration').value = row.registration_date;
        document.getElementById('habilitationStart').value = row.start_date;
        document.getElementById('habilitationEnd').value = row.end_date;
        document.getElementById('habilitationModalTitle').textContent = 'Editar habilitación';
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }));
    document.querySelectorAll('.delete-habilitation').forEach(form => form.addEventListener('submit', async event => {
        event.preventDefault();
        const answer = await Swal.fire({title:'¿Eliminar habilitación?', text:'Los requisitos y sus archivos se conservarán en sus registros originales.', icon:'warning', showCancelButton:true, confirmButtonText:'Eliminar habilitación', cancelButtonText:'Cancelar'});
        if (answer.isConfirmed) HTMLFormElement.prototype.submit.call(form);
    }));
    document.querySelectorAll('.edit-personal-format').forEach(button => button.addEventListener('click', () => {
        const record = JSON.parse(button.dataset.record);
        document.getElementById('personalFormatForm').reset();
        document.getElementById('personalFormatHabilitation').value = record.habilitation_id;
        document.getElementById('personalFormatCatalog').value = record.catalog_id;
        const today = new Date();
        const todayValue = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');
        document.getElementById('personalFormatRegistration').value = record.registration_date || todayValue;
        document.getElementById('personalFormatStart').value = record.start_date || '';
        document.getElementById('personalFormatEnd').value = record.end_date || '';
        document.getElementById('personalFormatModalTitle').textContent = (record.registration_date ? 'Editar' : 'Registrar') + ' formato: ' + record.name;
        document.getElementById('personalFormatFileHelp').textContent = record.has_file
            ? 'Si no selecciona otro archivo, se conservará el actual. PDF, JPG, PNG o WEBP; máximo 10 MB.'
            : 'Formatos permitidos: PDF, JPG, PNG y WEBP. Máximo 10 MB.';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('personalFormatModal')).show();
    }));
    const preview = document.getElementById('habilitationPreview');
    document.querySelectorAll('.preview-habilitation-file').forEach(button => button.addEventListener('click', () => {
        const node = document.createElement(/\.pdf$/i.test(button.dataset.url) ? 'iframe' : 'img');
        node.src = button.dataset.url; node.title = button.dataset.name; node.alt = button.dataset.name;
        document.getElementById('habilitationPreviewBody').replaceChildren(node);
        document.getElementById('habilitationPreviewTitle').textContent = button.dataset.name;
        bootstrap.Modal.getOrCreateInstance(preview).show();
    }));
    preview.addEventListener('hidden.bs.modal', () => document.getElementById('habilitationPreviewBody').replaceChildren());
});
</script>
