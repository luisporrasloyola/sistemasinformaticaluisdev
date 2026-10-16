<?php
declare(strict_types=1);

/** Mantiene el signo del saldo, incluso cuando los canjes superan las extras. */
function attendance_report_hours_label(int $minutes): string
{
    $sign = $minutes < 0 ? '-' : '';
    $absolute = abs($minutes);
    return sprintf('%s%d h %02d min', $sign, intdiv($absolute, 60), $absolute % 60);
}

/**
 * Libro mensual de un trabajador a partir de jornadas ya conciliadas.
 * El saldo inicial anterior al primer mes conocido es cero; no se inventan
 * horas históricas. Los descansos futuros se muestran como previstos y se
 * descuentan cuando llega su fecha.
 *
 * @return array<string, array<string, int>> Meses YYYY-MM en orden cronológico.
 */
function attendance_report_hours_ledger(
    array $days,
    string $firstMonth,
    string $lastMonth,
    int $openingMinutes = 0,
    ?string $asOfDate = null,
    array $openingOverrides = []
): array {
    foreach ([$firstMonth, $lastMonth] as $month) {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('Mes inválido para el libro de horas.');
        }
    }
    if ($firstMonth > $lastMonth) {
        throw new InvalidArgumentException('El mes inicial supera al final.');
    }
    $asOfDate ??= date('Y-m-d');
    $ledger = [];
    $month = DateTimeImmutable::createFromFormat('!Y-m-d', $firstMonth . '-01');
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $lastMonth . '-01');
    for (; $month <= $end; $month = $month->modify('+1 month')) {
        $key = $month->format('Y-m');
        $ledger[$key] = [
            'opening_minutes' => 0,
            'worked_minutes' => 0,
            'holiday_overtime_minutes' => 0,
            'early_overtime_minutes' => 0,
            'exit_overtime_minutes' => 0,
            'overtime_minutes' => 0,
            'redeemed_days' => 0,
            'redeemed_saturday_days' => 0,
            'redeemed_minutes' => 0,
            'planned_redemption_days' => 0,
            'closing_minutes' => 0,
        ];
    }

    foreach ($days as $day) {
        $date = (string) ($day['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        $key = substr($date, 0, 7);
        if (!isset($ledger[$key])) continue;
        $ledger[$key]['worked_minutes'] += max(0, (int) ($day['worked_minutes'] ?? 0));
        $ledger[$key]['holiday_overtime_minutes'] += max(0, (int) ($day['holiday_overtime_minutes'] ?? 0));
        $ledger[$key]['early_overtime_minutes'] += max(0, (int) ($day['early_overtime_minutes'] ?? 0));
        $ledger[$key]['exit_overtime_minutes'] += max(0, (int) ($day['exit_overtime_minutes'] ?? 0));
        if (($day['calendar_type'] ?? '') === 'rest' && ($day['rest_kind'] ?? '') === 'hours') {
            if ($date <= $asOfDate) {
                $ledger[$key]['redeemed_days']++;
                $isSaturday = (int) (new DateTimeImmutable($date))->format('N') === 6;
                $ledger[$key]['redeemed_minutes'] += $isSaturday ? 360 : 510;
                if ($isSaturday) $ledger[$key]['redeemed_saturday_days']++;
            } else {
                $ledger[$key]['planned_redemption_days']++;
            }
        }
    }

    $balance = $openingMinutes;
    foreach ($ledger as $key => &$totals) {
        if (array_key_exists($key, $openingOverrides)) {
            $balance = (int) $openingOverrides[$key];
        }
        $totals['opening_minutes'] = $balance;
        $totals['overtime_minutes'] = $totals['holiday_overtime_minutes'] + $totals['early_overtime_minutes'] + $totals['exit_overtime_minutes'];
        $balance += $totals['overtime_minutes'] - $totals['redeemed_minutes'];
        $totals['closing_minutes'] = $balance;
    }
    unset($totals);
    return $ledger;
}
