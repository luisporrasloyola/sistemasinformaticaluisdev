<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.programacion');
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$programDate = trim((string) ($_POST['program_date'] ?? ''));
$activity = trim((string) ($_POST['activity'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ($_POST['stops'] ?? '')));
$scheduleSource = (string) ($_POST['schedule_source'] ?? 'template');
$extraEntry = trim((string) ($_POST['extra_entry_time'] ?? ''));
$extraExit = trim((string) ($_POST['extra_exit_time'] ?? ''));
$extraAdvance = filter_var($_POST['extra_entry_advance'] ?? 0, FILTER_VALIDATE_INT);
$extraTolerance = filter_var($_POST['extra_tolerance'] ?? 0, FILTER_VALIDATE_INT);

if ($assignmentId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $programDate)) {
    json_response(['ok' => false, 'message' => 'Seleccione una asignación activa y una fecha válida.'], 422);
}
if (!in_array($scheduleSource, ['template', 'extraordinary'], true)) {
    json_response(['ok' => false, 'message' => 'Seleccione una modalidad de horario válida.'], 422);
}

$stmt = db()->prepare("SELECT aa.*, w.full_name, l.name AS location_name, s.name AS schedule_name
    FROM attendance_assignments aa
    JOIN workers w ON w.id = aa.worker_id
    JOIN attendance_locations l ON l.id = aa.location_id
    JOIN attendance_schedules s ON s.id = aa.schedule_id
    WHERE aa.id = :id AND aa.status = 1
      AND aa.valid_from <= :program_date_from AND (aa.valid_until IS NULL OR aa.valid_until >= :program_date_until)
    LIMIT 1");
$stmt->execute(['id' => $assignmentId, 'program_date_from' => $programDate, 'program_date_until' => $programDate]);
$assignment = $stmt->fetch();
if (!$assignment) json_response(['ok' => false, 'message' => 'La asignación seleccionada ya no está activa.'], 409);

if ($scheduleSource === 'extraordinary') {
    if (!preg_match('/^\d{2}:\d{2}$/', $extraEntry) || !preg_match('/^\d{2}:\d{2}$/', $extraExit)
        || $extraAdvance === false || $extraAdvance < 0 || $extraAdvance > 360
        || $extraTolerance === false || $extraTolerance < 0 || $extraTolerance > 180) {
        json_response(['ok' => false, 'message' => 'Revise las horas, la anticipación y la tolerancia del horario extraordinario.'], 422);
    }
    if ($extraEntry === $extraExit) json_response(['ok' => false, 'message' => 'La salida debe ser diferente de la entrada.'], 422);
    $entryTime = $extraEntry . ':00';
    $exitTime = $extraExit . ':00';
    $entryStart = (new DateTimeImmutable('2000-01-02 ' . $entryTime))->modify('-' . $extraAdvance . ' minutes')->format('H:i:s');
    $entryEnd = (new DateTimeImmutable('2000-01-02 ' . $entryTime))->modify('+' . $extraTolerance . ' minutes')->format('H:i:s');
    $tolerance = (int) $extraTolerance;
} else {
    $dayOfWeek = (int) (new DateTimeImmutable($programDate))->format('N');
    $stmt = db()->prepare("SELECT * FROM attendance_schedule_days WHERE schedule_id = :schedule_id AND day_of_week = :day_of_week AND status = 1 LIMIT 1");
    $stmt->execute(['schedule_id' => $assignment['schedule_id'], 'day_of_week' => $dayOfWeek]);
    $day = $stmt->fetch();
    if (!$day) {
        json_response(['ok' => false, 'message' => 'La plantilla "' . $assignment['schedule_name'] . '" no tiene horario para ese día. Puede utilizar un horario extraordinario.'], 422);
    }
    $entryTime = (string) ($day['entry_time'] ?: $day['entry_start']);
    $entryStart = (string) ($day['entry_start'] ?: $entryTime);
    $entryEnd = (string) ($day['entry_end'] ?: $entryTime);
    $exitTime = (string) ($day['exit_time'] ?: $day['exit_start']);
    $tolerance = (int) ($day['tolerance_minutes'] ?? 0);
}

function program_interval(string $date, string $start, string $end): array {
    $from = strtotime($date . ' ' . $start);
    $to = strtotime($date . ' ' . $end);
    if ($to <= $from) $to += 86400;
    return [$from, $to];
}

[$newStart, $newEnd] = program_interval($programDate, $entryTime, $exitTime);
$stmt = db()->prepare("SELECT id, entry_time, exit_time FROM attendance_programs
    WHERE worker_id = :worker_id AND program_date = :program_date AND status = 'programada' AND id <> :id");
$stmt->execute(['worker_id' => $assignment['worker_id'], 'program_date' => $programDate, 'id' => $id]);
foreach ($stmt->fetchAll() as $existing) {
    [$existingStart, $existingEnd] = program_interval($programDate, $existing['entry_time'], $existing['exit_time']);
    if ($newStart < $existingEnd && $newEnd > $existingStart) {
        json_response(['ok' => false, 'message' => 'El trabajador ya tiene otra programación que se cruza con este horario.'], 409);
    }
}

$pdo = db();
$programInUse = false;
if ($id > 0) {
    $usage = $pdo->prepare("SELECT
            EXISTS(SELECT 1 FROM attendance_marks am WHERE am.program_id = ap.id) AS has_marks,
            EXISTS(SELECT 1 FROM attendance_trips atp WHERE atp.program_id = ap.id) AS has_trips
        FROM attendance_programs ap WHERE ap.id = :id LIMIT 1");
    $usage->execute(['id' => $id]);
    $usageRow = $usage->fetch();
    if (!$usageRow) json_response(['ok' => false, 'message' => 'La programación ya no existe.'], 404);
    $programInUse = (int) $usageRow['has_marks'] === 1 || (int) $usageRow['has_trips'] === 1;
}
if ($programInUse) {
    json_response(['ok' => false, 'message' => 'No se puede modificar esta programación porque ya tiene marcaciones o desplazamientos. El historial registrado debe conservarse.'], 409);
}
$pdo->beginTransaction();
try {
    $values = ['assignment_id'=>$assignmentId, 'worker_id'=>$assignment['worker_id'], 'program_date'=>$programDate,
        'entry_time'=>$entryTime, 'entry_start'=>$entryStart, 'entry_end'=>$entryEnd, 'exit_time'=>$exitTime,
        'tolerance'=>$tolerance, 'schedule_source'=>$scheduleSource,
        'activity'=>$activity !== '' ? mb_substr($activity, 0, 180) : ($assignment['activity'] ?: null),
        'notes'=>$notes !== '' ? mb_substr($notes, 0, 500) : null];
    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE attendance_programs SET assignment_id=:assignment_id, worker_id=:worker_id,
            program_date=:program_date, entry_time=:entry_time, entry_start=:entry_start, entry_end=:entry_end,
            exit_time=:exit_time, tolerance_minutes=:tolerance, schedule_source=:schedule_source,
            activity=:activity, notes=:notes, status='programada' WHERE id=:id");
        $stmt->execute($values + ['id' => $id]);
        $exists = $pdo->prepare('SELECT id FROM attendance_programs WHERE id=:id');
        $exists->execute(['id' => $id]);
        if (!$exists->fetchColumn()) throw new RuntimeException('La programación ya no existe.');
        $programId = $id;
        $pdo->prepare('DELETE FROM attendance_program_stops WHERE program_id=:id')->execute(['id'=>$programId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO attendance_programs
            (assignment_id,worker_id,program_date,entry_time,entry_start,entry_end,exit_time,tolerance_minutes,schedule_source,activity,notes,status,created_by_user_id)
            VALUES (:assignment_id,:worker_id,:program_date,:entry_time,:entry_start,:entry_end,:exit_time,:tolerance,:schedule_source,:activity,:notes,'programada',:user_id)");
        $stmt->execute($values + ['user_id'=>(int)(current_user()['id'] ?? 0) ?: null]);
        $programId = (int) $pdo->lastInsertId();
    }

    $pdo->commit();
    json_response(['ok'=>true,'message'=>$scheduleSource === 'extraordinary' ? 'La jornada extraordinaria fue programada correctamente.' : 'La jornada fue programada correctamente.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error instanceof PDOException && $error->getCode() === '23000') json_response(['ok'=>false,'message'=>'Ya existe una programación para esa asignación y fecha.'],409);
    json_response(['ok'=>false,'message'=>$error->getMessage()],500);
}
