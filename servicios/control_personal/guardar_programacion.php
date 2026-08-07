<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.programacion');
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$locationId = (int) ($_POST['location_id'] ?? 0);
$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$programDate = trim((string) ($_POST['program_date'] ?? ''));
$activity = trim((string) ($_POST['activity'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ($_POST['stops'] ?? '')));
$scheduleSource = (string) ($_POST['schedule_source'] ?? 'template');
$extraEntry = trim((string) ($_POST['extra_entry_time'] ?? ''));
$extraExit = trim((string) ($_POST['extra_exit_time'] ?? ''));
$extraAdvance = filter_var($_POST['extra_entry_advance'] ?? 0, FILTER_VALIDATE_INT);
$extraTolerance = filter_var($_POST['extra_tolerance'] ?? 0, FILTER_VALIDATE_INT);
$stopLocationIds = array_values((array) ($_POST['stop_location_ids'] ?? []));
$stopActivities = array_values((array) ($_POST['stop_activities'] ?? []));
$stopEstimatedTimes = array_values((array) ($_POST['stop_estimated_times'] ?? []));
$isRoute = count($stopLocationIds) > 0;

if ($assignmentId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $programDate)) {
    json_response(['ok' => false, 'message' => 'Seleccione una asignación activa y una fecha válida.'], 422);
}
if (!in_array($scheduleSource, ['template', 'extraordinary'], true)) {
    json_response(['ok' => false, 'message' => 'Seleccione una modalidad de horario válida.'], 422);
}

