<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');
header('Content-Type: application/json; charset=utf-8');

$area = ($_GET['area'] ?? '') === 'equipo' ? 'equipo' : 'personal';
$query = trim((string) ($_GET['q'] ?? ''));
$query = function_exists('mb_substr') ? mb_substr($query, 0, 100) : substr($query, 0, 100);

if ($area === 'personal') {
    $stmt = db()->prepare('SELECT id, full_name AS name, document_number AS detail FROM workers WHERE full_name LIKE :name OR document_number LIKE :detail ORDER BY full_name, id LIMIT 20');
} else {
    $stmt = db()->prepare('SELECT id, equipo AS name, serie_placa AS detail FROM maquinarias WHERE equipo LIKE :name OR serie_placa LIKE :detail ORDER BY equipo, id LIMIT 20');
}
$term = '%' . $query . '%';
$stmt->execute(['name' => $term, 'detail' => $term]);
$results = array_map(static fn (array $row): array => [
    'id' => (int) $row['id'],
    'text' => $row['name'] . ($row['detail'] ? ' - ' . $row['detail'] : ''),
], $stmt->fetchAll());
echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
