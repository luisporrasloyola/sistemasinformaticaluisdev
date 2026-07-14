<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

try {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $userId = (int) (current_user()['id'] ?? 0);
    if ($userId <= 0) {
        json_response(['ok' => true, 'unread_count' => 0, 'notifications' => []]);
    }

    $hasAudit = db()->prepare('SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
        LIMIT 1');
    $hasAudit->execute([
        'table_name' => 'worker_requirements',
        'column_name' => 'observation_status',
    ]);
    if (!$hasAudit->fetchColumn()) {
        json_response(['ok' => true, 'unread_count' => 0, 'notifications' => []]);
    }

    $stmt = db()->prepare("SELECT
            wr.id,
            wr.observations,
            wr.observation_status,
            wr.observation_at,
            wr.created_at,
            wr.updated_at,
            w.full_name,
            rc.name AS requirement_name,
            observed_by.name AS observed_by,
            registered_by.name AS registered_by
        FROM worker_requirements wr
        JOIN workers w ON w.id = wr.worker_id
        JOIN requirements_catalog rc ON rc.id = wr.requirement_id
        LEFT JOIN users observed_by ON observed_by.id = wr.observation_by_user_id
        LEFT JOIN users registered_by ON registered_by.id = wr.registered_by_user_id
        WHERE (
            (
                wr.observation_status = 'observed'
                OR (
                    COALESCE(wr.observation_status, 'none') = 'none'
                    AND TRIM(COALESCE(wr.observations, '')) <> ''
                )
            )
            AND wr.registered_by_user_id = :user_id
        )
        OR (
            wr.observation_status = 'corrected'
            AND wr.observation_by_user_id = :reviewer_user_id
        )
        ORDER BY COALESCE(wr.observation_at, wr.updated_at, wr.created_at) DESC, wr.id DESC
        LIMIT 30");
    $stmt->execute([
        'user_id' => $userId,
        'reviewer_user_id' => $userId,
    ]);

    $notifications = [];
    foreach ($stmt->fetchAll() as $row) {
        $status = (string) ($row['observation_status'] ?? '');
        $observation = strip_observation_header((string) ($row['observations'] ?? ''));
        $notifications[] = [
            'id' => (int) $row['id'],
            'status' => $status,
            'status_label' => $status === 'corrected' ? 'Corregido por revisar' : 'Observado',
            'full_name' => (string) $row['full_name'],
            'requirement' => (string) $row['requirement_name'],
            'observation' => $observation,
            'observed_by' => (string) ($row['observed_by'] ?: ($row['registered_by'] ?? '')),
            'registered_by' => (string) ($row['registered_by'] ?? ''),
            'created_at' => (string) ($row['observation_at'] ?: ($row['updated_at'] ?: $row['created_at'])),
        ];
    }

    json_response([
        'ok' => true,
        'unread_count' => count($notifications),
        'notifications' => $notifications,
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => 'No se pudieron cargar las observaciones.'], 500);
}

function strip_observation_header(string $value): string
{
    if (str_contains($value, "\n")) {
        return trim((string) substr($value, (int) strpos($value, "\n") + 1));
    }

    return trim($value);
}
