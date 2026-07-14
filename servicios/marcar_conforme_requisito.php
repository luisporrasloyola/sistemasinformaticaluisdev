<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

require_role('Administrador');
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Registro no válido.'], 400);
}

$user = current_user();
$userId = (int) ($user['id'] ?? 0) ?: null;
$userName = trim((string) ($user['name'] ?? 'Administrador'));
$pdo = db();

$stmt = $pdo->prepare("UPDATE worker_requirements
    SET observation_status = 'approved',
        observation_resolved_by_user_id = :user_id,
        observation_resolved_at = NOW()
    WHERE id = :id");
$stmt->execute([
    'user_id' => $userId,
    'id' => $id,
]);

$log = $pdo->prepare('INSERT INTO worker_requirement_activity_log (worker_requirement_id, user_id, action_type, description)
    VALUES (:worker_requirement_id, :user_id, :action_type, :description)');
$log->execute([
    'worker_requirement_id' => $id,
    'user_id' => $userId,
    'action_type' => 'conformidad',
    'description' => 'Conformidad registrada por ' . $userName . '.',
]);

json_response(['ok' => true]);
