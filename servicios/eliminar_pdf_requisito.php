<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/pmi_requirement_files.php';
require_once __DIR__ . '/../config/database.php';
require_module_access('requisitos.pmi_individual');
if (is_personal_role()) json_response(['ok' => false, 'message' => 'No tiene permiso para eliminar adjuntos.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);
$kind = (string) ($_POST['kind'] ?? 'primary');
$columns = pmi_requirement_file_columns()[$kind] ?? null;
if ($id < 1 || !$columns) json_response(['ok' => false, 'message' => 'Archivo inválido.'], 422);
$pdo = db();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT requirement_id, file_path, word_file_path, image_file_path FROM worker_requirements WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('El requisito ya no existe.');
    if (!current_user_can_document('requisitos.pmi_individual', (int) $row['requirement_id'], 'upload')) throw new RuntimeException('No tiene permiso para eliminar este archivo.');
    [$pathColumn, $nameColumn] = $columns;
    $path = $row[$pathColumn] ?? null;
    $pdo->prepare("UPDATE worker_requirements SET $pathColumn = NULL, $nameColumn = NULL WHERE id = ?")->execute([$id]);
    $pdo->commit();
    pmi_requirement_delete_unreferenced($pdo, [$path]);
    json_response(['ok' => true]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $error->getMessage()], 400);
}

