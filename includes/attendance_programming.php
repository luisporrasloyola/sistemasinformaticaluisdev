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

function attendance_programs_for_worker_date(int $workerId, string $date): array
{
    $stmt = db()->prepare("SELECT ap.*, aa.location_id, aa.schedule_id, aa.activity AS assignment_activity,
            aa.instructions AS assignment_instructions,
            l.name AS location_name, l.latitude, l.longitude, l.address, l.reference, l.radius_meters,
            s.name AS schedule_name, w.company_id, w.full_name, w.document_number
        FROM attendance_programs ap
        JOIN attendance_assignments aa ON aa.id = ap.assignment_id
        JOIN attendance_locations l ON l.id = aa.location_id
        JOIN attendance_schedules s ON s.id = aa.schedule_id
        JOIN workers w ON w.id = ap.worker_id
        WHERE ap.worker_id = :worker_id AND ap.program_date = :program_date AND ap.status = 'programada' AND aa.status = 1
          AND aa.valid_from <= ap.program_date AND (aa.valid_until IS NULL OR aa.valid_until >= ap.program_date)
        ORDER BY ap.entry_time, ap.id");
    $stmt->execute(['worker_id' => $workerId, 'program_date' => $date]);
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
