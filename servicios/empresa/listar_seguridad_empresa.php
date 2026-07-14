<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa.seguridad');

function empresa_seguridad_status(string $endDate): array
{
    $today = new DateTimeImmutable('today');
    $end = new DateTimeImmutable($endDate);
    $warningLimit = $today->modify('+30 days');
    if ($end < $today) return ['label' => 'NO APTO', 'class' => 'text-bg-danger'];
    if ($end <= $warningLimit) return ['label' => 'POR VENCER', 'class' => 'text-bg-warning'];
    return ['label' => 'APTO', 'class' => 'text-bg-success'];
}

$empresaId = (int) ($_GET['empresa_id'] ?? 0);
$stmt = db()->prepare("SELECT ed.*, edc.nombre AS documento
    FROM empresa_seguridad_documentos ed
    JOIN empresa_seguridad_catalogo edc ON edc.id = ed.documento_id
    WHERE ed.empresa_id = :empresa_id
    ORDER BY edc.id");
$stmt->execute(['empresa_id' => $empresaId]);
$rows = $stmt->fetchAll();
foreach ($rows as &$row) {
    $row['status'] = empresa_seguridad_status($row['fecha_fin']);
}
json_response(['ok' => true, 'rows' => $rows]);
