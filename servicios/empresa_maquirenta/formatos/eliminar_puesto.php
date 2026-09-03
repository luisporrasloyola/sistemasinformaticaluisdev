<?php
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../../../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Puesto no válido.'], 400);
}

$stmt = db()->prepare('SELECT name FROM empresa_maquirenta_formato_puestos WHERE id = :id');
$stmt->execute(['id' => $id]);
$position = $stmt->fetch();
if (!$position) {
    json_response(['ok' => false, 'message' => 'No se encontró el puesto de trabajo.'], 404);
}

$checks = [
    ['sql' => 'SELECT COUNT(*) FROM empresa_maquirenta_formato_personal_puestos WHERE position_id = :id', 'message' => 'No se puede eliminar el puesto porque ya está asignado a personal.'],
    ['sql' => 'SELECT COUNT(*) FROM empresa_maquirenta_formato_requisitos WHERE position_id = :id', 'message' => 'No se puede eliminar el puesto porque tiene requisitos registrados.'],
    ['sql' => 'SELECT COUNT(*) FROM empresa_maquirenta_formato_puesto_requisitos WHERE position_id = :id', 'message' => 'No se puede eliminar el puesto porque tiene requisitos configurados.'],
];

foreach ($checks as $check) {
    $stmt = db()->prepare($check['sql']);
    $stmt->execute(['id' => $id]);
    if ((int) $stmt->fetchColumn() > 0) {
        json_response(['ok' => false, 'message' => $check['message']], 409);
    }
}

db()->prepare('UPDATE empresa_maquirenta_formato_puestos SET status = 0 WHERE id = :id')->execute(['id' => $id]);
json_response(['ok' => true]);