$existingProgram = null;
$editingExistingRoute = false;
if ($id > 0) {
    $existingStmt = db()->prepare("SELECT ap.*,
            EXISTS(SELECT 1 FROM attendance_program_stops aps WHERE aps.program_id=ap.id) AS has_route
        FROM attendance_programs ap WHERE ap.id=:id LIMIT 1");
    $existingStmt->execute(['id' => $id]);
    $existingProgram = $existingStmt->fetch();
    if (!$existingProgram) json_response(['ok' => false, 'message' => 'La programación ya no existe.'], 404);
    $editingExistingRoute = (bool) $existingProgram['has_route'];

    // Al editar se conserva el vínculo original. El selector solo identifica
    // al trabajador; no debe trasladar silenciosamente el historial a otra asignación.
    $assignmentId = (int) $existingProgram['assignment_id'];
    if ($editingExistingRoute) $programDate = (string) $existingProgram['program_date'];
}

$stmt = db()->prepare("SELECT aa.*, w.full_name, l.name AS location_name, s.name AS schedule_name
    FROM attendance_assignments aa
    JOIN workers w ON w.id = aa.worker_id
    JOIN attendance_locations l ON l.id = aa.location_id
    JOIN attendance_schedules s ON s.id = aa.schedule_id
    WHERE aa.id = :id
      AND ((aa.status = 1
            AND aa.valid_from <= :program_date_from
            AND (aa.valid_until IS NULL OR aa.valid_until >= :program_date_until))
        OR :allow_historical = 1)
    LIMIT 1");
$stmt->execute([
    'id' => $assignmentId,
    'program_date_from' => $programDate,
    'program_date_until' => $programDate,
    'allow_historical' => $editingExistingRoute ? 1 : 0,
]);
$assignment = $stmt->fetch();
if (!$assignment) json_response(['ok' => false, 'message' => 'La asignación seleccionada ya no está activa para esta fecha.'], 409);

// El recorrido amplía una jornada existente: conserva automáticamente
// el lugar y el horario de la asignación vigente del trabajador.
if ($isRoute) {
    $locationId = (int) $assignment['location_id'];
    $scheduleId = (int) $assignment['schedule_id'];
    $scheduleSource = 'template';
} elseif ($locationId <= 0 || $scheduleId <= 0) {
    json_response(['ok'=>false,'message'=>'Seleccione el lugar de marcación y la plantilla de horario.'],422);
}

$locationStmt = db()->prepare('SELECT id, name FROM attendance_locations WHERE id=:id AND status=1 LIMIT 1');
$locationStmt->execute(['id' => $locationId]);
$selectedLocation = $locationStmt->fetch();
$scheduleStmt = db()->prepare('SELECT id, name FROM attendance_schedules WHERE id=:id AND status=1 LIMIT 1');
$scheduleStmt->execute(['id' => $scheduleId]);
$selectedSchedule = $scheduleStmt->fetch();
if (!$selectedLocation || !$selectedSchedule) {
    json_response(['ok' => false, 'message' => 'Seleccione un lugar de marcación y una plantilla de horario vigentes.'], 422);
}

$stops = [];
$stopLocationStmt = db()->prepare('SELECT id, name FROM attendance_locations WHERE id=:id AND status=1 LIMIT 1');
foreach ($stopLocationIds as $index => $rawLocationId) {
    $stopLocationId = (int) $rawLocationId;
    $stopActivity = trim((string) ($stopActivities[$index] ?? ''));
    $stopEstimatedTime = trim((string) ($stopEstimatedTimes[$index] ?? ''));
    if ($stopLocationId <= 0 || $stopActivity === '') {
        json_response(['ok' => false, 'message' => 'Seleccione el lugar y describa la actividad de cada punto del recorrido.'], 422);
    }
    if ($stopEstimatedTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $stopEstimatedTime)) {
        json_response(['ok' => false, 'message' => 'Revise la hora estimada de llegada de los puntos del recorrido.'], 422);
    }
    $stopLocationStmt->execute(['id' => $stopLocationId]);
    $stopLocation = $stopLocationStmt->fetch();
    if (!$stopLocation) json_response(['ok' => false, 'message' => 'Uno de los lugares del recorrido ya no está disponible.'], 422);
    $stops[] = [
        'location_id' => $stopLocationId,
        'destination' => (string) $stopLocation['name'],
        'activity' => mb_substr($stopActivity, 0, 255),
        'estimated_time' => $stopEstimatedTime !== '' ? $stopEstimatedTime . ':00' : null,
    ];
}

if ($scheduleSource === 'extraordinary') {
    if (!preg_match('/^\d{2}:\d{2}$/', $extraEntry) || !preg_match('/^\d{2}:\d{2}$/', $extraExit)
        || $extraAdvance === false || $extraAdvance < 0 || $extraAdvance > 360
        || $extraTolerance === false || $extraTolerance < 0 || $extraTolerance > 180) {
        json_response(['ok' => false, 'message' => 'Revise las horas, la anticipación y la tolerancia del horario especial.'], 422);
    }
    if ($extraEntry === $extraExit) json_response(['ok' => false, 'message' => 'La salida debe ser diferente de la entrada.'], 422);
    $entryTime = $extraEntry . ':00';
    $exitTime = $extraExit . ':00';
    $entryStart = (new DateTimeImmutable('2000-01-02 ' . $entryTime))->modify('-' . $extraAdvance . ' minutes')->format('H:i:s');
    $entryEnd = (new DateTimeImmutable('2000-01-02 ' . $entryTime))->modify('+' . $extraTolerance . ' minutes')->format('H:i:s');
    $tolerance = (int) $extraTolerance;
} elseif ($editingExistingRoute && $existingProgram) {
    // El horario del recorrido existente es histórico y no debe recalcularse
    // con una plantilla que pudo cambiar después de iniciada la jornada.
    $entryTime = (string) $existingProgram['entry_time'];
    $entryStart = (string) $existingProgram['entry_start'];
    $entryEnd = (string) $existingProgram['entry_end'];
    $exitTime = (string) $existingProgram['exit_time'];
    $tolerance = (int) $existingProgram['tolerance_minutes'];
} else {
    $dayOfWeek = (int) (new DateTimeImmutable($programDate))->format('N');
    $stmt = db()->prepare("SELECT * FROM attendance_schedule_days WHERE schedule_id = :schedule_id AND day_of_week = :day_of_week AND status = 1 LIMIT 1");
    $stmt->execute(['schedule_id' => $assignment['schedule_id'], 'day_of_week' => $dayOfWeek]);
    $day = $stmt->fetch();
    if (!$day) {
        json_response(['ok' => false, 'message' => 'La plantilla "' . $assignment['schedule_name'] . '" no tiene horario para ese día. Puede crear una programación especial.'], 422);
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
            EXISTS(SELECT 1 FROM attendance_trips atp WHERE atp.program_id = ap.id) AS has_trips,
            EXISTS(SELECT 1 FROM attendance_work_completions awc WHERE awc.program_id = ap.id) AS has_completions
        FROM attendance_programs ap WHERE ap.id = :id LIMIT 1");
    $usage->execute(['id' => $id]);
    $usageRow = $usage->fetch();
    if (!$usageRow) json_response(['ok' => false, 'message' => 'La programación ya no existe.'], 404);
    $programInUse = (int) $usageRow['has_marks'] === 1 || (int) $usageRow['has_trips'] === 1 || (int) $usageRow['has_completions'] === 1;
}
if ($programInUse && !$editingExistingRoute) {
    json_response(['ok' => false, 'message' => 'No se puede modificar porque ya tiene marcaciones, desplazamientos o trabajos finalizados propios. El historial registrado debe conservarse.'], 409);
}

if ($programInUse && $editingExistingRoute) {
    $recordedLocationsStmt = $pdo->prepare("SELECT DISTINCT location_id FROM (
            SELECT first_destination_location_id AS location_id
            FROM attendance_trips
            WHERE program_id=:trip_program AND first_destination_location_id IS NOT NULL
            UNION
            SELECT ats.location_id
            FROM attendance_trip_stops ats
            JOIN attendance_trips atp ON atp.id=ats.trip_id
            WHERE atp.program_id=:stop_program AND ats.location_id IS NOT NULL
        ) recorded_locations");
    $recordedLocationsStmt->execute(['trip_program' => $id, 'stop_program' => $id]);
    $submittedLocationIds = array_map(static fn(array $stop): int => (int) $stop['location_id'], $stops);
    foreach ($recordedLocationsStmt->fetchAll(PDO::FETCH_COLUMN) as $recordedLocationId) {
        if (!in_array((int) $recordedLocationId, $submittedLocationIds, true)) {
            json_response([
                'ok' => false,
                'message' => 'No puede retirar un lugar que el trabajador ya visitó o hacia el cual se está desplazando. Puede actualizar su actividad o llegada estimada.',
            ], 409);
        }
    }
}
$pdo->beginTransaction();
try {
    $creatingProgram = $id <= 0;
    $values = ['assignment_id'=>$assignmentId, 'worker_id'=>$assignment['worker_id'],
        'location_id'=>$locationId, 'schedule_id'=>$scheduleId, 'program_date'=>$programDate,
        'entry_time'=>$entryTime, 'entry_start'=>$entryStart, 'entry_end'=>$entryEnd, 'exit_time'=>$exitTime,
        'tolerance'=>$tolerance, 'schedule_source'=>$scheduleSource,
        'activity'=>$activity !== '' ? mb_substr($activity, 0, 180) : ($assignment['activity'] ?: null),
        'notes'=>$notes !== '' ? mb_substr($notes, 0, 500) : null];
    if ($programInUse && $editingExistingRoute && $existingProgram) {
        // Una edición operativa del recorrido no debe alterar la jornada que ya
        // comenzó. Solo se actualizarán destinos, actividades, horas estimadas
        // e indicaciones.
        $values['assignment_id'] = (int) $existingProgram['assignment_id'];
        $values['worker_id'] = (int) $existingProgram['worker_id'];
        $values['location_id'] = (int) $existingProgram['location_id'];
        $values['schedule_id'] = (int) $existingProgram['schedule_id'];
        $values['program_date'] = (string) $existingProgram['program_date'];
        $values['entry_time'] = (string) $existingProgram['entry_time'];
        $values['entry_start'] = (string) $existingProgram['entry_start'];
        $values['entry_end'] = (string) $existingProgram['entry_end'];
        $values['exit_time'] = (string) $existingProgram['exit_time'];
        $values['tolerance'] = (int) $existingProgram['tolerance_minutes'];
        $values['schedule_source'] = (string) $existingProgram['schedule_source'];
        $values['activity'] = $existingProgram['activity'];
    }
    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE attendance_programs SET assignment_id=:assignment_id, worker_id=:worker_id,
            location_id=:location_id, schedule_id=:schedule_id,
            program_date=:program_date, entry_time=:entry_time, entry_start=:entry_start, entry_end=:entry_end,
            exit_time=:exit_time, tolerance_minutes=:tolerance, schedule_source=:schedule_source,
            activity=:activity, notes=:notes, status='programada' WHERE id=:id");
        $stmt->execute($values + ['id' => $id]);
        $exists = $pdo->prepare('SELECT id FROM attendance_programs WHERE id=:id');
        $exists->execute(['id' => $id]);
        if (!$exists->fetchColumn()) throw new RuntimeException('La programación ya no existe.');
        $programId = $id;
        if ($editingExistingRoute) {
            // Ambos formularios editan las indicaciones de la misma jornada.
            // Si existe una personalización diaria, se actualiza para que siempre
            // prevalezca el último texto guardado.
            $syncOverride = $pdo->prepare("UPDATE attendance_journey_overrides
                SET instructions=:instructions, updated_by_user_id=:user_id, updated_at=CURRENT_TIMESTAMP
                WHERE assignment_id=:assignment_id AND journey_date=:journey_date");
            $syncOverride->execute([
                'instructions' => $notes !== '' ? mb_substr($notes, 0, 500) : '',
                'user_id' => (int) (current_user()['id'] ?? 0),
                'assignment_id' => (int) $existingProgram['assignment_id'],
                'journey_date' => (string) $existingProgram['program_date'],
            ]);
        }
        $pdo->prepare('DELETE FROM attendance_program_stops WHERE program_id=:id')->execute(['id'=>$programId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO attendance_programs
            (assignment_id,worker_id,location_id,schedule_id,program_date,entry_time,entry_start,entry_end,exit_time,tolerance_minutes,schedule_source,activity,notes,status,created_by_user_id)
            VALUES (:assignment_id,:worker_id,:location_id,:schedule_id,:program_date,:entry_time,:entry_start,:entry_end,:exit_time,:tolerance,:schedule_source,:activity,:notes,'programada',:user_id)");
        $stmt->execute($values + ['user_id'=>(int)(current_user()['id'] ?? 0) ?: null]);
        $programId = (int) $pdo->lastInsertId();
    }

    if ($stops) {
        $stopInsert = $pdo->prepare('INSERT INTO attendance_program_stops
            (program_id,location_id,stop_order,destination,activity,estimated_time)
            VALUES (:program_id,:location_id,:stop_order,:destination,:activity,:estimated_time)');
        foreach ($stops as $index => $stop) {
            $stopInsert->execute($stop + ['program_id' => $programId, 'stop_order' => $index + 1]);
        }
    }

    // Si el recorrido se asigna después de que el trabajador inició su jornada
    // o finalizó el trabajo anterior, se vinculan esos registros base al nuevo
    // recorrido para que pueda continuar sin volver a marcar entrada.
    if ($creatingProgram && $stops) {
        $linkMarks = $pdo->prepare('UPDATE attendance_marks SET program_id=:program_id
            WHERE assignment_id=:assignment_id AND worker_id=:worker_id AND mark_date=:program_date AND program_id IS NULL');
        $linkMarks->execute(['program_id'=>$programId,'assignment_id'=>$assignmentId,'worker_id'=>$assignment['worker_id'],'program_date'=>$programDate]);
        $linkCompletions = $pdo->prepare('UPDATE attendance_work_completions SET program_id=:program_id
            WHERE assignment_id=:assignment_id AND worker_id=:worker_id AND work_date=:program_date AND program_id IS NULL');
        $linkCompletions->execute(['program_id'=>$programId,'assignment_id'=>$assignmentId,'worker_id'=>$assignment['worker_id'],'program_date'=>$programDate]);
    }

    $pdo->commit();
    json_response(['ok'=>true,'message'=>$scheduleSource === 'extraordinary' ? 'La programación especial fue guardada correctamente.' : 'La jornada fue programada correctamente.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error instanceof PDOException && $error->getCode() === '23000') json_response(['ok'=>false,'message'=>'Ya existe una programación para esa asignación y fecha.'],409);
    json_response(['ok'=>false,'message'=>$error->getMessage()],500);
}
