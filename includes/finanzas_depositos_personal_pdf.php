<?php
declare(strict_types=1);

/**
 * Conserva la página original de Interbank y elimina del flujo PDF el texto de
 * otros beneficiarios. Solo admite este formato simple, de una página y con
 * contenido FlateDecode; ante cualquier duda devuelve null.
 */
function personal_deposit_batch_redacted_pdf(string $path, int $rowIndex, int $rowCount, string $date): ?string
{
    if ($rowCount < 2 || $rowCount > 30 || $rowIndex < 0 || $rowIndex >= $rowCount) return null;
    $source = @file_get_contents($path);
    if (!is_string($source) || strlen($source) > 5 * 1024 * 1024 || !str_starts_with($source, '%PDF-')
        || str_contains($source, '/Encrypt') || str_contains($source, '/ObjStm')
        || str_contains($source, '/Annots') || str_contains($source, '/EmbeddedFiles')
        || str_contains($source, '/Metadata') || str_contains($source, '/AcroForm')) return null;
    if (!preg_match_all('/(\d+) 0 obj\b.*?endobj/s', $source, $objectMatches, PREG_SET_ORDER)
        || count($objectMatches) > 100) return null;
    $objects = [];
    $pageObject = null;
    foreach ($objectMatches as $match) {
        $number = (int) $match[1];
        if ($number < 1 || isset($objects[$number])) return null;
        $objects[$number] = $match[0];
        if (str_contains($match[0], '/Subtype/Image')
            && (!preg_match('/\/Height\s+(\d+)/', $match[0], $imageHeight) || (int) $imageHeight[1] > 300)) return null;
        if (preg_match('/\/Type\s*\/Page\b/', $match[0])) {
            if ($pageObject !== null) return null;
            $pageObject = $match[0];
        }
    }
    if ($pageObject === null || !preg_match('/\/Contents\s+(\d+)\s+0\s+R/', $pageObject, $contentRef)
        || !preg_match('/\/Root\s+(\d+)\s+0\s+R/', $source, $rootRef)) return null;
    $contentId = (int) $contentRef[1];
    $rootId = (int) $rootRef[1];
    if (!isset($objects[$contentId], $objects[$rootId])) return null;
    $contentObject = $objects[$contentId];
    if (!preg_match('/^(\d+ 0 obj\s*<<)(.*?)(>>\s*stream\r?\n)(.*?)(\r?\nendstream\s*endobj)$/s', $contentObject, $parts)
        || !str_contains($parts[2], '/FlateDecode') || !preg_match('/\/Length\s+\d+/', $parts[2])) return null;
    $decoded = @gzuncompress($parts[4]);
    if (!is_string($decoded) || strlen($decoded) > 2000000 || preg_match('/\bTJ\b/', $decoded)) return null;

    // El primer campo de cada fila está en x=41 en este formato. Los saltos
    // entre líneas del mismo beneficiario son menores que entre beneficiarios.
    if (!preg_match_all('/1 0 0 1 41 ([\d.]+) Tm\s*(?:\/F\d+ \d+ Tf\s*)?(?:[\d.]+ [\d.]+ [\d.]+ rg\s*|\d+ g\s*)?\((.*?)\)Tj/s',
        $decoded, $nameMatches, PREG_SET_ORDER)) return null;
    $nameLines = [];
    foreach ($nameMatches as $match) {
        $y = (float) $match[1];
        if ($y > 100 && $y < 355 && trim($match[2]) !== '') $nameLines[] = $y;
    }
    if (!$nameLines) return null;
    $groups = [];
    foreach ($nameLines as $y) {
        $last = count($groups) - 1;
        if ($last >= 0 && $groups[$last][1] - $y < 15) $groups[$last][1] = $y;
        else $groups[] = [$y, $y];
    }
    if (count($groups) !== $rowCount) return null;
    $upper = $rowIndex === 0 ? 352.0 : ($groups[$rowIndex - 1][1] + $groups[$rowIndex][0]) / 2;
    $lower = $rowIndex === $rowCount - 1 ? $groups[$rowIndex][1] - 10 : ($groups[$rowIndex][1] + $groups[$rowIndex + 1][0]) / 2;
    if ($upper <= $lower || $upper - $lower > 75) return null;

    $labels = array_fill_keys([
        'Detalle de solicitud', 'Información de la solicitud', 'Creación', 'Autorizadores',
        'Programación de envío', 'Canal', 'Información de cargo', 'Datos de cargo',
        'Información de lote', 'Estado', 'Nombre de lote', 'Número de operación',
        'Total soles', 'Total dólares', 'Beneficiario', 'Tipo de abono', 'Cuenta',
        'Monto', 'Observación',
    ], true);
    $tokens = preg_match_all('/1 0 0 1 [\d.]+ ([\d.]+) Tm|\((?:\\\\.|[^\\)])*\)Tj/s',
        $decoded, $commands, PREG_OFFSET_CAPTURE);
    if (!$tokens) return null;
    $redacted = '';
    $cursor = 0;
    $currentY = null;
    $keptOwn = 0;
    foreach ($commands[0] as $index => [$command, $offset]) {
        $redacted .= substr($decoded, $cursor, $offset - $cursor);
        if (str_ends_with($command, ' Tm')) {
            $currentY = (float) $commands[1][$index][0];
            $redacted .= $command;
        } else {
            $value = substr($command, 1, -3);
            $inOwnRow = $currentY !== null && $currentY <= $upper && $currentY >= $lower;
            $label = @iconv('Windows-1252', 'UTF-8//IGNORE', $value) ?: $value;
            $keep = $inOwnRow || isset($labels[$label]) || $value === $date;
            $redacted .= $keep ? $command : '()Tj';
            if ($inOwnRow && trim($value) !== '') $keptOwn++;
        }
        $cursor = $offset + strlen($command);
    }
    $redacted .= substr($decoded, $cursor);
    if ($keptOwn < 3) return null;
    $compressed = gzcompress($redacted, 6);
    if ($compressed === false) return null;
    $dict = preg_replace('/\/Length\s+\d+/', '/Length ' . strlen($compressed), $parts[2], 1);
    if (!is_string($dict)) return null;
    $objects[$contentId] = $parts[1] . $dict . $parts[3] . $compressed . $parts[5];
    if (preg_match('/\/Info\s+(\d+)\s+0\s+R/', $source, $infoRef)) unset($objects[(int) $infoRef[1]]);

    // Se reconstruye la tabla de referencias para no conservar una copia
    // recuperable del flujo original en revisiones o secciones sin usar.
    $header = substr($source, 0, strpos($source, $objectMatches[0][0]));
    if (!str_starts_with($header, '%PDF-')) return null;
    $result = $header;
    $offsets = [];
    $maxId = max(array_keys($objects));
    for ($number = 1; $number <= $maxId; $number++) {
        if (!isset($objects[$number])) continue;
        $offsets[$number] = strlen($result);
        $result .= $objects[$number] . "\n";
    }
    $xrefOffset = strlen($result);
    $result .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
    for ($number = 1; $number <= $maxId; $number++) {
        $result .= isset($offsets[$number])
            ? sprintf('%010d 00000 n ', $offsets[$number]) . "\n"
            : "0000000000 00000 f \n";
    }
    $result .= "trailer\n<</Size " . ($maxId + 1) . '/Root ' . $rootId . " 0 R>>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
    return $result;
}
