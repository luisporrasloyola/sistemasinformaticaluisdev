<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_role('Administrador');

$workerId = filter_var($_GET['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sourcePositionId = filter_var($_GET['source_position_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$workerId || !$sourcePositionId) json_response(['ok' => false, 'message' => 'Seleccione un trabajador y su puesto.'], 422);

$stmt = db()->prepare('SELECT src.id AS source_id, dst.position_id AS target_position_id
    FROM worker_requirements src
    JOIN worker_requirements dst ON dst.worker_id = src.worker_id AND dst.requirement_id = src.requirement_id AND dst.position_id <> src.position_id
    JOIN worker_positions wp ON wp.worker_id = dst.worker_id AND wp.position_id = dst.position_id
    WHERE src.worker_id = ? AND src.position_id = ?');
$stmt->execute([$workerId, $sourcePositionId]);
json_response(['ok' => true, 'existing' => $stmt->fetchAll()]);
