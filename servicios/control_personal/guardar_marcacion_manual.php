<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role('Administrador');
verify_csrf($_POST['csrf_token'] ?? null);
header('Content-Type: application/json; charset=utf-8');

function manual_response(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function manual_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function manual_valid_time(string $value): bool
{
    return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);
}

$workerId = max(0, (int) ($_POST['worker_id'] ?? 0));
$markDate = trim((string) ($_POST['mark_date'] ?? ''));
$entryTime = trim((string) ($_POST['entry_time'] ?? ''));
$exitTime = trim((string) ($_POST['exit_time'] ?? ''));
$reason = trim((string) ($_POST['reason'] ?? ''));
$entryLocationId = max(0, (int) ($_POST['entry_location_id'] ?? 0));
$exitLocationId = max(0, (int) ($_POST['exit_location_id'] ?? 0));
$attendanceResult = trim((string) ($_POST['attendance_result'] ?? ''));
$allowTodayExit = ($_POST['allow_today_exit'] ?? '') === '1';
$requestedScheduleId = max(0, (int) ($_POST['schedule_id'] ?? 0));
$projectProvided = array_key_exists('project_id', $_POST);
$projectValue = trim((string) ($_POST['project_id'] ?? ''));
$projectId = $projectValue === '' ? 0 : (ctype_digit($projectValue) ? (int) $projectValue : -1);

if (!$workerId || !manual_valid_date($markDate)) manual_response(['ok' => false, 'message' => 'El trabajador o la fecha no son válidos.'], 422);
if ($markDate > date('Y-m-d')) manual_response(['ok' => false, 'message' => 'No se pueden corregir jornadas futuras.'], 409);
$sameDay = $markDate === date('Y-m-d');
if (!in_array($attendanceResult, ['puntual', 'tardanza', 'falta'], true)) manual_response(['ok' => false, 'message' => 'Seleccione el resultado de la asistencia.'], 422);
if ($reason === '' || mb_strlen($reason) > 500) manual_response(['ok' => false, 'message' => 'Ingrese el motivo de la corrección.'], 422);
if ($projectProvided && $projectId < 0) manual_response(['ok' => false, 'message' => 'Seleccione un proyecto válido.'], 422);

$pdo = db();
$actor = current_user();
$actorId = (int) ($actor['id'] ?? 0) ?: null;
$actorName = trim((string) ($actor['name'] ?? 'Administrador'));
if ($projectProvided && $projectId > 0) {
    $projectCheck = $pdo->prepare('SELECT id FROM attendance_projects WHERE id=:project AND status=1 LIMIT 1');
    $projectCheck->execute(['project' => $projectId]);
    if (!$projectCheck->fetchColumn()) manual_response(['ok' => false, 'message' => 'Seleccione un proyecto activo.'], 422);
}

if ($attendanceResult === 'falta') {
    try {
        $pdo->beginTransaction();
        $workerStmt = $pdo->prepare('SELECT id FROM workers WHERE id = :id LIMIT 1 FOR UPDATE');
        $workerStmt->execute(['id' => $workerId]);
        if (!$workerStmt->fetchColumn()) {
            throw new RuntimeException('El trabajador no existe.');
        }
        if ($sameDay) {
            $existingMarkStmt = $pdo->prepare('SELECT id FROM attendance_marks WHERE worker_id=:worker AND mark_date=:date LIMIT 1 FOR UPDATE');
            $existingMarkStmt->execute(['worker' => $workerId, 'date' => $markDate]);
            if (!$existingMarkStmt->fetchColumn()) {
                throw new DomainException('Para corregir la asistencia de hoy debe existir una entrada o salida registrada.');
            }
        }
        $override = $pdo->prepare("INSERT INTO attendance_manual_day_overrides
            (worker_id, mark_date, attendance_status, reason, adjusted_by_user_id)
            VALUES (:worker, :date, 'falta', :reason, :user)
            ON DUPLICATE KEY UPDATE attendance_status='falta', reason=VALUES(reason),
                adjusted_by_user_id=VALUES(adjusted_by_user_id), updated_at=CURRENT_TIMESTAMP");
        $override->execute(['worker' => $workerId, 'date' => $markDate, 'reason' => $reason, 'user' => $actorId]);
        $pdo->commit();
        manual_response(['ok' => true, 'message' => 'La jornada fue registrada como falta y las marcaciones originales se conservaron.']);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof DomainException) manual_response(['ok' => false, 'message' => $error->getMessage()], 409);
        manual_response(['ok' => false, 'message' => 'No se pudo registrar la falta. Ejecute la actualización SQL de correcciones manuales.'], 500);
    }
}

