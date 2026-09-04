<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (is_personal_role()) {
    json_response(['ok'=>true,'unread_count'=>0,'notifications'=>[]]);
}

try {
    $stmt = db()->query("SELECT n.id AS notification_id,n.type,n.title,n.body,n.worker_id,n.created_at AS notification_at,
            w.full_name,awc.program_id,awc.activity,awc.observations,awc.completed_at,awc.work_date,l.name AS location_name,
            COALESCE(ap.entry_time,sd.entry_time,sd.entry_start) AS entry_time,
            COALESCE(ap.exit_time,sd.exit_time,sd.exit_start) AS exit_time
        FROM notifications n
        JOIN workers w ON w.id=n.worker_id
        LEFT JOIN attendance_work_completions awc ON awc.id=(
            SELECT awc2.id FROM attendance_work_completions awc2
            WHERE awc2.worker_id=n.worker_id AND ABS(TIMESTAMPDIFF(SECOND,awc2.completed_at,n.created_at))<=10
            ORDER BY ABS(TIMESTAMPDIFF(SECOND,awc2.completed_at,n.created_at)),awc2.id DESC LIMIT 1
        )
        LEFT JOIN attendance_locations l ON l.id=awc.location_id
        LEFT JOIN attendance_programs ap ON ap.id=awc.program_id
        LEFT JOIN attendance_assignments aa ON aa.id=awc.assignment_id
        LEFT JOIN attendance_schedule_days sd ON sd.schedule_id=COALESCE(ap.schedule_id,aa.schedule_id)
            AND sd.day_of_week=WEEKDAY(awc.work_date)+1 AND sd.status=1
        WHERE n.type='temporary_trip_exception' AND n.is_read=0
        ORDER BY n.created_at DESC,n.id DESC LIMIT 50");

    $notifications=[];
    foreach ($stmt->fetchAll() as $row) {
        $entryTime=$row['entry_time'] ? substr((string)$row['entry_time'],0,5) : null;
        $exitTime=$row['exit_time'] ? substr((string)$row['exit_time'],0,5) : null;
        $overtimeMinutes=0;
        if ($row['completed_at'] && $row['work_date'] && $entryTime && $exitTime) {
            $officialExit=strtotime($row['work_date'].' '.$exitTime);
            if (strtotime($exitTime)<=strtotime($entryTime)) $officialExit=strtotime('+1 day',$officialExit);
            $completedAt=strtotime((string)$row['completed_at']);
            if ($completedAt>$officialExit) $overtimeMinutes=(int)floor(($completedAt-$officialExit)/60);
        }
        $notifications[]=[
            'notification_id'=>(int)$row['notification_id'],
            'type'=>(string)$row['type'],
            'title'=>(string)$row['title'],
            'body'=>(string)$row['body'],
            'worker_id'=>(int)$row['worker_id'],
            'full_name'=>(string)$row['full_name'],
            'location'=>(string)($row['location_name'] ?? ''),
            'activity'=>(string)($row['activity'] ?? ''),
            'observations'=>(string)($row['observations'] ?? ''),
            'completed_at'=>(string)($row['completed_at'] ?: $row['notification_at']),
            'entry_time'=>$entryTime,
            'exit_time'=>$exitTime,
            'overtime_minutes'=>$overtimeMinutes,
            'is_overtime'=>$overtimeMinutes>0,
            'needs_assignment'=>$row['type']==='work_location_completed' && empty($row['program_id']),
        ];
    }
    json_response(['ok'=>true,'unread_count'=>count($notifications),'notifications'=>$notifications]);
} catch (Throwable $error) {
    json_response(['ok'=>false,'message'=>'No se pudieron cargar las alertas de recorridos.'],500);
}
