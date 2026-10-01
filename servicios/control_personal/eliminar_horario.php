<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_schedule_deletion.php';
require_role('Administrador');
verify_csrf($_POST['csrf_token'] ?? null);
$id = max(0, (int)($_POST['id'] ?? 0));
$confirmation = strtoupper(trim((string)($_POST['confirmation'] ?? '')));
if (!$id) json_response(['ok'=>false,'message'=>'Horario no válido.'], 422);
if ($confirmation !== 'ELIMINAR') json_response(['ok'=>false,'message'=>'Escriba ELIMINAR para confirmar.'], 422);

$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id FROM attendance_schedules WHERE id=:id FOR UPDATE');
    $stmt->execute(['id'=>$id]);
    if (!$stmt->fetchColumn()) throw new DomainException('El horario ya no existe.');
    $impact = attendance_schedule_deletion_impact($pdo, $id);
    $delete = static function (string $table, array $ids) use ($pdo): void {
        $list = attendance_location_int_list($ids);
        if ($list !== '0') $pdo->exec("DELETE FROM {$table} WHERE id IN ({$list})");
    };
    foreach (['adjustments'=>'attendance_manual_adjustments', 'trip_stops'=>'attendance_trip_stops',
        'program_stops'=>'attendance_program_stops', 'completions'=>'attendance_work_completions',
        'marks'=>'attendance_marks', 'overrides'=>'attendance_journey_overrides',
        'trips'=>'attendance_trips', 'programs'=>'attendance_programs',
        'assignments'=>'attendance_assignments', 'days'=>'attendance_schedule_days'] as $key=>$table) {
        $delete($table, $impact['ids'][$key]);
    }
    $pdo->prepare('DELETE FROM attendance_schedules WHERE id=:id')->execute(['id'=>$id]);
    $pdo->commit();

    $uploadRoot = realpath(UPLOAD_PATH);
    foreach ($impact['files'] as $relativePath) {
        $candidate = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $real = realpath($candidate);
        if ($uploadRoot && $real && str_starts_with($real, $uploadRoot . DIRECTORY_SEPARATOR) && is_file($real)) @unlink($real);
    }
    json_response(['ok'=>true,'message'=>'El horario y sus registros relacionados fueron eliminados definitivamente.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok'=>false,'message'=>$error instanceof DomainException ? $error->getMessage() : 'No se pudo completar la eliminación. Ningún registro fue eliminado.'], $error instanceof DomainException ? 409 : 500);
}