if ($entryTime === '' && $exitTime === '' && !$requestedScheduleId && !$projectProvided) manual_response(['ok' => false, 'message' => 'Ingrese al menos la hora de entrada o de salida.'], 422);
if ($sameDay && $exitTime !== '' && !$allowTodayExit) manual_response(['ok' => false, 'message' => 'Pulse «Permitir editar» antes de guardar la salida de hoy.'], 422);
if (($entryTime !== '' && !manual_valid_time($entryTime)) || ($exitTime !== '' && !manual_valid_time($exitTime))) manual_response(['ok' => false, 'message' => 'Ingrese horas válidas.'], 422);
if ($entryTime !== '' && $exitTime !== '' && $exitTime < $entryTime) manual_response(['ok' => false, 'message' => 'La hora de salida no puede ser anterior a la entrada.'], 422);
if ($sameDay && (($entryTime !== '' && $entryTime > date('H:i')) || ($exitTime !== '' && $exitTime > date('H:i')))) {
    manual_response(['ok' => false, 'message' => 'La hora corregida no puede ser futura.'], 422);
}

$locationStmt = $pdo->prepare('SELECT id FROM attendance_locations WHERE id = :id AND status = 1 LIMIT 1');
foreach (['entrada' => [$entryTime, $entryLocationId], 'salida' => [$exitTime, $exitLocationId]] as $type => [$time, $locationId]) {
    if ($time === '') continue;
    $locationStmt->execute(['id' => $locationId]);
    if (!$locationStmt->fetchColumn()) manual_response(['ok' => false, 'message' => 'Seleccione un lugar de marcación de ' . $type . ' válido.'], 422);
}

