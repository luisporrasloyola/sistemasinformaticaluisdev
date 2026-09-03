<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_module_access('requisitos.pmi_individual');

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT wr.id, wr.worker_id, wr.position_id, wr.requirement_id, rc.name AS requirement, p.name AS source_position FROM worker_requirements wr JOIN requirements_catalog rc ON rc.id=wr.requirement_id JOIN positions p ON p.id=wr.position_id WHERE wr.id=?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) json_response(['ok'=>false,'message'=>'El registro ya no existe.'],404);
$stmt = db()->prepare('SELECT p.id,p.name, EXISTS(SELECT 1 FROM worker_requirements existing WHERE existing.worker_id=wp.worker_id AND existing.position_id=p.id AND existing.requirement_id=?) AS already_exists FROM worker_positions wp JOIN positions p ON p.id=wp.position_id WHERE wp.worker_id=? AND p.id<>? ORDER BY p.name');
$stmt->execute([(int)$row['requirement_id'],(int)$row['worker_id'],(int)$row['position_id']]);
json_response(['ok'=>true,'record'=>$row,'positions'=>$stmt->fetchAll()]);