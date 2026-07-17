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

$stmt = db()->prepare("SELECT wr.*, rc.name AS requirement, COALESCE(u.name, '') AS registered_by
    FROM worker_requirements wr
    JOIN requirements_catalog rc ON rc.id = wr.requirement_id
    LEFT JOIN users u ON u.id = wr.registered_by_user_id
    WHERE wr.worker_id = :worker_id AND wr.position_id = :position_id
    ORDER BY rc.name");
$stmt->execute(['worker_id' => $workerId, 'position_id' => $positionId]);
$rows = $stmt->fetchAll();

foreach ($rows as &$row) {
    $row['status'] = requirement_status($row['end_date'], $row['start_date'], (int) $row['requirement_id']);
}

json_response(['ok' => true, 'rows' => $rows]);
