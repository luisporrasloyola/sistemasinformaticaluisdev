<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.informacion');
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new DomainException('Solicitud inválida.'); }
    verify_csrf($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 180) { throw new DomainException('Ingrese un nombre de proyecto válido.'); }
        $existing = db()->prepare('SELECT id FROM client_project_catalog WHERE name = ?');
        $existing->execute([$name]);
        if ($existing->fetchColumn()) { throw new DomainException('Este proyecto ya existe.'); }
        db()->prepare('INSERT INTO client_project_catalog (name) VALUES (?)')->execute([$name]);
        echo json_encode(['ok' => true, 'id' => (int) db()->lastInsertId(), 'name' => $name], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'delete') {
        $id = max(0, (int) ($_POST['project_id'] ?? 0));
        if (!$id) { throw new DomainException('Seleccione un proyecto válido.'); }
        $used = db()->prepare('SELECT 1 FROM client_project_assignments WHERE project_id = ? LIMIT 1');
        $used->execute([$id]);
        if ($used->fetchColumn()) { throw new DomainException('Este proyecto está asignado a un cliente. Desasígnelo y guarde antes de eliminarlo.'); }
        $delete = db()->prepare('DELETE FROM client_project_catalog WHERE id = ?');
        $delete->execute([$id]);
        if (!$delete->rowCount()) { throw new DomainException('El proyecto ya no existe.'); }
        echo json_encode(['ok' => true]);
    } else { throw new DomainException('Acción inválida.'); }
} catch (DomainException $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (PDOException $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'No se pudo modificar el catálogo de proyectos.'], JSON_UNESCAPED_UNICODE);
}
