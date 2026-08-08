let currentMaquirentaDocumentCompanyId = null;
let maquirentaDocumentModal = null;
let maquirentaDocumentReadOnly = false;
let maquirentaSegmentationType = 'ninguna';

document.addEventListener('DOMContentLoaded', () => {
    const companySearch = document.getElementById('maquirentaDocumentCompanySearch');
    if (!companySearch) return;

    maquirentaDocumentModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('maquirentaDocumentModal'));
    if (window.jQuery && $.fn.select2) {
        $('#maquirentaDocumentCompanySearch').select2({ theme: 'bootstrap4', width: '100%', placeholder: 'Escriba razón social o RUC' });
        $('#maquirentaDocumentCompanySearch').on('select2:select', (event) => loadMaquirentaDocumentCompany(event.params.data.id));
        $('#maquirentaDocumentSelect').select2({
            theme: 'bootstrap4', width: '100%', dropdownParent: $('#maquirentaDocumentModal'), placeholder: 'Buscar documento',
            ajax: { url: `${BASE_URL}/servicios/empresa_maquirenta/documentos.php?action=catalog`, dataType: 'json', delay: 200, data: (params) => ({ q: params.term || '' }) }
        });
        $('#maquirentaDocumentSelect').on('select2:select', (event) => configureMaquirentaSegmentation(event.params.data.tipo_segmentacion || 'ninguna'));
    }
    companySearch.addEventListener('change', (event) => { if (event.target.value) loadMaquirentaDocumentCompany(event.target.value); });
    document.getElementById('addMaquirentaDocumentBtn')?.addEventListener('click', openAddMaquirentaDocument);
    document.getElementById('maquirentaDocumentForm')?.addEventListener('submit', saveMaquirentaDocument);
    document.getElementById('newMaquirentaCatalogDocumentBtn')?.addEventListener('click', addMaquirentaCatalogDocument);
    document.getElementById('deleteMaquirentaCatalogDocumentBtn')?.addEventListener('click', deleteMaquirentaCatalogDocument);
    document.getElementById('downloadMaquirentaDocumentsBtn')?.addEventListener('click', () => downloadMaquirentaDocuments());
    document.getElementById('downloadSelectedMaquirentaDocumentsBtn')?.addEventListener('click', downloadSelectedMaquirentaDocuments);
    document.getElementById('changeMaquirentaDocumentPhotoBtn')?.addEventListener('click', () => document.getElementById('maquirentaDocumentPhotoInput')?.click());
    document.getElementById('maquirentaDocumentPhotoInput')?.addEventListener('change', uploadMaquirentaDocumentPhoto);
    document.getElementById('maquirentaSegmentMonth')?.addEventListener('change', syncMaquirentaMonthDates);
    document.getElementById('maquirentaSegmentYear')?.addEventListener('change', syncMaquirentaMonthDates);
});

function syncMaquirentaMonthDates() {
    const month=Number(document.getElementById('maquirentaSegmentMonth')?.value||0); const year=Number(document.getElementById('maquirentaSegmentYear')?.value||0);
    if(month<1||month>12||year<2000||year>2100)return;
    const padded=String(month).padStart(2,'0'); const lastDay=new Date(year,month,0).getDate();
    document.getElementById('maquirentaStartDate').value=`${year}-${padded}-01`;
    document.getElementById('maquirentaEndDate').value=`${year}-${padded}-${String(lastDay).padStart(2,'0')}`;
}

async function maquirentaDocumentRequest(url, options = {}) {
    const response = await fetch(url, options);
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo completar la operación.');
    return data;
}

async function loadMaquirentaDocumentCompany(id) {
    try {
        const data = await maquirentaDocumentRequest(`${BASE_URL}/servicios/empresa_maquirenta/documentos.php?action=profile&id=${encodeURIComponent(id)}`);
        currentMaquirentaDocumentCompanyId = String(id);
        const company = data.empresa;
        document.getElementById('maquirentaDocumentsWorkspace').classList.remove('d-none');
        document.getElementById('maquirentaDocumentPhoto').src = company.foto_path ? `${BASE_URL}/${company.foto_path}` : `${BASE_URL}/recursos/imagen_referencial.php`;
        document.getElementById('maquirentaDocumentName').textContent = company.razon_social || '';
        document.getElementById('maquirentaDocumentRuc').textContent = company.ruc || '';
        document.getElementById('maquirentaDocumentAddress').textContent = company.direccion || '';
        await loadMaquirentaDocuments();
    } catch (error) {
        Swal.fire('Atención', error.message, 'warning');
    }
}

