<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_report_closure.php';
require_role('Administrador');
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) json_response(['ok'=>false,'message'=>'Asignación no válida.'],422);
$userId = (int) (current_user()['id'] ?? 0) ?: null;
$pdo = db();
$pdo->beginTransaction();
try {
    $assignment = $pdo->prepare('SELECT worker_id, valid_from, valid_until FROM attendance_assignments WHERE id = :id AND status = 1 FOR UPDATE');
    $assignment->execute(['id' => $id]);
    $current = $assignment->fetch();
    if ($current) attendance_report_assert_calendar_editable((string) $current['valid_from'], (string) ($current['valid_until'] ?: '9999-12-31'), [(int) $current['worker_id']], $pdo);
    $stmt = $pdo->prepare('UPDATE attendance_assignments SET status=0,deactivated_at=NOW(),deactivated_by_user_id=:user_id WHERE id=:id AND status=1');
    $stmt->execute(['id'=>$id,'user_id'=>$userId]);
    if ($stmt->rowCount() === 0) throw new RuntimeException('La asignación ya estaba desactivada o no existe.');
    $pdo->commit();
    // La programación especial conserva su propia fecha, horario y lugar.
    // Desactivar el horario habitual no debe cancelarla ni hacer reaparecer la
    // jornada semanal en la fecha que ya fue programada como excepción.
    $message = 'Asignación desactivada. Las programaciones especiales y el historial se conservaron.';
    json_response(['ok'=>true,'message'=>$message]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok'=>false,'message'=>$error->getMessage()],409);
}
