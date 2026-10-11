<?php
declare(strict_types=1);

/**
 * Clasifica una fecha sin mezclar el estado de la marcación con el día especial.
 * Las correcciones administrativas y el horario efectivo ya están resueltos en la fila.
 */
function attendance_report_classify_day(array $row, ?string $asOfDate = null): array
{
    $asOfDate ??= date('Y-m-d');
    $date = (string) ($row['date'] ?? '');
    $entry = (string) ($row['entry'] ?? '-');
    $exit = (string) ($row['exit'] ?? '-');
    $hasEntry = $entry !== '' && $entry !== '-';
    $hasExit = $exit !== '' && $exit !== '-';
    $state = (string) ($row['state_key'] ?? '');
    $journey = (string) ($row['journey_key'] ?? '');
    $calendarType = (string) ($row['calendar_event_type'] ?? '');
    $calendarName = trim((string) ($row['calendar_event_name'] ?? ''));

    $restKind = null;
    if ($calendarType === 'rest') {
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $calendarName) ?? $calendarName, 'UTF-8');
        $restKind = match ($normalized) {
            'a cuenta de horas' => 'hours',
            'régimen laboral' => 'regime',
            default => 'unclassified',
        };
    }

    $worked = $hasEntry && $state !== 'absent';
    $incomplete = ($hasEntry && !$hasExit && $journey !== 'active') || (!$hasEntry && $hasExit);
    $regular = $worked && $hasExit && $state === 'attended'
        && $journey === 'completed'
        && (int) ($row['late_minutes'] ?? 0) === 0
        && (int) ($row['exit_overtime_minutes'] ?? 0) === 0;
    $futureUnmarked = $date > $asOfDate && !$hasEntry && !$hasExit;

    return [
        'worker_id' => (int) ($row['worker_id'] ?? 0),
        'date' => $date,
        'has_entry' => $hasEntry,
        'has_exit' => $hasExit,
        'worked' => $worked,
        'regular' => $regular,
        'incomplete' => $incomplete,
        'late' => $worked && (
            in_array($state, ['late', 'late_early_exit'], true)
            || (int) ($row['late_minutes'] ?? 0) > 0
        ),
        'absent' => $state === 'absent' && !$futureUnmarked,
        'pending' => !$hasEntry && !$hasExit && $calendarType === ''
            && ($state === 'pending' || $futureUnmarked),
        'calendar_type' => $calendarType,
        'calendar_event_id' => (int) ($row['calendar_event_id'] ?? 0),
        'rest_kind' => $restKind,
        'worked_minutes' => max(0, (int) ($row['worked_minutes'] ?? 0)),
        'early_overtime_minutes' => max(0, (int) ($row['early_overtime_minutes'] ?? 0)),
        'exit_overtime_minutes' => max(0, (int) ($row['exit_overtime_minutes'] ?? 0)),
    ];
}

/**
 * Produce una sola clasificación por trabajador y fecha. La fila con la pareja
 * entrada/salida prevalece ante registros parciales repetidos.
 */
function attendance_report_reconcile_days(array $rows, ?string $asOfDate = null): array
{
    $days = [];
    $scores = [];
    $duplicates = [];
    foreach ($rows as $row) {
        $day = attendance_report_classify_day($row, $asOfDate);
        $key = $day['worker_id'] . '|' . $day['date'];
        if ($day['worker_id'] <= 0 || $day['date'] === '') continue;
        $score = ((int) $day['has_entry'] + (int) $day['has_exit']) * 10
            + (int) $day['worked'] * 2 + (int) ($day['calendar_event_id'] > 0);
        if (isset($days[$key])) {
            $duplicates[$key] = ($duplicates[$key] ?? 1) + 1;
            if ($score <= $scores[$key]) continue;
        }
        $days[$key] = $day;
        $scores[$key] = $score;
    }
    ksort($days);
    ksort($duplicates);
    return ['days' => array_values($days), 'duplicate_days' => $duplicates];
}

/** @return array<string, array<string, int>> Totales de cada mes YYYY-MM. */
function attendance_report_summarize_monthly_days(array $days): array
{
    $months = [];
    foreach ($days as $day) {
        $date = (string) ($day['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        $month = substr($date, 0, 7);
        if (!isset($months[$month])) {
            $months[$month] = array_fill_keys([
                'worked', 'regular', 'incomplete', 'late', 'absent', 'holiday',
                'non_working', 'vacation', 'rest', 'rest_hours', 'rest_regime',
                'rest_unclassified', 'pending',
            ], 0);
        }
        foreach (['worked', 'regular', 'incomplete', 'late', 'absent', 'pending'] as $key) {
            $months[$month][$key] += (int) ($day[$key] ?? false);
        }
        $calendarType = (string) ($day['calendar_type'] ?? '');
        if (in_array($calendarType, ['holiday', 'non_working', 'vacation'], true)) {
            $months[$month][$calendarType]++;
        } elseif ($calendarType === 'rest') {
            $months[$month]['rest']++;
            $restKey = match ($day['rest_kind'] ?? null) {
                'hours' => 'rest_hours',
                'regime' => 'rest_regime',
                default => 'rest_unclassified',
            };
            $months[$month][$restKey]++;
        }
    }
    ksort($months);
    return $months;
}
