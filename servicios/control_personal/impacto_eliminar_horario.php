<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_schedule_deletion.php';
require_role('Administrador');
$id = max(0, (int)($_GET['id'] ?? 0));
if (!$id) json_response(['ok'=>false,'message'=>'Horario no válido.'], 422);
$stmt = db()->prepare('SELECT id,name FROM attendance_schedules WHERE id=:id');
$stmt->execute(['id'=>$id]);
$schedule = $stmt->fetch();
if (!$schedule) json_response(['ok'=>false,'message'=>'El horario ya no existe.'], 404);
$impact = attendance_schedule_deletion_impact(db(), $id);
json_response(['ok'=>true,'schedule'=>['name'=>$schedule['name']],'counts'=>$impact['counts']]);