async function loadMaquirentaDocuments() {
    if (!currentMaquirentaDocumentCompanyId) return;
    const data = await maquirentaDocumentRequest(`${BASE_URL}/servicios/empresa_maquirenta/documentos.php?action=list&empresa_maquirenta_id=${encodeURIComponent(currentMaquirentaDocumentCompanyId)}&t=${Date.now()}`);
    const tbody = document.querySelector('#maquirentaDocumentsTable tbody');
    tbody.innerHTML = '';
    const groups = new Map();
    (data.rows || []).forEach((row) => {
        if (!groups.has(String(row.documento_id))) groups.set(String(row.documento_id), { name: row.documento, rows: [] });
        groups.get(String(row.documento_id)).rows.push(row);
    });
    groups.forEach((group, documentId) => {
        const groupKey = `maquirenta-group-${documentId}`;
        tbody.insertAdjacentHTML('beforeend', `<tr class="maquirenta-document-group-row" data-group="${groupKey}">
            <td class="text-center"><input class="form-check-input maquirenta-group-check" type="checkbox" data-group="${groupKey}" title="Seleccionar archivos de ${escapeHtml(group.name)}"></td>
            <td colspan="4"><button class="maquirenta-group-toggle" type="button" data-group="${groupKey}" aria-expanded="true"><i class="fa-solid fa-chevron-down"></i><strong>${escapeHtml(group.name)}</strong><span>${group.rows.length} ${group.rows.length === 1 ? 'documento' : 'documentos'}</span></button></td>
            <td colspan="3"></td>
        </tr>`);
        group.rows.forEach((row) => renderMaquirentaDocumentRow(tbody, row, groupKey));
    });

    tbody.querySelectorAll('.maquirenta-group-toggle').forEach((button) => button.addEventListener('click', () => {
        const groupKey=button.dataset.group; const expanded=button.getAttribute('aria-expanded')==='true';
        button.setAttribute('aria-expanded',String(!expanded)); button.querySelector('i')?.classList.toggle('fa-chevron-down',!expanded); button.querySelector('i')?.classList.toggle('fa-chevron-right',expanded);
        tbody.querySelectorAll(`.maquirenta-document-child[data-group="${groupKey}"]`).forEach((row)=>row.classList.toggle('d-none',expanded));
    }));
    tbody.querySelectorAll('.maquirenta-group-check').forEach((check) => check.addEventListener('change', () => {
        tbody.querySelectorAll(`.maquirenta-document-check[data-group="${check.dataset.group}"]:not(:disabled)`).forEach((child)=>{child.checked=check.checked;});
    }));
}

function renderMaquirentaDocumentRow(tbody, row, groupKey) {
        const hasFile = !!row.archivo_path;
        const download = hasFile ? `<a class="btn btn-sm btn-outline-success" href="${BASE_URL}/${row.archivo_path}" download="${escapeHtml(row.archivo_nombre_original || row.display_name)}" title="Descargar"><i class="fa-solid fa-download"></i></a>` : '';
        tbody.insertAdjacentHTML('beforeend', `<tr class="maquirenta-document-child" data-group="${groupKey}">
            <td class="text-center"><input class="form-check-input maquirenta-document-check" data-group="${groupKey}" type="checkbox" value="${row.id}" ${hasFile ? '' : 'disabled'} title="${hasFile ? 'Seleccionar archivo' : 'Sin archivo adjunto'}"></td>
            <td><span class="maquirenta-segment-indent"><i class="fa-solid fa-turn-up fa-rotate-90"></i>${escapeHtml(row.segment_label || 'Documento general')}</span></td><td>${row.fecha_registro}</td><td>${row.fecha_inicio}</td><td>${row.fecha_fin}</td>
            <td><span class="badge ${row.status.class}">${row.status.label}</span></td><td>${escapeHtml(row.registered_by || '')}</td>
            <td class="text-nowrap"><button class="btn btn-sm btn-outline-primary" type="button" onclick="openEditMaquirentaDocument(${row.id})"><i class="fa-solid fa-pen"></i></button>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="openViewMaquirentaDocument(${row.id})"><i class="fa-solid fa-eye"></i></button>
            <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteMaquirentaDocument(${row.id})"><i class="fa-solid fa-trash"></i></button> ${download}</td></tr>`);
}

