<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa_maquirenta.datos_generales');
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Seleccione una empresa Maquirenta válida.'], 422);
}

$stmt = db()->prepare('UPDATE empresas_maquirenta SET status = 0 WHERE id = :id AND status = 1');
$stmt->execute(['id' => $id]);
if ($stmt->rowCount() !== 1) {
    json_response(['ok' => false, 'message' => 'La empresa Maquirenta no existe o ya fue eliminada.'], 404);
}
json_response(['ok' => true, 'message' => 'Empresa Maquirenta eliminada correctamente.']);
