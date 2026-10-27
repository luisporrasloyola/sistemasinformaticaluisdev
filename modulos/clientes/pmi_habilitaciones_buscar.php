<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');
header('Content-Type: application/json; charset=utf-8');

$area = ($_GET['area'] ?? '') === 'equipo' ? 'equipo' : 'personal';
$query = trim((string) ($_GET['q'] ?? ''));
$query = function_exists('mb_substr') ? mb_substr($query, 0, 100) : substr($query, 0, 100);

if ($area === 'personal') {
    $clientId = max(0, (int) ($_GET['client_id'] ?? 0));
    if (!$clientId) { http_response_code(400); echo json_encode(['results' => []]); exit; }
    $stmt = db()->prepare("SELECT w.id, w.full_name AS name, w.document_number AS detail FROM workers w
        WHERE (w.full_name LIKE :name OR w.document_number LIKE :detail)
        AND NOT EXISTS (SELECT 1 FROM client_pmi_habilitations h WHERE h.client_id = :client_id AND h.area = 'personal' AND h.subject_id = w.id AND h.id <> :edit_id)
        ORDER BY w.full_name, w.id LIMIT 20");
} else {
    $stmt = db()->prepare('SELECT id, equipo AS name, serie_placa AS detail FROM maquinarias WHERE equipo LIKE :name OR serie_placa LIKE :detail ORDER BY equipo, id LIMIT 20');
}
$term = '%' . $query . '%';
$params = ['name' => $term, 'detail' => $term];
if ($area === 'personal') { $params += ['client_id' => $clientId, 'edit_id' => max(0, (int) ($_GET['edit_id'] ?? 0))]; }
$stmt->execute($params);
$results = array_map(static fn (array $row): array => [
    'id' => (int) $row['id'],
    'text' => $row['name'] . ($row['detail'] ? ' - ' . $row['detail'] : ''),
], $stmt->fetchAll());
echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