$stmt = $pdo->prepare("SELECT aa.*, am.schedule_id AS marked_schedule_id FROM attendance_marks am
    JOIN attendance_assignments aa ON aa.id=am.assignment_id
    WHERE am.worker_id=:worker AND am.mark_date=:date
    ORDER BY (am.mark_type='entrada') DESC,am.mark_time,am.id LIMIT 1");
$stmt->execute(['worker' => $workerId, 'date' => $markDate]);
$assignment = $stmt->fetch() ?: null;
if (!$assignment && !$sameDay) {
    $stmt = $pdo->prepare("SELECT * FROM attendance_assignments WHERE worker_id=:worker AND valid_from<=:date1
        AND (valid_until IS NULL OR valid_until>=:date2) ORDER BY status DESC,valid_from DESC,id DESC LIMIT 1");
    $stmt->execute(['worker' => $workerId, 'date1' => $markDate, 'date2' => $markDate]);
    $assignment = $stmt->fetch() ?: null;
}
if (!$assignment) manual_response(['ok' => false, 'message' => 'El trabajador no tiene una asignación aplicable para esa fecha.'], 409);

$stmt = $pdo->prepare("SELECT * FROM attendance_programs WHERE worker_id=:worker AND assignment_id=:assignment
    AND program_date=:date AND status='programada' ORDER BY id DESC LIMIT 1");
$stmt->execute(['worker' => $workerId, 'assignment' => (int) $assignment['id'], 'date' => $markDate]);
$program = $stmt->fetch() ?: null;
$markedScheduleId = (int) ($assignment['marked_schedule_id'] ?? 0);
$scheduleId = $requestedScheduleId ?: ($markedScheduleId ?: (int) ($program['schedule_id'] ?? $assignment['schedule_id']));
if ($requestedScheduleId) {
    if (!$markedScheduleId) manual_response(['ok' => false, 'message' => 'Solo puede cambiar el horario de una jornada con marcación registrada.'], 409);
    $scheduleCheck = $pdo->prepare('SELECT id FROM attendance_schedules WHERE id=:schedule AND status=1 LIMIT 1');
    $scheduleCheck->execute(['schedule' => $scheduleId]);
    if (!$scheduleCheck->fetchColumn()) manual_response(['ok' => false, 'message' => 'Seleccione un horario activo.'], 422);
    $dayCheck = $pdo->prepare('SELECT id FROM attendance_schedule_days WHERE schedule_id=:schedule AND day_of_week=:day AND status=1 LIMIT 1');
    $dayCheck->execute(['schedule' => $scheduleId, 'day' => (int) date('N', strtotime($markDate))]);
    if (!$dayCheck->fetchColumn()) manual_response(['ok' => false, 'message' => 'El horario seleccionado no está configurado para este día.'], 422);
}
$scheduleDay = null;
if (!$program || $requestedScheduleId || $markedScheduleId) {
    $stmt = $pdo->prepare('SELECT * FROM attendance_schedule_days WHERE schedule_id=:schedule AND day_of_week=:day AND status=1 LIMIT 1');
    $stmt->execute(['schedule' => $scheduleId, 'day' => (int) date('N', strtotime($markDate))]);
    $scheduleDay = $stmt->fetch() ?: null;
}
$useProgramHours = $program && (!$markedScheduleId || (int) $program['schedule_id'] === $scheduleId);
$officialExit = substr((string) (($useProgramHours ? $program['exit_time'] : null) ?? $scheduleDay['exit_time'] ?? $scheduleDay['exit_start'] ?? '00:00:00'), 0, 8);
$officialEntry = (string) (($useProgramHours ? $program['entry_time'] : null) ?? $scheduleDay['entry_time'] ?? $scheduleDay['entry_start'] ?? '');
$entryTolerance = max(0, (int) (($useProgramHours ? $program['tolerance_minutes'] : null) ?? $scheduleDay['tolerance_minutes'] ?? 0));

$saveMark = static function (string $type, string $time, int $locationId) use ($pdo, $workerId, $markDate, $assignment, $program, $scheduleId, $officialExit, $reason, $actorId, $actorName, $attendanceResult, $sameDay, $allowTodayExit, $requestedScheduleId, $markedScheduleId, $projectProvided, $projectId): void {
    $normalized = $time . ':00';
    $status = $type === 'entrada' ? $attendanceResult : ($normalized >= $officialExit ? 'salida_valida' : 'salida_anticipada');
    $markedAt = $markDate . ' ' . $normalized;
    $markOrder = $type === 'entrada' ? 'mark_time ASC,id ASC' : 'mark_time DESC,id DESC';
    $find = $pdo->prepare("SELECT * FROM attendance_marks WHERE worker_id=:worker AND mark_date=:date AND mark_type=:type ORDER BY {$markOrder} LIMIT 1 FOR UPDATE");
    $find->execute(['worker' => $workerId, 'date' => $markDate, 'type' => $type]);
    $existing = $find->fetch() ?: null;
    if ($existing && $requestedScheduleId > 0 && $requestedScheduleId !== $markedScheduleId
        && $normalized === (string) $existing['mark_time'] && $locationId === (int) $existing['location_id']) return;
    if ($existing && $projectProvided && (int) ($existing['project_id'] ?? 0) !== $projectId
        && $normalized === (string) $existing['mark_time'] && $locationId === (int) $existing['location_id']
        && $status === (string) $existing['final_status']) return;
    if ($sameDay && !$existing && !($type === 'salida' && $allowTodayExit)) {
        throw new DomainException('La marcación de ' . $type . ' aún no existe. Para registrarla, use Marcación administrativa de hoy.');
    }
    if ($sameDay && $type === 'salida') {
        $entryCheck = $pdo->prepare("SELECT mark_time FROM attendance_marks WHERE worker_id=:worker AND mark_date=:date AND mark_type='entrada' ORDER BY mark_time,id LIMIT 1 FOR UPDATE");
        $entryCheck->execute(['worker' => $workerId, 'date' => $markDate]);
        $firstEntryTime = $entryCheck->fetchColumn();
        if (!$firstEntryTime) throw new DomainException('Primero debe existir una entrada registrada para completar la salida de hoy.');
        if ($normalized < (string) $firstEntryTime) throw new DomainException('La salida no puede ser anterior a la entrada.');
    }
    if ($existing && $type === 'entrada' && $sameDay && $normalized === (string) $existing['mark_time']
        && $locationId === (int) $existing['location_id'] && $status === (string) $existing['final_status']) return;
    $note = 'Corrección manual por ' . $actorName . ': ' . $reason;
    if ($existing) {
        $markId = (int) $existing['id'];
        $previous = (string) $existing['mark_time'];
        $update = $pdo->prepare("UPDATE attendance_marks SET mark_time=:time,marked_at=:marked,location_id=:location,schedule_status=:status,
            final_status=:final,observations=CONCAT_WS(CHAR(10),NULLIF(observations,''),:note) WHERE id=:id");
        $update->execute(['time' => $normalized, 'marked' => $markedAt, 'location' => $locationId, 'status' => $status, 'final' => $status, 'note' => $note, 'id' => $markId]);
    } else {
        $previous = null;
        $insert = $pdo->prepare("INSERT INTO attendance_marks
            (assignment_id,program_id,worker_id,location_id,schedule_id,mark_type,mark_date,mark_time,marked_at,latitude,longitude,accuracy_meters,address,distance_meters,within_radius,schedule_status,location_status,final_status,photo_path,evidence_path,observations)
            VALUES (:assignment,:program,:worker,:location,:schedule,:type,:date,:time,:marked,0,0,0,'Registro manual administrativo',0,1,:status,'ajuste_manual',:final,NULL,NULL,:note)");
        $insert->execute(['assignment' => (int) $assignment['id'], 'program' => $program['id'] ?? null, 'worker' => $workerId, 'location' => $locationId, 'schedule' => $scheduleId, 'type' => $type, 'date' => $markDate, 'time' => $normalized, 'marked' => $markedAt, 'status' => $status, 'final' => $status, 'note' => $note]);
        $markId = (int) $pdo->lastInsertId();
    }
    $audit = $pdo->prepare('INSERT INTO attendance_manual_adjustments
        (attendance_mark_id,worker_id,mark_date,mark_type,previous_time,new_time,previous_location_id,new_location_id,previous_status,new_status,reason,adjusted_by_user_id)
        VALUES (:mark,:worker,:date,:type,:previous,:new_time,:previous_location,:new_location,:previous_status,:new_status,:reason,:user)');
    $audit->execute(['mark' => $markId, 'worker' => $workerId, 'date' => $markDate, 'type' => $type, 'previous' => $previous, 'new_time' => $normalized, 'previous_location' => $existing['location_id'] ?? null, 'new_location' => $locationId, 'previous_status' => $existing['final_status'] ?? null, 'new_status' => $status, 'reason' => $reason, 'user' => $actorId]);
};

try {
    $pdo->beginTransaction();
    $clearOverride = $pdo->prepare('DELETE FROM attendance_manual_day_overrides WHERE worker_id=:worker AND mark_date=:date');
    $clearOverride->execute(['worker' => $workerId, 'date' => $markDate]);
    if ($entryTime !== '') $saveMark('entrada', $entryTime, $entryLocationId);
    if ($exitTime !== '') $saveMark('salida', $exitTime, $exitLocationId);
    if ($requestedScheduleId) {
        $marksStmt = $pdo->prepare('SELECT id, mark_type, mark_time, location_id, location_status, schedule_id, final_status FROM attendance_marks WHERE worker_id=:worker AND mark_date=:date FOR UPDATE');
        $marksStmt->execute(['worker' => $workerId, 'date' => $markDate]);
        $updateSchedule = $pdo->prepare('UPDATE attendance_marks SET schedule_id=:schedule, schedule_status=:schedule_status, final_status=:final_status WHERE id=:id');
        $auditSchedule = $pdo->prepare('INSERT INTO attendance_manual_adjustments
            (attendance_mark_id,worker_id,mark_date,mark_type,previous_time,new_time,previous_location_id,new_location_id,previous_status,new_status,reason,adjusted_by_user_id)
            VALUES (:mark,:worker,:date,:type,:previous_time,:new_time,:previous_location,:new_location,:previous_status,:new_status,:reason,:user)');
        foreach ($marksStmt->fetchAll() as $mark) {
            if ((int) $mark['schedule_id'] === $requestedScheduleId) continue;
            $newStatus = (string) $mark['final_status'];
            if ($mark['mark_type'] === 'salida') {
                $newStatus = (string) $mark['mark_time'] >= $officialExit ? 'salida_valida' : 'salida_anticipada';
            } elseif ($officialEntry !== '' && !($mark['location_status'] === 'registro_administrativo' && $newStatus === 'puntual')) {
                $delaySeconds = strtotime('2000-01-01 ' . (string) $mark['mark_time']) - strtotime('2000-01-01 ' . $officialEntry);
                $newStatus = $delaySeconds > $entryTolerance * 60 ? 'tardanza' : 'puntual';
            }
            $updateSchedule->execute(['schedule' => $requestedScheduleId, 'schedule_status' => $newStatus, 'final_status' => $newStatus, 'id' => (int) $mark['id']]);
            $auditSchedule->execute([
                'mark' => (int) $mark['id'], 'worker' => $workerId, 'date' => $markDate,
                'type' => $mark['mark_type'], 'previous_time' => $mark['mark_time'], 'new_time' => $mark['mark_time'],
                'previous_location' => $mark['location_id'], 'new_location' => $mark['location_id'],
                'previous_status' => $mark['final_status'], 'new_status' => $newStatus,
                'reason' => 'Horario ' . (int) $mark['schedule_id'] . ' → ' . $requestedScheduleId . '. ' . mb_substr($reason, 0, 450),
                'user' => $actorId,
            ]);
        }
    }
    if ($projectProvided) {
        $projectMarks = $pdo->prepare('SELECT id, mark_type, mark_time, location_id, project_id, final_status FROM attendance_marks WHERE worker_id=:worker AND mark_date=:date FOR UPDATE');
        $projectMarks->execute(['worker' => $workerId, 'date' => $markDate]);
        $marks = $projectMarks->fetchAll();
        if (!$marks) throw new DomainException('No hay marcaciones registradas para cambiar el proyecto de esta fecha.');
        $updateProject = $pdo->prepare('UPDATE attendance_marks SET project_id=:project WHERE id=:id');
        $auditProject = $pdo->prepare('INSERT INTO attendance_manual_adjustments
            (attendance_mark_id,worker_id,mark_date,mark_type,previous_time,new_time,previous_location_id,new_location_id,previous_status,new_status,reason,adjusted_by_user_id)
            VALUES (:mark,:worker,:date,:type,:previous_time,:new_time,:previous_location,:new_location,:previous_status,:new_status,:reason,:user)');
        foreach ($marks as $mark) {
            $previousProjectId = (int) ($mark['project_id'] ?? 0);
            if ($previousProjectId === $projectId) continue;
            $updateProject->execute(['project' => $projectId ?: null, 'id' => (int) $mark['id']]);
            $auditProject->execute([
                'mark' => (int) $mark['id'], 'worker' => $workerId, 'date' => $markDate,
                'type' => $mark['mark_type'], 'previous_time' => $mark['mark_time'], 'new_time' => $mark['mark_time'],
                'previous_location' => $mark['location_id'], 'new_location' => $mark['location_id'],
                'previous_status' => $mark['final_status'], 'new_status' => $mark['final_status'],
                'reason' => 'Proyecto ' . ($previousProjectId ?: 'ninguno') . ' → ' . ($projectId ?: 'ninguno') . '. ' . mb_substr($reason, 0, 430),
                'user' => $actorId,
            ]);
        }
    }
    $pdo->commit();
    manual_response(['ok' => true, 'message' => 'La asistencia fue corregida y auditada correctamente.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error instanceof DomainException) manual_response(['ok' => false, 'message' => $error->getMessage()], 409);
    error_log('Error al guardar corrección de asistencia: ' . $error->getMessage());
    manual_response(['ok' => false, 'message' => 'No se pudo guardar la corrección. Revise el registro de errores del servidor.'], 500);
}
