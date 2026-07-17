<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/status_alerts.php';
require_login();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function maquinaria_document_status(string $endDate, string $startDate, int $documentId): array
{
    return status_alert_document_status($endDate, 'maquinaria.documentos', $documentId);
}

$maquinariaId = (int) ($_GET['maquinaria_id'] ?? 0);
$stmt = db()->prepare("SELECT md.*, mdc.nombre AS documento, COALESCE(u.name, '') AS registered_by
    FROM maquinaria_documentos md
    JOIN maquinaria_documentos_catalogo mdc ON mdc.id = md.documento_id
    LEFT JOIN users u ON u.id = md.registered_by_user_id
    WHERE md.maquinaria_id = :maquinaria_id
    ORDER BY mdc.nombre");
$stmt->execute(['maquinaria_id' => $maquinariaId]);
$rows = $stmt->fetchAll();
foreach ($rows as &$row) {
    $row['status'] = maquinaria_document_status($row['fecha_fin'], $row['fecha_inicio'], (int) $row['documento_id']);
}
json_response(['ok' => true, 'rows' => $rows]);
