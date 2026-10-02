<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

if (is_personal_role()) {
    $stmt = db()->prepare("SELECT w.id, CONCAT(w.full_name, ' - ', w.document_number, ' - ', COALESCE(c.name, 'Sin empresa')) AS text
        FROM workers w LEFT JOIN companies c ON c.id = w.company_id WHERE w.id = :id LIMIT 1");
    $stmt->execute(['id' => current_user_worker_id()]);
    json_response(['results' => $stmt->fetchAll()]);
}
$q = '%' . trim((string) ($_GET['q'] ?? '')) . '%';
$stmt = db()->prepare("SELECT w.id, CONCAT(w.full_name, ' - ', w.document_number, ' - ', COALESCE(c.name, 'Sin empresa')) AS text
    FROM workers w LEFT JOIN companies c ON c.id = w.company_id
    WHERE w.full_name LIKE :q_name OR w.document_number LIKE :q_document OR c.name LIKE :q_company
    ORDER BY w.full_name LIMIT 20");
$stmt->execute(['q_name' => $q, 'q_document' => $q, 'q_company' => $q]);
json_response(['results' => $stmt->fetchAll()]);
