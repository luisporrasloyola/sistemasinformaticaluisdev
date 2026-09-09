'use strict';

function initQuickAttendanceMarking() {
    const quickBaseUrl = window.APP_URL || window.location.origin;
    const quickCsrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const quickEscapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    })[character]);
    const worker = document.getElementById('markWorkerId');
    const location = document.getElementById('markLocationSelect');
    const schedule = document.getElementById('markScheduleSelect');
    const project = document.getElementById('markProjectSelect');
    const entryButton = document.getElementById('markEntryBtn');
    const exitButton = document.getElementById('markExitBtn');
    const changeSelectionButton = document.getElementById('markChangeSelectionBtn');
    const statusPanel = document.getElementById('markStatusPanel');
    const help = document.getElementById('markPermissionHelp');
    const camera = document.getElementById('markCamera');
    const canvas = document.getElementById('markCanvas');
    const cameraEmpty = document.getElementById('markCameraEmpty');
    const photoPreview = document.getElementById('markPhotoPreview');
    const mapElement = document.getElementById('markMap');
    const mapEmpty = document.getElementById('markMapEmpty');
    const marksPagination = document.getElementById('recentMarksPagination');
    const tripsPagination = document.getElementById('recentTripsPagination');
    if (!worker || !location || !schedule || !project || !entryButton || !exitButton || !changeSelectionButton || !camera || !canvas || !mapElement) return;

    let state = null;
    let stream = null;
    let map = null;
    let locationMarker = null;
    let radiusCircle = null;
    let currentMarker = null;
    let editingSelection = true;
    let marksPage = 1;
    let movementsPage = 1;

    const selectElements = [location, schedule, project];
    const searchableSelects = new Map();
    const selectedValues = new Map();
    const searchConfig = new Map([
        [location, { placeholder: 'Buscar lugar de marcación', input: 'Escriba el nombre del lugar...', empty: 'No se encontraron lugares' }],
        [schedule, { placeholder: 'Buscar horario', input: 'Escriba el nombre del horario...', empty: 'No se encontraron horarios' }],
        [project, { placeholder: 'Buscar proyecto', input: 'Escriba el nombre del proyecto...', empty: 'No se encontraron proyectos' }]
    ]);

    if (window.jQuery && window.jQuery.fn.select2) {
        if (worker.tagName === 'SELECT' && !window.jQuery(worker).hasClass('select2-hidden-accessible')) {
            const workerSearch = window.jQuery(worker).select2({
                theme: 'bootstrap4',
                width: '100%',
                placeholder: worker.dataset.placeholder || 'Buscar trabajador',
                allowClear: true,
                minimumResultsForSearch: 0,
                language: {
                    noResults: () => 'No se encontraron trabajadores',
                    searching: () => 'Buscando...'
                }
            });
            workerSearch.on('select2:open.quickAttendance', () => {
                const searchField = document.querySelector('.select2-container--open .select2-search__field');
                if (searchField) {
                    searchField.placeholder = 'Escriba nombre, documento o empresa...';
                    searchField.focus();
                }
            });
        }
        selectElements.forEach((select) => {
            const config = searchConfig.get(select);
            try {
                const searchable = window.jQuery(select).select2({
                    theme: 'bootstrap4',
                    width: '100%',
                    placeholder: select.dataset.placeholder || config.placeholder,
                    allowClear: true,
                    minimumResultsForSearch: 0,
                    language: {
                        noResults: () => config.empty,
                        searching: () => 'Buscando...'
                    }
                });
                searchable.on('select2:open', () => {
                    const searchField = document.querySelector('.select2-container--open .select2-search__field');
                    if (searchField) {
                        searchField.placeholder = config.input;
                        searchField.focus();
                    }
                });
                searchable.on('select2:select.quickAttendance', (event) => {
                    const selectedId = String(event.params?.data?.id || '');
                    selectedValues.set(select, selectedId);
                    select.value = selectedId;
                    renderStatus();
                    if (select === location) updateMap();
                });
                searchable.on('select2:clear.quickAttendance', () => {
                    selectedValues.set(select, '');
                    select.value = '';
                    renderStatus();
                    if (select === location) updateMap();
                });
                searchableSelects.set(select, searchable);
            } catch (error) {
                searchableSelects.delete(select);
            }
        });
    }
    function selectValue(select) {
        const explicitValue = selectedValues.get(select);
        const jqueryValue = searchableSelects.has(select) ? searchableSelects.get(select).val() : null;
        return String(explicitValue || select.value || jqueryValue || '').trim();
    }
    function setSelect(select, value) {
        const normalizedValue = value ? String(value) : '';
        selectedValues.set(select, normalizedValue);
        select.value = normalizedValue;
        const searchable = searchableSelects.get(select);
        if (searchable) searchable.val(normalizedValue).trigger('change.select2');
    }
    function selectedLocation() {
        const locationId = selectValue(location);
        const option = Array.from(location.options).find((item) => item.value === locationId);
        if (!option?.value) return null;
        return { id:Number(option.value), name:option.textContent.trim(), latitude:Number(option.dataset.latitude), longitude:Number(option.dataset.longitude), radius:Number(option.dataset.radius || 100) };
    }
    function syncCurrentLocationOption() {
        Array.from(location.options).forEach((option) => { option.disabled = false; });
        if (state?.has_entry === true && state?.has_exit !== true) {
            const lastEntry = [...(state.marks || [])].reverse().find((mark) => mark.mark_type === 'entrada');
            const currentOption = lastEntry
                ? Array.from(location.options).find((option) => Number(option.value) === Number(lastEntry.location_id))
                : null;
            if (currentOption) currentOption.disabled = true;
        }
        searchableSelects.get(location)?.trigger('change.select2');
    }

    function renderStatus() {
        const requiresWorkerSelection = worker.tagName === 'SELECT';
        const locationValue = selectValue(location);
        const scheduleValue = selectValue(schedule);
        const projectValue = selectValue(project);
        const workerValue = String(worker.value || '').trim();
        const complete = !!locationValue && !!scheduleValue && !!projectValue && (!requiresWorkerSelection || !!workerValue);
        const closed = state?.has_exit === true;
        const started = state?.has_entry === true;
        const selectionLocked = started && !editingSelection && !closed;

        selectElements.forEach((select) => { select.disabled = selectionLocked || closed; });
        searchableSelects.forEach((searchable) => searchable.prop('disabled', selectionLocked || closed).trigger('change.select2'));
        entryButton.classList.toggle('d-none', selectionLocked || closed);
        changeSelectionButton.classList.toggle('d-none', !selectionLocked);
        entryButton.disabled = !complete || closed || selectionLocked;
        changeSelectionButton.disabled = closed;
        exitButton.disabled = !complete || !started || closed;

        statusPanel.innerHTML = [
            `<span class="badge ${started ? 'text-bg-primary' : 'text-bg-secondary'}">${started ? 'Marcación registrada' : 'Sin marcación de entrada'}</span>`,
            `<span class="badge ${closed ? 'text-bg-primary' : 'text-bg-secondary'}">${closed ? 'Salida registrada' : 'Salida no registrada'}</span>`
        ].join('');

        if (closed) {
            help.textContent = 'Jornada finalizada. La salida ya fue registrada.';
        } else if (selectionLocked) {
            help.textContent = 'Puede finalizar su jornada o cambiar de lugar y proyecto para registrar otra marcación.';
        } else if (complete) {
            help.textContent = started ? 'Revise las nuevas opciones y registre su marcación.' : 'Revise las opciones y registre su primera marcación.';
        } else {
            const missing = [];
            if (!locationValue) missing.push('lugar de marcación');
            if (!scheduleValue) missing.push('horario');
            if (!projectValue) missing.push('proyecto');
            if (requiresWorkerSelection && !workerValue) missing.push('trabajador');
            help.textContent = `Seleccione ${missing.join(', ')} para registrar su asistencia.`;
        }
    }
    function updateMap() {
        const place = selectedLocation();
        if (!place || !Number.isFinite(place.latitude) || !Number.isFinite(place.longitude)) {
            mapElement.classList.add('d-none'); mapEmpty?.classList.remove('d-none'); return;
        }
        mapElement.classList.remove('d-none'); mapEmpty?.classList.add('d-none');
        if (!window.L) return;
        if (!map) { map=L.map(mapElement).setView([place.latitude,place.longitude],16); L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(map); }
        const point=[place.latitude,place.longitude];
        if (!locationMarker) locationMarker=L.marker(point).addTo(map); else locationMarker.setLatLng(point);
        locationMarker.bindPopup(place.name);
        if (!radiusCircle) radiusCircle=L.circle(point,{radius:place.radius,color:'#2563eb',fillColor:'#2563eb',fillOpacity:.12}).addTo(map); else {radiusCircle.setLatLng(point);radiusCircle.setRadius(place.radius);}
        map.setView(point,16); setTimeout(()=>map.invalidateSize(),100);
    }
    function renderMarkStatus(status) {
        const normalized = String(status || '').trim().toLowerCase();
        const labels = {
            puntual: 'Puntual',
            tardanza: 'Tardanza',
            salida_valida: 'Salida válida',
            salida_anticipada: 'Salida anticipada',
            dentro_del_radio: 'Dentro del radio',
            fuera_del_radio: 'Fuera del radio'
        };
        const classes = {
            puntual: 'text-bg-success',
            tardanza: 'text-bg-warning text-dark',
            salida_valida: 'text-bg-primary',
            salida_anticipada: 'text-bg-early-exit',
            dentro_del_radio: 'text-bg-success',
            fuera_del_radio: 'text-bg-danger'
        };
        const label = labels[normalized] || (normalized ? normalized.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase()) : 'Pendiente');
        return `<span class="badge ${classes[normalized] || 'text-bg-secondary'}">${quickEscapeHtml(label)}</span>`;
    }
    function renderPagination(container, pagination, pageType) {
        if (!container || !pagination) return;
        const page = Number(pagination.page || 1);
        const pages = Number(pagination.pages || 1);
        const total = Number(pagination.total || 0);
        container.innerHTML = `<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-3">
            <small class="text-muted">${total} registro${total === 1 ? '' : 's'} · Página ${page} de ${pages}</small>
            <div class="btn-group btn-group-sm" role="group" aria-label="Paginación">
                <button type="button" class="btn btn-outline-secondary" data-page-type="${pageType}" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>Anterior</button>
                <button type="button" class="btn btn-outline-secondary" data-page-type="${pageType}" data-page="${page + 1}" ${page >= pages ? 'disabled' : ''}>Siguiente</button>
            </div>
        </div>`;
    }
    async function loadRecent() {
        const marksBody = document.getElementById('recentAttendanceMarks');
        const movementsBody = document.getElementById('recentAttendanceTrips');
        if (!worker.value) return;

        try {
            const response = await fetch(`${quickBaseUrl}/servicios/control_personal/listar_marcaciones_recientes.php?worker_id=${encodeURIComponent(worker.value)}&marks_page=${marksPage}&movements_page=${movementsPage}&_=${Date.now()}`, { cache: 'no-store' });
            const data = await response.json();
            if (!data.ok) throw new Error(data.message || 'No se pudo cargar la actividad reciente.');

            const journeyMarks = data.journey_marks || [];

            if (marksBody) {
                marksBody.innerHTML = journeyMarks.length
                    ? journeyMarks.map((mark) => `<tr>
                        <td>${quickEscapeHtml(mark.date)}</td>
                        <td>${quickEscapeHtml(mark.time || '-')}</td>
                        <td class="fw-semibold ${mark.type === 'Entrada' ? 'text-success' : 'text-primary'}">${mark.type}</td>
                        <td>${quickEscapeHtml(mark.worker || '-')}</td>
                        <td>${quickEscapeHtml(mark.location || '-')}</td>
                        <td>${quickEscapeHtml(mark.project || '-')}</td>
                        <td>${renderMarkStatus(mark.status)}</td>
                        <td>${mark.photo_path ? `<a class="btn btn-sm btn-outline-success" target="_blank" href="${quickBaseUrl}/${quickEscapeHtml(mark.photo_path)}"><i class="fa-regular fa-image"></i></a>` : '-'}</td>
                    </tr>`).join('')
                    : '<tr><td colspan="8" class="text-center text-muted py-4">Sin entradas ni salidas recientes.</td></tr>';
            }

            const movements = data.movements || [];
            if (movementsBody) {
                movementsBody.innerHTML = movements.length
                    ? movements.map((movement) => `<tr>
                        <td>${quickEscapeHtml(movement.date)}</td>
                        <td>${quickEscapeHtml(movement.start)}</td>
                        <td>${quickEscapeHtml(movement.end)}</td>
                        <td>${quickEscapeHtml(movement.duration)}</td>
                        <td>${quickEscapeHtml(movement.origin)}</td>
                        <td class="fw-semibold">${quickEscapeHtml(movement.destination)}</td>
                        <td>${quickEscapeHtml(movement.project || '-')}</td>
                        <td><span class="badge text-bg-success">${quickEscapeHtml(movement.status || 'Registrado')}</span></td>
<td>${movement.photo_path ? `<a class="btn btn-sm btn-outline-success" target="_blank" href="${quickBaseUrl}/${quickEscapeHtml(movement.photo_path)}" title="Ver foto de llegada"><i class="fa-regular fa-image"></i></a>` : '-'}</td>
                    </tr>`).join('')
                    : '<tr><td colspan="9" class="text-center text-muted py-4">Sin desplazamientos laborales registrados.</td></tr>';
            }
            marksPage = Number(data.pagination?.marks?.page || 1);
            movementsPage = Number(data.pagination?.movements?.page || 1);
            renderPagination(marksPagination, data.pagination?.marks, 'marks');
            renderPagination(tripsPagination, data.pagination?.movements, 'movements');        } catch (error) {
            if (marksBody) marksBody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">No se pudo cargar el historial.</td></tr>';
            if (movementsBody) movementsBody.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-4">No se pudieron cargar los desplazamientos.</td></tr>';
        }
    }
    async function loadState(resetSelections=false) {
        if (resetSelections) {
            selectElements.forEach((item) => setSelect(item, ''));
            state = null;
            editingSelection = true;
            syncCurrentLocationOption();
            renderStatus();
            updateMap();
        }
        if (!worker.value) { state = null; renderStatus(); return; }

        const requestedWorkerId = String(worker.value);
        const response = await fetch(`${quickBaseUrl}/servicios/control_personal/contexto_marcacion.php?worker_id=${encodeURIComponent(requestedWorkerId)}&_=${Date.now()}`, { cache: 'no-store' });
        const data = await response.json();
        if (String(worker.value) !== requestedWorkerId) return;
        if (!data.ok) {
            state = null;
            renderStatus();
            return Swal.fire('Atención', data.message || 'No se pudo consultar la jornada.', 'warning');
        }

        state = data;
        editingSelection = !data.has_entry && !data.has_exit;
        const defaults = data.defaults || {};
        if (!selectValue(location) && Number(defaults.location_id) > 0) setSelect(location, defaults.location_id);
        if (!selectValue(schedule) && Number(defaults.schedule_id) > 0) setSelect(schedule, defaults.schedule_id);
        if (!selectValue(project) && Number(defaults.project_id) > 0) setSelect(project, defaults.project_id);
        syncCurrentLocationOption();
        renderStatus();
        updateMap();
        loadRecent();
    }
    async function cameraPhoto() {
        if(!window.isSecureContext)throw new Error('La cámara y el GPS requieren una conexión HTTPS segura.');
        if(!stream){stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user'},audio:false});camera.srcObject=stream;await camera.play();cameraEmpty?.classList.add('d-none');}
        canvas.width=camera.videoWidth||640;canvas.height=camera.videoHeight||480;canvas.getContext('2d').drawImage(camera,0,0,canvas.width,canvas.height);const data=canvas.toDataURL('image/jpeg',.86);if(photoPreview){photoPreview.src=data;photoPreview.classList.remove('d-none');}return data;
    }
    function stopCamera() {
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
        camera.pause();
        camera.srcObject = null;
        camera.removeAttribute('src');
        camera.load();
        cameraEmpty?.classList.remove('d-none');
        if (photoPreview) {
            photoPreview.classList.add('d-none');
            photoPreview.removeAttribute('src');
        }
    }
    function gps() { return new Promise((resolve,reject)=>navigator.geolocation?navigator.geolocation.getCurrentPosition(resolve,()=>reject(new Error('No se pudo obtener la ubicación GPS. Verifique los permisos.')),{enableHighAccuracy:true,timeout:20000,maximumAge:0}):reject(new Error('Este dispositivo no admite GPS.'))); }
    async function mark(type) {
        const locationValue=selectValue(location),scheduleValue=selectValue(schedule),projectValue=selectValue(project);
        if(!locationValue||!scheduleValue||!projectValue)return Swal.fire('Datos incompletos','Seleccione lugar de marcación, horario y proyecto.','info');
        if(type==='salida'){const answer=await Swal.fire({icon:'question',title:'¿Registrar salida?',text:'Al confirmar, finalizará su jornada de hoy y ya no podrá registrar otros lugares o proyectos.',showCancelButton:true,confirmButtonText:'Sí, marcar salida',cancelButtonText:'Cancelar',reverseButtons:true});if(!answer.isConfirmed)return;}
        const button=type==='entrada'?entryButton:exitButton;const original=button.innerHTML;button.disabled=true;button.innerHTML='<i class="fa-solid fa-spinner fa-spin me-2"></i>Registrando...';
        try { const [position,photo]=await Promise.all([gps(),cameraPhoto()]);const place=selectedLocation();if(map&&place){const current=[position.coords.latitude,position.coords.longitude];if(!currentMarker)currentMarker=L.marker(current).addTo(map);else currentMarker.setLatLng(current);map.fitBounds(L.latLngBounds([[place.latitude,place.longitude],current]).pad(.3));}
            const body=new FormData();body.append('csrf_token',quickCsrf);body.append('worker_id',worker.value);body.append('mark_type',type);body.append('location_id',locationValue);body.append('schedule_id',scheduleValue);body.append('project_id',projectValue);body.append('latitude',position.coords.latitude);body.append('longitude',position.coords.longitude);body.append('accuracy',position.coords.accuracy);body.append('photo_data',photo);body.append('observations','');
            const response=await fetch(`${quickBaseUrl}/servicios/control_personal/registrar_marcacion.php`,{method:'POST',body});const data=await response.json();if(!data.ok)throw new Error(data.message||'No se pudo registrar la marcación.');stopCamera();state={...(state||{}),has_entry:true,has_exit:type==='salida'};editingSelection=false;marksPage=1;movementsPage=1;renderStatus();await Swal.fire('Marcación registrada',data.message,'success');await loadState(false);
        } catch(error){Swal.fire('No se pudo registrar',error.message||String(error),'warning');} finally {stopCamera();button.innerHTML=original;renderStatus();}
    }
    function handlePaginationClick(event) {
        const button = event.target.closest('button[data-page-type][data-page]');
        if (!button || button.disabled) return;
        const nextPage = Math.max(1, Number(button.dataset.page || 1));
        if (button.dataset.pageType === 'marks') marksPage = nextPage;
        if (button.dataset.pageType === 'movements') movementsPage = nextPage;
        loadRecent();
    }
    marksPagination?.addEventListener('click', handlePaginationClick);
    tripsPagination?.addEventListener('click', handlePaginationClick);
    worker.addEventListener('change',()=>{ marksPage=1; movementsPage=1; loadState(true); });
    selectElements.forEach(select=>select.addEventListener('change',()=>{selectedValues.set(select,String(select.value||''));renderStatus();if(select===location)updateMap();}));
    changeSelectionButton.addEventListener('click',()=>{
        editingSelection = true;
        setSelect(location, '');
        syncCurrentLocationOption();
        renderStatus();
        const locationSearch = searchableSelects.get(location);
        if (locationSearch) locationSearch.select2('open'); else location.focus();
    });
    entryButton.addEventListener('click',()=>mark('entrada'));
    exitButton.addEventListener('click',()=>mark('salida'));
    renderStatus();if(worker.value)loadState(true);
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initQuickAttendanceMarking, { once: true });
} else {
    initQuickAttendanceMarking();
}