function openAddMaquirentaDocument() {
    if (!currentMaquirentaDocumentCompanyId) return Swal.fire('Atención', 'Seleccione una empresa Maquirenta.', 'warning');
    maquirentaDocumentReadOnly = false;
    const form = document.getElementById('maquirentaDocumentForm');
    form.reset(); form.classList.remove('was-validated'); setMaquirentaDocumentReadonly(false);
    document.getElementById('maquirentaDocumentModalTitle').textContent = 'Agregar documentos';
    document.getElementById('maquirentaDocumentId').value = '';
    document.getElementById('maquirentaDocumentCompanyId').value = currentMaquirentaDocumentCompanyId;
    document.getElementById('maquirentaRegistrationDate').value = localDateValue();
    $('#maquirentaDocumentSelect').val(null).trigger('change');
    configureMaquirentaSegmentation('ninguna');
    renderMaquirentaCurrentFile(null); maquirentaDocumentModal.show();
}

async function fillMaquirentaDocument(id) {
    const data = await maquirentaDocumentRequest(`${BASE_URL}/servicios/empresa_maquirenta/documentos.php?action=get&id=${id}`);
    const row = data.row;
    document.getElementById('maquirentaDocumentForm').reset();
    document.getElementById('maquirentaDocumentId').value = row.id;
    document.getElementById('maquirentaDocumentCompanyId').value = row.empresa_maquirenta_id;
    document.getElementById('maquirentaRegistrationDate').value = row.fecha_registro;
    document.getElementById('maquirentaStartDate').value = row.fecha_inicio;
    document.getElementById('maquirentaEndDate').value = row.fecha_fin;
    document.getElementById('maquirentaDocumentObservations').value = row.observaciones || '';
    $('#maquirentaDocumentSelect').append(new Option(row.documento, row.documento_id, true, true)).trigger('change');
    configureMaquirentaSegmentation(row.tipo_segmentacion || 'ninguna', row.segmento_clave || '', Number(row.periodo_anio || 0));
    renderMaquirentaCurrentFile(row);
}

window.openEditMaquirentaDocument = async (id) => { try { maquirentaDocumentReadOnly=false; await fillMaquirentaDocument(id); setMaquirentaDocumentReadonly(false); document.getElementById('maquirentaDocumentModalTitle').textContent='Editar documentos'; maquirentaDocumentModal.show(); } catch(error){ Swal.fire('Atención',error.message,'warning'); } };
window.openViewMaquirentaDocument = async (id) => { try { maquirentaDocumentReadOnly=true; await fillMaquirentaDocument(id); setMaquirentaDocumentReadonly(true); document.getElementById('maquirentaDocumentModalTitle').textContent='Visualizar documentos'; maquirentaDocumentModal.show(); } catch(error){ Swal.fire('Atención',error.message,'warning'); } };

function setMaquirentaDocumentReadonly(state) {
    document.querySelectorAll('#maquirentaDocumentForm input, #maquirentaDocumentForm textarea, #maquirentaDocumentForm select').forEach((element) => { if (element.type !== 'hidden') element.disabled = state; });
    document.querySelector('#maquirentaDocumentForm button[type="submit"]')?.classList.toggle('d-none', state);
    document.getElementById('newMaquirentaCatalogDocumentBtn')?.classList.toggle('d-none', state);
    document.getElementById('deleteMaquirentaCatalogDocumentBtn')?.classList.toggle('d-none', state);
}

