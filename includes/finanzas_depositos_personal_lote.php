<?php
declare(strict_types=1);

/** @return array{0:int,1:int,2:int,3:int}|null */
function personal_deposit_batch_region(mixed $raw): ?array
{
    if (!is_array($raw) || array_keys($raw) !== [0, 1, 2, 3]) return null;
    $region = [];
    foreach ($raw as $part) {
        if (!is_int($part) && !(is_string($part) && ctype_digit($part))) return null;
        $value = (int) $part;
        if ($value < 0 || $value > 1000) return null;
        $region[] = $value;
    }
    [$x1, $y1, $x2, $y2] = $region;
    // Una fila debe ser legible, pero no abarcar otras filas o el resumen del lote.
    if ($x2 - $x1 < 200 || $y2 - $y1 < 12 || $y2 - $y1 > 70) return null;
    return $region;
}

/**
 * Ubica las filas del listado de Interbank mediante sus separadores horizontales.
 * Si la estructura no es inequívoca, se rechaza el lote para no asociar una foto ajena.
 * @return array<int, array{0:int,1:int,2:int,3:int}>|null
 */
function personal_deposit_batch_detect_regions(string $path, int $rowCount): ?array
{
    if ($rowCount < 2 || $rowCount > 30 || !function_exists('imagecreatefromstring')) return null;
    $size = @getimagesize($path);
    if (!$size || $size[0] < 500 || $size[1] < 400 || $size[0] * $size[1] > 8000000) return null;
    $bytes = @file_get_contents($path);
    $image = $bytes === false ? false : @imagecreatefromstring($bytes);
    unset($bytes);
    if (!$image) return null;
    $width = imagesx($image);
    $height = imagesy($image);
    $startX = (int) ($width * .05);
    $endX = (int) ($width * .97);
    $step = max(8, (int) (($endX - $startX) / 70));
    $lines = [];
    $runStart = null;
    for ($y = (int) ($height * .35); $y < (int) ($height * .95); $y++) {
        $samples = 0;
        $grey = 0;
        for ($x = $startX; $x < $endX; $x += $step) {
            $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            $samples++;
            if ($rgb['red'] >= 205 && $rgb['red'] <= 248
                && abs($rgb['red'] - $rgb['green']) <= 10
                && abs($rgb['red'] - $rgb['blue']) <= 10) $grey++;
        }
        $isLine = $samples > 10 && $grey / $samples >= .80;
        if ($isLine && $runStart === null) $runStart = $y;
        if (!$isLine && $runStart !== null) {
            if ($y - $runStart <= 2) $lines[] = $runStart;
            $runStart = null;
        }
    }
    imagedestroy($image);
    if (count($lines) < $rowCount + 1) return null;
    for ($start = count($lines) - $rowCount - 1; $start >= 0; $start--) {
        $window = array_slice($lines, $start, $rowCount + 1);
        $gaps = [];
        for ($i = 0; $i < $rowCount; $i++) $gaps[] = $window[$i + 1] - $window[$i];
        $average = array_sum($gaps) / count($gaps);
        if ($average < 25 || $average > min(150, $height * .18)) continue;
        if (max($gaps) - min($gaps) > max(14, $average * .25)) continue;
        $regions = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $top = (int) round(($window[$i] + 1) * 1000 / $height);
            $bottom = (int) round(($window[$i + 1] + 1) * 1000 / $height);
            $region = [25, $top, 980, $bottom];
            if (!personal_deposit_batch_region($region)) continue 2;
            $regions[] = $region;
        }
        return $regions;
    }
    return null;
}

/**
 * Detecta las filas del formato Interbank con cabecera verde y sin líneas
 * separadoras. Cada importe de la columna Monto marca una fila distinta.
 * @return array<int, array{0:int,1:int,2:int,3:int}>|null
 */
