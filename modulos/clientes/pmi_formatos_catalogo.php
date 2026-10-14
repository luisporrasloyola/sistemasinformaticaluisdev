<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');
header('Content-Type: application/json; charset=utf-8');

$clientId = (int) ($_REQUEST['client_id'] ?? 0);
$check = db()->prepare('SELECT 1 FROM clients WHERE id = :id');
$check->execute(['id' => $clientId]);
if ($clientId < 1 || !$check->fetchColumn()) { http_response_code(404); exit(json_encode(['ok' => false, 'message' => 'Cliente no encontrado.'])); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
    $stmt = db()->prepare('SELECT name AS id, name AS text FROM client_pmi_format_catalog WHERE client_id = :client_id AND name LIKE :q ORDER BY name LIMIT 50');
    $stmt->execute(['client_id' => $clientId, 'q' => '%' . $query . '%']);
    echo json_encode(['results' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

verify_csrf($_POST['csrf_token'] ?? null);
try {
    $action = (string) ($_POST['action'] ?? '');
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 180) { throw new DomainException('Ingrese un nombre de formato válido.'); }
    if ($action === 'add') {
        $stmt = db()->prepare('INSERT INTO client_pmi_format_catalog (client_id, name) VALUES (:client_id, :name)');
        $stmt->execute(['client_id' => $clientId, 'name' => $name]);
        echo json_encode(['ok' => true, 'item' => ['id' => $name, 'text' => $name]], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'delete') {
        $used = db()->prepare('SELECT 1 FROM client_pmi_format_documents d JOIN client_pmi_format_catalog c ON c.id = d.catalog_id WHERE d.client_id = :client_id AND c.name = :name LIMIT 1');
        $used->execute(['client_id' => $clientId, 'name' => $name]);
        if ($used->fetchColumn()) { throw new DomainException('Este formato ya tiene documentos registrados y no puede eliminarse.'); }
        $usedByPersonal = db()->prepare('SELECT 1 FROM client_pmi_habilitation_formats f
            JOIN client_pmi_format_catalog c ON c.id = f.catalog_id
            WHERE c.client_id = :client_id AND c.name = :name LIMIT 1');
        $usedByPersonal->execute(['client_id' => $clientId, 'name' => $name]);
        if ($usedByPersonal->fetchColumn()) { throw new DomainException('Este formato tiene archivos o fechas registrados en Personal habilitado y no puede eliminarse.'); }
        $stmt = db()->prepare('DELETE FROM client_pmi_format_catalog WHERE client_id = :client_id AND name = :name');
        $stmt->execute(['client_id' => $clientId, 'name' => $name]);
        if (!$stmt->rowCount()) { throw new DomainException('Formato no encontrado.'); }
        echo json_encode(['ok' => true]);
    } else {
        throw new DomainException('Acción inválida.');
    }
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $error instanceof DomainException ? $error->getMessage() : 'No se pudo actualizar el catálogo.'], JSON_UNESCAPED_UNICODE);
}
