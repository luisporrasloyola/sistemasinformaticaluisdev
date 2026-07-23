<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT wr.*, rc.name AS requirement,
        observed_by.name AS observation_by,
        observed_by.role AS observation_by_role,
        resolved_by.name AS observation_resolved_by,
        resolved_by.role AS observation_resolved_by_role,
        registered_by.role AS registered_by_role
    FROM worker_requirements wr
    JOIN requirements_catalog rc ON rc.id = wr.requirement_id
    LEFT JOIN users observed_by ON observed_by.id = wr.observation_by_user_id
    LEFT JOIN users resolved_by ON resolved_by.id = wr.observation_resolved_by_user_id
    LEFT JOIN users registered_by ON registered_by.id = wr.registered_by_user_id
    WHERE wr.id = :id");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    json_response(['ok' => false], 404);
}

$logStmt = db()->prepare("SELECT al.action_type, al.description, al.created_at, u.name AS user_name, u.role AS user_role
    FROM worker_requirement_activity_log al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE al.worker_requirement_id = :id
    ORDER BY al.created_at DESC, al.id DESC");
$logStmt->execute(['id' => $id]);

$registeredByAdmin = in_array(
    mb_strtolower(trim((string) ($row['registered_by_role'] ?? '')), 'UTF-8'),
    ['admin', 'administrador'],
    true
);
$canObserve = is_admin()
    || (is_gestor_role() && !$registeredByAdmin && current_user_can_document(
        'requisitos.pmi_individual',
        (int) $row['requirement_id'],
        'upload'
    ));

json_response([
    'ok' => true,
    'row' => $row,
    'activity' => $logStmt->fetchAll(),
    'can_observe' => $canObserve,
]);
