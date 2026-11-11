<?php
declare(strict_types=1);

/** La jornada 21x7 consta de 21 días A, un día M y siete días D. */
function attendance_labor_cycle_day(?string $start, string $date): ?array
{
    if (!$start || $date < $start) return null;
    $days = (int) (new DateTimeImmutable($start))->diff(new DateTimeImmutable($date))->days;
    $position = $days % 29;
    $code = $position < 21 ? 'A' . ($position + 1) : ($position === 21 ? 'M' : 'D' . ($position - 21));
    return ['code' => $code, 'position' => $position + 1, 'start' => $start,
        'rest' => $position >= 22, 'transition' => $position === 21];
}

/** Una selección regular interrumpe el ciclo; otras marcas 21x7 no reinician su conteo. */
function attendance_labor_cycle_state(array $history, string $date): array
{
    $start = null;
    $daily = null;
    foreach ($history as $row) {
        if ((string) $row['work_date'] > $date) break;
        if ((string) $row['regime'] === 'regular') $start = null;
        elseif ($start === null) $start = (string) $row['work_date'];
        if ((string) $row['work_date'] === $date) $daily = $row;
    }
    return ['regime' => $start === null ? 'regular' : '21x7',
        'cycle' => attendance_labor_cycle_day($start, $date), 'daily' => $daily];
}

/** Una sola lectura indexada para todos los trabajadores visibles. */
function attendance_labor_cycle_history(PDO $pdo, array $workerIds, string $through): array
{
    $workerIds = array_values(array_unique(array_filter(array_map('intval', $workerIds), static fn(int $id): bool => $id > 0)));
    if (!$workerIds) return [];
    $history = [];
    foreach (array_chunk($workerIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("SELECT worker_id, work_date, regime, allow_entry_overtime, allow_exit_overtime
            FROM attendance_labor_regimes WHERE worker_id IN ($placeholders) AND work_date <= ?
            ORDER BY worker_id, work_date");
        $stmt->execute([...$chunk, $through]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $history[(int) $row['worker_id']][] = $row;
    }
    return $history;
}

function attendance_labor_cycle_range(array $history, string $from, string $through): array
{
    $result = [];
    foreach ($history as $workerId => $events) {
        $index = 0;
        $start = null;
        $cursor = new DateTimeImmutable($from);
        $last = new DateTimeImmutable($through);
        while ($cursor <= $last) {
            $date = $cursor->format('Y-m-d');
            $daily = null;
            while (isset($events[$index]) && (string) $events[$index]['work_date'] <= $date) {
                $event = $events[$index++];
                if ((string) $event['regime'] === 'regular') $start = null;
                elseif ($start === null) $start = (string) $event['work_date'];
                if ((string) $event['work_date'] === $date) $daily = $event;
            }
            if ($start !== null || $daily !== null) {
                $result[$workerId][$date] = ['regime' => $start === null ? 'regular' : '21x7',
                    'cycle' => attendance_labor_cycle_day($start, $date), 'daily' => $daily];
            }
            $cursor = $cursor->modify('+1 day');
        }
    }
    return $result;
}
