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
$type = trim((string) ($_POST['calendar_event_type'] ?? ''));
$restKind = trim((string) ($_POST['rest_kind'] ?? ''));
$reason = trim((string) ($_POST['reason'] ?? ''));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

if ($workerId <= 0 || !$parsedDate || $parsedDate->format('Y-m-d') !== $date
    || !in_array($type, ['vacation', 'rest', 'holiday', 'non_working'], true)
    || ($type === 'rest' && !in_array($restKind, ['hours', 'regime'], true))
    || $reason === '' || mb_strlen($reason) > 500) {
    json_response(['ok' => false, 'message' => 'Revise el trabajador, la fecha, el tipo y la observación.'], 422);
}
if ($date >= date('Y-m-d')) {
    json_response(['ok' => false, 'message' => 'El calendario de asistencia solo puede corregirse en fechas anteriores.'], 409);
}

$name = match ($type) {
    'vacation' => 'Vacaciones',
    'holiday' => 'Feriado',
    'non_working' => 'No laborable',
    default => $restKind === 'hours' ? 'A cuenta de horas' : 'Régimen laboral',
};

$pdo = db();
try {
    $pdo->beginTransaction();
    attendance_report_assert_editable($workerId, $date, $pdo, true);

    $worker = $pdo->prepare('SELECT id, company_id FROM workers WHERE id = :id LIMIT 1');
    $worker->execute(['id' => $workerId]);
    $workerRow = $worker->fetch();
    if (!$workerRow) throw new DomainException('El trabajador no existe.');

    $assignment = $pdo->prepare('SELECT id FROM attendance_assignments
        WHERE worker_id = :worker AND status = 1 AND valid_from <= :date_from
          AND (valid_until IS NULL OR valid_until >= :date_until)
          AND (deactivated_at IS NULL OR DATE(deactivated_at) >= :date_deactivated)
        LIMIT 1');
    $assignment->execute([
        'worker' => $workerId, 'date_from' => $date,
        'date_until' => $date, 'date_deactivated' => $date,
    ]);
    if (!$assignment->fetchColumn()) {
        throw new DomainException('El trabajador no tiene una asignación activa para esa fecha.');
    }

    $marks = $pdo->prepare('SELECT id FROM attendance_marks WHERE worker_id = :worker AND mark_date = :date LIMIT 1');
    $marks->execute(['worker' => $workerId, 'date' => $date]);
    if ($marks->fetchColumn()) {
        throw new DomainException('Este día tiene marcaciones. Revise las marcaciones antes de asignarle un día especial.');
    }

    $current = attendance_calendar_resolve_event(
        attendance_calendar_events_between($date, $date), $date, $workerId, (int) $workerRow['company_id']
    );
    $absence = $pdo->prepare('SELECT id FROM attendance_manual_day_overrides WHERE worker_id = :worker AND mark_date = :date LIMIT 1');
    $absence->execute(['worker' => $workerId, 'date' => $date]);
    $hasAdministrativeAbsence = (bool) $absence->fetchColumn();
    if ($current && (string) $current['event_type'] === $type
        && ($type !== 'rest' || (string) $current['name'] === $name)
        && !$hasAdministrativeAbsence) {
        $pdo->commit();
        json_response(['ok' => true, 'message' => 'El calendario laboral ya tiene esa clasificación para este día.']);
    }

    // Una corrección individual prevalece sobre los eventos generales sin alterarlos.
    // Las versiones individuales anteriores de un solo día se conservan inactivas.
    $disable = $pdo->prepare("UPDATE attendance_calendar_days SET status = 0
        WHERE scope_type = 'worker' AND worker_id = :worker AND calendar_date = :date
          AND (end_date IS NULL OR end_date = :end_date) AND status = 1");
    $disable->execute(['worker' => $workerId, 'date' => $date, 'end_date' => $date]);

    $insert = $pdo->prepare('INSERT INTO attendance_calendar_days
        (calendar_date, end_date, event_type, name, scope_type, company_id, worker_id, status, created_by_user_id)
        VALUES (:date, :end_date, :type, :name, \'worker\', NULL, :worker, 1, :actor)');
    $insert->execute([
        'date' => $date, 'end_date' => $date, 'type' => $type, 'name' => $name,
        'worker' => $workerId, 'actor' => (int) (current_user()['id'] ?? 0) ?: null,
    ]);

    // Una falta administrativa previa no debe ocultar el nuevo día de calendario.
    $clearAbsence = $pdo->prepare('DELETE FROM attendance_manual_day_overrides WHERE worker_id = :worker AND mark_date = :date');
    $clearAbsence->execute(['worker' => $workerId, 'date' => $date]);

    $audit = $pdo->prepare('INSERT INTO attendance_calendar_corrections
        (worker_id, calendar_date, previous_event_type, previous_name, event_type, event_name, reason, adjusted_by_user_id)
        VALUES (:worker, :date, :previous_type, :previous_name, :type, :name, :reason, :actor)');
    $audit->execute([
        'worker' => $workerId, 'date' => $date,
        'previous_type' => $current['event_type'] ?? null,
        'previous_name' => $current['name'] ?? null,
        'type' => $type, 'name' => $name, 'reason' => $reason,
        'actor' => (int) (current_user()['id'] ?? 0) ?: null,
    ]);
    $pdo->commit();
    json_response(['ok' => true, 'message' => 'El día se actualizó en el calendario laboral y en el reporte de asistencias.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error instanceof DomainException) {
        json_response(['ok' => false, 'message' => $error->getMessage()], 409);
    }
    error_log('Error al corregir calendario desde asistencia: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudo guardar el día de calendario laboral.'], 500);
}
