<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_module_access('requisitos.pmi_individual');
if (is_personal_role()) json_response(['ok' => false, 'message' => 'No tiene permiso para modificar separadores.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);

$action = (string) ($_POST['action'] ?? '');
$workerId = filter_var($_POST['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$positionId = filter_var($_POST['position_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$separatorId = filter_var($_POST['separator_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$scopeType = (string) ($_POST['scope_type'] ?? '');
$scopeId = filter_var($_POST['scope_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$afterItemId = filter_var($_POST['after_item_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if (!$workerId || !$positionId || !in_array($action, ['create', 'move', 'delete'], true) || ($action !== 'create' && !$separatorId)) {
    json_response(['ok' => false, 'message' => 'Solicitud inválida.'], 422);
}

$pdo = db();
try {
    $pdo->beginTransaction();
    $position = $pdo->prepare('SELECT 1 FROM worker_positions WHERE worker_id = ? AND position_id = ? FOR UPDATE');
    $position->execute([$workerId, $positionId]);
    if (!$position->fetchColumn()) throw new RuntimeException('El puesto no pertenece al trabajador.');

    if ($action !== 'create') {
        $current = $pdo->prepare('SELECT id FROM pmi_requirement_separators WHERE id = ? AND worker_id = ? AND position_id = ? FOR UPDATE');
        $current->execute([$separatorId, $workerId, $positionId]);
        if (!$current->fetchColumn()) throw new RuntimeException('El separador ya no existe.');
    }
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM pmi_requirement_separators WHERE id = ?')->execute([$separatorId]);
        $pdo->commit();
        json_response(['ok' => true]);
    }
    if (!in_array($scopeType, ['folders', 'folder', 'ungrouped'], true) || $scopeId === false || $afterItemId === false || ($scopeType === 'folder' && !$scopeId) || ($scopeType !== 'folder' && $scopeId !== 0)) {
        throw new RuntimeException('Seleccione una posición válida.');
    }
    if ($scopeType === 'folder') {
        $folder = $pdo->prepare('SELECT 1 FROM pmi_requirement_folders WHERE id = ? AND worker_id = ? AND position_id = ?');
        $folder->execute([$scopeId, $workerId, $positionId]);
        if (!$folder->fetchColumn()) throw new RuntimeException('La carpeta ya no está disponible.');
    }
    if ($afterItemId > 0) {
        if ($scopeType === 'folders') {
            $anchor = $pdo->prepare('SELECT 1 FROM pmi_requirement_folders WHERE id = ? AND worker_id = ? AND position_id = ?');
            $anchor->execute([$afterItemId, $workerId, $positionId]);
        } else {
            $anchor = $pdo->prepare('SELECT 1 FROM worker_requirements wr LEFT JOIN pmi_requirement_folder_items fi ON fi.requirement_record_id = wr.id WHERE wr.id = ? AND wr.worker_id = ? AND wr.position_id = ? AND ' . ($scopeType === 'folder' ? 'fi.folder_id = ?' : 'fi.folder_id IS NULL'));
            $params = [$afterItemId, $workerId, $positionId];
            if ($scopeType === 'folder') $params[] = $scopeId;
            $anchor->execute($params);
        }
        if (!$anchor->fetchColumn()) throw new RuntimeException('El registro de referencia ya no está en esa posición.');
    }
    if ($action === 'create') {
        $stmt = $pdo->prepare('INSERT INTO pmi_requirement_separators (worker_id, position_id, scope_type, scope_id, after_item_id, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$workerId, $positionId, $scopeType, $scopeId, $afterItemId, current_user()['id'] ?? null]);
    } else {
        $stmt = $pdo->prepare('UPDATE pmi_requirement_separators SET scope_type = ?, scope_id = ?, after_item_id = ? WHERE id = ?');
        $stmt->execute([$scopeType, $scopeId, $afterItemId, $separatorId]);
    }
    $pdo->commit();
    json_response(['ok' => true]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') json_response(['ok' => false, 'message' => 'Ya hay un separador en ese lugar.'], 409);
    error_log('PMI separators: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudo guardar el separador.'], 500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
