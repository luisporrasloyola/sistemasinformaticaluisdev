<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');
verify_csrf($_POST['csrf_token'] ?? null);

$id = max(0, (int) ($_POST['id'] ?? 0));
$action = (string) ($_POST['action'] ?? '');
if ($id <= 0 || !in_array($action, ['hide', 'restore'], true)) {
    json_response(['ok' => false, 'message' => 'Solicitud no válida.'], 422);
}

$pdo = db();
$locationStmt = $pdo->prepare('SELECT id,name,status FROM attendance_locations WHERE id=:id LIMIT 1');
$locationStmt->execute(['id' => $id]);
$location = $locationStmt->fetch();
if (!$location) {
    json_response(['ok' => false, 'message' => 'El lugar de marcación no existe.'], 404);
}

if ($action === 'hide') {
    $openStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_marks entrada
        WHERE entrada.location_id=:location_id
          AND entrada.mark_date=CURDATE()
          AND entrada.mark_type='entrada'
          AND NOT EXISTS (
              SELECT 1 FROM attendance_marks newer
              WHERE newer.worker_id=entrada.worker_id
                AND newer.mark_date=entrada.mark_date
                AND (newer.marked_at>entrada.marked_at OR (newer.marked_at=entrada.marked_at AND newer.id>entrada.id))
          )");
    $openStmt->execute(['location_id' => $id]);
    $openJourneys = (int) $openStmt->fetchColumn();
    if ($openJourneys > 0) {
        json_response([
            'ok' => false,
            'message' => "No puede ocultar este lugar porque tiene {$openJourneys} jornada(s) abierta(s). Primero registre las salidas.",
        ], 409);
    }
}

$status = $action === 'restore' ? 1 : 0;
$update = $pdo->prepare('UPDATE attendance_locations SET status=:status WHERE id=:id');
$update->execute(['status' => $status, 'id' => $id]);

json_response([
    'ok' => true,
    'message' => $action === 'restore'
        ? 'El lugar fue restaurado y vuelve a estar disponible.'
        : 'El lugar fue ocultado. Sus asignaciones, marcaciones, programaciones, fotos e historial se conservaron.',
]);