<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('cobranzas.parte_diario');

$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$stmt = db()->prepare('SELECT id, business_name, ruc FROM clients WHERE business_name LIKE :name OR ruc LIKE :ruc ORDER BY business_name, id LIMIT 20');
$stmt->execute(['name' => '%' . $query . '%', 'ruc' => '%' . $query . '%']);
$results = array_map(static fn (array $client): array => [
    'id' => (int) $client['id'],
    'text' => $client['business_name'] . ' - ' . $client['ruc'],
], $stmt->fetchAll());
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
