<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$q = '%' . trim((string) ($_GET['q'] ?? '')) . '%';

$stmt = db()->prepare("SELECT id, name AS text FROM requirements_catalog WHERE status = 1 AND name LIKE :q ORDER BY id");
$stmt->execute(['q' => $q]);

$rows = filter_allowed_documents('requisitos.pmi_individual', $stmt->fetchAll(), 'id', 'upload');
json_response(['results' => $rows]);
