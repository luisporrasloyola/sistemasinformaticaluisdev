<?php
declare(strict_types=1);

function attendance_location_int_list(array $values): string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0)));
    return $ids ? implode(',', $ids) : '0';
}

function attendance_location_column_ids(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function attendance_location_deletion_impact(PDO $pdo, int $locationId): array
{
    $assignments = attendance_location_column_ids($pdo, 'SELECT id FROM attendance_assignments WHERE location_id=:id', ['id'=>$locationId]);
    $assignmentList = attendance_location_int_list($assignments);
    $programs = attendance_location_column_ids($pdo, "SELECT id FROM attendance_programs WHERE location_id=:id OR assignment_id IN ({$assignmentList})", ['id'=>$locationId]);
    $programList = attendance_location_int_list($programs);
    $trips = attendance_location_column_ids($pdo, "SELECT DISTINCT t.id FROM attendance_trips t
        LEFT JOIN attendance_trip_stops ts ON ts.trip_id=t.id
        WHERE t.first_destination_location_id=:first_id OR t.last_location_id=:last_id
           OR t.assignment_id IN ({$assignmentList}) OR t.program_id IN ({$programList}) OR ts.location_id=:stop_id", [
        'first_id'=>$locationId,'last_id'=>$locationId,'stop_id'=>$locationId,
    ]);
    $tripList = attendance_location_int_list($trips);
    $marks = attendance_location_column_ids($pdo, "SELECT id FROM attendance_marks
        WHERE location_id=:id OR assignment_id IN ({$assignmentList}) OR program_id IN ({$programList})", ['id'=>$locationId]);
    $markList = attendance_location_int_list($marks);
    $programStops = attendance_location_column_ids($pdo, "SELECT id FROM attendance_program_stops WHERE location_id=:id OR program_id IN ({$programList})", ['id'=>$locationId]);
    $tripStops = attendance_location_column_ids($pdo, "SELECT id FROM attendance_trip_stops WHERE location_id=:id OR trip_id IN ({$tripList})", ['id'=>$locationId]);
    $completions = attendance_location_column_ids($pdo, "SELECT id FROM attendance_work_completions
        WHERE location_id=:id OR assignment_id IN ({$assignmentList}) OR program_id IN ({$programList})", ['id'=>$locationId]);
    $overrides = attendance_location_column_ids($pdo, "SELECT id FROM attendance_journey_overrides WHERE assignment_id IN ({$assignmentList})");
    $adjustments = attendance_location_column_ids($pdo, "SELECT id FROM attendance_manual_adjustments
        WHERE attendance_mark_id IN ({$markList}) OR previous_location_id=:previous_id OR new_location_id=:new_id", [
        'previous_id'=>$locationId,'new_id'=>$locationId,
    ]);
    $personnel = attendance_location_column_ids($pdo, 'SELECT worker_id FROM attendance_location_workers WHERE location_id=:id', ['id'=>$locationId]);

    $fileStmt = $pdo->query("SELECT photo_path,evidence_path FROM attendance_marks WHERE id IN ({$markList})");
    $files = [];
    foreach ($fileStmt->fetchAll() as $row) {
        foreach (['photo_path','evidence_path'] as $column) {
            $value = trim((string)($row[$column] ?? ''));
            if ($value !== '') $files[] = $value;
        }
    }
    $files = array_values(array_unique($files));

    return [
        'ids'=>[
            'assignments'=>$assignments,'programs'=>$programs,'program_stops'=>$programStops,
            'trips'=>$trips,'trip_stops'=>$tripStops,'marks'=>$marks,'completions'=>$completions,
            'overrides'=>$overrides,'adjustments'=>$adjustments,'personnel'=>$personnel,
        ],
        'counts'=>[
            'assignments'=>count($assignments),'programs'=>count($programs),'program_stops'=>count($programStops),
            'trips'=>count($trips),'trip_stops'=>count($tripStops),'marks'=>count($marks),
            'photos'=>count($files),'completions'=>count($completions),'overrides'=>count($overrides),
            'adjustments'=>count($adjustments),'personnel'=>count($personnel),
        ],
        'files'=>$files,
    ];
}