<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_labor_cycle.php';
require_once __DIR__ . '/attendance_report_closure.php';

/** Se ejecuta dentro de la transacción del cambio de asistencia/calendario. */
function attendance_labor_regime_correct(PDO $pdo, int $workerId, string $date, string $regime, string $reason, ?int $actorId): void
{
    if (!in_array($regime, ['regular', '21x7'], true) || $reason === '' || mb_strlen($reason) > 500) {
        throw new DomainException('Indique el régimen y el motivo de la corrección.');
    }
    $today = date('Y-m-d');
    attendance_report_reopen_for_correction($pdo, $workerId, $date, max($date, $today), $reason, $actorId);
    // El reporte solo genera filas para fechas con una asignación aplicable o una marcación.
    $assignment = $pdo->prepare('SELECT 1 FROM attendance_assignments
        WHERE worker_id = ? AND status = 1 AND valid_from <= ?
          AND (valid_until IS NULL OR valid_until >= ?)
          AND (deactivated_at IS NULL OR DATE(deactivated_at) >= ?)
        LIMIT 1');
    $assignment->execute([$workerId, $date, $date, $date]);
    if (!$assignment->fetchColumn()) {
        $marked = $pdo->prepare('SELECT 1 FROM attendance_marks WHERE worker_id = ? AND mark_date = ? LIMIT 1');
        $marked->execute([$workerId, $date]);
        if (!$marked->fetchColumn()) {
            throw new DomainException('No hay una asignación ni marcación para esta fecha. Asigne primero un horario al trabajador.');
        }
    }
    $history = attendance_labor_cycle_history($pdo, [$workerId], $date);
    $previous = attendance_labor_cycle_state($history[$workerId] ?? [], $date)['regime'];
    $stmt = $pdo->prepare('INSERT INTO attendance_labor_regimes
        (worker_id, work_date, regime, selected_by_user_id)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE regime = VALUES(regime), selected_by_user_id = VALUES(selected_by_user_id),
            allow_entry_overtime = 0, allow_exit_overtime = 0');
    $stmt->execute([$workerId, $date, $regime, $actorId]);
    $audit = $pdo->prepare('INSERT INTO attendance_labor_regime_audit
        (worker_id, work_date, previous_regime, new_regime, reason, adjusted_by_user_id)
        VALUES (?, ?, ?, ?, ?, ?)');
    $audit->execute([$workerId, $date, $previous, $regime, $reason, $actorId]);
}
