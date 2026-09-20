<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_location_deletion.php';
require_role('Administrador');

$id = max(0, (int) ($_GET['id'] ?? 0));
if ($id <= 0) json_response(['ok'=>false,'message'=>'Lugar de marcación no válido.'], 422);

$stmt = db()->prepare('SELECT id,name,status FROM attendance_locations WHERE id=:id LIMIT 1');
$stmt->execute(['id'=>$id]);
$location = $stmt->fetch();
if (!$location) json_response(['ok'=>false,'message'=>'El lugar ya no existe.'], 404);

$impact = attendance_location_deletion_impact(db(), $id);
json_response([
    'ok'=>true,
    'location'=>['id'=>(int)$location['id'],'name'=>(string)$location['name'],'hidden'=>(int)$location['status']===0],
    'counts'=>$impact['counts'],
    'total_records'=>array_sum($impact['counts']) - $impact['counts']['photos'],
]);