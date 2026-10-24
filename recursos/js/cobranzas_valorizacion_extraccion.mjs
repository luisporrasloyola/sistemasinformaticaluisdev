// Lectura conservadora de los datos del formato "Parte diario".
// Devuelve solo valores que superan validaciones de fecha, hora y placa.
const normalized = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\r/g, '');
const tidy = value => String(value || '').replace(/\s+/g, ' ').trim();

export function parseDate(text) {
    const source = normalized(text).replace(/[|]/g, '/').replace(/\b[oO](?=\d)/g, '0');
    const header = source.match(/dia\s*[:.]?\s*([0-3]?\d)\s*mes\s*[:.]?\s*([01]?\d)\s*ano\s*[:.]?\s*((?:19|20)?\d{2})/i);
    const match = header || source.match(/(?:^|\D)([0-3]?\d)\s*[\/\.\-\s]\s*([01]?\d)\s*[\/\.\-\s]\s*((?:19|20)?\d{2})(?!\d)/);
    if (!match) return '';
    const day = Number(match[1]), month = Number(match[2]);
    let year = Number(match[3]);
    if (year < 100) year += 2000;
    if (year < 2000 || year > 2099) return '';
    const date = new Date(Date.UTC(year, month - 1, day));
    if (date.getUTCFullYear() !== year || date.getUTCMonth() + 1 !== month || date.getUTCDate() !== day) return '';
    return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

export function parseTime(text) {
    const source = normalized(text).replace(/\b([0-2]?\d)\s*[hH\. ]\s*([0-5]\d)\b/g, '$1:$2');
    const match = source.match(/(?:^|[^\d])([01]?\d|2[0-3])\s*:\s*([0-5]\d)\s*([aApP]\.?\s*[mM]\.?)?(?!\d)/);
    if (!match) return '';
    let hour = Number(match[1]);
    const suffix = (match[3] || '').replace(/[.\s]/g, '').toLowerCase();
    if (suffix && hour > 12) return '';
    if (suffix === 'am' && hour === 12) hour = 0;
    if (suffix === 'pm' && hour < 12) hour += 12;
    return `${String(hour).padStart(2, '0')}:${match[2]}`;
}

export function parsePlate(text) {
    const source = normalized(text).toUpperCase();
    const labelled = source.match(/(?:PLACA\s*\/?\s*SERIE|PLACA|SERIE)\s*[:.\-]?\s*([A-Z0-9][A-Z0-9\s-]{4,22})/);
    const equipmentPlate = source.match(/\bEQUIPO\b[^\n]{0,80}?\b([A-Z0-9]{2,10}\s*-\s*[A-Z0-9]{2,10})\b/);
    const candidate = labelled ? labelled[1] : (equipmentPlate ? equipmentPlate[1] : source);
    const match = candidate.match(/\b(?=[A-Z0-9-]*\d)[A-Z0-9]{2,10}(?:\s*-\s*[A-Z0-9]{2,10})?\b/);
    if (!match) return '';
    const plate = match[0].replace(/\s+/g, '');
    return plate.length >= 5 && plate.length <= 20 ? plate : '';
}

export function parseEquipment(text, zone = false) {
    const source = normalized(text);
    const labelled = source.match(/\bequipo\s*[:.\-]?\s*([^\n\r]{3,100})/i);
    let value = labelled ? labelled[1] : (zone ? source.split('\n').map(tidy).filter(Boolean)[0] || '' : '');
    value = value.replace(/\b(?:placa\s*\/?\s*serie|placa|serie|trabajos? a realizar)\b.*$/i, '');
    value = value.replace(/\b[A-Z0-9]{2,10}\s*-\s*[A-Z0-9]{2,10}\b.*$/i, '');
    value = tidy(value.replace(/^[\s:.-]+|[\s:.,-]+$/g, ''));
    if (!/[A-Za-zÁÉÍÓÚÑáéíóúñ]{3}/.test(value) || value.length < 4 || value.length > 120) return '';
    if (/^(?:cliente|lugar|equipo|placa|serie|fecha|hora|parte diario)$/i.test(value)) return '';
    return value;
}

export function parseDocumentText(text) {
    const source = normalized(text);
    const lines = source.split('\n').map(tidy);
    const result = {equipo: '', placa_serie: '', fecha_servicio: '', hora_inicio: '', hora_final: ''};
    // Evita proponer, por ejemplo, la fecha de emisión de una factura ajena al parte diario.
    const formSignals = [
        /\bequipo\b/i,
        /\b(?:placa|serie)\b/i,
        /\b(?:fecha|dia\s+\d{1,2}\s+mes)\b/i,
        /\b(?:inicio|llegada)\b/i,
        /\b(?:final|salida)\b/i,
    ].filter(pattern => pattern.test(source)).length;
    if (!/\bparte\s+diario\b/i.test(source) && formSignals < 3) return result;
    result.equipo = parseEquipment(source);
    result.placa_serie = parsePlate(source.match(/(?:placa\s*\/?\s*serie|placa|serie)[^\n]{0,35}/i)?.[0] || source.match(/\bequipo\b[^\n]{0,100}/i)?.[0] || '');
    const dateLine = lines.find(line => /\b(?:fecha|dia\s*[:.]?\s*\d{1,2}\s*mes)\b/i.test(line) && parseDate(line));
    // Una fecha aislada puede ser de emisión o impresión: exigir cabecera o etiqueta.
    result.fecha_servicio = dateLine ? parseDate(dateLine) : '';

    const startLabel = /(?:llegada\s+a\s+(?:la\s+)?instalacion\s+del\s+cliente|hora\s+de\s+inicio|\binicio\b)/i;
    const endLabel = /(?:salida\s+de\s+(?:la\s+)?instalacion\s+del\s+cliente|hora\s+final|\bfinal\b)/i;
    const afterLabel = (line, label) => {
        const match = label.exec(line);
        if (!match) return '';
        return line.slice(match.index + match[0].length).split(/(?:llegada|salida|hora de inicio|hora final)/i)[0];
    };
    const startLine = lines.find(line => parseTime(afterLabel(line, startLabel)));
    const endLine = lines.find(line => parseTime(afterLabel(line, endLabel)));
    result.hora_inicio = startLine ? parseTime(afterLabel(startLine, startLabel)) : '';
    result.hora_final = endLine ? parseTime(afterLabel(endLine, endLabel)) : '';
    return result;
}

export const FOCUS_ZONES = Object.freeze({
    fecha_servicio: {x: 0.68, y: 0.025, width: 0.32, height: 0.13},
    equipo: {x: 0.35, y: 0.14, width: 0.43, height: 0.14},
    placa_serie: {x: 0.72, y: 0.14, width: 0.28, height: 0.14},
    hora_inicio: {x: 0.34, y: 0.40, width: 0.33, height: 0.19},
    hora_final: {x: 0.70, y: 0.40, width: 0.30, height: 0.19},
});

export function parseFocusedText(field, text) {
    if (field === 'equipo') return parseEquipment(text, true);
    if (field === 'placa_serie') return parsePlate(text);
    if (field === 'fecha_servicio') return parseDate(text);
    if (field === 'hora_inicio' || field === 'hora_final') return parseTime(text);
    return '';
}
