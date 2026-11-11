<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_report_closure.php';
require_once __DIR__ . '/../../includes/attendance_labor_regime_correction.php';
require_module_access('control_personal.dashboard');
if (!is_admin()) json_response(['ok' => false, 'message' => 'Solo el administrador puede corregir el régimen laboral.'], 403);
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = filter_input(INPUT_POST, 'worker_id', FILTER_VALIDATE_INT);
$date = (string) ($_POST['work_date'] ?? '');
$regime = (string) ($_POST['regime'] ?? '');
$reason = trim((string) ($_POST['reason'] ?? ''));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$workerId || $workerId < 1 || !$parsedDate || $parsedDate->format('Y-m-d') !== $date
    || !in_array($regime, ['regular', '21x7'], true)
    || $reason === '' || mb_strlen($reason) > 500) {
    json_response(['ok' => false, 'message' => 'Indique un trabajador, fecha, régimen y motivo válidos.'], 400);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $worker = $pdo->prepare('SELECT id FROM workers WHERE id = ? LIMIT 1');
    $worker->execute([$workerId]);
    if (!$worker->fetchColumn()) throw new DomainException('El trabajador no existe.');
    attendance_labor_regime_correct($pdo, (int) $workerId, $date, $regime, $reason,
        (int) (current_user()['id'] ?? 0) ?: null);
    $pdo->commit();
    json_response(['ok' => true, 'message' => 'Régimen laboral actualizado.']);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $error instanceof DomainException ? $error->getMessage() : 'No se pudo actualizar el régimen laboral.'], 400);
}
