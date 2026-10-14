<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');
header('Content-Type: application/json; charset=utf-8');
$clientId = (int) ($_REQUEST['client_id'] ?? 0);
$check = db()->prepare('SELECT 1 FROM clients WHERE id = :id');
$check->execute(['id' => $clientId]);
if ($clientId < 1 || !$check->fetchColumn()) { http_response_code(404); echo json_encode(['ok' => false, 'message' => 'Cliente no encontrado.']); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
    $stmt = db()->prepare('SELECT id, name AS text FROM client_pmi_base_catalog WHERE client_id = :client_id AND name LIKE :q ORDER BY name LIMIT 50');
    $stmt->execute(['client_id' => $clientId, 'q' => '%' . $q . '%']);
    echo json_encode(['results' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
verify_csrf($_POST['csrf_token'] ?? null);
$action = (string) ($_POST['action'] ?? '');
try {
    if ($action === 'add') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 180) { throw new DomainException('Ingrese un nombre de documento válido.'); }
        $stmt = db()->prepare('INSERT INTO client_pmi_base_catalog (client_id, name) VALUES (:client_id, :name)');
        $stmt->execute(['client_id' => $clientId, 'name' => $name]);
        echo json_encode(['ok' => true, 'item' => ['id' => (int) db()->lastInsertId(), 'text' => $name]], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT name FROM client_pmi_base_catalog WHERE id = :id AND client_id = :client_id');
        $stmt->execute(['id' => $id, 'client_id' => $clientId]);
        $name = $stmt->fetchColumn();
        if ($name === false) { throw new DomainException('Documento no encontrado.'); }
        $used = db()->prepare('SELECT 1 FROM client_pmi_base_documents WHERE client_id = :client_id AND name = :name LIMIT 1');
        $used->execute(['client_id' => $clientId, 'name' => $name]);
        if ($used->fetchColumn()) { throw new DomainException('Este nombre ya tiene documentos registrados y no puede eliminarse.'); }
        db()->prepare('DELETE FROM client_pmi_base_catalog WHERE id = :id AND client_id = :client_id')->execute(['id' => $id, 'client_id' => $clientId]);
        echo json_encode(['ok' => true]);
    } else {
        throw new DomainException('Acción inválida.');
    }
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $error instanceof DomainException ? $error->getMessage() : 'No se pudo actualizar el catálogo.'], JSON_UNESCAPED_UNICODE);
}
