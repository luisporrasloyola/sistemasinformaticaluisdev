<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

function maquinaria_document_status(string $endDate, string $startDate): array
{
    $today = new DateTimeImmutable('today');
    $end = new DateTimeImmutable($endDate);
    $warningLimit = $today->modify('+30 days');

    if ($end < $today) {
        return ['label' => 'NO APTO', 'class' => 'text-bg-danger'];
    }

    if ($end <= $warningLimit) {
        return ['label' => 'POR VENCER', 'class' => 'text-bg-warning'];
    }

    return ['label' => 'APTO', 'class' => 'text-bg-success'];
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
    $row['status'] = maquinaria_document_status($row['fecha_fin'], $row['fecha_inicio']);
}
json_response(['ok' => true, 'rows' => $rows]);
