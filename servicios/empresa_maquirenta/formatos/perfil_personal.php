<?php
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../../../config/database.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT w.*, c.name AS company FROM empresa_maquirenta_formato_personal w LEFT JOIN empresa_maquirenta_formato_empresas c ON c.id = w.company_id WHERE w.id = :id");
$stmt->execute(['id' => $id]);
$worker = $stmt->fetch();

if (!$worker) {
    json_response(['ok' => false], 404);
}

$stmt = db()->prepare('SELECT p.id, p.name FROM empresa_maquirenta_formato_personal_puestos wp JOIN empresa_maquirenta_formato_puestos p ON p.id = wp.position_id WHERE wp.worker_id = :id ORDER BY p.name');
$stmt->execute(['id' => $id]);
$positions = $stmt->fetchAll();

json_response(['ok' => true, 'worker' => $worker, 'positions' => $positions, 'app_url' => APP_URL]);

