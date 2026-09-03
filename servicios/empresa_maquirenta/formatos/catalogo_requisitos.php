<?php
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../../../config/database.php';
require_login();

$q = '%' . trim((string) ($_GET['q'] ?? '')) . '%';

$stmt = db()->prepare("SELECT id, name AS text FROM empresa_maquirenta_formato_requisitos_catalogo WHERE status = 1 AND name LIKE :q ORDER BY id");
$stmt->execute(['q' => $q]);

$rows = filter_allowed_documents('empresa_maquirenta.pmi_individual', $stmt->fetchAll(), 'id', 'upload');
json_response(['results' => $rows]);
