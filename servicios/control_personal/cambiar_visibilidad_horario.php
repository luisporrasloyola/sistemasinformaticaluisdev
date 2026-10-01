<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');
verify_csrf($_POST['csrf_token'] ?? null);
$id = max(0, (int)($_POST['id'] ?? 0));
$action = (string)($_POST['action'] ?? '');
if (!$id || !in_array($action, ['hide','restore'], true)) json_response(['ok'=>false,'message'=>'Solicitud no válida.'], 422);
$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT status FROM attendance_schedules WHERE id=:id FOR UPDATE');
    $stmt->execute(['id'=>$id]);
    $status = $stmt->fetchColumn();
    if ($status === false) throw new DomainException('El horario ya no existe.');
    $newStatus = $action === 'hide' ? 0 : 1;
    if ((int)$status !== $newStatus) {
        $pdo->prepare('UPDATE attendance_schedules SET status=:status WHERE id=:id')->execute(['status'=>$newStatus,'id'=>$id]);
    }
    $pdo->commit();
    json_response(['ok'=>true,'message'=>$action === 'hide' ? 'El horario fue ocultado; sus registros se conservaron.' : 'El horario fue restaurado.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok'=>false,'message'=>$error instanceof DomainException ? $error->getMessage() : 'No se pudo actualizar el horario.'], $error instanceof DomainException ? 404 : 500);
}
