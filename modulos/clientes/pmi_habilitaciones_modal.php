<div class="modal fade habilitation-form-modal" id="habilitationModal" tabindex="-1" aria-labelledby="habilitationModalTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><form class="modal-content" method="post" action="<?= APP_URL ?>/modulos/clientes/pmi_habilitaciones_guardar.php" id="habilitationForm">
    <div class="modal-header"><h2 class="modal-title h5" id="habilitationModalTitle">Nueva habilitación</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="client_id" value="<?= $clientId ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="habilitationId">
        <div class="row g-3"><div class="col-12"><label class="form-label" for="habilitationArea">Área de habilitación</label><select class="form-select" name="area" id="habilitationArea" required><option value="personal">Personal</option><option value="equipo">Equipo</option></select></div>
        <div class="col-12"><label class="form-label" for="habilitationSubject">Nombre</label><select class="form-select" name="subject_id" id="habilitationSubject" required><option value="">Seleccione personal</option></select><small class="text-muted" id="habilitationSource">Personal de Requisitos - Personal</small></div>
        <div class="col-12" id="habilitationPositionsPanel" hidden><div class="habilitation-positions-box"><div class="habilitation-positions-heading"><div><div class="habilitation-positions-title"><i class="fa-solid fa-briefcase" aria-hidden="true"></i><strong>Puestos</strong><span class="habilitation-positions-count" id="habilitationPositionsCount">0 seleccionados</span></div><p>Elige los puestos que aparecerán en esta habilitación.</p></div><label class="habilitation-positions-select-all" for="habilitationPositionsAll"><input class="form-check-input" type="checkbox" id="habilitationPositionsAll"><span>Seleccionar todo</span></label></div><div id="habilitationPositionsList" class="habilitation-positions-list" aria-live="polite">Seleccione primero un trabajador.</div><small class="habilitation-positions-error d-none" id="habilitationPositionsError"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> Seleccione al menos un puesto.</small></div></div>
        <div class="col-12"><strong>Vigencia de habilitación</strong></div><div class="col-md-4"><label class="form-label" for="habilitationRegistration">F. Registro</label><input class="form-control" type="date" name="registration_date" id="habilitationRegistration" required></div><div class="col-md-4"><label class="form-label" for="habilitationStart">F. Inicio</label><input class="form-control" type="date" name="start_date" id="habilitationStart" required></div><div class="col-md-4"><label class="form-label" for="habilitationEnd">F. Fin</label><input class="form-control" type="date" name="end_date" id="habilitationEnd" required></div></div>
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
        updateHabilitationDownloads(group.closest('.border.rounded-3'));
    }));
    const modal = document.getElementById('habilitationModal'), form = document.getElementById('habilitationForm');
    const area = document.getElementById('habilitationArea'), subject = document.getElementById('habilitationSubject');
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
        positionsPanel.hidden = area.value !== 'personal';
        positionsError.classList.add('d-none');
        if (positionsPanel.hidden) return;
        if (!subject.value) { positionsList.textContent = 'Seleccione primero un trabajador.'; positionsAll.disabled = true; return; }
        positionsList.textContent = 'Cargando puestos…';
        positionsAll.disabled = true;
        try {
            const url = <?= json_encode(APP_URL . '/modulos/clientes/pmi_habilitaciones_puestos.php') ?> + '?worker_id=' + encodeURIComponent(subject.value);
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
            if (!positionsList.children.length) positionsList.textContent = 'Este trabajador no tiene puestos asignados.';
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
        if (area.value !== 'personal') return;
        if (positionsList.querySelector('input[name="position_ids[]"]:checked')) return;
        event.preventDefault(); form.dataset.positionValidationAttempted = '1'; positionsError.classList.remove('d-none'); positionsPanel.scrollIntoView({block: 'nearest'});
    });
    const searchableSubject = window.jQuery && jQuery.fn.select2 ? jQuery(subject) : null;
    if (searchableSubject) searchableSubject.select2({theme:'bootstrap4', width:'100%', dropdownParent:jQuery(modal), placeholder:'Buscar por nombre o documento', ajax:{url:<?= json_encode(APP_URL . '/modulos/clientes/pmi_habilitaciones_buscar.php') ?>, dataType:'json', delay:350, data:params=>({area:area.value,q:params.term||''}), processResults:data=>({results:data.results||[]})}, language:{noResults:()=> 'No se encontraron resultados', searching:()=> 'Buscando…'}});
    let changingSubjectProgrammatically = false;
    const subjectChanged = () => { if (!changingSubjectProgrammatically) loadPositions(); };
    if (searchableSubject) searchableSubject.on('change', subjectChanged);
    else subject.addEventListener('change', subjectChanged);
    function populate(selected = null) {
        changingSubjectProgrammatically = true;
        subject.replaceChildren(new Option(area.value === 'personal' ? 'Seleccione personal' : 'Seleccione equipo', ''));
        if (selected) subject.add(new Option(selected.subject_name + (selected.subject_detail ? ' - ' + selected.subject_detail : ''), selected.subject_id, true, true));
        searchableSubject?.trigger('change');
        changingSubjectProgrammatically = false;
        document.getElementById('habilitationSource').textContent = area.value === 'personal' ? 'Personal de Requisitos - Personal' : 'Equipo de Maquinaria - Datos generales';
        loadPositions(selected?.position_ids || []);
    }
    area.addEventListener('change', () => populate());
    document.getElementById('newHabilitation')?.addEventListener('click', () => {
        form.reset(); document.getElementById('habilitationId').value = ''; document.getElementById('habilitationModalTitle').textContent = 'Nueva habilitación';
        area.value = 'personal'; populate();
        const now = new Date(), today = [now.getFullYear(), String(now.getMonth()+1).padStart(2,'0'), String(now.getDate()).padStart(2,'0')].join('-');
        document.getElementById('habilitationRegistration').value = today;
        bootstrap.Modal.getOrCreateInstance(modal).show();
    });
    document.querySelectorAll('.edit-habilitation').forEach(button => button.addEventListener('click', () => {
        const row = JSON.parse(button.dataset.record);
        document.getElementById('habilitationId').value = row.id; area.value = row.area; populate(row);
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
