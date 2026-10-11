<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_closure.php';
require_module_access('control_personal.reporte_asistencias');
verify_csrf($_POST['csrf_token'] ?? null);
header('Content-Type: application/json; charset=utf-8');

$workerId = (int) ($_POST['worker_id'] ?? 0);
$month = trim((string) ($_POST['month'] ?? ''));
if ($workerId <= 0 || !attendance_report_valid_month($month)) {
    json_response(['ok' => false, 'message' => 'Seleccione un trabajador y mes válidos.'], 422);
}
if (!is_admin() && !is_personal_role()) {
    json_response(['ok' => false, 'message' => 'Solo el administrador o el trabajador pueden cerrar el reporte.'], 403);
}
require_personal_own_worker($workerId);

$pdo = db();
try {
    $pdo->beginTransaction();
    $workerStmt = $pdo->prepare('SELECT id FROM workers WHERE id = :id LIMIT 1 FOR UPDATE');
    $workerStmt->execute(['id' => $workerId]);
    if (!$workerStmt->fetchColumn()) {
        throw new DomainException('El trabajador ya no existe.');
    }
    $insert = $pdo->prepare('INSERT IGNORE INTO attendance_report_month_closures (worker_id, report_month) VALUES (:worker_id, :report_month)');
    $insert->execute(['worker_id' => $workerId, 'report_month' => $month]);
    $status = attendance_report_closure_status($workerId, $month, $pdo, true);
    $ownField = is_admin() ? 'admin_closed_at' : 'staff_closed_at';
    if ($status[$ownField]) {
        $pdo->commit();
        json_response(['ok' => true, 'message' => 'Su cierre ya estaba registrado.', 'status' => $status]);
    }
    $userId = (int) (current_user()['id'] ?? 0);
    if ($userId <= 0) throw new DomainException('La sesión no identifica al usuario.');
    if (is_admin()) {
        $update = $pdo->prepare('UPDATE attendance_report_month_closures SET admin_user_id = :user_id, admin_closed_at = NOW()
            WHERE worker_id = :worker_id AND report_month = :report_month AND admin_closed_at IS NULL');
    } else {
        $update = $pdo->prepare('UPDATE attendance_report_month_closures SET staff_user_id = :user_id, staff_closed_at = NOW()
            WHERE worker_id = :worker_id AND report_month = :report_month AND staff_closed_at IS NULL');
    }
    $update->execute(['user_id' => $userId, 'worker_id' => $workerId, 'report_month' => $month]);
    $status = attendance_report_closure_status($workerId, $month, $pdo);
    $pdo->commit();
    json_response([
        'ok' => true,
        'message' => $status['fully_closed'] ? 'El reporte mensual quedó cerrado por ambas partes.' : 'Su cierre quedó registrado. Falta la confirmación de la otra parte.',
        'status' => $status,
    ]);
} catch (DomainException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $error->getMessage()], 409);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('No se pudo cerrar el reporte mensual: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudo cerrar el reporte. Verifique que esté instalada la actualización SQL.'], 500);
}
