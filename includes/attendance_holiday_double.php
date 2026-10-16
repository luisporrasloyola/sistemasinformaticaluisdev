<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/** Solo devuelve excepciones: todo feriado sin registro se duplica por defecto. */
function attendance_holiday_double_disabled_between(string $from, string $to, array $workerIds = [], ?PDO $pdo = null): array
{
    $pdo ??= db();
    $params = ['from_date' => $from, 'to_date' => $to];
    $sql = 'SELECT worker_id, work_date FROM attendance_holiday_double_overrides
        WHERE work_date BETWEEN :from_date AND :to_date AND double_enabled = 0';
    if ($workerIds) {
        $placeholders = [];
        foreach (array_values(array_unique(array_map('intval', $workerIds))) as $index => $id) {
            $key = 'worker_' . $index;
            $params[$key] = $id;
            $placeholders[] = ':' . $key;
        }
        $sql .= ' AND worker_id IN (' . implode(',', $placeholders) . ')';
    }
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
    } catch (PDOException $error) {
        if ($error->getCode() === '42S02') return [];
        throw $error;
    }
    $disabled = [];
    foreach ($statement->fetchAll() as $row) {
        $disabled[(int) $row['worker_id']][(string) $row['work_date']] = true;
    }
    return $disabled;
}

/** Separa la marcación real del crédito extraordinario por feriado. */
function attendance_holiday_apply_multiplier(int $actualWorked, int $scheduled, int $earlyExtra, int $exitExtra, bool $enabled, bool $completed): array
{
    return [
        'worked_minutes' => max(0, $actualWorked),
        'holiday_overtime_minutes' => $enabled && $completed ? max(0, $scheduled - 60) * 2 : 0,
        'early_overtime_minutes' => max(0, $earlyExtra) * ($enabled ? 2 : 1),
        'exit_overtime_minutes' => max(0, $exitExtra) * ($enabled ? 2 : 1),
    ];
}
