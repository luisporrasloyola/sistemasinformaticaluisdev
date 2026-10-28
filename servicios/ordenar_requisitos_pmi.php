<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_role('Administrador');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = filter_var($_POST['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$positionId = filter_var($_POST['position_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$kind = (string) ($_POST['kind'] ?? '');
$direction = (string) ($_POST['direction'] ?? '');
if (!$workerId || !$positionId || !$recordId || !in_array($kind, ['folder', 'record'], true) || !in_array($direction, ['up', 'down'], true)) {
    json_response(['ok' => false, 'message' => 'Movimiento inválido.'], 422);
}

$pdo = db();
try {
    $pdo->beginTransaction();
    $position = $pdo->prepare('SELECT 1 FROM worker_positions WHERE worker_id = ? AND position_id = ?');
    $position->execute([$workerId, $positionId]);
    if (!$position->fetchColumn()) throw new RuntimeException('El puesto no pertenece al trabajador.');

    if ($kind === 'folder') {
        $stmt = $pdo->prepare('SELECT id FROM pmi_requirement_folders f WHERE worker_id = ? AND position_id = ? AND EXISTS (SELECT 1 FROM pmi_requirement_folder_items fi WHERE fi.folder_id = f.id) ORDER BY CASE WHEN sort_order = 0 THEN 2147483647 ELSE sort_order END, name, id FOR UPDATE');
        $stmt->execute([$workerId, $positionId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $update = $pdo->prepare('UPDATE pmi_requirement_folders SET sort_order = ? WHERE id = ?');
    } else {
        $scope = $pdo->prepare('SELECT fi.folder_id FROM worker_requirements wr LEFT JOIN pmi_requirement_folder_items fi ON fi.requirement_record_id = wr.id WHERE wr.id = ? AND wr.worker_id = ? AND wr.position_id = ?');
        $scope->execute([$recordId, $workerId, $positionId]);
        $folderId = $scope->fetchColumn();
        if ($folderId === false) throw new RuntimeException('El requisito ya no pertenece a este puesto.');
        if ($folderId !== null) {
            $stmt = $pdo->prepare('SELECT wr.id FROM pmi_requirement_folder_items fi JOIN worker_requirements wr ON wr.id = fi.requirement_record_id JOIN requirements_catalog rc ON rc.id = wr.requirement_id WHERE fi.folder_id = ? AND wr.worker_id = ? AND wr.position_id = ? ORDER BY CASE WHEN fi.sort_order = 0 THEN 2147483647 ELSE fi.sort_order END, rc.name, wr.id FOR UPDATE');
            $stmt->execute([(int) $folderId, $workerId, $positionId]);
            $update = $pdo->prepare('UPDATE pmi_requirement_folder_items SET sort_order = ? WHERE requirement_record_id = ? AND folder_id = ?');
        } else {
            $stmt = $pdo->prepare('SELECT wr.id FROM worker_requirements wr LEFT JOIN pmi_requirement_folder_items fi ON fi.requirement_record_id = wr.id JOIN requirements_catalog rc ON rc.id = wr.requirement_id WHERE wr.worker_id = ? AND wr.position_id = ? AND fi.folder_id IS NULL ORDER BY CASE WHEN wr.sort_order = 0 THEN 2147483647 ELSE wr.sort_order END, rc.name, wr.id FOR UPDATE');
            $stmt->execute([$workerId, $positionId]);
            $update = $pdo->prepare('UPDATE worker_requirements SET sort_order = ? WHERE id = ? AND worker_id = ? AND position_id = ?');
        }
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $index = array_search((int) $recordId, $ids, true);
    if ($index === false) throw new RuntimeException('El elemento ya no está disponible. Actualice la página.');
    $other = $index + ($direction === 'up' ? -1 : 1);
    if ($other < 0 || $other >= count($ids)) throw new RuntimeException('El elemento ya está en el extremo de la lista.');
    [$ids[$index], $ids[$other]] = [$ids[$other], $ids[$index]];
    foreach ($ids as $order => $id) {
        if ($kind === 'folder') $update->execute([$order + 1, $id]);
        elseif ($folderId !== null) $update->execute([$order + 1, $id, (int) $folderId]);
        else $update->execute([$order + 1, $id, $workerId, $positionId]);
    }
    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
