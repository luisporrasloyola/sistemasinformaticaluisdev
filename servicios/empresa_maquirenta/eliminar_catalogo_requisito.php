<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Seleccione un requisito válido.'], 400);
}

$reqStmt = db()->prepare('SELECT id, name FROM empresa_maquirenta_formato_requisitos_catalogo WHERE id = :id LIMIT 1');
$reqStmt->execute(['id' => $id]);
$requirement = $reqStmt->fetch();

if (!$requirement) {
    json_response(['ok' => false, 'message' => 'El requisito no existe.'], 404);
}

if (!current_user_can_document('empresa_maquirenta.pmi_individual', $id, 'manage')) {
    json_response(['ok' => false, 'message' => 'No tiene permisos para eliminar este requisito.'], 403);
}

$usedStmt = db()->prepare('SELECT COUNT(*) FROM empresa_maquirenta_formato_requisitos WHERE requirement_id = :id');
$usedStmt->execute(['id' => $id]);

if ((int) $usedStmt->fetchColumn() > 0) {
    json_response([
        'ok' => false,
        'message' => 'No se puede eliminar porque este requisito ya tiene documentos registrados.'
    ], 409);
}

try {
    db()->beginTransaction();

    $deleteRelations = db()->prepare('DELETE FROM empresa_maquirenta_formato_puesto_requisitos WHERE requirement_id = :id');
    $deleteRelations->execute(['id' => $id]);

    $disable = db()->prepare('UPDATE empresa_maquirenta_formato_requisitos_catalogo SET status = 0 WHERE id = :id');
    $disable->execute(['id' => $id]);

    db()->commit();
    json_response(['ok' => true, 'message' => 'Requisito eliminado del catálogo.']);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    json_response(['ok' => false, 'message' => 'No se pudo eliminar el requisito.'], 400);
}
