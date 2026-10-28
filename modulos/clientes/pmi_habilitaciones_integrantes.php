<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/pmi_project_scope.php';
require_module_access('clientes.pmi');
header('Content-Type: application/json; charset=utf-8');

$clientId = max(0, (int) ($_GET['client_id'] ?? 0));
if (!$clientId) {
    http_response_code(400);
    echo json_encode(['workers' => []]);
    exit;
}
try { $projectId = pmi_project_id($clientId, $_GET); }
catch (DomainException $error) { http_response_code(400); echo json_encode(['workers' => []]); exit; }

$stmt = db()->prepare('SELECT w.id, w.full_name, p.id AS position_id, p.name AS position_name
    FROM workers w
    LEFT JOIN worker_positions wp ON wp.worker_id = w.id
    LEFT JOIN client_pmi_general_positions gp ON gp.position_id = wp.position_id AND gp.client_id = ? AND gp.project_id = ?
    LEFT JOIN positions p ON p.id = gp.position_id
    WHERE NOT EXISTS (SELECT 1 FROM client_pmi_habilitations h WHERE h.client_id = ? AND h.project_id = ? AND h.area = \'personal\' AND h.subject_id = w.id AND h.id <> ?)
    ORDER BY w.full_name, w.id, p.name');
$stmt->execute([$clientId, $projectId, $clientId, $projectId, max(0, (int) ($_GET['edit_id'] ?? 0))]);
$workers = [];
foreach ($stmt as $row) {
    $id = (int) $row['id'];
    if (!isset($workers[$id])) {
        $workers[$id] = ['id' => $id, 'name' => $row['full_name'], 'positions' => []];
    }
    if ($row['position_id'] !== null) {
        $workers[$id]['positions'][(int) $row['position_id']] = ['id' => (int) $row['position_id'], 'name' => $row['position_name']];
    }
}
foreach ($workers as &$worker) {
    $worker['positions'] = array_values($worker['positions']);
}
unset($worker);
echo json_encode(['workers' => array_values($workers)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
