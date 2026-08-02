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
        EXISTS(SELECT 1 FROM attendance_marks am WHERE am.program_id = ap.id) AS has_marks,
        EXISTS(SELECT 1 FROM attendance_trips atp WHERE atp.program_id = ap.id) AS has_trips
    FROM attendance_programs ap WHERE ap.id = :id LIMIT 1");
$check->execute(['id' => $id]);
$program = $check->fetch();

if (!$program) {
    json_response(['ok' => false, 'message' => 'La programación ya no existe o fue eliminada anteriormente.'], 404);
}
if ((int) $program['has_marks'] === 1 || (int) $program['has_trips'] === 1) {
    json_response(['ok' => false, 'message' => 'No se puede eliminar porque esta programación ya tiene marcaciones o desplazamientos laborales. El historial debe conservarse.'], 409);
}

$delete = db()->prepare("DELETE FROM attendance_programs
    WHERE id = :id
      AND NOT EXISTS (SELECT 1 FROM attendance_marks am WHERE am.program_id = attendance_programs.id)
      AND NOT EXISTS (SELECT 1 FROM attendance_trips atp WHERE atp.program_id = attendance_programs.id)");
$delete->execute(['id' => $id]);
if ($delete->rowCount() !== 1) {
    json_response(['ok' => false, 'message' => 'La programación cambió mientras se procesaba la solicitud. Actualice la página e inténtelo nuevamente.'], 409);
}

json_response(['ok' => true, 'message' => 'La programación fue eliminada correctamente porque no tenía actividad registrada.']);
