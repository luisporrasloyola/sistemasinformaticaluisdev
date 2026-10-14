<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_once __DIR__ . '/../../includes/attendance_report_closure.php';

require_role('Administrador');
require_module_access('control_personal.reporte_asistencias');
verify_csrf($_POST['csrf_token'] ?? null);
header('Content-Type: application/json; charset=utf-8');

$workerId = (int) ($_POST['worker_id'] ?? 0);
$month = trim((string) ($_POST['month'] ?? ''));
$rawHours = $_POST['hours'] ?? null;
$rawMinutes = $_POST['minutes'] ?? null;
$reason = trim((string) ($_POST['reason'] ?? ''));
if ($workerId <= 0 || $month !== '2026-10') json_response(['ok' => false, 'message' => 'El trabajador o el mes no son válidos.'], 422);
if (!is_string($rawHours) || !preg_match('/^\d{1,4}$/D', $rawHours)) {
    json_response(['ok' => false, 'message' => 'Ingrese las horas como un número entero entre 0 y 9999.'], 422);
}
if (!is_string($rawMinutes) || !preg_match('/^\d{1,2}$/D', $rawMinutes) || (int) $rawMinutes > 59) {
    json_response(['ok' => false, 'message' => 'Ingrese los minutos como un número entre 0 y 59.'], 422);
}
if ($reason === '' || mb_strlen($reason) > 500) {
    json_response(['ok' => false, 'message' => 'Escriba un motivo para el ajuste (máximo 500 caracteres).'], 422);
}
$hours = (int) $rawHours;
$minutes = (int) $rawMinutes;

$newMinutes = $hours * 60 + $minutes;
$actorId = (int) (current_user()['id'] ?? 0);
if ($actorId <= 0) json_response(['ok' => false, 'message' => 'No se pudo identificar al administrador.'], 403);
$pdo = db();

try {
    $pdo->beginTransaction();
    $workerStmt = $pdo->prepare('SELECT id FROM workers WHERE id=:worker_id FOR UPDATE');
    $workerStmt->execute(['worker_id' => $workerId]);
    if (!$workerStmt->fetchColumn()) throw new DomainException('El trabajador no existe.');

    attendance_report_assert_editable($workerId, '2026-10-01', $pdo, true);
    $closedLater = $pdo->prepare("SELECT 1 FROM attendance_report_month_closures
        WHERE worker_id=:worker_id AND report_month>'2026-10'
          AND admin_closed_at IS NOT NULL AND staff_closed_at IS NOT NULL LIMIT 1");
    $closedLater->execute(['worker_id' => $workerId]);
    if ($closedLater->fetchColumn()) {
        throw new DomainException('No se puede ajustar octubre porque un reporte posterior ya fue cerrado por ambas partes.');
    }

    $existingStmt = $pdo->prepare("SELECT opening_minutes FROM attendance_hemp_opening_adjustments
        WHERE worker_id=:worker_id AND report_month='2026-10' FOR UPDATE");
    $existingStmt->execute(['worker_id' => $workerId]);
    $existing = $existingStmt->fetchColumn();
    $previousMinutes = $existing !== false
        ? (int) $existing
        : (int) attendance_report_build_hours_ledger($workerId, '2026-10')['current']['opening_minutes'];
    if ($previousMinutes === $newMinutes) {
        $pdo->commit();
        json_response(['ok' => true, 'message' => 'El saldo HEMP ya tiene ese valor.']);
    }

    $save = $pdo->prepare("INSERT INTO attendance_hemp_opening_adjustments
        (worker_id,report_month,opening_minutes,reason,adjusted_by_user_id)
        VALUES (:worker_id,'2026-10',:opening_minutes,:reason,:actor_id)
        ON DUPLICATE KEY UPDATE opening_minutes=VALUES(opening_minutes), reason=VALUES(reason),
            adjusted_by_user_id=VALUES(adjusted_by_user_id), adjusted_at=CURRENT_TIMESTAMP");
    $save->execute(['worker_id' => $workerId, 'opening_minutes' => $newMinutes,
        'reason' => $reason, 'actor_id' => $actorId]);
    $history = $pdo->prepare("INSERT INTO attendance_hemp_opening_adjustment_history
        (worker_id,report_month,previous_minutes,new_minutes,reason,adjusted_by_user_id)
        VALUES (:worker_id,'2026-10',:previous_minutes,:new_minutes,:reason,:actor_id)");
    $history->execute(['worker_id' => $workerId, 'previous_minutes' => $previousMinutes,
        'new_minutes' => $newMinutes, 'reason' => $reason, 'actor_id' => $actorId]);
    $pdo->commit();
    json_response(['ok' => true, 'message' => 'HEMP de octubre actualizado. Los saldos de octubre y meses siguientes se recalcularán automáticamente.']);
} catch (DomainException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $error->getMessage()], 409);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Error al ajustar HEMP de octubre: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudo guardar el ajuste HEMP. Verifique que esté instalada la actualización SQL.'], 500);
}
