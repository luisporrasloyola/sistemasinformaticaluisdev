<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$q = '%' . trim((string) ($_GET['q'] ?? '')) . '%';

$stmt = db()->prepare("SELECT id, name AS text FROM requirements_catalog WHERE status = 1 AND name LIKE :q ORDER BY id");
$stmt->execute(['q' => $q]);

json_response(['results' => $stmt->fetchAll()]);
