// Edición visual y OCR en el navegador. El archivo adjunto original no se altera.
import {parseDocumentText, parseFocusedText, FOCUS_ZONES} from './cobranzas_valorizacion_extraccion.mjs?v=3';
document.addEventListener('DOMContentLoaded', () => {
    const previewContent = document.getElementById('cvPreviewContent');
    if (!previewContent) return;
    const form = document.getElementById('cvFormModal').querySelector('form');
    const fileInput = document.getElementById('cvFile');
    const submit = form.querySelector('button[type="submit"]');
    const appUrl = window.APP_URL || '';
    const tools = document.createElement('div');
    tools.className = 'border-top mt-3 pt-3';
    tools.hidden = true;
    tools.innerHTML = `
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            <strong class="me-2">Mejorar lectura</strong>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-edit="left" aria-label="Girar a la izquierda"><i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i>Girar izquierda</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-edit="right" aria-label="Girar a la derecha"><i class="fa-solid fa-rotate-right me-1" aria-hidden="true"></i>Girar derecha</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-edit="crop"><i class="fa-solid fa-crop-simple me-1" aria-hidden="true"></i>Seleccionar recorte</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-edit="apply" disabled>Aplicar recorte</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-edit="reset">Restablecer</button>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            <label for="cvContrast" class="small fw-semibold mb-0">Contraste</label>
            <input id="cvContrast" type="range" min="70" max="200" value="100" step="10" aria-label="Contraste de la vista previa">
            <output id="cvContrastValue" for="cvContrast" class="small text-muted">100 %</output>
            <button type="button" class="btn btn-sm btn-primary ms-auto" data-edit="ocr"><i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i>Leer y extraer datos</button>
            <button type="button" class="btn btn-sm btn-outline-primary" data-edit="zones" title="Úselo solo si el documento corresponde al formato Parte diario">Leer zonas del parte</button>
        </div>
        <div class="small text-muted mb-2">Arrastre sobre la imagen para elegir el recorte y pulse «Aplicar recorte». Estos ajustes solo ayudan a leer; el adjunto se guarda tal como se seleccionó.</div>
        <div id="cvOcrStatus" class="small text-muted" role="status"></div>
        <div id="cvOcrResult" class="mt-2" hidden><label class="form-label small fw-semibold" for="cvOcrText">Texto detectado (revíselo antes de usarlo)</label><textarea id="cvOcrText" class="form-control" rows="5" readonly></textarea></div>`;
    previewContent.after(tools);
    const geminiButton = document.createElement('button');
    geminiButton.type = 'button';
    geminiButton.className = 'btn btn-sm btn-outline-primary';
    geminiButton.dataset.edit = 'gemini';
    geminiButton.textContent = 'Reintentar con Gemini';
    tools.querySelector('[data-edit="zones"]').after(geminiButton);
    const geminiNotice = document.createElement('div');
    geminiNotice.className = 'small text-muted mb-2';
    geminiNotice.textContent = 'Al adjuntar un archivo se envía una copia reducida de la vista actual a Google para completar los campos. Revise los datos antes de guardar.';
    tools.querySelector('#cvOcrStatus').before(geminiNotice);
    const contrast = tools.querySelector('#cvContrast');
    const contrastValue = tools.querySelector('#cvContrastValue');
    const ocrStatus = tools.querySelector('#cvOcrStatus');
    const fieldStatus = document.createElement('div');
    fieldStatus.className = 'small text-muted mb-2';
    fieldStatus.setAttribute('role', 'status');
    fieldStatus.setAttribute('aria-live', 'polite');
    document.getElementById('cvReviewSuggestions').before(fieldStatus);
    const ocrResult = tools.querySelector('#cvOcrResult');
    const ocrText = tools.querySelector('#cvOcrText');
    const extracted = document.createElement('div');
    extracted.id = 'cvExtracted';
    extracted.className = 'border rounded p-2 mb-3 bg-light';
    extracted.hidden = true;
    extracted.innerHTML = '<strong class="small">Lecturas candidatas</strong><div id="cvExtractedList" class="mt-1"></div><div class="small text-muted mt-1">Compare con el documento y pulse «Usar» solo si coincide.</div>';
    document.getElementById('cvReviewSuggestions').append(extracted);
    const extractedList = extracted.querySelector('#cvExtractedList');
    const fieldIds = {equipo:'cvEquipment', fecha_servicio:'cvDate', hora_inicio:'cvStart', hora_final:'cvEnd'};
    const fieldLabels = {equipo:'Equipo', placa_serie:'Placa/serie', fecha_servicio:'Fecha', hora_inicio:'Llegada', hora_final:'Salida'};
    const fields = Object.fromEntries(Object.entries(fieldIds).map(([key, id]) => [key, document.getElementById(id)]));
    const visualReview = document.createElement('div');
    visualReview.className = 'cv-visual-review border rounded p-2 mb-3';
    visualReview.hidden = true;
    visualReview.innerHTML = '<strong class="small">Ampliación de las casillas del parte</strong><div class="small text-muted mb-2">Compare cada casilla con el archivo original antes de guardar.</div>';
    const visualGrid = document.createElement('div');
    visualGrid.className = 'cv-visual-grid';
    visualReview.append(visualGrid);
    document.getElementById('cvReviewSuggestions').append(visualReview);
    const visualZones = {
        equipo:{label:'Equipo y placa/serie',field:'equipo',x:.39,y:.19,width:.61,height:.06},
        fecha_servicio:{label:'Día / mes / año',field:'fecha_servicio',x:.75,y:.07,width:.25,height:.055},
        hora_inicio:{label:'Llegada a instalación',field:'hora_inicio',x:.41,y:.46,width:.21,height:.055},
        hora_final:{label:'Salida de instalación',field:'hora_final',x:.81,y:.46,width:.19,height:.055},
    };
    const visualCanvases = {};
    for (const [key, zone] of Object.entries(visualZones)) {
        const card = document.createElement('div');
        card.className = 'cv-visual-card';
        const label = document.createElement('span');
        label.className = 'small fw-semibold';
        label.textContent = zone.label;
        const canvas = document.createElement('canvas');
        canvas.setAttribute('aria-label', 'Ampliación de ' + zone.label);
        visualCanvases[key] = canvas;
        card.append(label, canvas);
        card.addEventListener('click', () => fields[zone.field]?.focus());
        visualGrid.append(card);
    }
    let suggestions = {};
    const states = {};
    for (const [key, field] of Object.entries(fields)) {
        const state = document.createElement('div');
        state.className = 'cv-field-state';
        field.insertAdjacentElement('afterend', state);
        states[key] = state;
        field.addEventListener('input', updateStates);
    }
    function updateStates() {
        for (const [key, field] of Object.entries(fields)) {
            const pending = !field.value;
            states[key].textContent = pending ? 'Pendiente de revisión' : 'Dato ingresado; verifique con el documento';
            states[key].className = 'cv-field-state ' + (pending ? 'is-pending' : 'is-ready');
        }
    }
    updateStates();
    function showSuggestions() {
        extractedList.replaceChildren();
        for (const [key, label] of Object.entries(fieldLabels)) {
            if (!suggestions[key]) continue;
            const item = document.createElement('div');
            item.className = 'cv-review-suggestion small';
            const value = document.createElement('span');
            value.textContent = `${label}: ${suggestions[key]}`;
            const use = document.createElement('button');
            use.type = 'button';
            use.className = 'btn btn-sm btn-outline-primary py-0';
            use.dataset.field = key;
            use.textContent = 'Usar';
            use.setAttribute('aria-label', `Usar lectura de ${label}`);
            item.append(value, use);
            extractedList.append(item);
        }
        extracted.hidden = !extractedList.childElementCount;
    }
    extracted.addEventListener('click', event => {
        const button = event.target.closest('button[data-field]');
        if (!button) return;
        const key = button.dataset.field;
        if (!fields[key] || !suggestions[key]) return;
        fields[key].value = suggestions[key];
        fields[key].dispatchEvent(new Event('input', {bubbles:true}));
        fields[key].focus();
    });
    function applySuggestions(values) {
        const combined = values.equipo && values.placa_serie ? `${values.equipo} ${values.placa_serie}` : '';
        const review = {...values, equipo:combined};
        for (const [key, value] of Object.entries(review)) {
            if (!value || !fields[key]) continue;
            suggestions[key] = value;
        }
        showSuggestions();
    }
    const applyCrop = tools.querySelector('[data-edit="apply"]');
    const cropButton = tools.querySelector('[data-edit="crop"]');
    const ocrButton = tools.querySelector('[data-edit="ocr"]');
    const editorCanvas = document.createElement('canvas');
    editorCanvas.className = 'd-block rounded border';
    editorCanvas.style.maxWidth = '100%';
    editorCanvas.style.maxHeight = '380px';
    editorCanvas.style.width = 'auto';
    editorCanvas.style.height = 'auto';
    editorCanvas.style.touchAction = 'none';
    const wrapper = document.createElement('div');
    wrapper.style.position = 'relative';
    wrapper.style.display = 'inline-block';
    wrapper.style.maxWidth = '100%';
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:absolute;border:2px dashed #2563eb;background:rgba(37,99,235,.16);pointer-events:none;display:none';
    wrapper.append(editorCanvas, overlay);

    let source = null;
    let working = null;
    let crop = null;
    let selecting = false;
    let dragStart = null;
    let ocrBusy = false;
    let geminiRequest = null;
    let worker = null;
    let generation = 0;
    let cropped = false;

    function endWorker() {
        if (!worker) return;
        const old = worker;
        worker = null;
        Promise.resolve(old.terminate()).catch(() => {});
    }
    function resetSelection() {
        crop = null;
        selecting = false;
        dragStart = null;
        overlay.style.display = 'none';
        applyCrop.disabled = true;
        cropButton.classList.remove('active');
        editorCanvas.style.cursor = 'auto';
    }
    function render() {
        if (!working) return;
        editorCanvas.width = working.width;
        editorCanvas.height = working.height;
        const ctx = editorCanvas.getContext('2d');
        ctx.filter = `contrast(${contrast.value}%)`;
        ctx.drawImage(working, 0, 0);
        ctx.filter = 'none';
        visualReview.hidden = cropped || editorCanvas.height / editorCanvas.width < 0.8;
        for (const [key, zone] of Object.entries(visualZones)) {
            const canvas = visualCanvases[key];
            const sx = Math.round(editorCanvas.width * zone.x), sy = Math.round(editorCanvas.height * zone.y);
            const sw = Math.min(editorCanvas.width - sx, Math.round(editorCanvas.width * zone.width));
            const sh = Math.min(editorCanvas.height - sy, Math.round(editorCanvas.height * zone.height));
            const scale = Math.min(3, 720 / Math.max(sw, sh));
            canvas.width = Math.max(1, Math.round(sw * scale));
            canvas.height = Math.max(1, Math.round(sh * scale));
            canvas.getContext('2d').drawImage(editorCanvas, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
        }
        contrastValue.textContent = contrast.value + ' %';
        ocrResult.hidden = true;
        ocrText.value = '';
        ocrStatus.textContent = '';
        resetSelection();
    }
    function makeWorking() {
        if (!source) return;
        const width = source.naturalWidth || source.width;
        const height = source.naturalHeight || source.height;
        const scale = Math.min(1, 3000 / Math.max(width, height));
        working = document.createElement('canvas');
        cropped = false;
        working.width = Math.max(1, Math.round(width * scale));
        working.height = Math.max(1, Math.round(height * scale));
        const ctx = working.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, working.width, working.height);
        ctx.drawImage(source, 0, 0, working.width, working.height);
        contrast.value = '100';
        ocrResult.hidden = true;
        ocrText.value = '';
        ocrStatus.textContent = '';
        render();
    }
    function rotate(clockwise) {
        if (!working) return;
        const rotated = document.createElement('canvas');
        rotated.width = working.height;
        rotated.height = working.width;
        const ctx = rotated.getContext('2d');
        ctx.translate(clockwise ? rotated.width : 0, clockwise ? 0 : rotated.height);
        ctx.rotate(clockwise ? Math.PI / 2 : -Math.PI / 2);
        ctx.drawImage(working, 0, 0);
        working = rotated;
        render();
    }
    function point(event) {
        const rect = editorCanvas.getBoundingClientRect();
        return {
            x: Math.max(0, Math.min(rect.width, event.clientX - rect.left)),
            y: Math.max(0, Math.min(rect.height, event.clientY - rect.top)),
            width: rect.width,
            height: rect.height,
        };
    }
    function drawCrop(a, b) {
        const x = Math.min(a.x, b.x), y = Math.min(a.y, b.y);
        const width = Math.abs(a.x - b.x), height = Math.abs(a.y - b.y);
        overlay.style.left = x + 'px';
        overlay.style.top = y + 'px';
        overlay.style.width = width + 'px';
        overlay.style.height = height + 'px';
        overlay.style.display = 'block';
        crop = {
            x: Math.round(x * working.width / a.width),
            y: Math.round(y * working.height / a.height),
            width: Math.round(width * working.width / a.width),
            height: Math.round(height * working.height / a.height),
        };
        applyCrop.disabled = crop.width < 80 || crop.height < 80;
    }
    editorCanvas.addEventListener('pointerdown', event => {
        if (!selecting || !working || ocrBusy) return;
        dragStart = point(event);
        editorCanvas.setPointerCapture(event.pointerId);
        drawCrop(dragStart, dragStart);
    });
    editorCanvas.addEventListener('pointermove', event => {
        if (dragStart) drawCrop(dragStart, point(event));
    });
    editorCanvas.addEventListener('pointerup', event => {
        if (!dragStart) return;
        drawCrop(dragStart, point(event));
        dragStart = null;
    });
    editorCanvas.addEventListener('pointercancel', () => { dragStart = null; resetSelection(); });
    contrast.addEventListener('input', () => { if (working && !ocrBusy) render(); });
    tools.addEventListener('click', async event => {
        const button = event.target.closest('button[data-edit]');
        if (!button || !working || ocrBusy) return;
        const action = button.dataset.edit;
        if (action === 'left' || action === 'right') rotate(action === 'right');
        if (action === 'reset') makeWorking();
        if (action === 'crop') {
            selecting = !selecting;
            cropButton.classList.toggle('active', selecting);
            editorCanvas.style.cursor = selecting ? 'crosshair' : 'auto';
            if (!selecting) resetSelection();
        }
        if (action === 'apply' && crop && crop.width >= 80 && crop.height >= 80) {
            const clipped = document.createElement('canvas');
            clipped.width = crop.width; clipped.height = crop.height;
            clipped.getContext('2d').drawImage(working, crop.x, crop.y, crop.width, crop.height, 0, 0, crop.width, crop.height);
            working = clipped;
            cropped = true;
            render();
        }
        if (action === 'ocr' || action === 'zones') await runOcr(action === 'zones');
        if (action === 'gemini') await runGemini();
    });

    async function runGemini() {
        if (ocrBusy || !working) return;
        ocrBusy = true;
        const currentGeneration = generation;
        const request = new AbortController();
        geminiRequest = request;
        const before = Object.fromEntries(Object.entries(fields).map(([key, field]) => [key, field.value]));
        geminiButton.disabled = true;
        fileInput.disabled = true;
        submit.disabled = true;
        ocrStatus.textContent = 'Gemini está leyendo el documento…';
        fieldStatus.textContent = 'Leyendo la foto automáticamente…';
        document.dispatchEvent(new CustomEvent('cv:reading-start'));
        try {
            const canvas = document.createElement('canvas');
            const scale = Math.min(1, 2000 / Math.max(editorCanvas.width, editorCanvas.height));
            canvas.width = Math.round(editorCanvas.width * scale);
            canvas.height = Math.round(editorCanvas.height * scale);
            canvas.getContext('2d').drawImage(editorCanvas, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', .84));
            canvas.width = canvas.height = 0;
            if (!blob || blob.size > 1500000) throw new Error('La vista preparada supera 1,5 MB. Recorte o reduzca la imagen.');
            const data = new FormData();
            data.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
            data.append('imagen', blob, 'lectura.jpg');
            const response = await fetch(appUrl + '/servicios/cobranzas/valorizacion_gemini.php', {method:'POST', body:data, credentials:'same-origin', signal:request.signal});
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'No se pudo completar la lectura.');
            if (currentGeneration !== generation) return;
            const values = result.fields || {};
            const date = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(values.fecha_servicio || '');
            if (date) {
                const iso = `${date[3]}-${date[2]}-${date[1]}`;
                const parsed = new Date(iso + 'T12:00:00');
                values.fecha_servicio = !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === iso ? iso : null;
            } else values.fecha_servicio = null;
            for (const key of ['hora_inicio', 'hora_final']) if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(values[key] || '')) values[key] = null;
            applySuggestions(values);
            const completed = {...values, equipo: values.equipo && values.placa_serie ? `${values.equipo} ${values.placa_serie}` : null};
            for (const [key, value] of Object.entries(completed)) {
                if (!fields[key] || !value || fields[key].value !== before[key]) continue;
                fields[key].value = value;
                fields[key].dispatchEvent(new Event('input', {bubbles:true}));
            }
            const count = [values.equipo && values.placa_serie, values.fecha_servicio, values.hora_inicio, values.hora_final].filter(Boolean).length;
            ocrStatus.textContent = `${count} de 4 datos detectados. Compruebe equipo, placa, fecha y horas con el documento.`;
            fieldStatus.textContent = count ? 'Lectura completada. Corrija cualquier dato que no coincida con la foto; las horas totales se calculan automáticamente.' : 'No se identificaron datos seguros. Complete los campos manualmente.';
            document.dispatchEvent(new CustomEvent('cv:reading-complete', {detail: {count}}));
        } catch (error) {
            if (currentGeneration === generation) ocrStatus.textContent = error.message || 'No se pudo completar la lectura.';
            if (currentGeneration === generation) fieldStatus.textContent = 'La lectura automática no terminó. Puede reintentar o completar los campos manualmente.';
            if (currentGeneration === generation) document.dispatchEvent(new CustomEvent('cv:reading-error', {detail: {message: error.message || 'No se pudo completar la lectura.'}}));
        } finally {
            ocrBusy = false;
            if (geminiRequest === request) geminiRequest = null;
            geminiButton.disabled = false;
            fileInput.disabled = false;
            submit.disabled = false;
            if (currentGeneration !== generation && source && !document.getElementById('cvFormModal').classList.contains('editing')) {
                setTimeout(() => runGemini(), 0);
            }
        }
    }

    async function runOcr(forceZones = false) {
        if (ocrBusy || !working) return;
        const startedAt = performance.now();
        ocrBusy = true;
        fileInput.disabled = true;
        submit.disabled = true;
        const lockedControls = [...tools.querySelectorAll('button, input'), ...previewContent.querySelectorAll('button')].map(element => [element, element.disabled]);
        lockedControls.forEach(([element]) => { element.disabled = true; });
        const currentGeneration = generation;
        ocrStatus.textContent = 'Cargando lector gratuito en este dispositivo…';
        ocrResult.hidden = true;
        try {
            if (!worker) {
                const tesseract = await import(appUrl + '/recursos/vendor/tesseract/tesseract.esm.min.js');
                const base = appUrl + '/recursos/vendor/tesseract';
                worker = await tesseract.default.createWorker('spa', 1, {
                    workerPath: base + '/worker.min.js',
                    corePath: base + '/core',
                    langPath: base + '/lang',
                    gzip: false,
                    logger: message => {
                        if (currentGeneration !== generation) return;
                        if (message.status === 'recognizing text') ocrStatus.textContent = 'Leyendo texto: ' + Math.round((message.progress || 0) * 100) + ' %';
                    },
                });
            }
            if (currentGeneration !== generation) { endWorker(); return; }
            const result = await worker.recognize(editorCanvas);
            if (currentGeneration !== generation) return;
            ocrText.value = (result.data.text || '').trim();
            ocrResult.hidden = false;
            const values = parseDocumentText(ocrText.value);
            const formLikely = Object.values(values).some(Boolean) || /\b(?:parte\s+diario|control\s+de\s+tiempo|llegada\s+a\s+instalaci[oó]n|salida\s+de\s+instalaci[oó]n)\b/i.test(ocrText.value);
            if (!cropped && (formLikely || forceZones) && editorCanvas.height / editorCanvas.width >= 0.8 && editorCanvas.width >= 500) {
                for (const [field, zone] of Object.entries(FOCUS_ZONES)) {
                    const verifyIdentity = field === 'equipo' || field === 'placa_serie';
                    if (values[field] && !verifyIdentity) continue;
                    if (currentGeneration !== generation) return;
                    ocrStatus.textContent = `Buscando ${fieldLabels[field].toLowerCase()} en el formato…`;
                    const region = document.createElement('canvas');
                    const sx = Math.round(editorCanvas.width * zone.x), sy = Math.round(editorCanvas.height * zone.y);
                    const sw = Math.min(editorCanvas.width - sx, Math.round(editorCanvas.width * zone.width));
                    const sh = Math.min(editorCanvas.height - sy, Math.round(editorCanvas.height * zone.height));
                    const scale = Math.min(2, 1800 / Math.max(sw, sh));
                    region.width = Math.round(sw * scale); region.height = Math.round(sh * scale);
                    const context = region.getContext('2d');
                    context.fillStyle = '#fff'; context.fillRect(0, 0, region.width, region.height);
                    context.drawImage(editorCanvas, sx, sy, sw, sh, 0, 0, region.width, region.height);
                    const focused = await worker.recognize(region);
                    const focusedValue = parseFocusedText(field, focused.data.text || '');
                    if (verifyIdentity && values[field]) {
                        const comparable = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().replace(/[^A-Z0-9]/g, '');
                        if (!focusedValue || comparable(values[field]) !== comparable(focusedValue)) values[field] = '';
                    } else if (!verifyIdentity) {
                        values[field] = focusedValue;
                    }
                    region.width = region.height = 0;
                }
            }
            if (currentGeneration !== generation) return;
            applySuggestions(values);
            const elapsed = ((performance.now() - startedAt) / 1000).toFixed(1);
            const candidateCount = [values.equipo && values.placa_serie, values.fecha_servicio, values.hora_inicio, values.hora_final].filter(Boolean).length;
            ocrStatus.textContent = `${candidateCount} de 4 lecturas candidatas en ${elapsed} s, procesadas en este dispositivo. ` +
                (candidateCount ? 'Revise cada dato antes de usarlo; la letra borrosa puede producir errores.' : 'No se obtuvieron datos legibles. Ajuste la vista o complete los campos manualmente.');
        } catch (error) {
            if (currentGeneration === generation) ocrStatus.textContent = 'No se pudo leer este documento. Puede ajustar la imagen o registrar los datos manualmente.';
            endWorker();
            console.error('OCR de Cobranzas:', error);
        } finally {
            ocrBusy = false;
            fileInput.disabled = false;
            submit.disabled = false;
            lockedControls.forEach(([element, disabled]) => { element.disabled = disabled; });
        }
    }

    document.addEventListener('cv:source', event => {
        if (geminiRequest) geminiRequest.abort();
        generation++;
        fieldStatus.textContent = '';
        suggestions = {};
        showSuggestions();
        if (source) source.style.display = '';
        source = event.detail?.source || null;
        working = null;
        visualReview.hidden = true;
        resetSelection();
        tools.hidden = !source;
        if (!source) { endWorker(); return; }
        source.style.display = 'none';
        source.after(wrapper);
        makeWorking();
        if (event.detail?.text) {
            const values = parseDocumentText(event.detail.text);
            applySuggestions(values);
            ocrStatus.textContent = Object.values(values).some(Boolean)
                ? 'Se extrajo texto seleccionable del PDF. Compruebe las lecturas candidatas con el documento.'
                : 'El texto seleccionable no identifica datos del parte diario. Si es un escaneo, pruebe la lectura de imagen.';
        }
        setTimeout(() => runGemini(), 0);
    });
    document.getElementById('cvFormModal').addEventListener('hidden.bs.modal', () => {
        if (geminiRequest) geminiRequest.abort();
        generation++;
        endWorker();
    });
    document.getElementById('cvFormModal').addEventListener('shown.bs.modal', updateStates);
});
