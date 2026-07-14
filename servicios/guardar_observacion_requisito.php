<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

require_login();
if (!is_admin()) {
    json_response(['ok' => false, 'message' => 'Solo un administrador puede registrar observaciones.'], 403);
}
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$observation = trim((string) ($_POST['observation'] ?? ''));

if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Registro no válido.'], 400);
}

$existsStmt = db()->prepare('SELECT 1 FROM worker_requirements WHERE id = :id LIMIT 1');
$existsStmt->execute(['id' => $id]);
if (!$existsStmt->fetchColumn()) {
    json_response(['ok' => false, 'message' => 'El requisito seleccionado no existe o fue eliminado.'], 404);
}

$user = current_user();
$userId = (int) ($user['id'] ?? 0) ?: null;
$userName = trim((string) ($user['name'] ?? 'Administrador'));
$observation = preg_replace('/^Administrador .+ tiene esta observaci[oó]n:\R/u', '', $observation) ?? $observation;

if ($observation !== '') {
    $observation = 'Administrador ' . $userName . " tiene esta observación:\n" . $observation;
}

$pdo = db();
$stmt = $pdo->prepare("UPDATE worker_requirements
    SET observations = :observations,
        observation_status = :status,
        observation_by_user_id = :user_id,
        observation_at = :observation_at,
        observation_resolved_by_user_id = NULL,
        observation_resolved_at = NULL
    WHERE id = :id");
$stmt->execute([
    'observations' => $observation,
    'status' => $observation !== '' ? 'observed' : 'none',
    'user_id' => $userId,
    'observation_at' => $observation !== '' ? date('Y-m-d H:i:s') : null,
    'id' => $id,
]);

$logDescription = $observation !== ''
    ? 'Registro observado por ' . $userName . '.'
    : 'Observación retirada por ' . $userName . '.';

$log = $pdo->prepare('INSERT INTO worker_requirement_activity_log (worker_requirement_id, user_id, action_type, description)
    VALUES (:worker_requirement_id, :user_id, :action_type, :description)');
$log->execute([
    'worker_requirement_id' => $id,
    'user_id' => $userId,
    'action_type' => $observation !== '' ? 'observacion' : 'observacion_retirada',
    'description' => $logDescription,
]);

json_response(['ok' => true]);
