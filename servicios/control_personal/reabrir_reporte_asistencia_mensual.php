<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_closure.php';
require_module_access('control_personal.reporte_asistencias');
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = filter_input(INPUT_POST, 'worker_id', FILTER_VALIDATE_INT);
$month = trim((string) ($_POST['month'] ?? ''));
$action = trim((string) ($_POST['action'] ?? ''));
$reason = trim((string) ($_POST['reason'] ?? ''));
if (!$workerId || $workerId < 1 || !attendance_report_valid_month($month)
    || !in_array($action, ['request', 'approve'], true)) {
    json_response(['ok' => false, 'message' => 'Trabajador, mes o acción inválidos.'], 422);
}
if ($action === 'request' && (!is_admin() || $reason === '' || mb_strlen($reason) > 500)) {
    json_response(['ok' => false, 'message' => 'Solo el administrador puede solicitar la reapertura e indicar su motivo.'], 403);
}
if ($action === 'approve' && !is_personal_role()) {
    json_response(['ok' => false, 'message' => 'Solo el trabajador puede aceptar la reapertura.'], 403);
}
require_personal_own_worker((int) $workerId);

$pdo = db();
try {
    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT id FROM workers WHERE id = ? FOR UPDATE');
    $lock->execute([$workerId]);
    if (!$lock->fetchColumn()) throw new DomainException('El trabajador no existe.');

    $closure = $pdo->prepare('SELECT admin_user_id, admin_closed_at, staff_user_id, staff_closed_at
        FROM attendance_report_month_closures WHERE worker_id = ? AND report_month = ? FOR UPDATE');
    $closure->execute([$workerId, $month]);
    $closed = $closure->fetch(PDO::FETCH_ASSOC);
    if (!$closed || !$closed['admin_closed_at'] || !$closed['staff_closed_at']) {
        throw new DomainException('El reporte ya no está cerrado por ambas partes. Actualice la página.');
    }
    $latest = $pdo->prepare('SELECT id, status FROM attendance_report_reopen_requests
        WHERE worker_id = ? AND report_month = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $latest->execute([$workerId, $month]);
    $request = $latest->fetch(PDO::FETCH_ASSOC);
    $actorId = (int) (current_user()['id'] ?? 0);
    if ($actorId <= 0) throw new DomainException('La sesión no identifica al usuario.');

    if ($action === 'request') {
        if (($request['status'] ?? '') === 'pending') throw new DomainException('Ya hay una solicitud pendiente de respuesta del trabajador.');
        $insert = $pdo->prepare('INSERT INTO attendance_report_reopen_requests
            (worker_id, report_month, reason, requested_by_user_id,
             previous_admin_user_id, previous_admin_closed_at, previous_staff_user_id, previous_staff_closed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$workerId, $month, $reason, $actorId, $closed['admin_user_id'],
            $closed['admin_closed_at'], $closed['staff_user_id'], $closed['staff_closed_at']]);
        $message = 'Solicitud enviada. El reporte seguirá cerrado hasta que el trabajador acepte.';
    } else {
        if (!$request || $request['status'] !== 'pending') throw new DomainException('No hay una solicitud de reapertura pendiente.');
        $approve = $pdo->prepare("UPDATE attendance_report_reopen_requests
            SET status = 'approved', approved_by_user_id = ?, approved_at = NOW()
            WHERE id = ? AND status = 'pending'");
        $approve->execute([$actorId, $request['id']]);
        $reopen = $pdo->prepare('UPDATE attendance_report_month_closures
            SET admin_user_id = NULL, admin_closed_at = NULL, staff_user_id = NULL, staff_closed_at = NULL
            WHERE worker_id = ? AND report_month = ?');
        $reopen->execute([$workerId, $month]);
        $message = 'Reporte reabierto por acuerdo de ambas partes. Los cierres anteriores quedaron registrados.';
    }
    $pdo->commit();
    json_response(['ok' => true, 'message' => $message]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error instanceof DomainException) json_response(['ok' => false, 'message' => $error->getMessage()], 409);
    error_log('Error al reabrir reporte de asistencia: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudo tramitar la reapertura del reporte.'], 500);
}