function configureMaquirentaSegmentation(type, value = '', year = 0) {
    maquirentaSegmentationType = type;
    const field=document.getElementById('maquirentaSegmentValueField'); const yearField=document.getElementById('maquirentaSegmentYearField');
    const input=document.getElementById('maquirentaSegmentValue'); const month=document.getElementById('maquirentaSegmentMonth');
    const label=document.getElementById('maquirentaSegmentValueLabel'); const help=document.getElementById('maquirentaSegmentHelp'); const yearInput=document.getElementById('maquirentaSegmentYear');
    const segmented=type!=='ninguna'; field.classList.toggle('d-none',!segmented); yearField.classList.toggle('d-none',type!=='mes');
    field.classList.toggle('col-md-6',type==='mes'); field.classList.toggle('col-12',type!=='mes');
    input.classList.toggle('d-none',type==='mes'); month.classList.toggle('d-none',type!=='mes'); input.required=segmented&&type!=='mes'; month.required=type==='mes'; yearInput.required=type==='mes';
    input.type=type==='numero'?'number':'text'; input.min=type==='numero'?'1':''; input.value=type==='mes'?'':value; month.value=type==='mes'?String(Number(value)||''):''; yearInput.value=type==='mes'?String(year||new Date().getFullYear()):'';
    const config={numero:['Número correlativo','Ejemplo: 1, 2 o 3'],mes:['Mes','Seleccione el mes y el año de la boleta'],codigo:['Código','Ejemplo: 115, 145 o 158'],texto:['Identificación','Ingrese un nombre o referencia para el segmento']};
    label.textContent=config[type]?.[0]||'Segmento'; help.textContent=config[type]?.[1]||'';
}

function renderMaquirentaCurrentFile(row) {
    const box = document.getElementById('maquirentaCurrentFile');
    if (!row?.archivo_path) { box.classList.add('d-none'); box.innerHTML=''; return; }
    box.classList.remove('d-none');
    box.innerHTML = `${documentAttachmentHeader(row)}<div class="d-flex gap-2 mt-2"><a class="btn btn-sm btn-outline-primary" target="_blank" href="${BASE_URL}/${row.archivo_path}"><i class="fa-solid fa-up-right-from-square me-1"></i>Abrir</a><button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteMaquirentaDocumentFile(${row.id})"><i class="fa-solid fa-trash me-1"></i>Eliminar</button></div>`;
}

async function saveMaquirentaDocument(event) {
    event.preventDefault(); const form=event.currentTarget;
    if (maquirentaSegmentationType === 'mes') document.getElementById('maquirentaSegmentValue').value=document.getElementById('maquirentaSegmentMonth').value;
    if (maquirentaDocumentReadOnly || !form.checkValidity()) { form.classList.add('was-validated'); return; }
    const button=form.querySelector('[type="submit"]'); const progress=document.getElementById('maquirentaUploadProgress'); const bar=progress.querySelector('.progress-bar'); const label=progress.querySelector('small'); button.disabled=true;
    try {
        const data=await postFormWithProgress(`${BASE_URL}/servicios/empresa_maquirenta/documentos.php?action=save`,new FormData(form),(percent)=>{progress.classList.remove('d-none');bar.style.width=`${percent}%`;label.textContent=percent<100?`Subiendo archivo: ${percent}%`:'Procesando archivo...';});
        if(!data.ok) throw new Error(data.message || 'No se pudo guardar.'); maquirentaDocumentModal.hide(); await loadMaquirentaDocuments();
    } catch(error){Swal.fire('Atención',error.message,'warning');} finally {button.disabled=false;progress.classList.add('d-none');bar.style.width='0%';}
}

async function maquirentaDocumentPost(action, values) {
    const body=new FormData(); body.append('csrf_token',csrf); Object.entries(values).forEach(([key,value])=>body.append(key,value));
    return maquirentaDocumentRequest(`${BASE_URL}/servicios/empresa_maquirenta/documentos.php?action=${action}`,{method:'POST',body});
}

window.deleteMaquirentaDocument = async (id) => { if(!await confirmAction('¿Eliminar documento?'))return; try{await maquirentaDocumentPost('delete',{id});await loadMaquirentaDocuments();}catch(error){Swal.fire('Atención',error.message,'warning');} };
window.deleteMaquirentaDocumentFile = async (id) => { if(!await confirmAction('¿Eliminar archivo adjunto?'))return; try{await maquirentaDocumentPost('delete_file',{id});renderMaquirentaCurrentFile(null);await loadMaquirentaDocuments();}catch(error){Swal.fire('Atención',error.message,'warning');} };

