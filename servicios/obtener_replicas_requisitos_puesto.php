<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_role('Administrador');

$workerId = filter_var($_GET['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sourcePositionId = filter_var($_GET['source_position_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$workerId || !$sourcePositionId) json_response(['ok' => false, 'message' => 'Seleccione un trabajador y su puesto.'], 422);

$folderId = filter_var($_GET['folder_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($folderId) {
    $folderStmt = db()->prepare('SELECT id FROM pmi_requirement_folders WHERE id = ? AND worker_id = ? AND position_id = ?');
    $folderStmt->execute([$folderId, $workerId, $sourcePositionId]);
    if (!$folderStmt->fetchColumn()) json_response(['ok' => false, 'message' => 'La carpeta ya no pertenece al puesto actual.'], 404);
    $pendingStmt = db()->prepare('SELECT wp.position_id
        FROM worker_positions wp
        JOIN pmi_requirement_folder_items source_item ON source_item.folder_id = ?
        JOIN worker_requirements src ON src.id = source_item.requirement_record_id AND src.worker_id = wp.worker_id AND src.position_id = ?
        LEFT JOIN worker_requirements dst ON dst.worker_id = wp.worker_id AND dst.position_id = wp.position_id AND dst.requirement_id = src.requirement_id
        LEFT JOIN pmi_requirement_folder_items target_item ON target_item.requirement_record_id = dst.id
        WHERE wp.worker_id = ? AND wp.position_id <> ?
        GROUP BY wp.position_id
        HAVING SUM(CASE WHEN dst.id IS NULL OR target_item.folder_id IS NULL THEN 1 ELSE 0 END) > 0');
    $pendingStmt->execute([$folderId, $sourcePositionId, $workerId, $sourcePositionId]);
    json_response(['ok' => true, 'pending_positions' => array_map('intval', $pendingStmt->fetchAll(PDO::FETCH_COLUMN))]);
}

$stmt = db()->prepare('SELECT src.id AS source_id, dst.position_id AS target_position_id
    FROM worker_requirements src
    JOIN worker_requirements dst ON dst.worker_id = src.worker_id AND dst.requirement_id = src.requirement_id AND dst.position_id <> src.position_id
    JOIN worker_positions wp ON wp.worker_id = dst.worker_id AND wp.position_id = dst.position_id
    WHERE src.worker_id = ? AND src.position_id = ?');
$stmt->execute([$workerId, $sourcePositionId]);
json_response(['ok' => true, 'existing' => $stmt->fetchAll()]);
