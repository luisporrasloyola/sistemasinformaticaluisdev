<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_module_access('requisitos.pmi_individual');
if (is_personal_role()) json_response(['ok' => false, 'message' => 'No tiene permiso para modificar separadores.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = filter_var($_POST['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$positionId = filter_var($_POST['position_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$positions = $_POST['positions'] ?? null;
if (!$workerId || !$positionId || !is_array($positions) || !$positions || count($positions) > 100 || count(array_unique($positions)) !== count($positions)) {
    json_response(['ok' => false, 'message' => 'Seleccione entre 1 y 100 ubicaciones diferentes.'], 422);
}

$pdo = db();
try {
    $pdo->beginTransaction();
    $workerPosition = $pdo->prepare('SELECT 1 FROM worker_positions WHERE worker_id = ? AND position_id = ? FOR UPDATE');
    $workerPosition->execute([$workerId, $positionId]);
    if (!$workerPosition->fetchColumn()) throw new RuntimeException('El puesto no pertenece al trabajador.');
    $folder = $pdo->prepare('SELECT 1 FROM pmi_requirement_folders WHERE id = ? AND worker_id = ? AND position_id = ?');
    $folderRecord = $pdo->prepare('SELECT 1 FROM worker_requirements wr JOIN pmi_requirement_folder_items fi ON fi.requirement_record_id = wr.id WHERE wr.id = ? AND wr.worker_id = ? AND wr.position_id = ? AND fi.folder_id = ?');
    $ungroupedRecord = $pdo->prepare('SELECT 1 FROM worker_requirements wr LEFT JOIN pmi_requirement_folder_items fi ON fi.requirement_record_id = wr.id WHERE wr.id = ? AND wr.worker_id = ? AND wr.position_id = ? AND fi.folder_id IS NULL');
    $insert = $pdo->prepare('INSERT INTO pmi_requirement_separators (worker_id, position_id, scope_type, scope_id, after_item_id, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($positions as $key) {
        if (!is_string($key) || !preg_match('/^(folders|folder|ungrouped):([0-9]+):([0-9]+)$/', $key, $match)) throw new RuntimeException('Una ubicación no es válida.');
        $scopeType = $match[1];
        $scopeId = (int) $match[2];
        $afterId = (int) $match[3];
        if (($scopeType === 'folder' && $scopeId < 1) || ($scopeType !== 'folder' && $scopeId !== 0)) throw new RuntimeException('Una ubicación no es válida.');
        if ($scopeType === 'folder') {
            $folder->execute([$scopeId, $workerId, $positionId]);
            if (!$folder->fetchColumn()) throw new RuntimeException('Una carpeta ya no está disponible.');
        }
        if ($afterId > 0) {
            if ($scopeType === 'folders') {
                $folder->execute([$afterId, $workerId, $positionId]);
                $valid = $folder->fetchColumn();
            } elseif ($scopeType === 'folder') {
                $folderRecord->execute([$afterId, $workerId, $positionId, $scopeId]);
                $valid = $folderRecord->fetchColumn();
            } else {
                $ungroupedRecord->execute([$afterId, $workerId, $positionId]);
                $valid = $ungroupedRecord->fetchColumn();
            }
            if (!$valid) throw new RuntimeException('Un requisito o carpeta cambió de ubicación. Actualice la página.');
        }
        $insert->execute([$workerId, $positionId, $scopeType, $scopeId, $afterId, current_user()['id'] ?? null]);
    }
    $pdo->commit();
    json_response(['ok' => true, 'created' => count($positions)]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') json_response(['ok' => false, 'message' => 'Ya hay un separador en una ubicación seleccionada. Actualice la página.'], 409);
    error_log('PMI separators batch: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudieron guardar los separadores.'], 500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
