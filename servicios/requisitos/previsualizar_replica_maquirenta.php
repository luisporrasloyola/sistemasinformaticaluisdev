<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
}
verify_csrf($_POST['csrf_token'] ?? null);
$ids = array_values(array_unique(array_filter(array_map('intval', $_POST['worker_ids'] ?? []), static fn(int $id): bool => $id > 0)));
if (!$ids) {
    json_response(['ok' => false, 'message' => 'Seleccione al menos un trabajador.'], 422);
}
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = db()->prepare("SELECT w.id, w.full_name, w.document_number, c.name AS company
    FROM workers w LEFT JOIN companies c ON c.id=w.company_id
    WHERE w.id IN ($marks) ORDER BY w.full_name");
$stmt->execute($ids);
$workers = $stmt->fetchAll();
$positionStmt = db()->prepare('SELECT p.id, p.name FROM worker_positions wp JOIN positions p ON p.id=wp.position_id WHERE wp.worker_id=? ORDER BY p.name');
$requirementStmt = db()->prepare('SELECT wr.id, rc.name, wr.registration_date, wr.start_date, wr.end_date, wr.file_path FROM worker_requirements wr JOIN requirements_catalog rc ON rc.id=wr.requirement_id WHERE wr.worker_id=? AND wr.position_id=? ORDER BY rc.name');
foreach ($workers as &$worker) {
    $positionStmt->execute([(int) $worker['id']]);
    $positions = $positionStmt->fetchAll();
    foreach ($positions as &$position) {
        $requirementStmt->execute([(int) $worker['id'], (int) $position['id']]);
        $position['requirements'] = $requirementStmt->fetchAll();
    }
    unset($position);
    $worker['positions'] = $positions;
}
unset($worker);
json_response(['ok' => true, 'workers' => $workers]);