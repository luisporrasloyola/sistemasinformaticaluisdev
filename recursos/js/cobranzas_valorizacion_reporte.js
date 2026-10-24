const exportButton = document.getElementById('cvExportPdf');

if (exportButton) {
    const libraryUrl = new URL('../vendor/pdf-lib/pdf-lib.min.js', import.meta.url).href;
    const headerUrl = new URL('../../img/encabezado.png', import.meta.url).href;
    const footerUrl = new URL('../../img/piedepagina.png', import.meta.url).href;
    const signatureUrl = new URL('../../img/firma.png', import.meta.url).href;
    let libraryPromise;

    function loadPdfLibrary() {
        if (window.PDFLib) return Promise.resolve(window.PDFLib);
        if (!libraryPromise) {
            libraryPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = libraryUrl;
                script.onload = () => window.PDFLib ? resolve(window.PDFLib) : reject(new Error('No se pudo cargar el generador PDF.'));
                script.onerror = () => reject(new Error('No se pudo cargar el generador PDF.'));
                document.head.append(script);
            });
        }
        return libraryPromise;
    }

    const clean = value => String(value ?? '').replace(/[\r\n\t]+/g, ' ').replace(/[^\x20-\xFF]/g, '-').trim();
    const dateText = date => /^\d{4}-\d{2}-\d{2}$/.test(date) ? `${date.slice(8, 10)}/${date.slice(5, 7)}/${date.slice(0, 4)}` : clean(date);
    const hoursText = minutes => `${String(Math.floor(Number(minutes) / 60)).padStart(2, '0')}:${String(Number(minutes) % 60).padStart(2, '0')}`;
    const money = cents => new Intl.NumberFormat('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(Number(cents) / 100);

    function fitText(value, font, size, width) {
        const text = clean(value);
        if (font.widthOfTextAtSize(text, size) <= width) return text;
        let result = text;
        while (result && font.widthOfTextAtSize(result + '...', size) > width) result = result.slice(0, -1);
        return result + '...';
    }

    function wrapText(value, font, size, width) {
        const words = clean(value).split(/\s+/).filter(Boolean);
        if (!words.length) return ['-'];
        const lines = [];
        let line = '';
        for (const word of words) {
            const candidate = line ? `${line} ${word}` : word;
            if (font.widthOfTextAtSize(candidate, size) <= width) {
                line = candidate;
                continue;
            }
            if (line) { lines.push(line); line = ''; }
            for (const char of word) {
                if (line && font.widthOfTextAtSize(line + char, size) > width) {
                    lines.push(line);
                    line = '';
                }
                line += char;
            }
        }
        if (line) lines.push(line);
        return lines;
    }

    async function buildPdf(data, lib, progress) {
        const pdf = await lib.PDFDocument.create();
        pdf.setTitle(`Valorización - ${data.client.name}`);
        pdf.setAuthor(data.supplier.name);
        const regular = await pdf.embedFont(lib.StandardFonts.Helvetica);
        const bold = await pdf.embedFont(lib.StandardFonts.HelveticaBold);
        const dark = lib.rgb(0.08, 0.15, 0.26);
        const muted = lib.rgb(0.30, 0.38, 0.49);
        const blue = lib.rgb(0.08, 0.29, 0.67);
        const pale = lib.rgb(0.94, 0.97, 1);
        const line = lib.rgb(0.77, 0.82, 0.89);
        const A4 = [595.28, 841.89];
        const left = 24;
        const tableWidth = A4[0] - left * 2;
        const [headerResponse, footerResponse, signatureResponse] = await Promise.all([fetch(headerUrl), fetch(footerUrl), fetch(signatureUrl)]);
        if (!headerResponse.ok || !footerResponse.ok || !signatureResponse.ok) throw new Error('No se pudieron cargar las imágenes del reporte.');
        const [headerImage, footerImage, signatureImage] = await Promise.all([
            pdf.embedPng(await headerResponse.arrayBuffer()),
            pdf.embedPng(await footerResponse.arrayBuffer()),
            pdf.embedPng(await signatureResponse.arrayBuffer())
        ]);
        const headerHeight = A4[0] * headerImage.height / headerImage.width;
        const footerHeight = A4[0] * footerImage.height / footerImage.width;
        const signatureWidth = 138;
        const signatureHeight = signatureWidth * signatureImage.height / signatureImage.width;
        const signatureY = footerHeight + 29;
        const signatureTop = signatureY + signatureHeight;
        const drawBrand = sheet => {
            sheet.drawImage(headerImage, {x: 0, y: A4[1] - headerHeight, width: A4[0], height: headerHeight});
            sheet.drawImage(footerImage, {x: 0, y: 0, width: A4[0], height: footerHeight});
            const footerLines = ['www.lifemaquinarias.com', 'Av. Nicolas Ayllon 2941 El Agustino'];
            footerLines.forEach((text, index) => {
                const size = 7.5;
                sheet.drawText(text, {
                    x: (A4[0] - regular.widthOfTextAtSize(text, size)) / 2,
                    y: footerHeight + (index === 0 ? 16 : 5),
                    size,
                    font: regular,
                    color: dark
                });
            });
        };
        const widths = [104, 83, 60, 69, 59, 43, tableWidth - 418];
        const labels = ['Equipo', 'Operador', 'Fecha', 'Hora de inicio', 'Hora final', 'Horas', 'Observaciones'];
        const columns = [left];
        widths.forEach(width => columns.push(columns[columns.length - 1] + width));
        let page;
        let y;
        let summaryPage = 0;

        const drawLine = (fromX, fromY, toX, toY, color = line) => page.drawLine({start: {x: fromX, y: fromY}, end: {x: toX, y: toY}, thickness: 0.7, color});
        const drawFitted = (value, x, baseline, width, size = 9, font = regular, color = dark) => {
            page.drawText(fitText(value, font, size, width), {x, y: baseline, size, font, color});
        };
        const drawInfo = (label, value, x, baseline, labelWidth, maxWidth) => {
            drawFitted(label, x, baseline, labelWidth - 4, 8.5, bold, muted);
            drawFitted(value || '-', x + labelWidth, baseline, maxWidth - labelWidth, 9, regular);
        };

        function drawTableHeader(top) {
            page.drawRectangle({x: left, y: top - 18, width: tableWidth, height: 18, color: pale, borderColor: line, borderWidth: 0.7});
            labels.forEach((label, index) => drawFitted(label, columns[index] + 6, top - 12, widths[index] - 12, 8, bold));
            for (let i = 1; i < columns.length - 1; i++) drawLine(columns[i], top, columns[i], top - 18);
            return top - 18;
        }

        function addSummaryPage() {
            summaryPage++;
            page = pdf.addPage(A4);
            drawBrand(page);
            const title = data.rates.length === 1 ? `VALORIZACIÓN DE ALQUILER DE ${data.rates[0].name.toUpperCase()}` : 'VALORIZACIÓN DE ALQUILER DE EQUIPOS';
            const titleSize = 12.5;
            const heading = fitText(title, bold, titleSize, tableWidth);
            page.drawText(heading, {x: (A4[0] - bold.widthOfTextAtSize(heading, titleSize)) / 2, y: 698, size: titleSize, font: bold, color: dark});
            if (summaryPage === 1) {
                page.drawRectangle({x: left, y: 631, width: tableWidth, height: 54, borderColor: line, borderWidth: 0.7});
                drawLine(366, 631, 366, 685);
                drawInfo('Cliente', data.client.name, 32, 674, 105, 326);
                drawInfo('Lugar de servicio', data.client.location || '-', 32, 662, 105, 326);
                drawInfo('RUC - Cliente', data.client.ruc, 32, 650, 105, 326);
                const contact = data.client.contact ? `${data.client.contact.name} · ${data.client.contact.phone}` : 'Sin contacto comercial';
                drawInfo('Contacto', contact, 32, 638, 105, 326);
                drawFitted('Proveedor', 374, 674, 190, 8.5, bold, muted);
                drawFitted(data.supplier.name, 374, 662, 188, 8.2);
                drawInfo('RUC', data.supplier.ruc, 374, 639, 34, 190);
                y = drawTableHeader(625);
            } else {
                y = drawTableHeader(680);
            }
        }

        addSummaryPage();
        for (let rowIndex = 0; rowIndex < data.records.length; rowIndex++) {
            const row = data.records[rowIndex];
            const notes = wrapText(row.notes, regular, 8, widths[6] - 12);
            const remainingRows = data.records.length - rowIndex;
            const totalsHeight = 20 + (4 + data.rates.length) * 15;
            let noteIndex = 0;
            while (noteIndex < notes.length) {
                if (y - 16 < 119) { addSummaryPage(); continue; }
                const linesOnPage = Math.floor((y - 119 - 6) / 9);
                const segment = notes.slice(noteIndex, noteIndex + linesOnPage);
                const rowHeight = Math.max(16, segment.length * 9 + 6);
                const isLastSegment = noteIndex + segment.length === notes.length;
                if (isLastSegment && remainingRows <= 5 && y - rowHeight - totalsHeight < signatureTop + 12 && y < 300) {
                    addSummaryPage();
                    continue;
                }
                page.drawRectangle({x: left, y: y - rowHeight, width: tableWidth, height: rowHeight, borderColor: line, borderWidth: 0.5});
                for (let i = 1; i < columns.length - 1; i++) drawLine(columns[i], y, columns[i], y - rowHeight);
                const values = noteIndex === 0
                    ? [row.equipment, row.operator || '-', dateText(row.date), row.start, row.end, hoursText(row.minutes)]
                    : [`${row.equipment} (cont.)`, '', '', '', '', ''];
                values.forEach((value, index) => drawFitted(value, columns[index] + 6, y - 11, widths[index] - 12, 8, index === 0 ? bold : regular));
                segment.forEach((note, index) => drawFitted(note, columns[6] + 6, y - 11 - index * 9, widths[6] - 12, 8));
                y -= rowHeight;
                noteIndex += segment.length;
                if (noteIndex < notes.length) addSummaryPage();
            }
        }

        const summaryLines = 4 + data.rates.length;
        if (y - 20 - summaryLines * 15 < signatureTop + 12) addSummaryPage();
        y -= 20;
        const totalLabelX = 215;
        const totalValueRight = A4[0] - left - 8;
        function drawTotal(label, value, emphasize = false) {
            const size = emphasize ? 11 : 9;
            const font = emphasize ? bold : regular;
            drawFitted(label, totalLabelX, y, 285, size, emphasize ? bold : regular);
            const shown = clean(value);
            page.drawText(shown, {x: totalValueRight - font.widthOfTextAtSize(shown, size), y, size, font, color: emphasize ? blue : dark});
            y -= emphasize ? 18 : 15;
        }
        const currencySymbol = data.currency === 'USD' ? '$' : 'S/.';
        const moneyWithCurrency = cents => `${currencySymbol} ${money(cents)}`;
        drawTotal('Horas totales', hoursText(data.total_minutes));
        data.rates.forEach(rate => drawTotal(`Precio por hora ${rate.name}`, moneyWithCurrency(Math.round(Number(rate.price) * 100))));
        drawTotal(`Precio del ${dateText(data.first_date)} al ${dateText(data.last_date)}`, moneyWithCurrency(data.subtotal_cents));
        drawTotal('IGV (18 %)', moneyWithCurrency(data.igv_cents));
        drawLine(totalLabelX, y + 12, totalValueRight, y + 12);
        drawTotal('Monto total', moneyWithCurrency(data.total_cents), true);
        page.drawImage(signatureImage, {x: A4[0] - left - signatureWidth, y: signatureY, width: signatureWidth, height: signatureHeight});
        progress(`Añadiendo adjuntos (0/${data.records.length})…`);

        let attachmentSheet;
        let attachmentCount = 0;
        const sheetWidth = A4[0];
        const sheetHeight = A4[1];
        const cellGap = 12;
        const cellWidth = (sheetWidth - 48 - cellGap) / 2;
        const cellHeight = 282;
        function addAttachment(embedded, row, isPdf, partLabel = '') {
            if (attachmentCount % 4 === 0) {
                attachmentSheet = pdf.addPage([sheetWidth, sheetHeight]);
                drawBrand(attachmentSheet);
            }
            const slot = attachmentCount % 4;
            const x = 24 + (slot % 2) * (cellWidth + cellGap);
            const top = slot < 2 ? 704 : 400;
            const bottom = top - cellHeight;
            attachmentSheet.drawRectangle({x, y: bottom, width: cellWidth, height: cellHeight, borderColor: line, borderWidth: 0.8});
            const caption = `Parte diario · ${dateText(row.date)} · ${row.equipment}${partLabel}`;
            attachmentSheet.drawText(fitText(caption, bold, 8.5, cellWidth - 16), {x: x + 8, y: top - 15, size: 8.5, font: bold, color: dark});
            const innerWidth = cellWidth - 16;
            const innerHeight = cellHeight - 34;
            const scale = Math.min(innerWidth / embedded.width, innerHeight / embedded.height);
            const width = embedded.width * scale;
            const height = embedded.height * scale;
            const options = {x: x + 8 + (innerWidth - width) / 2, y: bottom + 8 + (innerHeight - height) / 2, width, height};
            if (isPdf) attachmentSheet.drawPage(embedded, options);
            else attachmentSheet.drawImage(embedded, options);
            attachmentCount++;
        }

        for (let index = 0; index < data.records.length; index++) {
            const row = data.records[index];
            const response = await fetch(row.file_url, {credentials: 'same-origin'});
            if (!response.ok) throw new Error(`No se pudo abrir el adjunto del ${dateText(row.date)}.`);
            const type = (response.headers.get('content-type') || '').split(';')[0].toLowerCase();
            if (type === 'application/pdf') {
                const source = await lib.PDFDocument.load(await response.arrayBuffer());
                const pages = source.getPages();
                for (let part = 0; part < pages.length; part++) {
                    const embedded = await pdf.embedPage(pages[part]);
                    addAttachment(embedded, row, true, pages.length > 1 ? ` · pág. ${part + 1}/${pages.length}` : '');
                }
            } else if (['image/jpeg', 'image/png', 'image/webp'].includes(type)) {
                const bitmap = await createImageBitmap(await response.blob());
                const scale = Math.min(1, 1800 / Math.max(bitmap.width, bitmap.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(bitmap.width * scale));
                canvas.height = Math.max(1, Math.round(bitmap.height * scale));
                const context = canvas.getContext('2d', {alpha: false});
                context.fillStyle = '#fff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                bitmap.close();
                const jpegBlob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.82));
                if (!jpegBlob) throw new Error(`No se pudo preparar la imagen del ${dateText(row.date)}.`);
                const image = await pdf.embedJpg(await jpegBlob.arrayBuffer());
                addAttachment(image, row, false);
            } else throw new Error(`El adjunto del ${dateText(row.date)} no es una imagen ni un PDF válido.`);
            progress(`Añadiendo adjuntos (${index + 1}/${data.records.length})…`);
        }
        return pdf.save({useObjectStreams: true});
    }

    exportButton.addEventListener('click', async () => {
        if (exportButton.disabled) return;
        const original = exportButton.innerHTML;
        const progress = message => { exportButton.textContent = message; };
        exportButton.disabled = true;
        let downloadUrl;
        try {
            progress('Preparando PDF…');
            const response = await fetch(exportButton.dataset.url, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
            if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('La sesión venció. Actualice la página e ingrese de nuevo.');
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo preparar la valorización.');
            const lib = await loadPdfLibrary();
            const bytes = await buildPdf(data, lib, progress);
            downloadUrl = URL.createObjectURL(new Blob([bytes], {type: 'application/pdf'}));
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = `valorizacion_${data.client.ruc}_${data.first_date}_${data.last_date}.pdf`;
            document.body.append(link);
            link.click();
            link.remove();
        } catch (error) {
            const message = error instanceof Error ? error.message : 'No se pudo generar el PDF.';
            if (window.Swal) Swal.fire({icon: 'error', title: 'No se pudo generar el PDF', text: message});
            else alert(message);
        } finally {
            exportButton.disabled = false;
            exportButton.innerHTML = original;
            if (downloadUrl) setTimeout(() => URL.revokeObjectURL(downloadUrl), 60000);
        }
    });
}
