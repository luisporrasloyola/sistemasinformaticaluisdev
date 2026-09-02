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
$requestedLocationId = max(0, (int) ($_POST['location_id'] ?? 0));
$attendanceResult = trim((string) ($_POST['attendance_result'] ?? ''));

if (!$workerId || !manual_valid_date($markDate)) manual_response(['ok' => false, 'message' => 'El trabajador o la fecha no son válidos.'], 422);
if ($markDate >= date('Y-m-d')) manual_response(['ok' => false, 'message' => 'Solo se pueden corregir jornadas anteriores al día actual.'], 409);
if (!in_array($attendanceResult, ['puntual', 'tardanza', 'falta'], true)) manual_response(['ok' => false, 'message' => 'Seleccione el resultado de la asistencia.'], 422);
if ($reason === '' || mb_strlen($reason) > 500) manual_response(['ok' => false, 'message' => 'Ingrese el motivo de la corrección.'], 422);

$pdo = db();
$actor = current_user();
$actorId = (int) ($actor['id'] ?? 0) ?: null;
$actorName = trim((string) ($actor['name'] ?? 'Administrador'));

if ($attendanceResult === 'falta') {
    try {
        $pdo->beginTransaction();
        $workerStmt = $pdo->prepare('SELECT id FROM workers WHERE id = :id LIMIT 1 FOR UPDATE');
        $workerStmt->execute(['id' => $workerId]);
        if (!$workerStmt->fetchColumn()) {
            throw new RuntimeException('El trabajador no existe.');
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
        manual_response(['ok' => false, 'message' => 'No se pudo registrar la falta. Ejecute la actualización SQL de correcciones manuales.'], 500);
    }
}

if ($entryTime === '' && $exitTime === '') manual_response(['ok' => false, 'message' => 'Ingrese al menos la hora de entrada o de salida.'], 422);
if (($entryTime !== '' && !manual_valid_time($entryTime)) || ($exitTime !== '' && !manual_valid_time($exitTime))) manual_response(['ok' => false, 'message' => 'Ingrese horas válidas.'], 422);
if ($entryTime !== '' && $exitTime !== '' && $exitTime < $entryTime) manual_response(['ok' => false, 'message' => 'La hora de salida no puede ser anterior a la entrada.'], 422);

$locationStmt = $pdo->prepare('SELECT id, name FROM attendance_locations WHERE id = :id AND status = 1 LIMIT 1');
$locationStmt->execute(['id' => $requestedLocationId]);
if (!$locationStmt->fetch()) manual_response(['ok' => false, 'message' => 'Seleccione un lugar de marcación válido.'], 422);

$stmt = $pdo->prepare("SELECT * FROM attendance_assignments WHERE worker_id=:worker AND valid_from<=:date1
    AND (valid_until IS NULL OR valid_until>=:date2) ORDER BY status DESC,valid_from DESC,id DESC LIMIT 1");
$stmt->execute(['worker' => $workerId, 'date1' => $markDate, 'date2' => $markDate]);
$assignment = $stmt->fetch();
if (!$assignment) manual_response(['ok' => false, 'message' => 'El trabajador no tiene una asignación aplicable para esa fecha.'], 409);

$stmt = $pdo->prepare("SELECT * FROM attendance_programs WHERE worker_id=:worker AND assignment_id=:assignment
    AND program_date=:date AND status='programada' ORDER BY id DESC LIMIT 1");
$stmt->execute(['worker' => $workerId, 'assignment' => (int) $assignment['id'], 'date' => $markDate]);
$program = $stmt->fetch() ?: null;
$scheduleId = (int) ($program['schedule_id'] ?? $assignment['schedule_id']);
$scheduleDay = null;
if (!$program) {
    $stmt = $pdo->prepare('SELECT * FROM attendance_schedule_days WHERE schedule_id=:schedule AND day_of_week=:day AND status=1 LIMIT 1');
    $stmt->execute(['schedule' => $scheduleId, 'day' => (int) date('N', strtotime($markDate))]);
    $scheduleDay = $stmt->fetch() ?: null;
}
$officialExit = substr((string) ($program['exit_time'] ?? $scheduleDay['exit_time'] ?? $scheduleDay['exit_start'] ?? '00:00:00'), 0, 8);

$saveMark = static function (string $type, string $time) use ($pdo, $workerId, $markDate, $assignment, $program, $scheduleId, $requestedLocationId, $officialExit, $reason, $actorId, $actorName, $attendanceResult): void {
    $normalized = $time . ':00';
    $status = $type === 'entrada' ? $attendanceResult : ($normalized >= $officialExit ? 'salida_valida' : 'salida_anticipada');
    $markedAt = $markDate . ' ' . $normalized;
    $markOrder = $type === 'entrada' ? 'mark_time ASC,id ASC' : 'mark_time DESC,id DESC';
    $find = $pdo->prepare("SELECT * FROM attendance_marks WHERE worker_id=:worker AND mark_date=:date AND mark_type=:type ORDER BY {$markOrder} LIMIT 1 FOR UPDATE");
    $find->execute(['worker' => $workerId, 'date' => $markDate, 'type' => $type]);
    $existing = $find->fetch() ?: null;
    $locationId = $existing ? (int) $existing['location_id'] : $requestedLocationId;
    if ($existing && substr((string) $existing['mark_time'], 0, 5) === $time && (string) $existing['final_status'] === $status) return;

    $note = 'Corrección manual por ' . $actorName . ': ' . $reason;
    if ($existing) {
        $markId = (int) $existing['id'];
        $previous = (string) $existing['mark_time'];
        $update = $pdo->prepare("UPDATE attendance_marks SET mark_time=:time,marked_at=:marked,schedule_status=:status,
            final_status=:final,observations=CONCAT_WS(CHAR(10),NULLIF(observations,''),:note) WHERE id=:id");
        $update->execute(['time' => $normalized, 'marked' => $markedAt, 'status' => $status, 'final' => $status, 'note' => $note, 'id' => $markId]);
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
    if ($entryTime !== '') $saveMark('entrada', $entryTime);
    if ($exitTime !== '') $saveMark('salida', $exitTime);
    $pdo->commit();
    manual_response(['ok' => true, 'message' => 'La asistencia fue corregida y auditada correctamente.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    manual_response(['ok' => false, 'message' => 'No se pudo guardar. Verifique que la actualización SQL esté instalada.'], 500);
}