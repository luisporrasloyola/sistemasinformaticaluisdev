<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_projects.php';
require_once __DIR__ . '/../../includes/attendance_overnight.php';
require_module_access('control_personal.control_asistencia');
ensure_quick_attendance_marking_schema();

$requestedWorkerId = (int) ($_GET['worker_id'] ?? 0);
$linkedWorkerId = (int) (current_user_worker_id() ?? 0);
$workerId = is_personal_role() ? $linkedWorkerId : $requestedWorkerId;
if ($workerId <= 0) json_response(['ok'=>false,'message'=>'Seleccione un trabajador.'],400);

$workerStmt = db()->prepare('SELECT id,full_name,document_number,company_id FROM workers WHERE id=:id LIMIT 1');
$workerStmt->execute(['id'=>$workerId]);
$worker = $workerStmt->fetch();
if (!$worker) json_response(['ok'=>false,'message'=>'El trabajador no existe.'],404);

$today = date('Y-m-d');
$overnight = attendance_overnight_context(db(), $workerId, new DateTimeImmutable());
$workDate = $overnight['work_date'] ?? $today;
$holidayStmt = db()->prepare("SELECT event_type FROM attendance_calendar_days
    WHERE status = 1 AND event_type IN ('holiday', 'non_working', 'vacation', 'permission', 'rest')
      AND calendar_date <= :today_start AND COALESCE(end_date, calendar_date) >= :today_end
      AND (scope_type = 'all'
        OR (scope_type = 'company' AND company_id = :company_id)
        OR (scope_type = 'worker' AND worker_id = :worker_id))
    ORDER BY CASE scope_type WHEN 'worker' THEN 3 WHEN 'company' THEN 2 ELSE 1 END DESC, id DESC
    LIMIT 1");
$holidayStmt->execute(['today_start' => $workDate, 'today_end' => $workDate,
    'company_id' => (int) $worker['company_id'], 'worker_id' => $workerId]);
$holidayToday = $holidayStmt->fetchColumn() === 'holiday';
$assignmentStmt = db()->prepare("SELECT aa.id,aa.location_id,aa.schedule_id,aa.activity,l.name AS location_name,s.name AS schedule_name
    FROM attendance_assignments aa
    JOIN attendance_locations l ON l.id=aa.location_id AND l.status=1
    JOIN attendance_schedules s ON s.id=aa.schedule_id AND s.status=1
    WHERE aa.worker_id=:worker_id AND aa.status=1 AND aa.valid_from<=:today
      AND (aa.valid_until IS NULL OR aa.valid_until>=:today_until)
    ORDER BY aa.id DESC LIMIT 1");
$assignmentStmt->execute(['worker_id'=>$workerId,'today'=>$workDate,'today_until'=>$workDate]);
$assignment = $assignmentStmt->fetch() ?: null;

$marksStmt = db()->prepare("SELECT id,mark_type,mark_time,marked_at,location_id,schedule_id,project_id
    FROM attendance_marks WHERE worker_id=:worker_id AND mark_date=:today ORDER BY marked_at,id");
$marksStmt->execute(['worker_id'=>$workerId,'today'=>$workDate]);
$marks = $marksStmt->fetchAll();
$lastMark = $marks ? $marks[array_key_last($marks)] : null;
$hasEntry = (bool)array_filter($marks,static fn(array $mark):bool=>$mark['mark_type']==='entrada');
$hasExit = (bool)array_filter($marks,static fn(array $mark):bool=>$mark['mark_type']==='salida');

$defaultLocationId = (int)($lastMark['location_id'] ?? $assignment['location_id'] ?? 0);
$defaultScheduleId = (int)($lastMark['schedule_id'] ?? $assignment['schedule_id'] ?? 0);
$defaultProjectId = (int)($lastMark['project_id'] ?? 0);
if (!$defaultProjectId && !empty($assignment['activity'])) {
    $projectStmt = db()->prepare('SELECT id FROM attendance_projects WHERE status=1 AND name=:name LIMIT 1');
    $projectStmt->execute(['name'=>$assignment['activity']]);
    $defaultProjectId = (int)$projectStmt->fetchColumn();
}

json_response([
    'ok'=>true,
    'worker'=>$worker,
    'assignment'=>$assignment,
    'defaults'=>['location_id'=>$defaultLocationId,'schedule_id'=>$defaultScheduleId,'project_id'=>$defaultProjectId],
    'marks'=>$marks,
    'has_entry'=>$hasEntry,
    'has_exit'=>$hasExit,
    'can_self_mark'=>$linkedWorkerId === $workerId,
    'today'=>$today,
    'work_date'=>$workDate,
    'holiday_today'=>$holidayToday,
]);
