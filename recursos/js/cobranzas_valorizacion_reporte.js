const exportButtons = [...document.querySelectorAll('.cv-export-pdf')];

if (exportButtons.length) {
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

    async function buildPdf(data, lib, progress, mode) {
        const pdf = await lib.PDFDocument.create();
        pdf.setTitle(`Valorización - ${data.client.name}`);
        pdf.setAuthor(data.supplier.name);
        const regular = await pdf.embedFont(lib.StandardFonts.Helvetica);
        const bold = await pdf.embedFont(lib.StandardFonts.HelveticaBold);
        const tableText = lib.rgb(0, 0, 0);
        const tableLine = lib.rgb(0.18, 0.18, 0.18);
        const tableHeaderFill = lib.rgb(0.85, 0.85, 0.85);
        const tableRowFill = lib.rgb(0.965, 0.965, 0.965);
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
        const signatureWidth = 104;
        const signatureHeight = signatureWidth * signatureImage.height / signatureImage.width;
        const signatureY = footerHeight + 12;
        const signatureTop = signatureY + signatureHeight;
        const contentBottom = Math.max(footerHeight + 30, signatureTop + 8);
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
                    color: tableText
                });
            });
        };
        const isCobranzas = mode === 'cobranzas';
        const widths = isCobranzas ? [146, 72, 77, 67, 49, tableWidth - 411] : [96, 80, 58, 62, 54, 43, 84, tableWidth - 477];
        const labels = isCobranzas
            ? ['Equipo', 'Fecha', 'Hora de inicio', 'Hora final', 'Horas', 'Observaciones']
            : ['Equipo', 'Operador', 'Fecha', 'Hora de inicio', 'Hora final', 'Horas', 'Observaciones', 'Registrado por'];
        const notesIndex = isCobranzas ? 5 : 6;
        const rowLineHeight = 7.5;
        const minRowHeight = 13;
        const totalLineHeight = 11;
        const columns = [left];
        widths.forEach(width => columns.push(columns[columns.length - 1] + width));
        let page;
        let y;
        let summaryPage = 0;

        const drawLine = (fromX, fromY, toX, toY, color = tableLine) => page.drawLine({start: {x: fromX, y: fromY}, end: {x: toX, y: toY}, thickness: 0.7, color});
        const drawFitted = (value, x, baseline, width, size = 9, font = regular, color = tableText) => {
            page.drawText(fitText(value, font, size, width), {x, y: baseline, size, font, color});
        };
        const drawInfo = (label, value, x, baseline, labelWidth, maxWidth) => {
            drawFitted(label, x, baseline, labelWidth - 4, 7, bold, tableText);
            drawFitted(value || '-', x + labelWidth, baseline, maxWidth - labelWidth, 7.5, regular, tableText);
        };

        function drawTableHeader(top) {
            page.drawRectangle({x: left, y: top - 16, width: tableWidth, height: 16, color: tableHeaderFill, borderColor: tableLine, borderWidth: 0.85});
            labels.forEach((label, index) => drawFitted(label, columns[index] + 6, top - 11, widths[index] - 12, isCobranzas ? 6.5 : 6, bold, tableText));
            for (let i = 1; i < columns.length - 1; i++) drawLine(columns[i], top, columns[i], top - 16, tableLine);
            return top - 16;
        }

        function addSummaryPage() {
            summaryPage++;
            page = pdf.addPage(A4);
            drawBrand(page);
            const title = data.rates.length === 1 ? `VALORIZACIÓN DE ALQUILER DE ${data.rates[0].name.toUpperCase()}` : 'VALORIZACIÓN DE ALQUILER DE EQUIPOS';
            const titleSize = 10;
            const heading = fitText(title, bold, titleSize, tableWidth);
            page.drawText(heading, {x: (A4[0] - bold.widthOfTextAtSize(heading, titleSize)) / 2, y: 706, size: titleSize, font: bold, color: tableText});
            if (summaryPage === 1) {
                page.drawRectangle({x: left, y: 627, width: tableWidth, height: 66, borderColor: tableLine, borderWidth: 0.85});
                drawLine(366, 627, 366, 693, tableLine);
                drawInfo('Cliente', data.client.name, 32, 683, 105, 326);
                drawInfo('Lugar de servicio', data.client.location || '-', 32, 673, 105, 326);
                drawInfo('RUC - Cliente', data.client.ruc, 32, 663, 105, 326);
                const contact = data.client.contact ? `${data.client.contact.name} · ${data.client.contact.phone}` : 'Sin contacto comercial';
                drawInfo('Contacto', contact, 32, 653, 105, 326);
                const period = data.client.period;
                const equipmentWithCapacity = data.client.capacity
                    ? `${data.client.equipment || '-'} ${data.client.capacity}`
                    : data.client.equipment || '-';
                drawInfo('Equipo', equipmentWithCapacity, 32, 643, 105, 326);
                drawInfo('Periodo', period ? `${dateText(period.from)} al ${dateText(period.to)}` : '-', 32, 633, 105, 326);
                drawFitted('Proveedor', 374, 683, 190, 7, bold, tableText);
                drawFitted(data.supplier.name, 374, 671, 188, 7, regular, tableText);
                drawInfo('RUC', data.supplier.ruc, 374, 650, 34, 190);
                y = drawTableHeader(623);
            } else {
                y = drawTableHeader(688);
            }
        }

        addSummaryPage();
        for (let rowIndex = 0; rowIndex < data.records.length; rowIndex++) {
            const row = data.records[rowIndex];
            const notes = wrapText(row.notes, regular, 6.5, widths[notesIndex] - 12);
            const operatorLines = isCobranzas ? [] : wrapText(row.operator || '-', regular, 6.5, widths[1] - 12);
            const registeredLines = isCobranzas ? [] : wrapText(row.registered_by || '-', regular, 6.5, widths[7] - 12);
            const remainingRows = data.records.length - rowIndex;
            const totalsHeight = 14 + (4 + data.rates.length) * totalLineHeight + 11;
            let noteIndex = 0;
            while (noteIndex < notes.length) {
                const nameHeight = noteIndex === 0 ? Math.max(operatorLines.length, registeredLines.length) * rowLineHeight + 5 : minRowHeight;
                if (y - Math.max(minRowHeight, nameHeight) < contentBottom) { addSummaryPage(); continue; }
                const linesOnPage = Math.floor((y - contentBottom - 5) / rowLineHeight);
                const segment = notes.slice(noteIndex, noteIndex + linesOnPage);
                const rowHeight = Math.max(minRowHeight, segment.length * rowLineHeight + 5, nameHeight);
                const isLastSegment = noteIndex + segment.length === notes.length;
                if (isLastSegment && remainingRows <= 5 && y - rowHeight - totalsHeight < contentBottom && y < 300) {
                    addSummaryPage();
                    continue;
                }
                page.drawRectangle({x: left, y: y - rowHeight, width: tableWidth, height: rowHeight, color: tableRowFill, borderColor: tableLine, borderWidth: 0.65});
                for (let i = 1; i < columns.length - 1; i++) drawLine(columns[i], y, columns[i], y - rowHeight, tableLine);
                const values = noteIndex === 0
                    ? (isCobranzas
                        ? [row.equipment, dateText(row.date), row.start, row.end, hoursText(row.minutes)]
                        : [row.equipment, '', dateText(row.date), row.start, row.end, hoursText(row.minutes)])
                    : [`${row.equipment} (cont.)`, ...Array(notesIndex - 1).fill('')];
                values.forEach((value, index) => drawFitted(value, columns[index] + 6, y - 9, widths[index] - 12, 6.5, index === 0 ? bold : regular, tableText));
                if (!isCobranzas && noteIndex === 0) {
                    operatorLines.forEach((value, index) => drawFitted(value, columns[1] + 6, y - 9 - index * rowLineHeight, widths[1] - 12, 6.5, regular, tableText));
                    registeredLines.forEach((value, index) => drawFitted(value, columns[7] + 6, y - 9 - index * rowLineHeight, widths[7] - 12, 6.5, regular, tableText));
                }
                segment.forEach((note, index) => drawFitted(note, columns[notesIndex] + 6, y - 9 - index * rowLineHeight, widths[notesIndex] - 12, 6.5, regular, tableText));
                y -= rowHeight;
                noteIndex += segment.length;
                if (noteIndex < notes.length) addSummaryPage();
            }
        }

        const summaryLines = 4 + data.rates.length;
        if (y - 14 - summaryLines * totalLineHeight - 11 < contentBottom) addSummaryPage();
        y -= 14;
        const totalLabelX = 215;
        const totalValueRight = A4[0] - left - 8;
        function drawTotal(label, value, emphasize = false) {
            const size = emphasize ? 9 : 7.5;
            const font = emphasize ? bold : regular;
            drawFitted(label, totalLabelX, y, 285, size, emphasize ? bold : regular, tableText);
            const shown = clean(value);
            page.drawText(shown, {x: totalValueRight - font.widthOfTextAtSize(shown, size), y, size, font, color: tableText});
            y -= emphasize ? 16 : totalLineHeight;
        }
        const currencySymbol = data.currency === 'USD' ? '$' : 'S/.';
        const moneyWithCurrency = cents => `${currencySymbol} ${money(cents)}`;
        drawTotal('Horas totales', hoursText(data.total_minutes));
        data.rates.forEach(rate => drawTotal(`Precio por hora ${rate.name}`, moneyWithCurrency(Math.round(Number(rate.price) * 100))));
        drawTotal(`Precio del ${dateText(data.first_date)} al ${dateText(data.last_date)}`, moneyWithCurrency(data.subtotal_cents));
        drawTotal('IGV (18 %)', moneyWithCurrency(data.igv_cents));
        y -= 5;
        drawLine(totalLabelX, y + 9, totalValueRight, y + 9, tableLine);
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
            attachmentSheet.drawRectangle({x, y: bottom, width: cellWidth, height: cellHeight, borderColor: tableLine, borderWidth: 0.85});
            const caption = `Parte diario · ${dateText(row.date)} · ${row.equipment}${partLabel}`;
            attachmentSheet.drawText(fitText(caption, bold, 8.5, cellWidth - 16), {x: x + 8, y: top - 15, size: 8.5, font: bold, color: tableText});
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

    exportButtons.forEach(exportButton => exportButton.addEventListener('click', async () => {
        if (exportButton.disabled) return;
        const original = exportButton.innerHTML;
        const mode = exportButton.dataset.mode === 'cobranzas' ? 'cobranzas' : 'normal';
        const disabledBefore = exportButtons.map(button => button.disabled);
        const progress = message => { exportButton.textContent = message; };
        exportButtons.forEach(button => { button.disabled = true; });
        let downloadUrl;
        try {
            progress('Preparando PDF…');
            const response = await fetch(exportButton.dataset.url, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
            if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('La sesión venció. Actualice la página e ingrese de nuevo.');
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo preparar la valorización.');
            const lib = await loadPdfLibrary();
            const bytes = await buildPdf(data, lib, progress, mode);
            downloadUrl = URL.createObjectURL(new Blob([bytes], {type: 'application/pdf'}));
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = `valorizacion${mode === 'cobranzas' ? '_cobranzas' : ''}_${data.client.ruc}_${data.first_date}_${data.last_date}.pdf`;
            document.body.append(link);
            link.click();
            link.remove();
        } catch (error) {
            const message = error instanceof Error ? error.message : 'No se pudo generar el PDF.';
            if (window.Swal) Swal.fire({icon: 'error', title: 'No se pudo generar el PDF', text: message});
            else alert(message);
        } finally {
            exportButtons.forEach((button, index) => { button.disabled = disabledBefore[index]; });
            exportButton.innerHTML = original;
            if (downloadUrl) setTimeout(() => URL.revokeObjectURL(downloadUrl), 60000);
        }
    }));
}
