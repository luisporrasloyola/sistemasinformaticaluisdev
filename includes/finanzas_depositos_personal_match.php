<?php

/** @return array<int, array{value:string, masked:bool}> */
function personal_deposit_name_tokens(string $name): array
{
    $name = mb_strtoupper($name, 'UTF-8');
    $name = strtr($name, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    preg_match_all('/[A-Z0-9]+\**/u', $name, $matches);
    $tokens = [];
    foreach ($matches[0] as $raw) {
        $value = rtrim($raw, '*');
        if ($value === '' || in_array($value, ['DE', 'DEL', 'LA', 'LAS', 'LOS', 'Y'], true)) continue;
        $tokens[] = ['value' => $value, 'masked' => str_contains($raw, '*')];
    }
    return $tokens;
}

function personal_deposit_matches_name(string $creditor, string $workerName): bool
{
    $creditorTokens = personal_deposit_name_tokens($creditor);
    $workerTokens = personal_deposit_name_tokens($workerName);
    if (count($creditorTokens) < 2 || count($creditorTokens) > count($workerTokens)) return false;
    $strongTokens = 0;
    foreach ($creditorTokens as $token) {
        if (strlen($token['value']) >= 3) $strongTokens++;
        if ($token['masked'] && strlen($token['value']) < 3) return false;
    }
    if ($strongTokens < 2) return false;

    $assign = static function (int $index, array $used) use (&$assign, $creditorTokens, $workerTokens): bool {
        if ($index === count($creditorTokens)) return true;
        $token = $creditorTokens[$index];
        foreach ($workerTokens as $position => $workerToken) {
            if (isset($used[$position])) continue;
            $namePart = $workerToken['value'];
            $matches = $token['masked'] || strlen($token['value']) === 1
                ? str_starts_with($namePart, $token['value'])
                : $namePart === $token['value'];
            // Algunos bancos cortan el último nombre por el ancho del comprobante.
            // Exigir seis letras y todas las demás palabras para evitar coincidencias débiles.
            if (!$matches && $index === count($creditorTokens) - 1
                && strlen($token['value']) >= 6 && strlen($namePart) > strlen($token['value'])) {
                $matches = str_starts_with($namePart, $token['value']);
            }
            if ($matches && $assign($index + 1, $used + [$position => true])) return true;
        }
        return false;
    };
    return $assign(0, []);
}

/** @param array<int, array{id:int|string, full_name:string}> $workers */
function personal_deposit_unique_worker_id(string $creditor, array $workers): ?int
{
    $match = null;
    foreach ($workers as $worker) {
        if (!personal_deposit_matches_name($creditor, (string) $worker['full_name'])) continue;
        if ($match !== null) return null;
        $match = (int) $worker['id'];
    }
    return $match;
}

/**
 * Reconcilia nombres que el OCR separa dentro de una palabra (CO RAC -> CORAC)
 * o que omiten un segundo nombre del trabajador.
 * @param array<int, array{id:int|string, full_name:string}> $workers
 * @return array{id:int, creditor:string}|null
 */
function personal_deposit_batch_worker(string $creditor, array $workers): ?array
{
    // El PDF de Interbank divide nombres entre líneas y a veces Gemini añade
    // el documento de identidad que figura debajo del beneficiario.
    $creditor = preg_split('/\b(?:DNI|C\.?E\.?|DOCUMENTO|DOC\.?|RUC)\b\s*[:#.-]?/iu', $creditor, 2)[0] ?? $creditor;
    $creditor = preg_replace('/\b\d{6,12}\b/u', ' ', $creditor) ?? $creditor;
    $tokens = personal_deposit_name_tokens($creditor);
    if (count($tokens) < 2) return null;
    $collapsed = implode('', array_column($tokens, 'value'));
    $matches = [];
    $near = [];
    $subsetMatches = [];
    foreach ($workers as $worker) {
        $workerTokens = personal_deposit_name_tokens((string) $worker['full_name']);
        if (count($workerTokens) < 2) continue;
        $workerCollapsed = implode('', array_column($workerTokens, 'value'));
        // Los nombres completos del padrón son la referencia; los espacios
        // interiores y saltos de línea del PDF no cambian la identidad.
        $candidate = [
            'id' => (int) $worker['id'],
            'creditor' => (string) $worker['full_name'],
        ];
        if ($workerCollapsed === $collapsed) {
            $matches[(int) $worker['id']] = $candidate;
            continue;
        }
        $wordCount = count($workerTokens);
        if ($wordCount >= 4 && $wordCount <= 8) {
            for ($mask = 1; $mask < (1 << $wordCount); $mask++) {
                if (!($mask & 1) || !($mask & (1 << ($wordCount - 1)))) continue;
                $selected = [];
                foreach ($workerTokens as $position => $part) {
                    if ($mask & (1 << $position)) $selected[] = $part['value'];
                }
                if (count($selected) < 3 || $wordCount - count($selected) > 2) continue;
                if (implode('', $selected) === $collapsed) {
                    $subsetMatches[$candidate['id']] = $candidate;
                    break;
                }
            }
        }
        // Se aceptan pequeñas diferencias de lectura en un nombre largo
        // únicamente si el primer nombre coincide y el candidato es único.
        $first = $workerTokens[0]['value'];
        if ($tokens[0]['value'] !== $first || strlen($first) < 4) continue;
        $distance = levenshtein($collapsed, $workerCollapsed);
        $maxDistance = max(2, (int) floor(strlen($workerCollapsed) * .12));
        if ($distance <= $maxDistance) $near[] = ['distance' => $distance, 'worker' => $candidate];
    }
    if ($matches) return count($matches) === 1 ? array_values($matches)[0] : null;
    if ($subsetMatches) return count($subsetMatches) === 1 ? array_values($subsetMatches)[0] : null;
    usort($near, static fn(array $a, array $b): int => $a['distance'] <=> $b['distance']);
    if (!$near || (isset($near[1]) && $near[1]['distance'] - $near[0]['distance'] < 3)) return null;
    return $near[0]['worker'];
}

/** @return array<int, array{id:int|string, full_name:string}> */
function personal_deposit_workers(): array
{
    require_once __DIR__ . '/../config/database.php';
    static $workers = null;
    if ($workers === null) {
        $workers = db()->query('SELECT id, full_name FROM workers')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $workers;
}

function personal_deposit_is_own(string $creditor): bool
{
    $workerId = current_user_worker_id();
    return $workerId !== null && personal_deposit_unique_worker_id($creditor, personal_deposit_workers()) === $workerId;
}
