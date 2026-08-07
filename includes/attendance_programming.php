<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function attendance_program_schedule_day(array $program): array
{
    return [
        'id' => 'program-' . (int) $program['id'],
        'entry_time' => $program['entry_time'],
        'entry_start' => $program['entry_start'],
        'entry_end' => $program['entry_end'],
        'exit_time' => $program['exit_time'],
        'exit_start' => $program['exit_time'],
        'exit_end' => $program['exit_time'],
        'tolerance_minutes' => (int) $program['tolerance_minutes'],
        'source' => 'programacion',
    ];
}

function attendance_programs_for_worker_date(int $workerId, string $date, int $openProgramId = 0): array
{
    $stmt = db()->prepare("SELECT ap.*, COALESCE(ap.location_id, aa.location_id) AS location_id,
            COALESCE(ap.schedule_id, aa.schedule_id) AS schedule_id, aa.activity AS assignment_activity,
            aa.instructions AS assignment_instructions,
            EXISTS(SELECT 1 FROM attendance_program_stops aps WHERE aps.program_id=ap.id) AS has_route,
            (SELECT aa2.activity FROM attendance_assignments aa2
                WHERE aa2.worker_id=ap.worker_id AND aa2.status=1
                  AND aa2.location_id=COALESCE(ap.location_id, aa.location_id)
                  AND aa2.valid_from<=ap.program_date AND (aa2.valid_until IS NULL OR aa2.valid_until>=ap.program_date)
                ORDER BY aa2.id DESC LIMIT 1) AS current_assignment_activity,
            l.name AS location_name, l.latitude, l.longitude, l.address, l.reference, l.radius_meters,
            s.name AS schedule_name, w.company_id, w.full_name, w.document_number
        FROM attendance_programs ap
        JOIN attendance_assignments aa ON aa.id = ap.assignment_id
        JOIN attendance_locations l ON l.id = COALESCE(ap.location_id, aa.location_id)
        JOIN attendance_schedules s ON s.id = COALESCE(ap.schedule_id, aa.schedule_id)
        JOIN workers w ON w.id = ap.worker_id
        WHERE ap.worker_id = :worker_id AND ap.program_date = :program_date
          AND (ap.status = 'programada' OR (:open_program_id > 0 AND ap.id = :open_program_match))
        ORDER BY ap.entry_time, ap.id");
    $stmt->execute([
        'worker_id' => $workerId,
        'program_date' => $date,
        'open_program_id' => $openProgramId,
        'open_program_match' => $openProgramId,
    ]);
    return $stmt->fetchAll();
}

function attendance_select_program(array $programs, int $requestedId = 0): ?array
{
    if (!$programs) return null;
    if ($requestedId > 0) {
        foreach ($programs as $program) {
            if ((int) $program['id'] === $requestedId) return $program;
        }
    }
    $now = date('H:i:s');
    foreach ($programs as $program) {
        if ((string) $program['exit_time'] >= $now) return $program;
    }
    return $programs[count($programs) - 1];
}
