<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('cobranzas.valorizacion');

$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]) ?: 1;
$offset = ($page - 1) * 20;
if ($query === '') {
    $stmt = db()->query('SELECT id, business_name, ruc FROM clients ORDER BY business_name, id LIMIT 21 OFFSET ' . $offset);
} else {
    $stmt = db()->prepare('SELECT id, business_name, ruc FROM clients WHERE business_name LIKE :name OR ruc LIKE :ruc ORDER BY business_name, id LIMIT 21 OFFSET ' . $offset);
    $stmt->execute(['name' => '%' . $query . '%', 'ruc' => '%' . $query . '%']);
}
$clients = $stmt->fetchAll();
$hasMore = count($clients) > 20;
if ($hasMore) array_pop($clients);
$results = array_map(static fn (array $row): array => [
    'id' => (int) $row['id'],
    'text' => $row['business_name'] . ' - ' . $row['ruc'],
], $clients);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['results' => $results, 'pagination' => ['more' => $hasMore]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
