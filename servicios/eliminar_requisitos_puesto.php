<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/pmi_requirement_files.php';
require_once __DIR__ . '/../config/database.php';

require_module_access('requisitos.pmi_individual');
if (is_personal_role()) json_response(['ok' => false, 'message' => 'No tiene permiso para borrar requisitos.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = filter_var($_POST['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$positionId = filter_var($_POST['position_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$expectedCount = filter_var($_POST['expected_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$workerId || !$positionId || !$expectedCount) json_response(['ok' => false, 'message' => 'Seleccione un trabajador y un puesto con requisitos.'], 422);

$pdo = db();
try {
    $pdo->beginTransaction();
    $positionStmt = $pdo->prepare('SELECT 1 FROM worker_positions WHERE worker_id = ? AND position_id = ? LIMIT 1');
    $positionStmt->execute([$workerId, $positionId]);
    if (!$positionStmt->fetchColumn()) throw new RuntimeException('El puesto no pertenece al trabajador seleccionado.');

    $stmt = $pdo->prepare('SELECT id, requirement_id, file_path, word_file_path, image_file_path FROM worker_requirements WHERE worker_id = ? AND position_id = ? FOR UPDATE');
    $stmt->execute([$workerId, $positionId]);
    $rows = $stmt->fetchAll();
    if (count($rows) !== $expectedCount) throw new RuntimeException('La lista de requisitos cambió. Actualice la página antes de borrar.');
    foreach ($rows as $row) {
        if (!current_user_can_document('requisitos.pmi_individual', (int) $row['requirement_id'], 'upload')) {
            throw new RuntimeException('No tiene permiso para borrar todos los requisitos de este puesto.');
        }
    }

    $paths = [];
    foreach ($rows as $row) {
        foreach (['file_path', 'word_file_path', 'image_file_path'] as $column) if (!empty($row[$column])) $paths[(string) $row[$column]] = true;
    }
    $delete = $pdo->prepare('DELETE FROM worker_requirements WHERE worker_id = ? AND position_id = ?');
    $delete->execute([$workerId, $positionId]);
    if ($delete->rowCount() !== count($rows)) throw new RuntimeException('No se pudieron borrar todos los registros.');
    $pdo->commit();

    pmi_requirement_delete_unreferenced($pdo, array_keys($paths));
    json_response(['ok' => true, 'deleted' => count($rows)]);
} catch (RuntimeException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $error->getMessage()], 409);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Error al borrar requisitos del puesto: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudieron borrar los requisitos.'], 500);
}
