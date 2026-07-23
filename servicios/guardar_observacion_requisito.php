<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

require_login();
if (!is_admin() && !is_gestor_role()) {
    json_response(['ok' => false, 'message' => 'No tiene permisos para registrar observaciones.'], 403);
}
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$observation = trim((string) ($_POST['observation'] ?? ''));
if ($id <= 0) {
    json_response(['ok' => false, 'message' => 'Registro no válido.'], 400);
}
if ($observation === '') {
    json_response(['ok' => false, 'message' => 'Escriba la observación que desea registrar.'], 422);
}
if (mb_strlen($observation) > 3000) {
    json_response(['ok' => false, 'message' => 'La observación no puede superar 3000 caracteres.'], 422);
}

$pdo = db();
$currentStmt = $pdo->prepare('SELECT wr.requirement_id, wr.observations, wr.observation_by_user_id, wr.observation_at,
        registered_by.role AS registered_by_role
    FROM worker_requirements wr
    LEFT JOIN users registered_by ON registered_by.id = wr.registered_by_user_id
    WHERE wr.id = :id LIMIT 1');
$currentStmt->execute(['id' => $id]);
$current = $currentStmt->fetch();
if (!$current) {
    json_response(['ok' => false, 'message' => 'El requisito seleccionado no existe o fue eliminado.'], 404);
}

$requirementCatalogId = (int) $current['requirement_id'];
$registeredByAdmin = in_array(
    mb_strtolower(trim((string) ($current['registered_by_role'] ?? '')), 'UTF-8'),
    ['admin', 'administrador'],
    true
);
if (!is_admin() && $registeredByAdmin) {
    json_response([
        'ok' => false,
        'message' => 'Este requisito fue registrado por un administrador y solo puede ser observado por administradores.',
    ], 403);
}
if (!is_admin() && !current_user_can_document('requisitos.pmi_individual', $requirementCatalogId, 'upload')) {
    json_response(['ok' => false, 'message' => 'No tiene permisos para observar este requisito.'], 403);
}

$stripHeader = static function (string $value): string {
    $value = trim($value);
    if (preg_match('/^(?:Administrador|Gestor) .+ tiene esta observaci[oó]n:\R(.*)$/us', $value, $matches)) {
        return trim((string) ($matches[1] ?? ''));
    }
    return $value;
};

$user = current_user();
$userId = (int) ($user['id'] ?? 0) ?: null;
$previousObservation = $stripHeader((string) ($current['observations'] ?? ''));
$now = date('Y-m-d H:i:s');

try {
    $pdo->beginTransaction();

    $historyStmt = $pdo->prepare("SELECT COUNT(*) FROM worker_requirement_activity_log
        WHERE worker_requirement_id = :id AND action_type = 'observacion_registrada'");
    $historyStmt->execute(['id' => $id]);
    $hasContentHistory = (int) $historyStmt->fetchColumn() > 0;

    $insertHistory = $pdo->prepare('INSERT INTO worker_requirement_activity_log
        (worker_requirement_id, user_id, action_type, description, created_at)
        VALUES (:requirement_id, :user_id, :action_type, :description, :created_at)');

    // Conserva la observación anterior de registros creados antes del historial acumulativo.
    if (!$hasContentHistory && $previousObservation !== '') {
        $insertHistory->execute([
            'requirement_id' => $id,
            'user_id' => (int) ($current['observation_by_user_id'] ?? 0) ?: null,
            'action_type' => 'observacion_registrada',
            'description' => $previousObservation,
            'created_at' => $current['observation_at'] ?: $now,
        ]);
    }

    $stmt = $pdo->prepare("UPDATE worker_requirements
        SET observations = :observations,
            observation_status = 'observed',
            observation_by_user_id = :user_id,
            observation_at = :observation_at,
            observation_resolved_by_user_id = NULL,
            observation_resolved_at = NULL
        WHERE id = :id");
    $stmt->execute([
        'observations' => $observation,
        'user_id' => $userId,
        'observation_at' => $now,
        'id' => $id,
    ]);

    $insertHistory->execute([
        'requirement_id' => $id,
        'user_id' => $userId,
        'action_type' => 'observacion_registrada',
        'description' => $observation,
        'created_at' => $now,
    ]);

    $pdo->commit();
    json_response(['ok' => true, 'message' => 'Observación agregada al historial.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => 'No se pudo registrar la observación.'], 500);
}