function personal_deposit_batch_detect_green_regions(string $path, int $rowCount): ?array
{
    if ($rowCount < 2 || $rowCount > 30 || !function_exists('imagecreatefromstring')) return null;
    $size = @getimagesize($path);
    if (!$size || $size[0] < 500 || $size[1] < 400 || $size[0] * $size[1] > 8000000) return null;
    $bytes = @file_get_contents($path);
    $image = $bytes === false ? false : @imagecreatefromstring($bytes);
    unset($bytes);
    if (!$image) return null;
    $width = imagesx($image);
    $height = imagesy($image);
    $amountX1 = (int) ($width * .60);
    $amountX2 = (int) ($width * .72);
    $header = [];
    for ($y = (int) ($height * .35); $y < (int) ($height * .75); $y++) {
        $green = 0;
        $samples = 0;
        for ($x = $amountX1; $x < $amountX2; $x += 5) {
            $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            $samples++;
            if ($rgb['green'] > 80 && $rgb['green'] > $rgb['red'] * 1.5
                && $rgb['green'] > $rgb['blue'] * 1.5 && $rgb['red'] < 90) $green++;
        }
        if ($samples > 10 && $green / $samples > .8) $header[] = $y;
    }
    if (count($header) < 20 || max($header) - min($header) > 100) { imagedestroy($image); return null; }
    $headerBottom = max($header);
    $lines = [];
    for ($y = $headerBottom + 2; $y < (int) ($height * .92); $y++) {
        $dark = 0;
        for ($x = $amountX1; $x < $amountX2; $x += 2) {
            $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            if ($rgb['red'] < 130 && $rgb['green'] < 130 && $rgb['blue'] < 130) $dark++;
        }
        if ($dark >= 2) $lines[] = $y;
    }
    $bands = [];
    foreach ($lines as $y) {
        $last = count($bands) - 1;
        if ($last >= 0 && $y - $bands[$last][1] <= 12) $bands[$last][1] = $y;
        else $bands[] = [$y, $y];
    }
    $bands = array_values(array_filter($bands, static fn(array $band): bool => $band[1] - $band[0] >= 7));
    if (count($bands) !== $rowCount) { imagedestroy($image); return null; }
    $centers = array_map(static fn(array $band): float => ($band[0] + $band[1]) / 2, $bands);
    $nameLines = [];
    for ($y = $headerBottom + 2; $y < (int) ($height * .92); $y++) {
        $dark = 0;
        for ($x = (int) ($width * .07); $x < (int) ($width * .27); $x += 2) {
            $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            if ($rgb['red'] < 130 && $rgb['green'] < 130 && $rgb['blue'] < 130) $dark++;
        }
        if ($dark >= 2) $nameLines[] = $y;
    }
    imagedestroy($image);
    $nameBands = [];
    foreach ($nameLines as $y) {
        $last = count($nameBands) - 1;
        if ($last >= 0 && $y - $nameBands[$last][1] <= 5) $nameBands[$last][1] = $y;
        else $nameBands[] = [$y, $y];
    }
    $nameBands = array_values(array_filter($nameBands, static fn(array $band): bool => $band[1] - $band[0] >= 4));
    $starts = [];
    foreach ($centers as $center) {
        $best = null;
        $distance = 26;
        foreach ($nameBands as $index => $band) {
            $gap = abs(($band[0] + $band[1]) / 2 - $center);
            if ($gap < $distance) { $distance = $gap; $best = $index; }
        }
        if ($best === null || ($starts && $best <= end($starts))) return null;
        $starts[] = $best;
    }
    $boundaries = [$headerBottom + 2];
    for ($i = 1; $i < $rowCount; $i++) {
        $previousEnd = $nameBands[$starts[$i] - 1][1] ?? null;
        $nextStart = $nameBands[$starts[$i]][0];
        if ($previousEnd === null || $nextStart - $previousEnd < 8) return null;
        $boundaries[] = (int) floor(($previousEnd + $nextStart) / 2);
    }
    $boundaries[] = min($height, $nameBands[count($nameBands) - 1][1] + 20);
    $regions = [];
    for ($i = 0; $i < $rowCount; $i++) {
        if ($bands[$i][0] < $boundaries[$i] || $bands[$i][1] >= $boundaries[$i + 1]) return null;
        $region = [25, (int) round($boundaries[$i] * 1000 / $height), 980,
            (int) round($boundaries[$i + 1] * 1000 / $height)];
        if (!personal_deposit_batch_region($region)) return null;
        $regions[] = $region;
    }
    return $regions;
}

/**
 * Genera un JPEG opaco con solamente la fila autorizada. Nunca expone el archivo original.
 * @param array{0:int,1:int,2:int,3:int} $region
 */
function personal_deposit_batch_redacted_jpeg(string $path, array $region, bool $greenLayout = false): ?string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return null;
    $size = @getimagesize($path);
    if (!$size || $size[0] < 100 || $size[1] < 100 || $size[0] * $size[1] > 8000000) return null;
    $bytes = @file_get_contents($path);
    if ($bytes === false) return null;
    $source = @imagecreatefromstring($bytes);
    unset($bytes);
    if (!$source) return null;
    $width = imagesx($source);
    $height = imagesy($source);
    if ($width < 100 || $height < 100 || $width * $height > 8000000) {
        imagedestroy($source);
        return null;
    }
    $target = imagecreatetruecolor($width, $height);
    if (!$target) {
        imagedestroy($source);
        return null;
    }
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefill($target, 0, 0, $white);
    [$x1, $y1, $x2, $y2] = $region;
    $left = (int) floor($x1 * $width / 1000);
    $top = (int) floor($y1 * $height / 1000);
    $right = min($width, (int) ceil($x2 * $width / 1000));
    $bottom = min($height, (int) ceil($y2 * $height / 1000));
    // Interbank separa las filas con una línea horizontal gris. Si no se puede
    // localizar el límite de la fila, no entregamos ningún fragmento original.
    $lineFound = $greenLayout;
    $sampleStart = max($left, (int) ($width * .05));
    $sampleEnd = min($right, (int) ($width * .98));
    $step = max(8, (int) (($sampleEnd - $sampleStart) / 70));
    $scanEnd = min($height, $bottom + max(10, (int) ($height * .015)));
    for ($y = $top + max(10, (int) ($height * .015)); !$greenLayout && $y < $scanEnd; $y++) {
        $samples = 0;
        $linePixels = 0;
        for ($x = $sampleStart; $x < $sampleEnd; $x += $step) {
            $rgb = imagecolorsforindex($source, imagecolorat($source, $x, $y));
            $samples++;
            if ($rgb['red'] >= 205 && $rgb['red'] <= 248
                && abs($rgb['red'] - $rgb['green']) <= 10
                && abs($rgb['red'] - $rgb['blue']) <= 10) $linePixels++;
        }
        if ($samples > 10 && $linePixels / $samples >= .80) {
            $bottom = $y + 1;
            $lineFound = true;
            break;
        }
    }
    if (!$lineFound) { imagedestroy($target); imagedestroy($source); return null; }
    imagecopy($target, $source, $left, $top, $left, $top, $right - $left, $bottom - $top);
    imagedestroy($source);
    ob_start();
    $ok = imagejpeg($target, null, 88);
    $jpeg = ob_get_clean();
    imagedestroy($target);
    return $ok && is_string($jpeg) ? $jpeg : null;
}
