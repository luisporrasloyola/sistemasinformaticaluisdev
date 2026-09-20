<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_location_deletion.php';
require_role('Administrador');
verify_csrf($_POST['csrf_token'] ?? null);

$id = max(0, (int) ($_POST['id'] ?? 0));
$confirmation = strtoupper(trim((string) ($_POST['confirmation'] ?? '')));
if ($id <= 0) json_response(['ok'=>false,'message'=>'Lugar de marcación no válido.'], 422);
if ($confirmation !== 'ELIMINAR') json_response(['ok'=>false,'message'=>'Escriba ELIMINAR para confirmar la eliminación definitiva.'], 422);

$pdo = db();
$pdo->beginTransaction();
try {
    $locationStmt = $pdo->prepare('SELECT id,name FROM attendance_locations WHERE id=:id LIMIT 1 FOR UPDATE');
    $locationStmt->execute(['id'=>$id]);
    $location = $locationStmt->fetch();
    if (!$location) throw new DomainException('El lugar ya no existe.');

    $impact = attendance_location_deletion_impact($pdo, $id);
    $deleteByIds = static function (PDO $pdo, string $table, array $ids): void {
        $list = attendance_location_int_list($ids);
        if ($list !== '0') $pdo->exec("DELETE FROM {$table} WHERE id IN ({$list})");
    };

    $deleteByIds($pdo, 'attendance_manual_adjustments', $impact['ids']['adjustments']);
    $deleteByIds($pdo, 'attendance_trip_stops', $impact['ids']['trip_stops']);
    $deleteByIds($pdo, 'attendance_program_stops', $impact['ids']['program_stops']);
    $deleteByIds($pdo, 'attendance_work_completions', $impact['ids']['completions']);
    $deleteByIds($pdo, 'attendance_marks', $impact['ids']['marks']);
    $deleteByIds($pdo, 'attendance_journey_overrides', $impact['ids']['overrides']);
    $deleteByIds($pdo, 'attendance_trips', $impact['ids']['trips']);
    $deleteByIds($pdo, 'attendance_programs', $impact['ids']['programs']);
    $deleteByIds($pdo, 'attendance_assignments', $impact['ids']['assignments']);
    $pdo->prepare('DELETE FROM attendance_location_workers WHERE location_id=:id')->execute(['id'=>$id]);
    $pdo->prepare('DELETE FROM attendance_locations WHERE id=:id')->execute(['id'=>$id]);
    $pdo->commit();

    $uploadRoot = realpath(UPLOAD_PATH);
    foreach ($impact['files'] as $relativePath) {
        $candidate = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $real = realpath($candidate);
        if ($uploadRoot && $real && str_starts_with($real, $uploadRoot . DIRECTORY_SEPARATOR) && is_file($real)) @unlink($real);
    }

    json_response([
        'ok'=>true,
        'message'=>'El lugar y todos los registros indicados fueron eliminados definitivamente.',
        'counts'=>$impact['counts'],
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $controlled = $error instanceof DomainException;
    json_response([
        'ok'=>false,
        'message'=>$controlled ? $error->getMessage() : 'No se pudo completar la eliminación. Ningún registro fue eliminado.',
    ], $controlled ? 409 : 500);
}