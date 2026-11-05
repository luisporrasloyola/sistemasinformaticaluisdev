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
