<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');
header('Content-Type: application/json; charset=utf-8');
$category = (string) ($_GET['categoria'] ?? '');
if ($category === 'personal') {
    $items = db()->query('SELECT id, name AS text FROM requirements_catalog WHERE status = 1 ORDER BY name, id')->fetchAll();
} elseif ($category === 'equipos') {
    $items = db()->query('SELECT id, nombre AS text FROM maquinaria_documentos_catalogo WHERE estado = 1 ORDER BY nombre, id')->fetchAll();
} else {
    http_response_code(400);
    echo json_encode(['items' => [], 'error' => 'Categoría inválida.']);
    exit;
}
echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
