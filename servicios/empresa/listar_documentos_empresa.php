<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/status_alerts.php';
require_module_access('empresa.documentos');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function empresa_document_status(string $endDate, int $documentId): array
{
    return status_alert_document_status($endDate, 'empresa.documentos', $documentId);
}

$empresaId = (int) ($_GET['empresa_id'] ?? 0);
$stmt = db()->prepare("SELECT ed.*, edc.nombre AS documento, COALESCE(u.name, '') AS registered_by
    FROM empresa_documentos ed
    JOIN empresa_documentos_catalogo edc ON edc.id = ed.documento_id
    LEFT JOIN users u ON u.id = ed.registered_by_user_id
    WHERE ed.empresa_id = :empresa_id
    ORDER BY edc.id");
$stmt->execute(['empresa_id' => $empresaId]);
$rows = $stmt->fetchAll();
foreach ($rows as &$row) {
    $row['status'] = empresa_document_status($row['fecha_fin'], (int) $row['documento_id']);
}
json_response(['ok' => true, 'rows' => $rows]);
