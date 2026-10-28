<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/pmi_requirement_files.php';
require_once __DIR__ . '/../config/database.php';
require_module_access('requisitos.pmi_individual');
if (is_personal_role()) json_response(['ok' => false, 'message' => 'El rol Personal solo puede visualizar sus requisitos.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
if ($id < 1) json_response(['ok' => false, 'message' => 'Requisito inválido.'], 422);

$pdo = db();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT requirement_id, file_path, word_file_path, image_file_path FROM worker_requirements WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->rollBack();
        json_response(['ok' => false, 'message' => 'El requisito ya no existe. Actualice la lista.'], 404);
    }
    if (!current_user_can_document('requisitos.pmi_individual', (int) $row['requirement_id'], 'upload')) {
        $pdo->rollBack();
        json_response(['ok' => false, 'message' => 'No tiene permiso para eliminar este requisito.'], 403);
    }
    $pdo->prepare('DELETE FROM worker_requirements WHERE id = ?')->execute([$id]);
    $pdo->commit();

    // El archivo se elimina solo después de confirmar el borrado del registro.
    pmi_requirement_delete_unreferenced($pdo, [$row['file_path'], $row['word_file_path'], $row['image_file_path']]);
    json_response(['ok' => true]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => 'No se pudo eliminar el requisito.'], 500);
}