async function addMaquirentaCatalogDocument() {
    const result=await Swal.fire({title:'Nuevo documento',input:'text',inputPlaceholder:'Nombre del documento',showCancelButton:true,confirmButtonText:'Agregar',cancelButtonText:'Cancelar'});
    if(!result.value)return;
    const typeResult=await Swal.fire({title:'Tipo de segmentación',input:'select',inputOptions:{ninguna:'Sin segmentación',numero:'Número correlativo',mes:'Mes y año',codigo:'Código',texto:'Texto libre'},inputValue:'ninguna',showCancelButton:true,confirmButtonText:'Crear categoría',cancelButtonText:'Cancelar'});
    if(!typeResult.value)return;
    try{const data=await maquirentaDocumentPost('catalog_save',{nombre:result.value,tipo_segmentacion:typeResult.value});const option=new Option(data.text,data.id,true,true);$('#maquirentaDocumentSelect').append(option).trigger('change');configureMaquirentaSegmentation(data.tipo_segmentacion);}catch(error){Swal.fire('Atención',error.message,'warning');}
}

async function deleteMaquirentaCatalogDocument() {
    const select=$('#maquirentaDocumentSelect'); const id=select.val(); const name=select.find('option:selected').text().trim();
    if(!id)return Swal.fire('Atención','Seleccione un documento para eliminar.','warning');
    const result=await Swal.fire({title:'¿Eliminar documento?',text:`Se quitará "${name}" del catálogo si no tiene registros asociados.`,icon:'warning',showCancelButton:true,confirmButtonText:'Sí, eliminar',cancelButtonText:'Cancelar'});
    if(!result.isConfirmed)return;
    try{const data=await maquirentaDocumentPost('catalog_delete',{id});select.find(`option[value="${id}"]`).remove();select.val(null).trigger('change');Swal.fire('Eliminado',data.message,'success');}catch(error){Swal.fire('Atención',error.message,'warning');}
}

async function uploadMaquirentaDocumentPhoto(event) {
    const input=event.currentTarget; if(!input.files?.[0]||!currentMaquirentaDocumentCompanyId)return;
    const body=new FormData();body.append('csrf_token',csrf);body.append('empresa_maquirenta_id',currentMaquirentaDocumentCompanyId);body.append('foto',input.files[0]);
    try{const data=await maquirentaDocumentRequest(`${BASE_URL}/servicios/empresa_maquirenta/documentos.php?action=upload_photo`,{method:'POST',body});document.getElementById('maquirentaDocumentPhoto').src=`${data.path}?v=${Date.now()}`;Swal.fire('Actualizado','Foto actualizada.','success');}catch(error){Swal.fire('Atención',error.message,'warning');}finally{input.value='';}
}

async function downloadSelectedMaquirentaDocuments() {
    const ids=Array.from(document.querySelectorAll('.maquirenta-document-check:checked')).map((item)=>item.value);
    if(!ids.length)return Swal.fire('Atención','Seleccione al menos un documento para descargar.','warning');
    await downloadMaquirentaDocuments(ids);
}

async function downloadMaquirentaDocuments(ids=[]) {
    if(!currentMaquirentaDocumentCompanyId)return Swal.fire('Atención','Seleccione una empresa Maquirenta.','warning');
    const params=new URLSearchParams({action:'download',empresa_maquirenta_id:currentMaquirentaDocumentCompanyId});if(ids.length)params.set('ids',ids.join(','));
    const response=await fetch(`${BASE_URL}/servicios/empresa_maquirenta/documentos.php?${params}`);
    if(!response.ok){const data=await response.json().catch(()=>({message:'No se pudo generar la descarga.'}));return Swal.fire('Atención',data.message,'warning');}
    const blob=await response.blob();const disposition=response.headers.get('Content-Disposition')||'';const match=disposition.match(/filename="([^"]+)"/);const link=document.createElement('a');link.href=URL.createObjectURL(blob);link.download=match?.[1]||'documentos_empresa_maquirenta.zip';document.body.appendChild(link);link.click();URL.revokeObjectURL(link.href);link.remove();
}
