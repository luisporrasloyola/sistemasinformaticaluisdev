<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.programacion');
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'La programación seleccionada no es válida.'], 422);
}

$check = db()->prepare("SELECT ap.id,
        (SELECT COUNT(*) FROM attendance_program_stops aps WHERE aps.program_id = ap.id) AS stops_count,
        (SELECT COUNT(*) FROM attendance_marks am
         WHERE am.program_id=ap.id OR (am.program_id IS NULL AND am.assignment_id=ap.assignment_id AND am.worker_id=ap.worker_id AND am.mark_date=ap.program_date)) AS marks_count,
        (SELECT COUNT(*) FROM attendance_trips atp
         WHERE atp.program_id=ap.id OR (atp.program_id IS NULL AND atp.assignment_id=ap.assignment_id AND atp.worker_id=ap.worker_id AND atp.trip_date=ap.program_date)) AS trips_count,
        (SELECT COUNT(*) FROM attendance_work_completions awc
         WHERE awc.program_id=ap.id OR (awc.program_id IS NULL AND awc.assignment_id=ap.assignment_id AND awc.worker_id=ap.worker_id AND awc.work_date=ap.program_date)) AS completions_count
    FROM attendance_programs ap WHERE ap.id = :id LIMIT 1");
$check->execute(['id' => $id]);
$program = $check->fetch();

if (!$program) {
    json_response(['ok' => false, 'message' => 'La programación ya no existe o fue eliminada anteriormente.'], 404);
}
$marksCount = (int) $program['marks_count'];
$tripsCount = (int) $program['trips_count'];
$completionsCount = (int) $program['completions_count'];
if ($marksCount > 0 || $tripsCount > 0 || $completionsCount > 0) {
    $reasons = [];
    if ($marksCount > 0) $reasons[] = $marksCount . ($marksCount === 1 ? ' marcación registrada' : ' marcaciones registradas');
    if ($tripsCount > 0) $reasons[] = $tripsCount . ($tripsCount === 1 ? ' desplazamiento iniciado' : ' desplazamientos iniciados');
    if ($completionsCount > 0) $reasons[] = $completionsCount . ($completionsCount === 1 ? ' trabajo finalizado' : ' trabajos finalizados');
    json_response([
        'ok' => false,
        'title' => (int) $program['stops_count'] > 0 ? 'No se puede eliminar el recorrido' : 'No se puede eliminar la programación',
        'message' => 'Este registro ya fue utilizado por el trabajador: ' . implode(', ', $reasons) . '. Debe conservarse para no alterar su asistencia ni el historial laboral.',
        'reasons' => $reasons,
    ], 409);
}

$delete = db()->prepare("DELETE FROM attendance_programs
    WHERE id = :id
      AND NOT EXISTS (SELECT 1 FROM attendance_marks am WHERE am.program_id = attendance_programs.id)
      AND NOT EXISTS (SELECT 1 FROM attendance_trips atp WHERE atp.program_id = attendance_programs.id)
      AND NOT EXISTS (SELECT 1 FROM attendance_work_completions awc WHERE awc.program_id = attendance_programs.id)");
$delete->execute(['id' => $id]);
if ($delete->rowCount() !== 1) {
    json_response(['ok' => false, 'message' => 'La programación cambió mientras se procesaba la solicitud. Actualice la página e inténtelo nuevamente.'], 409);
}

json_response(['ok' => true, 'message' => 'La programación fue eliminada correctamente porque no tenía actividad registrada.']);
