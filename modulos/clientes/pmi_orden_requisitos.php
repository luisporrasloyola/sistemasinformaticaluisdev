<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/pmi_project_scope.php';
require_module_access('clientes.pmi');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);
$clientId = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$positionId = filter_var($_POST['position_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$ids = $_POST['requirement_ids'] ?? null;
if (!$clientId || !$positionId || !is_array($ids) || !$ids || count($ids) > 200) json_response(['ok' => false, 'message' => 'Orden inválido.'], 422);
$ids = array_map('intval', $ids);
if (min($ids) < 1 || count($ids) !== count(array_unique($ids))) json_response(['ok' => false, 'message' => 'Orden inválido.'], 422);
$pdo = db();
try {
    $projectId = pmi_project_id($clientId, $_POST);
    $pdo->beginTransaction();
    $position = $pdo->prepare('SELECT 1 FROM client_pmi_general_positions WHERE client_id = ? AND project_id = ? AND position_id = ? FOR UPDATE');
    $position->execute([$clientId, $projectId, $positionId]);
    if (!$position->fetchColumn()) throw new RuntimeException('El puesto ya no está disponible.');
    $current = $pdo->prepare('SELECT requirement_id FROM client_pmi_general_position_requirements WHERE client_id = ? AND project_id = ? AND position_id = ? FOR UPDATE');
    $current->execute([$clientId, $projectId, $positionId]);
    $savedIds = array_map('intval', $current->fetchAll(PDO::FETCH_COLUMN));
    sort($savedIds);
    $submittedIds = $ids;
    sort($submittedIds);
    if ($savedIds !== $submittedIds) throw new RuntimeException('La lista cambió. Actualice la página.');
    $update = $pdo->prepare('UPDATE client_pmi_general_position_requirements SET sort_order = ? WHERE client_id = ? AND project_id = ? AND position_id = ? AND requirement_id = ?');
    foreach ($ids as $order => $id) $update->execute([$order + 1, $clientId, $projectId, $positionId, $id]);
    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $error instanceof RuntimeException ? $error->getMessage() : 'No se pudo guardar el orden.'], 400);
}
