<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$q = '%' . trim((string) ($_GET['q'] ?? '')) . '%';
$stmt = db()->prepare('SELECT id, nombre AS text FROM maquinaria_documentos_catalogo WHERE estado = 1 AND nombre LIKE :q ORDER BY id');
$stmt->execute(['q' => $q]);
json_response(['results' => $stmt->fetchAll()]);
