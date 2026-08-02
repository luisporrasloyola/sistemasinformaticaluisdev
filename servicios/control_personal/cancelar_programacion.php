<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.programacion');
verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) json_response(['ok'=>false,'message'=>'Programación no válida.'],422);
$stmt = db()->prepare("UPDATE attendance_programs ap SET ap.status='cancelada'
    WHERE ap.id=:id AND NOT EXISTS (SELECT 1 FROM attendance_marks am WHERE am.program_id=ap.id)");
$stmt->execute(['id'=>$id]);
if ($stmt->rowCount() === 0) {
    json_response(['ok'=>false,'message'=>'No se puede cancelar: la jornada ya tiene marcaciones o ya estaba cancelada.'],409);
}
json_response(['ok'=>true,'message'=>'La programación fue cancelada y se conserva en el historial.']);
