<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

require_role('Administrador');

$workerId = (int) ($_GET['worker_id'] ?? 0);
if ($workerId <= 0) {
    json_response(['ok' => false, 'message' => 'Trabajador no válido.'], 400);
}

$workerStmt = db()->prepare('SELECT full_name, document_number FROM workers WHERE id = :id LIMIT 1');
$workerStmt->execute(['id' => $workerId]);
$worker = $workerStmt->fetch();
if (!$worker) {
    json_response(['ok' => false, 'message' => 'El trabajador no existe.'], 404);
}

$stmt = db()->prepare("SELECT aa.id, aa.activity, aa.status, aa.created_at, aa.deactivated_at,
        l.name AS location_name, s.name AS schedule_name,
        creator.name AS created_by, deactivator.name AS deactivated_by
    FROM attendance_assignments aa
    JOIN attendance_locations l ON l.id = aa.location_id
    JOIN attendance_schedules s ON s.id = aa.schedule_id
    LEFT JOIN users creator ON creator.id = aa.created_by_user_id
    LEFT JOIN users deactivator ON deactivator.id = aa.deactivated_by_user_id
    WHERE aa.worker_id = :worker_id
    ORDER BY aa.created_at DESC, aa.id DESC");
$stmt->execute(['worker_id' => $workerId]);

json_response(['ok' => true, 'worker' => $worker, 'history' => $stmt->fetchAll()]);
