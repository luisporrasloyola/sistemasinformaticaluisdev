<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_module_access('control_personal.personal');
require_role('Administrador');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
}
verify_csrf($_POST['csrf_token'] ?? null);

$action = (string) ($_POST['action'] ?? '');
try {
    if ($action === 'add') {
        $name = preg_replace('/\s+/u', ' ', trim((string) ($_POST['name'] ?? '')));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
            json_response(['ok' => false, 'message' => 'Ingrese un banco de hasta 120 caracteres.'], 400);
        }
        $stmt = db()->prepare('INSERT INTO worker_bank_catalog (name) VALUES (:name) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
        $stmt->execute(['name' => $name]);
        json_response(['ok' => true, 'id' => (int) db()->lastInsertId(), 'name' => $name]);
    }

    if ($action === 'delete') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            json_response(['ok' => false, 'message' => 'Seleccione un banco válido.'], 400);
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM worker_bank_accounts WHERE bank_id = :id');
        $stmt->execute(['id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            json_response(['ok' => false, 'message' => 'No se puede eliminar un banco que ya tiene cuentas registradas.'], 409);
        }
        $stmt = db()->prepare('DELETE FROM worker_bank_catalog WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) {
            json_response(['ok' => false, 'message' => 'El banco ya no existe.'], 404);
        }
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'message' => 'Acción no válida.'], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => 'No se pudo actualizar el catálogo de bancos.'], 500);
}
