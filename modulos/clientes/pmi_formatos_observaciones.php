<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');
header('Content-Type: application/json; charset=utf-8');
$clientId = (int) ($_GET['client_id'] ?? 0);
$documentId = (int) ($_GET['document_id'] ?? 0);
if ($clientId < 1 || $documentId < 1) { http_response_code(400); exit(json_encode(['observations' => []])); }
$stmt = db()->prepare("SELECT o.note, o.created_at, COALESCE(u.name, 'Usuario') AS user_name FROM client_pmi_format_observations o JOIN client_pmi_format_documents d ON d.id = o.document_id LEFT JOIN users u ON u.id = o.created_by_user_id WHERE d.id = :document_id AND d.client_id = :client_id ORDER BY o.created_at DESC, o.id DESC");
$stmt->execute(['document_id' => $documentId, 'client_id' => $clientId]);
echo json_encode(['observations' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
