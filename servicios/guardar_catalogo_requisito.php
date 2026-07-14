<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);
$name = trim((string) ($_POST['name'] ?? ''));
$positionId = (int) ($_POST['position_id'] ?? 0);

if ($name === '') {
    json_response(['ok' => false, 'message' => 'Ingrese un requisito.'], 400);
}

if (!current_user_can_manage_scope('requisitos.pmi_individual')) {
    json_response(['ok' => false, 'message' => 'No tiene permisos para agregar requisitos.'], 403);
}

$exists = db()->prepare('SELECT id FROM requirements_catalog WHERE LOWER(name) = LOWER(:name) LIMIT 1');
$exists->execute(['name' => $name]);
if ($exists->fetch()) {
    json_response(['ok' => false, 'message' => 'Este requisito ya existe.'], 409);
}

$stmt = db()->prepare('INSERT INTO requirements_catalog (name, status) VALUES (:name, 1) ON DUPLICATE KEY UPDATE status = 1, id = LAST_INSERT_ID(id)');
$stmt->execute(['name' => $name]);
$requirementId = (int) db()->lastInsertId();

if ($positionId > 0) {
    $stmt = db()->prepare('INSERT IGNORE INTO position_requirements (position_id, requirement_id) VALUES (:position_id, :requirement_id)');
    $stmt->execute(['position_id' => $positionId, 'requirement_id' => $requirementId]);
}

json_response(['ok' => true, 'id' => $requirementId, 'text' => $name]);
