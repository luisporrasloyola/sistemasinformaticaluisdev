<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Seleccione un requisito válido.'], 400);
}

$reqStmt = db()->prepare('SELECT id, name FROM requirements_catalog WHERE id = :id LIMIT 1');
$reqStmt->execute(['id' => $id]);
$requirement = $reqStmt->fetch();

if (!$requirement) {
    json_response(['ok' => false, 'message' => 'El requisito no existe.'], 404);
}

$usedStmt = db()->prepare('SELECT COUNT(*) FROM worker_requirements WHERE requirement_id = :id');
$usedStmt->execute(['id' => $id]);

if ((int) $usedStmt->fetchColumn() > 0) {
    json_response([
        'ok' => false,
        'message' => 'No se puede eliminar porque este requisito ya tiene documentos registrados.'
    ], 409);
}

try {
    db()->beginTransaction();

    $deleteRelations = db()->prepare('DELETE FROM position_requirements WHERE requirement_id = :id');
    $deleteRelations->execute(['id' => $id]);

    $disable = db()->prepare('UPDATE requirements_catalog SET status = 0 WHERE id = :id');
    $disable->execute(['id' => $id]);

    db()->commit();
    json_response(['ok' => true, 'message' => 'Requisito eliminado del catálogo.']);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    json_response(['ok' => false, 'message' => 'No se pudo eliminar el requisito.'], 400);
}
