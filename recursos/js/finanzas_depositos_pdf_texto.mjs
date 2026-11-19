// Lectura local del formato Interbank con texto seleccionable. Los PDF escaneados
// vuelven al lector visual de Gemini; el original nunca se modifica.
const moneyCents = value => {
    const match = /^S\/\s*([\d,]+)\.(\d{2})$/.exec(value.replace(/\s+/g, ' ').trim());
    return match ? Number(match[1].replace(/,/g, '')) * 100 + Number(match[2]) : null;
};

export function parseInterbankTextPages(pages) {
    let date = null;
    let declaredTotal = null;
    const rows = [];
    for (const [pageIndex, items] of pages.entries()) {
        const clean = items.map(item => ({text: String(item.str || '').trim(), x: item.transform[4], y: item.transform[5]})).filter(item => item.text);
        if (pageIndex === 0 && !clean.some(item => item.text.includes('Detalle de solicitud'))) return null;
        const program = clean.find(item => item.text === 'Programación de envío');
        const totalLabel = clean.find(item => item.text === 'Total soles');
        const headerName = clean.find(item => item.text === 'Beneficiario');
        const headerAmount = clean.find(item => item.text === 'Monto' && headerName && Math.abs(item.y - headerName.y) < 4);
        if (!headerName || !headerAmount) return null;
        if (program) {
            const value = clean.find(item => /^\d{2}\/\d{2}\/\d{4}$/.test(item.text) && Math.abs(item.y - program.y) < 4 && item.x > program.x);
            if (!value || (date && date !== value.text)) return null;
            date = value.text;
        }
        if (totalLabel) {
            const value = clean.find(item => Math.abs(item.y - totalLabel.y) < 4 && item.x > totalLabel.x && moneyCents(item.text) !== null);
            if (!value) return null;
            declaredTotal = moneyCents(value.text);
        }
        const amounts = clean.filter(item => item.y < headerName.y - 5 && item.x >= headerAmount.x - 18 && item.x < headerAmount.x + 70 && moneyCents(item.text) !== null).sort((a, b) => b.y - a.y);
        for (let index = 0; index < amounts.length; index++) {
            const amount = amounts[index];
            const top = index === 0 ? headerName.y - 4 : amount.y + 12;
            const bottom = index === amounts.length - 1 ? amount.y - 35 : amounts[index + 1].y + 12;
            const line = clean.filter(item => item.y <= top && item.y >= bottom);
            if (!line.some(item => item.x > amount.x + 35 && /^Procesado$/i.test(item.text) && Math.abs(item.y - amount.y) < 4)) continue;
            const beneficiary = line.filter(item => item.x < headerName.x + 75).sort((a, b) => b.y - a.y || a.x - b.x);
            const identity = beneficiary.find(item => /^(?:DNI|CE)\s*\d{7,9}$/i.test(item.text));
            const name = beneficiary.filter(item => item !== identity).map(item => item.text).join(' ').replace(/\s+/g, ' ').trim();
            if (!identity || !name) return null;
            rows.push({name, document: identity.text.replace(/\D/g, ''), amount: moneyCents(amount.text)});
        }
    }
    if (!date || declaredTotal === null || rows.length < 2 || rows.length > 30) return null;
    if (rows.reduce((sum, row) => sum + row.amount, 0) !== declaredTotal) return null;
    return {date, total: declaredTotal, rows};
}

export async function extractInterbankTextPdf(file) {
    const pdfjs = await import('../vendor/pdfjs/pdf.min.mjs');
    pdfjs.GlobalWorkerOptions.workerSrc = new URL('../vendor/pdfjs/pdf.worker.min.mjs', import.meta.url).href;
    const task = pdfjs.getDocument({data: new Uint8Array(await file.arrayBuffer())});
    try {
        const pdf = await task.promise;
        if (pdf.numPages > 10) return null;
        const pages = [];
        for (let number = 1; number <= pdf.numPages; number++) {
            const page = await pdf.getPage(number);
            pages.push((await page.getTextContent()).items);
        }
        return parseInterbankTextPages(pages);
    } finally {
        await task.destroy();
    }
}
