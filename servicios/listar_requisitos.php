<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

function requirement_status(string $endDate, string $startDate): array
{
    $today = new DateTimeImmutable('today');
    $end = new DateTimeImmutable($endDate);
    $warningLimit = $today->modify('+30 days');

    if ($end < $today) {
        return ['label' => 'NO APTO', 'class' => 'text-bg-danger'];
    }

    if ($end <= $warningLimit) {
        return ['label' => 'POR VENCER', 'class' => 'text-bg-warning'];
    }

    return ['label' => 'APTO', 'class' => 'text-bg-success'];
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
    $row['status'] = requirement_status($row['end_date'], $row['start_date']);
}

json_response(['ok' => true, 'rows' => $rows]);
