<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.reporte_asistencias');
verify_csrf($_POST['csrf_token'] ?? null);
header('Content-Type: application/json; charset=utf-8');

$workerId = (int) ($_POST['worker_id'] ?? 0);
$dateFrom = trim((string) ($_POST['date_from'] ?? ''));
$dateTo = trim((string) ($_POST['date_to'] ?? ''));
$observation = trim((string) ($_POST['observation'] ?? ''));
if ($workerId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) || $dateFrom > $dateTo) {
    http_response_code(422); echo json_encode(['ok' => false, 'message' => 'Los datos del reporte no son válidos.']); exit;
}
if (mb_strlen($observation) > 3000) {
    http_response_code(422); echo json_encode(['ok' => false, 'message' => 'La observación no puede superar 3000 caracteres.']); exit;
}

try {
    db()->exec("CREATE TABLE IF NOT EXISTS attendance_report_notes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, worker_id INT UNSIGNED NOT NULL,
        date_from DATE NOT NULL, date_to DATE NOT NULL, observation TEXT NULL,
        responsible_user_id INT UNSIGNED NULL, responsible_name VARCHAR(180) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_attendance_report_note (worker_id, date_from, date_to),
        CONSTRAINT fk_attendance_report_note_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
        CONSTRAINT fk_attendance_report_note_user FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $user = current_user();
    $stmt = db()->prepare('INSERT INTO attendance_report_notes
        (worker_id, date_from, date_to, observation, responsible_user_id, responsible_name)
        VALUES (:worker_id, :date_from, :date_to, :observation, :user_id, :responsible_name)
        ON DUPLICATE KEY UPDATE observation = VALUES(observation), responsible_user_id = VALUES(responsible_user_id),
        responsible_name = VALUES(responsible_name), updated_at = CURRENT_TIMESTAMP');
    $stmt->execute(['worker_id' => $workerId, 'date_from' => $dateFrom, 'date_to' => $dateTo,
        'observation' => $observation, 'user_id' => (int) ($user['id'] ?? 0) ?: null,
        'responsible_name' => (string) ($user['name'] ?? 'Responsable')]);
    echo json_encode(['ok' => true, 'message' => 'Observación guardada correctamente.', 'responsible_name' => $user['name'] ?? 'Responsable']);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['ok' => false, 'message' => 'No se pudo guardar la observación.']);
}
