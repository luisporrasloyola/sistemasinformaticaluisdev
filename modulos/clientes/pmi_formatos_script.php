<script>
document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('clientFormatTable');
    if (!table) return;
    const clientId = <?= (int) $clientId ?>;
    const baseUrl = <?= json_encode(APP_URL) ?>;
    const checks = () => Array.from(table.querySelectorAll('.format-document-check'));
    const folderChecks = () => Array.from(document.querySelectorAll('.format-folder-document-check:not(:disabled)'));
    const folderSelectAll = document.getElementById('formatFolderSelectAll');
    const folderModal = document.getElementById('formatFolderModal');
    const documentModal = document.getElementById('formatDocumentModal');
    const documentForm = document.getElementById('formatDocumentForm');
    const formatCatalog = $('#formatDocumentName');
    const catalogUrl = baseUrl + '/modulos/clientes/pmi_formatos_catalogo.php';
    const csrf = documentForm.querySelector('[name="csrf_token"]').value;
    const today = () => { const date = new Date(); return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-'); };
    formatCatalog.select2({theme: 'bootstrap4', width: '100%', dropdownParent: $('#formatDocumentModal'), placeholder: 'Buscar formato', ajax: {
        url: catalogUrl, dataType: 'json', delay: 250,
        data: params => ({client_id: clientId, q: params.term || ''}),
        processResults: data => ({results: data.results || []}),
    }});
    formatCatalog.on('select2:open', () => document.querySelector('.select2-container--open .select2-search__field')?.focus());
    const catalogDialog = async options => {
        const focusTrap = bootstrap.Modal.getOrCreateInstance(documentModal)?._focustrap;
        focusTrap?.deactivate?.();
        try { return await Swal.fire({...options, didOpen: () => Swal.getInput()?.focus()}); }
        finally { setTimeout(() => focusTrap?.activate?.(), 0); }
    };
    document.getElementById('addFormatCatalog').addEventListener('click', async () => {
        const answer = await catalogDialog({title: 'Nuevo formato', input: 'text', inputPlaceholder: 'Nombre del formato', showCancelButton: true, confirmButtonText: 'Agregar', cancelButtonText: 'Cancelar', inputValidator: value => !value?.trim() ? 'Ingrese el nombre del formato.' : undefined});
        if (!answer.isConfirmed) return;
        const body = new FormData(); body.set('csrf_token', csrf); body.set('client_id', String(clientId)); body.set('action', 'add'); body.set('name', answer.value.trim());
        try {
            const response = await fetch(catalogUrl, {method: 'POST', body}); const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo crear el formato.');
            formatCatalog.append(new Option(data.item.text, data.item.id, true, true)).trigger('change');
        } catch (error) { Swal.fire('Atención', error.message, 'warning'); }
    });
    document.getElementById('deleteFormatCatalog').addEventListener('click', async () => {
        const name = formatCatalog.val();
        if (!name) { Swal.fire('Atención', 'Seleccione un formato para eliminar.', 'warning'); return; }
        const answer = await catalogDialog({title: '¿Eliminar formato?', text: 'Solo se puede eliminar si no tiene documentos registrados.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar'});
        if (!answer.isConfirmed) return;
        const body = new FormData(); body.set('csrf_token', csrf); body.set('client_id', String(clientId)); body.set('action', 'delete'); body.set('name', name);
        try {
            const response = await fetch(catalogUrl, {method: 'POST', body}); const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo eliminar el formato.');
            formatCatalog.find('option').filter((_, option) => option.value === name).remove();
            formatCatalog.val(null).trigger('change');
        } catch (error) { Swal.fire('Atención', error.message, 'warning'); }
    });

    table.querySelectorAll('.format-folder-toggle').forEach(button => button.addEventListener('click', () => {
        const expanded = button.getAttribute('aria-expanded') !== 'true';
        table.querySelectorAll('.format-document-row[data-folder-id="' + button.dataset.folderId + '"]').forEach(row => { row.hidden = !expanded; });
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        button.closest('.client-pmi-folder-header').classList.toggle('is-open', expanded);
        const icon = button.querySelector('.fa-chevron-down,.fa-chevron-up');
        icon?.classList.toggle('fa-chevron-up', expanded);
        icon?.classList.toggle('fa-chevron-down', !expanded);
    }));
    const selectAllFormats = document.getElementById('selectAllFormats');
    const syncSelectAllFormats = () => { const items = checks(); selectAllFormats.checked = items.length > 0 && items.every(check => check.checked); selectAllFormats.indeterminate = items.some(check => check.checked) && !selectAllFormats.checked; };
    selectAllFormats.addEventListener('change', event => { checks().forEach(check => { check.checked = event.target.checked; }); selectAllFormats.indeterminate = false; });
    checks().forEach(check => check.addEventListener('change', syncSelectAllFormats));
    document.getElementById('downloadAllFormats').addEventListener('click', event => {
        if (event.currentTarget.dataset.hasFiles !== '0') return;
        event.preventDefault();
        Swal.fire('Atención', 'No hay archivos adjuntos disponibles para descargar.', 'warning');
    });
    document.getElementById('downloadSelectedFormats').addEventListener('click', () => {
        const selected = checks().filter(check => check.checked);
        if (!selected.length) { Swal.fire('Atención', 'Seleccione al menos un formato.', 'warning'); return; }
        const ids = selected.filter(check => check.dataset.hasFile === '1').map(check => check.value);
        if (!ids.length) { Swal.fire('Atención', 'Los formatos seleccionados no tienen archivos adjuntos.', 'warning'); return; }
        const params = new URLSearchParams({cliente_id: String(clientId), tipo: 'formatos'});
        ids.forEach(id => params.append('ids[]', id));
        location.href = baseUrl + '/modulos/clientes/pmi_descargar.php?' + params;
    });

    const clearDocument = () => {
        documentForm.reset();
        formatCatalog.val(null).trigger('change');
        document.getElementById('formatDocumentId').value = '';
        document.getElementById('formatObservationHistory').classList.add('d-none');
        document.getElementById('formatObservationList').replaceChildren();
        const current = document.getElementById('formatCurrentFile');
        current.classList.add('d-none');
        current.replaceChildren();
    };
    document.getElementById('addFormatDocument').addEventListener('click', () => {
        clearDocument();
        document.getElementById('formatRegistration').value = today();
        document.getElementById('formatDocumentTitle').textContent = 'Agregar formato';
        bootstrap.Modal.getOrCreateInstance(documentModal).show();
    });
    table.querySelectorAll('.edit-format-document').forEach(button => button.addEventListener('click', async () => {
        const item = JSON.parse(button.dataset.document);
        clearDocument();
        document.getElementById('formatDocumentId').value = item.id;
        formatCatalog.append(new Option(item.name, item.name, true, true)).trigger('change');
        document.getElementById('formatRegistration').value = item.registration_date;
        document.getElementById('formatStart').value = item.start_date || '';
        document.getElementById('formatEnd').value = item.end_date || '';
        document.getElementById('formatDocumentTitle').textContent = 'Editar formato';
        if (item.file_path) {
            const link = document.createElement('a');
            link.href = baseUrl + '/' + item.file_path;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = 'Ver archivo actual';
            document.getElementById('formatCurrentFile').append(link);
            document.getElementById('formatCurrentFile').classList.remove('d-none');
        }
        bootstrap.Modal.getOrCreateInstance(documentModal).show();
        try {
            const response = await fetch(baseUrl + '/modulos/clientes/pmi_formatos_observaciones.php?client_id=' + clientId + '&document_id=' + encodeURIComponent(item.id));
            if (!response.ok) return;
            const data = await response.json();
            if (!data.observations?.length) return;
            const list = document.getElementById('formatObservationList');
            data.observations.forEach(entry => {
                const line = document.createElement('div'); line.className = 'border-top py-2';
                const head = document.createElement('strong'); head.textContent = entry.user_name + ' · ' + entry.created_at;
                const note = document.createElement('div'); note.textContent = entry.note;
                line.append(head, note); list.append(line);
            });
            document.getElementById('formatObservationHistory').classList.remove('d-none');
        } catch (_) { /* La edición permanece disponible. */ }
    }));

    const syncAll = () => { const available = folderChecks(); folderSelectAll.checked = available.length > 0 && available.every(check => check.checked); folderSelectAll.indeterminate = available.some(check => check.checked) && !folderSelectAll.checked; };
    folderSelectAll.addEventListener('change', () => folderChecks().forEach(check => { check.checked = folderSelectAll.checked; }));
    document.querySelectorAll('.format-folder-document-check').forEach(check => check.addEventListener('change', syncAll));
    const setupFolder = (id, name) => {
        const editing = Boolean(id);
        const selected = new Set(checks().filter(check => check.checked && !check.closest('tr').dataset.folderId).map(check => check.value));
        document.querySelectorAll('.format-folder-document-check').forEach(check => {
            const available = check.dataset.folderId === '0' || (editing && check.dataset.folderId === id);
            check.disabled = !available;
            check.closest('.client-pmi-folder-choice').hidden = !available;
            check.checked = editing ? check.dataset.folderId === id : selected.has(check.value);
        });
        if (!editing && !folderChecks().length) { Swal.fire('Atención', 'No hay formatos sin carpeta para organizar.', 'warning'); return; }
        syncAll();
        document.getElementById('formatFolderAction').value = editing ? 'update_folder' : 'create_folder';
        document.getElementById('formatFolderId').value = id || '';
        document.getElementById('formatFolderTitle').textContent = editing ? 'Editar carpeta de formatos' : 'Crear carpeta de formatos';
        document.getElementById('formatFolderSubmit').textContent = editing ? 'Guardar cambios' : 'Crear carpeta';
        document.getElementById('formatFolderName').value = name || '';
        bootstrap.Modal.getOrCreateInstance(folderModal).show();
    };
    document.getElementById('createFormatFolder').addEventListener('click', () => setupFolder('', ''));
    table.querySelectorAll('.edit-format-folder').forEach(button => button.addEventListener('click', () => setupFolder(button.dataset.folderId, button.dataset.folderName)));
    document.getElementById('formatFolderForm').addEventListener('submit', event => {
        if (folderChecks().some(check => check.checked)) return;
        event.preventDefault();
        Swal.fire('Atención', 'Seleccione al menos un formato para la carpeta.', 'warning');
    });

    document.addEventListener('submit', async event => {
        if (event.target.querySelector('input[name="action"]')?.value !== 'delete_document' || !(event.target.getAttribute('action') || '').includes('pmi_formatos_guardar.php')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const answer = await Swal.fire({titleText: '¿Eliminar formato?', text: 'Esta acción eliminará el formato y su archivo adjunto.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc3545'});
        if (answer.isConfirmed) HTMLFormElement.prototype.submit.call(event.target);
    }, true);
});
</script>
