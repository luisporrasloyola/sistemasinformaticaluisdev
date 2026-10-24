// Preparación local del adjunto de Cobranzas > Valorización.
// PDF.js se descarga únicamente si el usuario selecciona un PDF.
document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('cvFile');
    if (!input) return;

    const MAX_UPLOAD = 5 * 1024 * 1024;
    const MAX_IMAGE_SOURCE = 20 * 1024 * 1024;
    const appUrl = window.APP_URL || '';
    const form = input.closest('form');
    const submit = form.querySelector('button[type="submit"]');
    const preview = document.createElement('div');
    preview.className = '';
    preview.innerHTML = `
        <div class="border rounded bg-light p-3" aria-live="polite">
            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                <strong>Vista previa del archivo</strong>
                <span class="small text-muted" id="cvPreviewSize"></span>
            </div>
            <div class="small text-muted" id="cvPreviewStatus">Seleccione una imagen o PDF para revisarlo antes de guardar.</div>
            <div id="cvPreviewContent" class="mt-2"></div>
        </div>`;
    document.getElementById('cvPreviewHost').append(preview);
    const status = preview.querySelector('#cvPreviewStatus');
    const content = preview.querySelector('#cvPreviewContent');
    const sizeLabel = preview.querySelector('#cvPreviewSize');
    const help = input.parentElement.querySelector('.form-text');
    if (help) help.textContent = 'JPG, PNG, WEBP o PDF. Se guarda un máximo de 5 MB; las imágenes grandes se reducen en este navegador. Un archivo nuevo reemplaza al anterior al editar.';

    let sequence = 0;
    let busy = false;
    let objectUrl = null;
    let pdfDocument = null;
    const setBusy = value => { busy = value; submit.disabled = value; };
    const clear = () => {
        sequence++;
        document.dispatchEvent(new CustomEvent('cv:source', {detail: null}));
        content.replaceChildren();
        sizeLabel.textContent = '';
        status.textContent = 'Seleccione una imagen o PDF para revisarlo antes de guardar.';
        status.className = 'small text-muted';
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        if (pdfDocument) { pdfDocument.destroy(); pdfDocument = null; }
        setBusy(false);
    };
    const fail = message => {
        input.value = '';
        content.replaceChildren();
        sizeLabel.textContent = '';
        status.textContent = message;
        status.className = 'small text-danger';
        setBusy(false);
        document.dispatchEvent(new CustomEvent('cv:reading-error', {detail: {message}}));
    };
    const sizeText = bytes => (bytes / 1024 / 1024).toFixed(2) + ' MB';
    const canvasBlob = (canvas, quality) => new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));

    async function compressImage(file) {
        const bitmap = await createImageBitmap(file);
        try {
            for (const maxSide of [3000, 2600, 2200]) {
                const ratio = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(bitmap.width * ratio));
                canvas.height = Math.max(1, Math.round(bitmap.height * ratio));
                const context = canvas.getContext('2d');
                if (!context) throw new Error('No se pudo preparar la imagen.');
                context.fillStyle = '#fff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                for (const quality of [0.9, 0.82, 0.74]) {
                    const blob = await canvasBlob(canvas, quality);
                    if (blob && blob.size <= MAX_UPLOAD) {
                        const name = (file.name.replace(/\.[^.]+$/, '') || 'parte_diario') + '.jpg';
                        return new File([blob], name, {type: 'image/jpeg', lastModified: file.lastModified});
                    }
                }
            }
            throw new Error('La imagen sigue superando 5 MB. Tome otra foto o reduzca su resolución.');
        } finally {
            bitmap.close();
        }
    }

    function replaceSelectedFile(file) {
        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
    }

    function showImage(file) {
        objectUrl = URL.createObjectURL(file);
        const image = document.createElement('img');
        image.src = objectUrl;
        image.alt = 'Vista previa del parte diario seleccionado';
        image.className = 'img-fluid rounded border';
        image.style.maxHeight = '360px';
        content.replaceChildren(image);
        image.addEventListener('load', () => {
            if (image.isConnected) document.dispatchEvent(new CustomEvent('cv:source', {detail: {source: image, kind: 'image', text: ''}}));
        }, {once: true});
        status.textContent = 'Imagen lista para revisar y guardar.';
        status.className = 'small text-success';
    }

    async function showPdf(file, request) {
        const startedAt = performance.now();
        status.textContent = 'Abriendo PDF en este dispositivo…';
        const pdfjs = await import(appUrl + '/recursos/vendor/pdfjs/pdf.min.mjs');
        pdfjs.GlobalWorkerOptions.workerSrc = appUrl + '/recursos/vendor/pdfjs/pdf.worker.min.mjs';
        const task = pdfjs.getDocument({data: new Uint8Array(await file.arrayBuffer())});
        const pdf = await task.promise;
        if (request !== sequence) { await task.destroy(); return; }
        pdfDocument = task;
        let currentPage = 1;
        const controls = document.createElement('div');
        controls.className = 'd-flex align-items-center gap-2 mb-2';
        const previous = document.createElement('button');
        previous.type = 'button'; previous.className = 'btn btn-sm btn-outline-secondary'; previous.textContent = 'Anterior';
        const next = document.createElement('button');
        next.type = 'button'; next.className = 'btn btn-sm btn-outline-secondary'; next.textContent = 'Siguiente';
        const pageLabel = document.createElement('span');
        pageLabel.className = 'small text-muted';
        controls.append(previous, pageLabel, next);
        const canvas = document.createElement('canvas');
        canvas.className = 'd-block rounded border';
        canvas.style.maxWidth = '100%';
        canvas.style.height = 'auto';
        const textBox = document.createElement('textarea');
        textBox.className = 'form-control mt-2';
        textBox.rows = 4;
        textBox.readOnly = true;
        textBox.setAttribute('aria-label', 'Texto seleccionable de la página del PDF');
        content.replaceChildren(controls, canvas, textBox);
        let rendering = false;
        async function renderPage(number) {
            if (rendering || request !== sequence) return;
            rendering = true;
            previous.disabled = number <= 1;
            next.disabled = number >= pdf.numPages;
            pageLabel.textContent = `Página ${number} de ${pdf.numPages}`;
            try {
                const page = await pdf.getPage(number);
                const base = page.getViewport({scale: 1});
                const viewport = page.getViewport({scale: Math.min(2, 2200 / Math.max(base.width, base.height))});
                canvas.width = Math.ceil(viewport.width);
                canvas.height = Math.ceil(viewport.height);
                await page.render({canvasContext: canvas.getContext('2d'), viewport}).promise;
                const extracted = await page.getTextContent();
                const text = extracted.items.map(item => item.str || '').join(' ').trim();
                textBox.hidden = !text;
                textBox.value = text.slice(0, 15000);
                if (request === sequence) {
                    document.dispatchEvent(new CustomEvent('cv:source', {detail: {source: canvas, kind: 'pdf', text}}));
                    const elapsed = ((performance.now() - startedAt) / 1000).toFixed(1);
                    status.textContent = text
                        ? `PDF abierto en ${elapsed} s. Hay texto seleccionable; compruebe los datos escritos a mano en la imagen.`
                        : `Página escaneada abierta en ${elapsed} s. Use «Leer y extraer datos» para buscar los campos.`;
                    status.className = 'small text-success';
                    currentPage = number;
                }
            } finally { rendering = false; }
        }
        previous.addEventListener('click', () => renderPage(currentPage - 1));
        next.addEventListener('click', () => renderPage(currentPage + 1));
        await renderPage(1);
    }

    input.addEventListener('change', async () => {
        clear();
        const file = input.files[0];
        if (!file) return;
        const request = sequence;
        setBusy(true);
        try {
            const allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
            if (!allowed.includes(file.type)) throw new Error('Seleccione JPG, PNG, WEBP o PDF.');
            if (file.type === 'application/pdf') {
                if (file.size > MAX_UPLOAD) throw new Error('El PDF supera 5 MB. Redúzcalo antes de subirlo.');
                sizeLabel.textContent = sizeText(file.size);
                await showPdf(file, request);
            } else {
                if (file.size > MAX_IMAGE_SOURCE) throw new Error('La imagen supera 20 MB. Redúzcala antes de seleccionarla.');
                let prepared = file;
                if (file.size > MAX_UPLOAD) {
                    status.textContent = 'Reduciendo la imagen en este dispositivo…';
                    prepared = await compressImage(file);
                    if (request !== sequence) return;
                    replaceSelectedFile(prepared);
                }
                if (request !== sequence) return;
                sizeLabel.textContent = prepared === file ? sizeText(file.size) : sizeText(file.size) + ' → ' + sizeText(prepared.size);
                showImage(prepared);
            }
        } catch (error) {
            if (request === sequence) fail(error.message || 'No se pudo abrir el archivo.');
        } finally {
            if (request === sequence) setBusy(false);
        }
    });

    form.addEventListener('submit', event => {
        const file = input.files[0];
        if (busy || (file && file.size > MAX_UPLOAD)) {
            event.preventDefault();
            status.textContent = 'Espere a que termine la preparación del archivo o seleccione uno menor de 5 MB.';
            status.className = 'small text-danger';
        }
    });
    document.getElementById('cvFormModal').addEventListener('show.bs.modal', () => {
        clear();
        input.value = '';
        const recordId = Number(document.getElementById('cvId').value);
        if (recordId > 0) {
            const button = [...document.querySelectorAll('.cvEdit')].find(item => Number(JSON.parse(item.dataset.record).id) === recordId);
            if (button) {
                const record = JSON.parse(button.dataset.record);
                const image = /\.(?:jpe?g|png|webp)$/i.test(record.archivo_path || '');
                const original = document.createElement(image ? 'img' : 'iframe');
                original.src = appUrl + '/servicios/cobranzas/valorizacion_archivo.php?id=' + recordId;
                original.title = 'Archivo original del registro';
                original.className = image ? 'img-fluid rounded border' : 'w-100 rounded border';
                original.style.maxHeight = '42vh';
                if (!image) original.style.height = '42vh';
                content.replaceChildren(original);
                status.textContent = 'Archivo original guardado. Si selecciona otro, verá aquí el reemplazo.';
            }
        }
    });
});
