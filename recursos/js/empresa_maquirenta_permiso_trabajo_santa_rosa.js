document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.permisoTrabajoConfig;
    const byId = id => document.getElementById(id);
    const body = byId('permisoTrabajoBody');
    const searchInput = byId('permisoTrabajoSearch');
    const searchClear = byId('permisoTrabajoSearchClear');
    const paginationInfo = byId('permisoTrabajoPaginationInfo');
    const paginationList = byId('permisoTrabajoPagination');
    const forms = {
        main: byId('permisoTrabajoForm'),
        extend: byId('permisoAmpliarForm'),
        close: byId('permisoCerrarForm'),
        upload: byId('permisoCargarForm')
    };
    const modals = {
        main: bootstrap.Modal.getOrCreateInstance(byId('permisoTrabajoModal')),
        extend: bootstrap.Modal.getOrCreateInstance(byId('permisoAmpliarModal')),
        close: bootstrap.Modal.getOrCreateInstance(byId('permisoCerrarModal')),
        upload: bootstrap.Modal.getOrCreateInstance(byId('permisoCargarModal'))
    };
    let rows = [];
    let currentPage = 1;
    let perPage = 10;
    let searchTerm = '';
    let totalPages = 1;
    let searchTimer = null;
    let listController = null;

    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
    const displayDate = value => {
        if (!value) return '—';
        const parts = String(value).split('-');
        return Number(parts[2]) + '/' + Number(parts[1]) + '/' + parts[0];
    };
    const localToday = () => {
        const current = new Date();
        return new Date(current.getTime() - current.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    };
    const nextDay = value => {
        const result = new Date(value + 'T12:00:00');
        result.setDate(result.getDate() + 1);
        return result.toISOString().slice(0, 10);
    };
    const request = async (url, options = {}) => {
        const response = await fetch(url, {headers:{Accept:'application/json'}, ...options});
        const data = await response.json().catch(() => ({ok:false,message:'Respuesta inválida del servidor.'}));
        if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo completar la operación.');
        return data;
    };
    const stateBadge = state => {
        const values = {
            cerrado:['badge-cerrado','CERRADO'],
            vigente:['bg-success','VIGENTE'],
            no_apto:['bg-danger','NO APTO']
        };
        const item = values[state] || ['bg-secondary','SIN ESTADO'];
        return '<span class="badge ' + item[0] + '">' + item[1] + '</span>';
    };
    const findRow = id => rows.find(row => Number(row.id) === Number(id));
    const validateFiles = (input, max, required = false) => {
        const count = input.files.length;
        if (required && count === 0) throw new Error('Seleccione al menos un archivo.');
        if (count > max) throw new Error('Solo puede seleccionar hasta ' + max + ' archivo(s).');
    };

    function render() {
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-5">No hay permisos de trabajo registrados.</td></tr>';
            return;
        }
        body.innerHTML = rows.map(row => {
            const id = Number(row.id);
            const closed = row.estado === 'cerrado';
            const validityCount = Number(row.vigencia_count);
            const documentCount = Number(row.documento_count || 0);
            const totalDocuments = Number(row.total_documentos || 0);
            const documentLimit = validityCount === 1 ? 2 : 3;
            const title = '<button type="button" class="permiso-expand-btn js-toggle-details" data-id="' + id + '" aria-expanded="false" title="Ver vigencias y documentos"><i class="fa-solid fa-chevron-right"></i><span>' + escapeHtml(row.permiso_nombre) + '</span></button>';
            const actions =
                (!closed && documentCount < documentLimit ? '<button class="btn btn-outline-primary js-upload" data-id="' + id + '" title="Cargar documentos"><i class="fa-solid fa-cloud-arrow-up"></i></button>' : '') +
                (closed ? '' : '<button class="btn btn-outline-primary js-extend" data-id="' + id + '" title="Ampliar vigencia"><i class="fa-solid fa-calendar-plus"></i></button>') +
                '<button class="btn btn-outline-danger js-delete" data-id="' + id + '" title="Eliminar permiso"><i class="fa-solid fa-trash"></i></button>';
            return '<tr class="permiso-main-row"><td>' + title + '<small class="d-block text-muted ms-1">' + validityCount + ' ' + (validityCount === 1 ? 'vigencia' : 'vigencias') + ' · ' + totalDocuments + ' documento(s)</small></td>' +
                '<td>' + displayDate(row.fecha_registro) + '</td><td>' + displayDate(row.fecha_inicio_original || row.fecha_inicio) + '</td><td>' + displayDate(row.fecha_vencimiento_original || row.fecha_vencimiento) + '</td><td>' + displayDate(row.fecha_ampliacion) + '</td><td>' + displayDate(row.fecha_cierre) + '</td>' +
                '<td>' + stateBadge(row.estado) + '</td><td>' + escapeHtml(row.registered_by || '—') + '</td><td><div class="permiso-actions">' + actions + '</div></td></tr>' +
                '<tr class="permiso-detail-row d-none" id="permisoDetail' + id + '"><td colspan="9"><div class="permiso-detail-content"></div></td></tr>';
        }).join('');

        body.querySelectorAll('.js-toggle-details').forEach(button => button.onclick = () => toggleDetails(button));
        body.querySelectorAll('.js-upload').forEach(button => button.onclick = () => openUpload(Number(button.dataset.id)));
        body.querySelectorAll('.js-extend').forEach(button => button.onclick = () => openExtend(Number(button.dataset.id)));
        body.querySelectorAll('.js-delete').forEach(button => button.onclick = () => removeRecord(Number(button.dataset.id)));
    }
    function renderPagination(pagination) {
        currentPage = Number(pagination.page || 1);
        totalPages = Number(pagination.total_pages || 1);
        const total = Number(pagination.total || 0);
        paginationInfo.textContent = total
            ? 'Mostrando ' + pagination.from + ' a ' + pagination.to + ' de ' + total + ' registros'
            : (searchTerm ? 'No se encontraron permisos' : 'No hay permisos registrados');
        if (totalPages <= 1) {
            paginationList.innerHTML = '';
            return;
        }
        const maxVisiblePages = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
        let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
        if (endPage - startPage + 1 < maxVisiblePages) {
            startPage = Math.max(1, endPage - maxVisiblePages + 1);
        }

        let pageButtons = '';
        for (let page = startPage; page <= endPage; page++) {
            pageButtons += '<li class="page-item ' + (page === currentPage ? 'active' : '') + '"><button class="page-link js-page" type="button" data-page="' + page + '">' + page + '</button></li>';
        }
        paginationList.innerHTML =
            '<li class="page-item ' + (currentPage === 1 ? 'disabled' : '') + '"><button class="page-link js-page" type="button" data-page="' + (currentPage - 1) + '" aria-label="Anterior"><span aria-hidden="true">&laquo;</span></button></li>' +
            pageButtons +
            '<li class="page-item ' + (currentPage === totalPages ? 'disabled' : '') + '"><button class="page-link js-page" type="button" data-page="' + (currentPage + 1) + '" aria-label="Siguiente"><span aria-hidden="true">&raquo;</span></button></li>';
        paginationList.querySelectorAll('.js-page').forEach(button => {
            button.onclick = () => {
                const page = Number(button.dataset.page);
                if (page < 1 || page > totalPages || page === currentPage) return;
                currentPage = page;
                load();
            };
        });
    }

    async function load() {
        if (listController) listController.abort();
        listController = new AbortController();
        body.innerHTML = '<tr><td colspan="9" class="text-center py-5"><span class="spinner-border spinner-border-sm text-primary me-2"></span>Cargando permisos...</td></tr>';
        const query = new URLSearchParams({
            action:'list', page:String(currentPage), per_page:String(perPage),
            search:searchTerm, t:String(Date.now())
        });
        try {
            const data = await request(cfg.serviceUrl + '?' + query.toString(), {signal:listController.signal});
            rows = data.rows || [];
            render();
            renderPagination(data.pagination || {});
        } catch (error) {
            if (error.name === 'AbortError') return;
            body.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-5">' + escapeHtml(error.message) + '</td></tr>';
            paginationInfo.textContent = '';
            paginationList.innerHTML = '';
        }
    }
    async function toggleDetails(button) {
        const id = Number(button.dataset.id);
        const detail = byId('permisoDetail' + id);
        const content = detail.querySelector('.permiso-detail-content');
        const opening = detail.classList.contains('d-none');
        detail.classList.toggle('d-none', !opening);
        body.querySelectorAll('.js-toggle-details[data-id="' + id + '"]').forEach(trigger => trigger.setAttribute('aria-expanded', opening ? 'true' : 'false'));
        const chevron = body.querySelector('.permiso-expand-btn[data-id="' + id + '"] i');
        if (chevron) chevron.className = opening ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-right';
        if (!opening || detail.dataset.loaded === '1') return;
        content.innerHTML = '<div class="text-center py-4"><span class="spinner-border spinner-border-sm text-primary"></span></div>';
        try {
            const data = await request(cfg.serviceUrl + '?action=history&id=' + id + '&t=' + Date.now());
            const validities = (data.vigencias || []).slice().reverse();
            content.innerHTML = '<div class="vigencias-inline">' +
                '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Etapa</th><th>Fecha de registro</th><th>Fecha de inicio</th><th>Fecha de vencimiento</th><th>Ampliación</th><th>Fecha de cierre</th><th>Estado</th><th>Documentos</th></tr></thead><tbody>' +
                validities.map((validity, index) => {
                    const files = validity.archivos || [];
                    const regular = files.filter(file => file.tipo_archivo !== 'cierre');
                    const closure = files.filter(file => file.tipo_archivo === 'cierre');
                    const stageId = Number(validity.id);
                    const documentsButton = files.length ? '<button class="btn btn-sm btn-outline-primary permiso-manage-files js-manage-files" type="button" data-stage-id="' + stageId + '" title="Ver documentos"><i class="fa-solid fa-folder-open me-1"></i>Documentos</button>' : '<span class="text-muted small">Sin documentos</span>';
                    const registrationDate = validity.fecha_registro_etapa || (index === 0 ? validity.permiso_fecha_registro : String(validity.created_at || '').slice(0,10));
                    const rowClass = index === 0 ? 'vigencia-inicial-row' : 'vigencia-ampliacion-row vigencia-ampliacion-' + (((index - 1) % 3) + 1);
                    const editableDate = value => '<button class="permiso-editable-date js-edit-stage" type="button" data-stage-id="' + stageId + '" title="Editar fechas"><span>' + displayDate(value) + '</span><i class="fa-solid fa-pen"></i></button>';
                    const stageAction = index > 0 && index === validities.length - 1 ? '<button class="permiso-delete-stage js-delete-stage" type="button" data-stage-id="' + stageId + '" title="Eliminar esta ampliación"><i class="fa-solid fa-trash"></i><span>Eliminar</span></button>' : '';
                    const editorFields = index === 0
                        ? '<div class="permiso-inline-field"><label>Fecha de registro</label><input class="form-control form-control-sm" type="date" name="fecha_registro" value="' + registrationDate + '" required></div><div class="permiso-inline-field"><label>Fecha de inicio</label><input class="form-control form-control-sm" type="date" name="fecha_inicio" value="' + validity.fecha_inicio + '" required></div><div class="permiso-inline-field"><label>Fecha de vencimiento</label><input class="form-control form-control-sm" type="date" name="fecha_vencimiento" value="' + validity.fecha_vencimiento + '" required></div>'
                        : '<div class="permiso-inline-field"><label>Fecha de registro</label><input class="form-control form-control-sm" type="date" name="fecha_registro" value="' + registrationDate + '" required></div><div class="permiso-inline-field"><label>Fecha de vigencia de ampliación</label><input class="form-control form-control-sm" type="date" name="fecha_vencimiento" value="' + validity.fecha_vencimiento + '" required></div>';
                    const editorRow = '<tr class="permiso-inline-editor-row d-none" id="stageEditor' + stageId + '"><td colspan="8"><form class="permiso-inline-editor js-stage-form" data-stage-id="' + stageId + '" data-initial="' + (index === 0 ? '1' : '0') + '"><div class="permiso-inline-editor-fields">' + editorFields + '</div><div class="permiso-inline-editor-actions"><button class="btn btn-sm btn-primary" type="submit">Guardar</button></div></form></td></tr>';
                    const filesList = files.map((file,fileIndex)=>'<div class="permiso-inline-file"><div class="permiso-inline-file-name"><span>' + (fileIndex+1) + '</span><i class="fa-solid fa-file-lines"></i><strong title="' + escapeHtml(file.archivo_nombre_original) + '">' + escapeHtml(file.archivo_nombre_original) + '</strong></div><div class="permiso-inline-file-actions"><a class="btn btn-sm btn-outline-primary" href="' + cfg.serviceUrl + '?action=file&id=' + file.id + '"><i class="fa-solid fa-download me-1"></i>Descargar</a><button class="btn btn-sm btn-outline-secondary js-replace-file" type="button" data-file-id="' + file.id + '"><i class="fa-solid fa-rotate me-1"></i>Reemplazar</button><button class="btn btn-sm btn-outline-danger js-delete-file" type="button" data-file-id="' + file.id + '"><i class="fa-solid fa-trash me-1"></i>Eliminar</button></div></div>').join('');
                    const filesRow = files.length ? '<tr class="permiso-inline-files-row d-none" id="stageFiles' + stageId + '"><td colspan="8"><div class="permiso-inline-files"><div class="permiso-inline-files-title"><i class="fa-solid fa-folder-open"></i><span>Documentos de esta etapa</span></div>' + filesList + '</div></td></tr>' : '';
                    return '<tr class="' + rowClass + '"><td><div class="permiso-stage-cell"><strong>' + (index === 0 ? 'Vigencia inicial' : 'Ampliación ' + index) + '</strong>' + stageAction + '</div></td><td>' + editableDate(registrationDate) + '</td><td>' + (index === 0 ? editableDate(validity.fecha_inicio) : '—') + '</td><td>' + (index === 0 ? editableDate(validity.fecha_vencimiento) : '—') + '</td><td>' + (index === 0 ? '—' : editableDate(validity.fecha_vencimiento)) + '</td><td>' + displayDate(validity.fecha_cierre) + '</td><td>' + stateBadge(validity.estado) + '</td><td><span class="document-count-pill">' + files.length + '</span> ' + documentsButton + '</td></tr>' + editorRow + filesRow;
                }).join('') + '</tbody></table></div></div>';
            content.querySelectorAll('.js-edit-stage').forEach(button => button.onclick = () => toggleStageEditor(Number(button.dataset.stageId)));
            content.querySelectorAll('.js-cancel-stage').forEach(button => button.onclick = () => toggleStageEditor(Number(button.dataset.stageId)));
            content.querySelectorAll('.js-stage-form').forEach(form => form.onsubmit = event => {event.preventDefault();saveInlineStage(form);});
            content.querySelectorAll('.js-delete-stage').forEach(button => button.onclick = () => deleteStage(Number(button.dataset.stageId)));
            content.querySelectorAll('.js-manage-files').forEach(button => button.onclick = () => toggleStageFiles(Number(button.dataset.stageId)));
            content.querySelectorAll('.js-replace-file').forEach(button => button.onclick = () => replaceFile(Number(button.dataset.fileId)));
            content.querySelectorAll('.js-delete-file').forEach(button => button.onclick = () => deleteFile(Number(button.dataset.fileId)));
            detail.dataset.loaded = '1';
        } catch (error) {
            content.innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml(error.message) + '</div>';
        }
    }

    function toggleStageEditor(stageId) {
        const editor=byId('stageEditor'+stageId);if(!editor)return;
        const opening=editor.classList.contains('d-none');
        document.querySelectorAll('.permiso-inline-editor-row').forEach(row=>row.classList.add('d-none'));
        editor.classList.toggle('d-none',!opening);
        if(opening){const first=editor.querySelector('input');if(first)first.focus();}
    }
    function toggleStageFiles(stageId) {
        const panel=byId('stageFiles'+stageId);if(!panel)return;
        panel.classList.toggle('d-none');
    }
    async function saveInlineStage(form) {
        const initial=form.dataset.initial==='1';
        const end=form.querySelector('[name="fecha_vencimiento"]').value;
        const start=initial?form.querySelector('[name="fecha_inicio"]').value:'';
        const registration=form.querySelector('[name="fecha_registro"]').value;
        if(!registration||!end||(initial&&!start)){Swal.fire('Atención','Complete correctamente las fechas.','warning');return;}
        if(initial&&start>end){Swal.fire('Atención','La fecha de inicio no puede ser posterior al vencimiento.','warning');return;}
        const button=form.querySelector('[type="submit"]');button.disabled=true;
        const data=new FormData();data.append('action','update_stage');data.append('csrf_token',cfg.csrfToken);data.append('vigencia_id',form.dataset.stageId);data.append('fecha_vencimiento',end);data.append('fecha_inicio',start);data.append('fecha_registro',registration);
        try{await request(cfg.serviceUrl,{method:'POST',body:data});await load();Swal.fire({icon:'success',title:'Fechas actualizadas',timer:1200,showConfirmButton:false});}catch(error){Swal.fire('No se pudo actualizar',error.message,'error');}finally{button.disabled=false;}
    }
    async function deleteStage(stageId) {
        const result=await Swal.fire({title:'¿Eliminar ampliación?',text:'También se eliminarán sus archivos asociados.',icon:'warning',showCancelButton:true,confirmButtonText:'Sí, eliminar',cancelButtonText:'Cancelar',confirmButtonColor:'#dc2626'});if(!result.isConfirmed)return;const data=new FormData();data.append('action','delete_stage');data.append('csrf_token',cfg.csrfToken);data.append('vigencia_id',stageId);try{await request(cfg.serviceUrl,{method:'POST',body:data});await load();Swal.fire({icon:'success',title:'Ampliación eliminada',timer:1300,showConfirmButton:false});}catch(error){Swal.fire('Atención',error.message,'warning');}
    }
    function replaceFile(fileId) {
        const input=document.createElement('input');input.type='file';input.accept='application/pdf,image/jpeg,image/png,image/webp,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,.xls,.xlsx';input.onchange=async()=>{if(!input.files.length)return;const data=new FormData();data.append('action','replace_file');data.append('csrf_token',cfg.csrfToken);data.append('file_id',fileId);data.append('archivo[]',input.files[0]);try{await request(cfg.serviceUrl,{method:'POST',body:data});await load();Swal.fire({icon:'success',title:'Archivo reemplazado',timer:1300,showConfirmButton:false});}catch(error){Swal.fire('No se pudo reemplazar',error.message,'error');}};input.click();
    }
    async function deleteFile(fileId) {
        const result=await Swal.fire({title:'¿Eliminar archivo?',text:'Esta acción no se puede deshacer.',icon:'warning',showCancelButton:true,confirmButtonText:'Sí, eliminar',cancelButtonText:'Cancelar',confirmButtonColor:'#dc2626'});if(!result.isConfirmed)return;const data=new FormData();data.append('action','delete_file');data.append('csrf_token',cfg.csrfToken);data.append('file_id',fileId);try{await request(cfg.serviceUrl,{method:'POST',body:data});await load();Swal.fire({icon:'success',title:'Archivo eliminado',timer:1300,showConfirmButton:false});}catch(error){Swal.fire('No se pudo eliminar',error.message,'error');}
    }
    function openMain() {
        forms.main.reset();
        byId('permisoTrabajoId').value = '';
        byId('permisoTrabajoModalTitle').textContent = 'Agregar permiso de trabajo';
        byId('permisoTrabajoRegistro').value = localToday();
        modals.main.show();
    }

    function openUpload(id) {
        const row = findRow(id);
        if (!row) return;
        forms.upload.reset();
        byId('permisoCargarId').value = id;
        const max = Number(row.vigencia_count) === 1 ? 2 : 3;
        const remaining = max - Number(row.documento_count || 0);
        forms.upload.dataset.limit = remaining;
        byId('permisoCargarResumen').innerHTML = '<strong>' + escapeHtml(row.permiso_nombre) + '</strong><br><span>Vigencia: ' + displayDate(row.fecha_inicio) + ' al ' + displayDate(row.fecha_vencimiento) + '</span>';
        byId('permisoCargarLimite').innerHTML = '<i class="fa-solid fa-circle-info"></i><span>Puede cargar <strong>' + remaining + ' documento(s) adicional(es)</strong>. Después de seleccionar, el sistema consultará si desea cerrar la vigencia.</span>';
        modals.upload.show();
    }

    function openExtend(id) {
        const row = findRow(id);
        if (!row) return;
        forms.extend.reset();
        byId('permisoAmpliarId').value = id;
        byId('permisoAmpliarResumen').innerHTML = '<div class="permiso-resumen-titulo"><i class="fa-solid fa-file-shield me-2 text-primary"></i>' + escapeHtml(row.permiso_nombre) + '</div><div class="permiso-resumen-fecha"><i class="fa-solid fa-calendar-day"></i><span>Vencimiento actual: <strong>' + displayDate(row.fecha_vencimiento) + '</strong></span></div><small class="permiso-resumen-nota"><i class="fa-solid fa-clock-rotate-left me-1"></i>La ampliación creará una nueva fila y conservará la vigencia anterior.</small>';
        byId('permisoNuevaFecha').min = nextDay(row.fecha_vencimiento);
        byId('permisoNuevaFecha').value = nextDay(row.fecha_vencimiento);
        modals.extend.show();
    }

    function openClose(id) {
        const row = findRow(id);
        if (!row || row.estado === 'cerrado') return;
        forms.close.reset();
        byId('permisoCerrarId').value = id;
        byId('permisoFechaCierre').min = row.fecha_inicio;
        byId('permisoFechaCierre').max = localToday();
        byId('permisoFechaCierre').value = localToday();
        byId('permisoCerrarLimite').textContent = 'Debe seleccionar exactamente un documento de cierre. Este archivo cambiará el estado a CERRADO.';
        modals.close.show();
    }

    async function submitForm(form, modal, success) {
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            await request(cfg.serviceUrl, {method:'POST',body:new FormData(form)});
            modal.hide();
            await load();
            Swal.fire({icon:'success',title:success,timer:1500,showConfirmButton:false});
        } catch (error) {
            Swal.fire('No se pudo completar',error.message,'error');
        } finally {
            button.disabled = false;
        }
    }

    async function removeRecord(id) {
        const confirm = await Swal.fire({title:'¿Eliminar permiso?',text:'Se eliminarán todas sus vigencias y documentos.',icon:'warning',showCancelButton:true,confirmButtonText:'Sí, eliminar',cancelButtonText:'Cancelar',confirmButtonColor:'#dc3545'});
        if (!confirm.isConfirmed) return;
        const data = new FormData();
        data.append('action','delete'); data.append('id',id); data.append('csrf_token',cfg.csrfToken);
        try {
            await request(cfg.serviceUrl,{method:'POST',body:data});
            await load();
            Swal.fire({icon:'success',title:'Permiso eliminado',timer:1300,showConfirmButton:false});
        } catch (error) {
            Swal.fire('Atención',error.message,'warning');
        }
    }

    searchInput.addEventListener('input', () => {
        searchClear.classList.toggle('d-none', searchInput.value.trim() === '');
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            searchTerm = searchInput.value.trim();
            currentPage = 1;
            load();
        }, 400);
    });
    searchInput.addEventListener('keydown', event => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        clearTimeout(searchTimer);
        searchTerm = searchInput.value.trim();
        currentPage = 1;
        load();
    });
    searchClear.addEventListener('click', () => {
        searchInput.value = '';
        searchTerm = '';
        currentPage = 1;
        searchClear.classList.add('d-none');
        searchInput.focus();
        load();
    });
    byId('addPermisoTrabajoBtn').onclick = openMain;
    forms.main.onsubmit = event => {
        event.preventDefault();
        try {
            if (byId('permisoTrabajoInicio').value > byId('permisoTrabajoVencimiento').value) throw new Error('La fecha de inicio no puede ser posterior al vencimiento.');
            submitForm(forms.main,modals.main,'Permiso guardado');
        } catch (error) { Swal.fire('Atención',error.message,'warning'); }
    };
    forms.upload.onsubmit = async event => {
        event.preventDefault();
        try {
            const input = byId('permisoCargarAdjuntos');
            const remaining = Number(forms.upload.dataset.limit);
            validateFiles(input,remaining,true);
            const row = findRow(Number(byId('permisoCargarId').value));
            const limit = Number(row.vigencia_count) === 1 ? 2 : 3;
            const total = Number(row.documento_count || 0) + input.files.length;
            const forced = total >= limit;
            const decision = await Swal.fire({
                title: forced ? 'Con esta carga se cerrará la vigencia' : '¿Desea cerrar la vigencia?',
                html: '<div class="permiso-decision-resumen ' + (forced ? 'es-obligatorio' : '') + '">' +
                    '<span class="permiso-decision-icon"><i class="fa-solid ' + (forced ? 'fa-triangle-exclamation' : 'fa-file-circle-check') + '"></i></span>' +
                    '<div class="permiso-decision-contenido">' +
                    (forced
                        ? '<strong>Límite de documentos alcanzado</strong><p>Esta carga completará los <b>' + limit + ' documentos permitidos</b> y la vigencia quedará cerrada.</p>'
                        : '<span class="permiso-decision-etiqueta">Después de esta carga</span><div class="permiso-decision-contador"><strong>' + total + '</strong><span>de ' + limit + ' documentos</span></div><p>Elija si desea <b>cerrar la vigencia</b> o mantenerla abierta para cargar otro documento después.</p>') +
                    '</div></div>',
                icon: forced ? 'warning' : 'question',
                input: 'date', inputValue: localToday(), inputLabel: 'Fecha de cierre',
                showCancelButton:true, showDenyButton:!forced,
                confirmButtonText:'<i class="fa-solid fa-lock me-1"></i> Cargar y cerrar',
                denyButtonText:'<i class="fa-solid fa-file-arrow-up me-1"></i> Cargar permiso',
                cancelButtonText:'Cancelar',
                confirmButtonColor:'#1e3a8a', denyButtonColor:'#0e7490', cancelButtonColor:'#64748b',
                buttonsStyling:true,
                customClass:{popup:'permiso-decision-popup',title:'permiso-decision-title',htmlContainer:'permiso-decision-html',input:'permiso-decision-date',actions:'permiso-decision-actions'},
                preConfirm:value=>{if(!value){Swal.showValidationMessage('Seleccione la fecha de cierre.');return false}return value}
            });
            if(decision.isDismissed)return;
            const close=forced||decision.isConfirmed;
            byId('permisoCargarCerrar').value=close?'1':'0';
            byId('permisoCargarFechaCierre').value=close?decision.value:'';
            submitForm(forms.upload,modals.upload,close?'Documentos cargados y vigencia cerrada':'Documentos cargados');
        } catch (error) { Swal.fire('Atención',error.message,'warning'); }
    };
    forms.extend.onsubmit = event => {
        event.preventDefault();
        try {
            submitForm(forms.extend,modals.extend,'Vigencia ampliada');
        } catch (error) { Swal.fire('Atención',error.message,'warning'); }
    };
    forms.close.onsubmit = event => {
        event.preventDefault();
        try {
            validateFiles(byId('permisoCerrarAdjuntos'),1,true);
            submitForm(forms.close,modals.close,'Vigencia cerrada');
        } catch (error) { Swal.fire('Atención',error.message,'warning'); }
    };
    load();
});
