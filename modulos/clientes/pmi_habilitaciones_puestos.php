<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');
header('Content-Type: application/json; charset=utf-8');

$workerId = (int) ($_GET['worker_id'] ?? 0);
if ($workerId < 1) {
    http_response_code(400);
    echo json_encode(['positions' => []]);
    exit;
}

$stmt = db()->prepare('SELECT p.id, p.name FROM worker_positions wp JOIN positions p ON p.id = wp.position_id WHERE wp.worker_id = ? ORDER BY p.name, p.id LIMIT 200');
$stmt->execute([$workerId]);
echo json_encode(['positions' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
