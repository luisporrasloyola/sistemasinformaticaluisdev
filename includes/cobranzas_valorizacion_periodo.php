<?php
declare(strict_types=1);

function valorizacion_periodo(array $query, ?string $defaultMonth = null): array
{
    $mode = (string) ($query['periodo'] ?? 'mes');
    if (!in_array($mode, ['mes', 'fechas'], true)) $mode = 'mes';

    $month = (string) ($query['mes'] ?? $defaultMonth ?? date('Y-m'));
    $from = (string) ($query['desde'] ?? '');
    $to = (string) ($query['hasta'] ?? '');
    $start = null;
    $end = null;

    if ($mode === 'mes') {
        $parsed = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01') : false;
        if (!$parsed || $parsed->format('Y-m') !== $month) throw new InvalidArgumentException('Seleccione un mes válido.');
        $start = $parsed->format('Y-m-d');
        $end = $parsed->format('Y-m-t');
    } elseif ($mode === 'fechas') {
        foreach ([$from, $to] as $date) {
            $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Seleccione fechas válidas.');
        }
        if ($from > $to) throw new InvalidArgumentException('La fecha inicial no puede ser posterior a la final.');
        if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 366) {
            throw new InvalidArgumentException('Seleccione un periodo de hasta 12 meses.');
        }
        $start = $from;
        $end = $to;
    }

    return ['mode' => $mode, 'month' => $month, 'from' => $from, 'to' => $to, 'start' => $start, 'end' => $end];
}
