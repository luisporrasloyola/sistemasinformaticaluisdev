<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function attendance_report_valid_month(string $month): bool
{
    return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
}

/** @return array<string, mixed> */
function attendance_report_closure_status(int $workerId, string $month, ?PDO $pdo = null, bool $lock = false): array
{
    if ($workerId <= 0 || !attendance_report_valid_month($month)) {
        throw new InvalidArgumentException('Trabajador o mes inválido.');
    }
    $pdo ??= db();
    if ($lock) {
        $workerLock = $pdo->prepare('SELECT id FROM workers WHERE id = :id FOR UPDATE');
        $workerLock->execute(['id' => $workerId]);
        if (!$workerLock->fetchColumn()) throw new DomainException('El trabajador ya no existe.');
    }
    $sql = 'SELECT c.admin_user_id, c.admin_closed_at, c.staff_user_id, c.staff_closed_at,
                admin.name AS admin_name, staff.name AS staff_name
            FROM attendance_report_month_closures c
            LEFT JOIN users admin ON admin.id = c.admin_user_id
            LEFT JOIN users staff ON staff.id = c.staff_user_id
            WHERE c.worker_id = :worker_id AND c.report_month = :report_month';
    if ($lock) $sql .= ' FOR UPDATE';
    $schemaMissing = false;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['worker_id' => $workerId, 'report_month' => $month]);
        $row = $stmt->fetch() ?: [];
    } catch (PDOException $error) {
        if ($error->getCode() !== '42S02') throw $error;
        $row = [];
        $schemaMissing = true;
    }
    return [
        'admin_closed_at' => $row['admin_closed_at'] ?? null,
        'admin_name' => $row['admin_name'] ?? null,
        'staff_closed_at' => $row['staff_closed_at'] ?? null,
        'staff_name' => $row['staff_name'] ?? null,
        'fully_closed' => !empty($row['admin_closed_at']) && !empty($row['staff_closed_at']),
        'schema_missing' => $schemaMissing,
    ];
}

function attendance_report_assert_editable(int $workerId, string $date, ?PDO $pdo = null, bool $lock = false): void
{
    $status = attendance_report_closure_status($workerId, substr($date, 0, 7), $pdo, $lock);
    if ($status['fully_closed']) {
        throw new DomainException('El reporte de este trabajador y mes ya fue cerrado por ambas partes. No admite correcciones.');
    }
}

function attendance_report_assert_range_editable(int $workerId, string $from, string $to, ?PDO $pdo = null, bool $lock = false): void
{
    $month = new DateTimeImmutable(substr($from, 0, 7) . '-01');
    $end = new DateTimeImmutable(substr($to, 0, 7) . '-01');
    for (; $month <= $end; $month = $month->modify('+1 month')) {
        attendance_report_assert_editable($workerId, $month->format('Y-m-d'), $pdo, $lock);
    }
}

/** Bloquea cambios de calendario que alcanzarían cualquier reporte cerrado. */
function attendance_report_assert_calendar_editable(string $from, string $to, array $workerIds = [], ?PDO $pdo = null): void
{
    $pdo ??= db();
    $params = ['from_month' => substr($from, 0, 7), 'to_month' => substr($to, 0, 7)];
    $sql = 'SELECT 1 FROM attendance_report_month_closures WHERE report_month BETWEEN :from_month AND :to_month
        AND admin_closed_at IS NOT NULL AND staff_closed_at IS NOT NULL';
    if ($workerIds) {
        $ids = array_values(array_unique(array_map('intval', $workerIds)));
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = 'worker_' . $index;
            $params[$key] = $id;
            $placeholders[] = ':' . $key;
        }
        $sql .= ' AND worker_id IN (' . implode(',', $placeholders) . ')';
    }
    try {
        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
    } catch (PDOException $error) {
        if ($error->getCode() === '42S02') return;
        throw $error;
    }
    if ($stmt->fetchColumn()) {
        throw new DomainException('El cambio afectaría un reporte mensual cerrado por ambas partes.');
    }
}

function attendance_report_assert_schedule_editable(int $scheduleId, ?PDO $pdo = null): void
{
    if ($scheduleId <= 0) return;
    $pdo ??= db();
    $sql = "SELECT 1 FROM attendance_report_month_closures c
        WHERE c.admin_closed_at IS NOT NULL AND c.staff_closed_at IS NOT NULL
          AND (
            EXISTS (SELECT 1 FROM attendance_assignments aa
                WHERE aa.worker_id = c.worker_id AND aa.schedule_id = :assignment_schedule
                  AND aa.valid_from <= LAST_DAY(CONCAT(c.report_month, '-01'))
                  AND (aa.valid_until IS NULL OR aa.valid_until >= CONCAT(c.report_month, '-01')))
            OR EXISTS (SELECT 1 FROM attendance_marks am
                WHERE am.worker_id = c.worker_id AND am.schedule_id = :mark_schedule
                  AND am.mark_date BETWEEN CONCAT(c.report_month, '-01') AND LAST_DAY(CONCAT(c.report_month, '-01')))
          ) LIMIT 1";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['assignment_schedule' => $scheduleId, 'mark_schedule' => $scheduleId]);
    } catch (PDOException $error) {
        if ($error->getCode() === '42S02') return;
        throw $error;
    }
    if ($stmt->fetchColumn()) {
        throw new DomainException('Este horario se utiliza en un reporte mensual cerrado. Cree otra plantilla para cambios futuros.');
    }
}
