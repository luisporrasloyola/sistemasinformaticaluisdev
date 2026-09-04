<?php
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../../../config/database.php';
require_login();

if (is_personal_role()) {
    $stmt = db()->prepare('SELECT em.id, CONCAT(em.full_name, \' - \', em.document_number) AS text FROM workers w JOIN empresa_maquirenta_formato_personal em ON em.document_number = w.document_number WHERE w.id = :id LIMIT 1');
    $stmt->execute(['id' => current_user_worker_id()]);
    json_response(['results' => $stmt->fetchAll()]);
}
$q = '%' . trim((string) ($_GET['q'] ?? '')) . '%';
$stmt = db()->prepare("SELECT id, CONCAT(full_name, ' - ', document_number) AS text FROM empresa_maquirenta_formato_personal WHERE full_name LIKE :q_name OR document_number LIKE :q_document ORDER BY full_name LIMIT 20");
$stmt->execute(['q_name' => $q, 'q_document' => $q]);
json_response(['results' => $stmt->fetchAll()]);
