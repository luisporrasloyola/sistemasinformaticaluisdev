<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$q = '%' . trim((string) ($_GET['q'] ?? '')) . '%';
$stmt = db()->prepare("SELECT id, CONCAT(full_name, ' - ', document_number) AS text FROM workers WHERE full_name LIKE :q_name OR document_number LIKE :q_document ORDER BY full_name LIMIT 20");
$stmt->execute(['q_name' => $q, 'q_document' => $q]);
json_response(['results' => $stmt->fetchAll()]);
