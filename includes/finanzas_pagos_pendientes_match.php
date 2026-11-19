<?php
declare(strict_types=1);

/** Sugiere una deuda cuando comparte un nombre o apellido legible con el depósito. */
function pending_payment_creditor_matches(string $depositCreditor, string $debtCreditor): bool
{
    $tokens = static function (string $name): array {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = strtr($name, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);
        preg_match_all('/[\p{L}\p{N}]+\*?/u', $name, $matches);
        return $matches[0];
    };
    $deposit = $tokens($depositCreditor);
    $debt = $tokens($debtCreditor);
    foreach ($deposit as $word) {
        $masked = str_ends_with($word, '*');
        $value = rtrim($word, '*');
        if (mb_strlen($value, 'UTF-8') < ($masked ? 3 : 4)) continue;
        foreach ($debt as $candidate) {
            if ($masked ? str_starts_with($candidate, $value) : $candidate === $value) return true;
        }
    }
    return false;
}
