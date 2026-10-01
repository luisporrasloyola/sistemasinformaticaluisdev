<?php
declare(strict_types=1);

require_once __DIR__ . '/attendance_location_deletion.php';

function attendance_schedule_deletion_impact(PDO $pdo, int $scheduleId): array
{
    $assignments = attendance_location_column_ids($pdo, 'SELECT id FROM attendance_assignments WHERE schedule_id=:id', ['id'=>$scheduleId]);
    $assignmentList = attendance_location_int_list($assignments);
    $programs = attendance_location_column_ids($pdo, "SELECT id FROM attendance_programs WHERE schedule_id=:id OR assignment_id IN ({$assignmentList})", ['id'=>$scheduleId]);
    $programList = attendance_location_int_list($programs);
    $trips = attendance_location_column_ids($pdo, "SELECT id FROM attendance_trips WHERE assignment_id IN ({$assignmentList}) OR program_id IN ({$programList})");
    $tripList = attendance_location_int_list($trips);
    $marks = attendance_location_column_ids($pdo, "SELECT id FROM attendance_marks WHERE schedule_id=:id OR assignment_id IN ({$assignmentList}) OR program_id IN ({$programList})", ['id'=>$scheduleId]);
    $markList = attendance_location_int_list($marks);
    $ids = [
        'days'=>attendance_location_column_ids($pdo, 'SELECT id FROM attendance_schedule_days WHERE schedule_id=:id', ['id'=>$scheduleId]),
        'assignments'=>$assignments,
        'programs'=>$programs,
        'program_stops'=>attendance_location_column_ids($pdo, "SELECT id FROM attendance_program_stops WHERE program_id IN ({$programList})"),
        'trips'=>$trips,
        'trip_stops'=>attendance_location_column_ids($pdo, "SELECT id FROM attendance_trip_stops WHERE trip_id IN ({$tripList})"),
        'marks'=>$marks,
        'completions'=>attendance_location_column_ids($pdo, "SELECT id FROM attendance_work_completions WHERE assignment_id IN ({$assignmentList}) OR program_id IN ({$programList})"),
        'overrides'=>attendance_location_column_ids($pdo, "SELECT id FROM attendance_journey_overrides WHERE assignment_id IN ({$assignmentList})"),
        'adjustments'=>attendance_location_column_ids($pdo, "SELECT id FROM attendance_manual_adjustments WHERE attendance_mark_id IN ({$markList})"),
    ];
    $stmt = $pdo->query("SELECT photo_path,evidence_path FROM attendance_marks WHERE id IN ({$markList})");
    $files = [];
    foreach ($stmt->fetchAll() as $row) {
        foreach (['photo_path','evidence_path'] as $key) {
            $path = trim((string)($row[$key] ?? ''));
            if ($path !== '') $files[] = $path;
        }
    }
    $files = array_values(array_unique($files));
    $counts = array_map('count', $ids);
    $counts['photos'] = count($files);
    return ['ids'=>$ids,'counts'=>$counts,'files'=>$files];
}
