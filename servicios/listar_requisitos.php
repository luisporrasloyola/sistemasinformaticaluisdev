<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/status_alerts.php';
require_login();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function requirement_status(string $endDate, string $startDate, int $requirementId): array
{
    return status_alert_document_status($endDate, 'requisitos.pmi_individual', $requirementId);
}

$workerId = (int) ($_GET['trabajador_id'] ?? 0);
$positionId = (int) ($_GET['puesto_id'] ?? 0);
require_personal_own_worker($workerId, false);

$stmt = db()->prepare("SELECT wr.*, rc.name AS requirement, COALESCE(u.name, '') AS registered_by, fi.folder_id, fi.sort_order AS folder_item_order
    FROM worker_requirements wr
    JOIN requirements_catalog rc ON rc.id = wr.requirement_id
    LEFT JOIN users u ON u.id = wr.registered_by_user_id
    LEFT JOIN pmi_requirement_folder_items fi ON fi.requirement_record_id = wr.id
    WHERE wr.worker_id = :worker_id AND wr.position_id = :position_id
    ORDER BY rc.name");
$stmt->execute(['worker_id' => $workerId, 'position_id' => $positionId]);
$rows = filter_allowed_documents('requisitos.pmi_individual', $stmt->fetchAll(), 'requirement_id', 'view');

foreach ($rows as &$row) {
    $row['status'] = requirement_status($row['end_date'], $row['start_date'], (int) $row['requirement_id']);
    $row['can_modify'] = !is_personal_role() && current_user_can_document('requisitos.pmi_individual', (int) $row['requirement_id'], 'upload');
}

$foldersStmt = db()->prepare('SELECT id, name, sort_order FROM pmi_requirement_folders WHERE worker_id = ? AND position_id = ? ORDER BY CASE WHEN sort_order = 0 THEN 2147483647 ELSE sort_order END, name');
$foldersStmt->execute([$workerId, $positionId]);
$separatorsStmt = db()->prepare('SELECT id, scope_type, scope_id, after_item_id FROM pmi_requirement_separators WHERE worker_id = ? AND position_id = ?');
$separatorsStmt->execute([$workerId, $positionId]);
json_response(['ok' => true, 'rows' => $rows, 'folders' => $foldersStmt->fetchAll(), 'separators' => $separatorsStmt->fetchAll()]);
