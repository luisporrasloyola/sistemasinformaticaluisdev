<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_calendar.php';
require_once __DIR__ . '/../../includes/attendance_report_closure.php';

require_role('Administrador');
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = (int) ($_POST['worker_id'] ?? 0);
$date = trim((string) ($_POST['mark_date'] ?? ''));
$enabledValue = (string) ($_POST['double_enabled'] ?? '');
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($workerId <= 0 || !$parsedDate || $parsedDate->format('Y-m-d') !== $date
    || !in_array($enabledValue, ['0', '1'], true)) {
    json_response(['ok' => false, 'message' => 'Revise el trabajador, la fecha y el estado del interruptor.'], 422);
}
if ($date > date('Y-m-d')) {
    json_response(['ok' => false, 'message' => 'No se pueden modificar fechas futuras desde este detalle.'], 409);
}

$pdo = db();
try {
    $pdo->beginTransaction();
    attendance_report_assert_editable($workerId, $date, $pdo, true);
    $worker = $pdo->prepare('SELECT company_id FROM workers WHERE id = :id LIMIT 1');
    $worker->execute(['id' => $workerId]);
    $companyId = $worker->fetchColumn();
    if ($companyId === false) throw new DomainException('El trabajador no existe.');
    $event = attendance_calendar_event_for_worker($date, $workerId, (int) $companyId);
    if (($event['event_type'] ?? '') !== 'holiday') {
        throw new DomainException('Primero guarde este día como feriado en el calendario laboral.');
    }

    $save = $pdo->prepare('INSERT INTO attendance_holiday_double_overrides
        (worker_id, work_date, double_enabled, changed_by_user_id)
        VALUES (:worker, :date, :enabled, :actor)
        ON DUPLICATE KEY UPDATE double_enabled = VALUES(double_enabled),
            changed_by_user_id = VALUES(changed_by_user_id), updated_at = CURRENT_TIMESTAMP');
    $save->execute([
        'worker' => $workerId, 'date' => $date, 'enabled' => (int) $enabledValue,
        'actor' => (int) (current_user()['id'] ?? 0) ?: null,
    ]);
    $pdo->commit();
    json_response(['ok' => true, 'message' => $enabledValue === '1'
        ? 'El feriado vuelve a duplicar horas trabajadas y horas extras.'
        : 'El feriado se conserva, pero ninguna hora se duplica.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error instanceof DomainException) json_response(['ok' => false, 'message' => $error->getMessage()], 409);
    error_log('Error al guardar duplicación de feriado: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudo guardar el ajuste del feriado.'], 500);
}
