<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_location_access.php';
require_role('Administrador');
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = max(0, (int)($_POST['worker_id'] ?? 0));
$date = trim((string)($_POST['mark_date'] ?? ''));
$type = (string)($_POST['mark_type'] ?? '');
$time = trim((string)($_POST['mark_time'] ?? ''));
$scheduleId = max(0, (int)($_POST['schedule_id'] ?? 0));
$locationId = max(0, (int)($_POST['location_id'] ?? 0));
$projectId = max(0, (int)($_POST['project_id'] ?? 0));
$attendanceResult = trim((string)($_POST['attendance_result'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));
$today = date('Y-m-d');

if ($workerId <= 0) json_response(['ok'=>false,'message'=>'Seleccione un trabajador válido.'], 422);
if ($attendanceResult !== 'falta' && ($scheduleId <= 0 || $locationId <= 0 || $projectId <= 0 || !in_array($type, ['entrada','salida'], true))) {
    json_response(['ok'=>false,'message'=>'Seleccione trabajador, tipo, horario, lugar y proyecto válidos.'], 422);
}
if ($date !== $today) json_response(['ok'=>false,'message'=>'Solo se puede registrar administrativamente la jornada de hoy.'], 422);
if ($attendanceResult !== 'falta' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) json_response(['ok'=>false,'message'=>'Ingrese una hora válida.'], 422);
if ($attendanceResult !== 'falta' && strtotime($date . ' ' . $time . ':00') > time()) json_response(['ok'=>false,'message'=>'La hora de marcación no puede ser futura.'], 422);
if ($reason === '' || mb_strlen($reason) > 500) json_response(['ok'=>false,'message'=>'Ingrese un motivo de hasta 500 caracteres.'], 422);

if ($attendanceResult !== 'falta' && $type === 'entrada' && !in_array($attendanceResult, ['puntual', 'tardanza'], true)) {
    json_response(['ok'=>false,'message'=>'Seleccione el resultado de asistencia.'], 422);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $workerStmt = $pdo->prepare('SELECT id FROM workers WHERE id=:id FOR UPDATE');
    $workerStmt->execute(['id'=>$workerId]);
    if (!$workerStmt->fetchColumn()) throw new DomainException('El trabajador ya no existe.');

    if ($attendanceResult === 'falta') {
        $actorId = (int)(current_user()['id'] ?? 0) ?: null;
        $override = $pdo->prepare("INSERT INTO attendance_manual_day_overrides
            (worker_id, mark_date, attendance_status, reason, adjusted_by_user_id)
            VALUES (:worker, :date, 'falta', :reason, :user)
            ON DUPLICATE KEY UPDATE attendance_status='falta', reason=VALUES(reason),
                adjusted_by_user_id=VALUES(adjusted_by_user_id), updated_at=CURRENT_TIMESTAMP");
        $override->execute(['worker'=>$workerId,'date'=>$today,'reason'=>$reason,'user'=>$actorId]);
        $pdo->commit();
        json_response(['ok'=>true,'message'=>'La jornada fue registrada como falta. Las marcaciones existentes se conservaron.','status'=>'falta']);
    }

    $catalogStmt = $pdo->prepare("SELECT s.id AS schedule_id, l.id AS location_id, p.name AS project_name
        FROM attendance_schedules s
        JOIN attendance_locations l ON l.id=:location_id AND l.status=1
        JOIN attendance_projects p ON p.id=:project_id AND p.status=1
        WHERE s.id=:schedule_id AND s.status=1 LIMIT 1");
    $catalogStmt->execute(['schedule_id'=>$scheduleId,'location_id'=>$locationId,'project_id'=>$projectId]);
    $catalog = $catalogStmt->fetch();
    if (!attendance_worker_can_use_location($pdo, $workerId, $locationId, $today)) {
        throw new DomainException('El trabajador no está autorizado en este lugar de marcación. Configure su personal en Lugares de marcación.');
    }
    if (!$catalog) throw new DomainException('El horario, lugar o proyecto ya no está disponible.');
    $dayStmt = $pdo->prepare('SELECT entry_time,entry_start,entry_end,exit_time,exit_start FROM attendance_schedule_days WHERE schedule_id=:schedule AND day_of_week=:day AND status=1 LIMIT 1');
    $dayStmt->execute(['schedule'=>$scheduleId,'day'=>(int)date('N')]);
    $scheduleDay = $dayStmt->fetch();
    if (!$scheduleDay) throw new DomainException('El horario no tiene una jornada configurada para hoy.');
    if ($type === 'entrada') {
        $officialEntry = substr((string) ($scheduleDay['entry_time'] ?: $scheduleDay['entry_start']), 0, 5);
        $earliestEntry = substr((string) ($scheduleDay['entry_start'] ?: $scheduleDay['entry_time']), 0, 5);
        $earliestTimestamp = strtotime($today . ' ' . $earliestEntry);
        $markTimestamp = strtotime($today . ' ' . $time);
        if ($earliestTimestamp === false || $markTimestamp === false) throw new DomainException('La ventana de entrada del horario no es válida.');
        if ($earliestEntry > $officialEntry) $earliestTimestamp -= 86400;
        if ($markTimestamp < $earliestTimestamp) {
            throw new DomainException('Este horario permite marcar entrada desde las ' . $earliestEntry . '. Ajuste «Puede marcar antes» en la plantilla si necesita una entrada más temprana.');
        }
    }

    $marksStmt = $pdo->prepare('SELECT id,mark_type,mark_time,assignment_id,schedule_id FROM attendance_marks WHERE worker_id=:worker AND mark_date=:date ORDER BY marked_at,id FOR UPDATE');
    $marksStmt->execute(['worker'=>$workerId,'date'=>$today]);
    $marks = $marksStmt->fetchAll();
    $entries = array_values(array_filter($marks, static fn(array $mark): bool => $mark['mark_type'] === 'entrada'));
    $exits = array_values(array_filter($marks, static fn(array $mark): bool => $mark['mark_type'] === 'salida'));
    if ($type === 'entrada' && $entries) throw new DomainException('La entrada de hoy ya está registrada.');
    if ($exits) throw new DomainException('La salida de hoy ya está registrada; la jornada está cerrada.');
    if ($type === 'salida') {
        if (!$entries) throw new DomainException('Primero debe registrarse una entrada.');
        $firstEntry = $entries[0];
        if ($time < substr((string)$firstEntry['mark_time'], 0, 5)) throw new DomainException('La salida no puede ser anterior a la entrada.');
        if ((int)$firstEntry['schedule_id'] !== $scheduleId) throw new DomainException('Seleccione el mismo horario usado en la entrada.');
        $assignmentId = (int)$firstEntry['assignment_id'];
    } else {
        $assignmentStmt = $pdo->prepare("SELECT id FROM attendance_assignments
            WHERE worker_id=:worker AND location_id=:location AND schedule_id=:schedule
              AND valid_from<=:date_from AND (valid_until IS NULL OR valid_until>=:date_until)
            ORDER BY status DESC,id DESC LIMIT 1");
        $assignmentStmt->execute(['worker'=>$workerId,'location'=>$locationId,'schedule'=>$scheduleId,'date_from'=>$today,'date_until'=>$today]);
        $assignmentId = (int)$assignmentStmt->fetchColumn();
        if (!$assignmentId) {
            $createAssignment = $pdo->prepare('INSERT INTO attendance_assignments
                (worker_id,location_id,schedule_id,activity,instructions,valid_from,valid_until,status,created_by_user_id)
                VALUES (:worker,:location,:schedule,:activity,:instructions,:date_from,:date_until,0,:user)');
            $createAssignment->execute([
                'worker'=>$workerId,'location'=>$locationId,'schedule'=>$scheduleId,
                'activity'=>$catalog['project_name'],
                'instructions'=>'Registro generado desde marcación administrativa.',
                'date_from'=>$today,'date_until'=>$today,
                'user'=>(int)(current_user()['id'] ?? 0) ?: null,
            ]);
            $assignmentId = (int)$pdo->lastInsertId();
        }
    }

    $limit = $type === 'entrada'
        ? substr((string)($scheduleDay['entry_end'] ?: $scheduleDay['entry_time']), 0, 5)
        : substr((string)($scheduleDay['exit_time'] ?: $scheduleDay['exit_start']), 0, 5);
    if ($limit === '') throw new DomainException('El horario no tiene una hora válida para esta marcación.');
    $status = $type === 'entrada' ? $attendanceResult
        : ($time >= $limit ? 'salida_valida' : 'salida_anticipada');
    $actor = current_user();
    $actorId = (int)($actor['id'] ?? 0) ?: null;
    $actorName = trim((string)($actor['name'] ?? 'Administrador'));
    $note = 'Registro administrativo por ' . $actorName . ': ' . $reason;

    $insert = $pdo->prepare("INSERT INTO attendance_marks
        (assignment_id,program_id,worker_id,location_id,schedule_id,project_id,mark_type,mark_date,mark_time,marked_at,
         latitude,longitude,accuracy_meters,address,distance_meters,within_radius,schedule_status,location_status,final_status,photo_path,evidence_path,observations)
        VALUES (:assignment,NULL,:worker,:location,:schedule,:project,:type,:date,:time,:marked,
         0,0,0,'Registro administrativo sin GPS',0,0,:schedule_status,'registro_administrativo',:final_status,NULL,NULL,:observations)");
    $insert->execute([
        'assignment'=>$assignmentId,'worker'=>$workerId,'location'=>$locationId,'schedule'=>$scheduleId,
        'project'=>$projectId,'type'=>$type,'date'=>$today,'time'=>$time . ':00',
        'marked'=>$today . ' ' . $time . ':00','schedule_status'=>$status,'final_status'=>$status,'observations'=>$note,
    ]);
    $markId = (int)$pdo->lastInsertId();
    $audit = $pdo->prepare('INSERT INTO attendance_manual_adjustments
        (attendance_mark_id,worker_id,mark_date,mark_type,previous_time,new_time,previous_location_id,new_location_id,previous_status,new_status,reason,adjusted_by_user_id)
        VALUES (:mark,:worker,:date,:type,NULL,:time,NULL,:location,NULL,:status,:reason,:user)');
    $audit->execute(['mark'=>$markId,'worker'=>$workerId,'date'=>$today,'type'=>$type,'time'=>$time . ':00',
        'location'=>$locationId,'status'=>$status,'reason'=>$reason,'user'=>$actorId]);
    $clearOverride = $pdo->prepare('DELETE FROM attendance_manual_day_overrides WHERE worker_id=:worker AND mark_date=:date');
    $clearOverride->execute(['worker'=>$workerId,'date'=>$today]);
    $pdo->commit();
    json_response(['ok'=>true,'message'=>ucfirst($type) . ' registrada administrativamente.','status'=>$status]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $controlled = $error instanceof DomainException;
    json_response(['ok'=>false,'message'=>$controlled ? $error->getMessage() : 'No se pudo registrar la marcación administrativa.'], $controlled ? 409 : 500);
}
