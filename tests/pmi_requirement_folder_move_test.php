<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/pmi_requirement_folder_move.php';

$pdo = db();
$candidate = $pdo->query('SELECT f.id AS folder_id, f.worker_id, f.position_id, wr.id AS record_id
    FROM pmi_requirement_folders f
    JOIN worker_requirements wr ON wr.worker_id = f.worker_id AND wr.position_id = f.position_id
    LEFT JOIN pmi_requirement_folder_items fi ON fi.requirement_record_id = wr.id
    WHERE fi.requirement_record_id IS NULL LIMIT 1')->fetch();
if (!$candidate) throw new RuntimeException('No external requirement and folder available for verification.');
$workerId = (int) $candidate['worker_id'];
$positionId = (int) $candidate['position_id'];
$recordId = (int) $candidate['record_id'];
$folderId = (int) $candidate['folder_id'];
$pdo->beginTransaction();
try {
    $record = $pdo->prepare('SELECT * FROM worker_requirements WHERE id = ?');
    $record->execute([$recordId]);
    $originalRecord = $record->fetch();
    $members = $pdo->prepare('SELECT fi.requirement_record_id FROM pmi_requirement_folder_items fi
        JOIN worker_requirements wr ON wr.id = fi.requirement_record_id
        JOIN requirements_catalog rc ON rc.id = wr.requirement_id
        WHERE fi.folder_id = ? ORDER BY CASE WHEN fi.sort_order = 0 THEN 2147483647 ELSE fi.sort_order END, rc.name, wr.id');
    $members->execute([$folderId]);
    $originalMembers = array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN));
    $rejected = false;
    try {
        pmi_requirement_add_to_folder($pdo, 0, $positionId, $recordId, $folderId);
    } catch (DomainException $error) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Folder from another worker was accepted.');

    pmi_requirement_add_to_folder($pdo, $workerId, $positionId, $recordId, $folderId);
    $members->execute([$folderId]);
    if (array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN)) !== [...$originalMembers, $recordId]) {
        throw new RuntimeException('The member was not appended or the previous order changed.');
    }
    $record->execute([$recordId]);
    if ($record->fetch() !== $originalRecord) throw new RuntimeException('Requirement data or attachment paths changed.');
    $orphan = $pdo->prepare("SELECT COUNT(*) FROM pmi_requirement_separators WHERE worker_id = ? AND position_id = ? AND scope_type = 'ungrouped' AND scope_id = 0 AND after_item_id = ?");
    $orphan->execute([$workerId, $positionId, $recordId]);
    if ((int) $orphan->fetchColumn() !== 0) throw new RuntimeException('An external separator was orphaned.');
    $rejected = false;
    try {
        pmi_requirement_add_to_folder($pdo, $workerId, $positionId, $recordId, $folderId);
    } catch (DomainException $error) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Duplicate membership was accepted.');
    $rejected = false;
    try {
        pmi_requirement_remove_from_folder($pdo, 0, $positionId, $recordId, 'folder', $folderId, 'before');
    } catch (DomainException $error) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Extraction from another worker was accepted.');
    pmi_requirement_remove_from_folder($pdo, $workerId, $positionId, $recordId, 'folder', $folderId, 'before');
    $root = pmi_requirement_root_items($pdo, $workerId, $positionId);
    $recordIndex = null;
    foreach ($root as $index => $item) {
        if ($item['kind'] === 'record' && (int) $item['id'] === $recordId) $recordIndex = $index;
    }
    if ($recordIndex === null) throw new RuntimeException('Extracted record not visible outside.');
    if ($originalMembers && ($root[$recordIndex + 1]['kind'] !== 'folder' || (int) $root[$recordIndex + 1]['id'] !== $folderId)) {
        throw new RuntimeException('Extracted record was not inserted before its target.');
    }
    $members->execute([$folderId]);
    if (array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN)) !== $originalMembers) throw new RuntimeException('Extraction changed the remaining folder order.');
    $record->execute([$recordId]);
    $extracted = $record->fetch();
    foreach ($originalRecord as $key => $value) {
        if (in_array($key, ['sort_order', 'updated_at'], true)) continue;
        if ($extracted[$key] !== $value) throw new RuntimeException('Extraction changed data/files: ' . $key);
    }
    foreach ($originalMembers as $memberId) {
        pmi_requirement_remove_from_folder($pdo, $workerId, $positionId, $memberId, 'root', 0, 'end');
    }
    $folder = $pdo->prepare('SELECT COUNT(*) FROM pmi_requirement_folders WHERE id = ?');
    $folder->execute([$folderId]);
    if ((int) $folder->fetchColumn() !== 0) throw new RuntimeException('Empty folder cleanup failed.');
    $root = pmi_requirement_root_items($pdo, $workerId, $positionId);
    if ($originalMembers && (int) $root[count($root) - 1]['id'] !== $originalMembers[count($originalMembers) - 1]) throw new RuntimeException('Drop outside did not append.');
    echo "OK: add/extract, target order, data/files, separators, worker scope, duplicates and empty folder cleanup verified. All changes rolled back.\n";
} finally {
    $pdo->rollBack();
}
