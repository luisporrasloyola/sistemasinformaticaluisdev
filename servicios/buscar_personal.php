<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_module_access('requisitos.pmi_individual');

if (is_personal_role()) {
    $stmt = db()->prepare("SELECT w.id, CONCAT(w.full_name, ' - ', w.document_number, ' - ', COALESCE(c.name, 'Sin empresa')) AS text
        FROM workers w LEFT JOIN companies c ON c.id = w.company_id WHERE w.id = :id LIMIT 1");
    $stmt->execute(['id' => current_user_worker_id()]);
    json_response(['results' => $stmt->fetchAll()]);
}
$companyId = filter_var($_GET['empresa_id'] ?? null, FILTER_VALIDATE_INT);
if (!$companyId || $companyId < 1) {
    json_response(['results' => []]);
}
$q = '%' . trim((string) ($_GET['q'] ?? '')) . '%';
$stmt = db()->prepare("SELECT w.id, CONCAT(w.full_name, ' - ', w.document_number, ' - ', COALESCE(c.name, 'Sin empresa')) AS text
    FROM workers w LEFT JOIN companies c ON c.id = w.company_id
    WHERE w.company_id = :company_id AND (w.full_name LIKE :q_name OR w.document_number LIKE :q_document)
    ORDER BY w.full_name LIMIT 20");
$stmt->execute(['company_id' => $companyId, 'q_name' => $q, 'q_document' => $q]);
json_response(['results' => $stmt->fetchAll()]);
