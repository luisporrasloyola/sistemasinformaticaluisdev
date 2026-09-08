<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_projects.php';
require_module_access('control_personal.proyectos');
verify_csrf($_POST['csrf_token'] ?? null);
ensure_attendance_projects_schema();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) json_response(['ok'=>false,'message'=>'Proyecto no válido.'],400);
$stmt = db()->prepare('UPDATE attendance_projects SET status=0 WHERE id=:id AND status=1');
$stmt->execute(['id'=>$id]);
if ($stmt->rowCount() === 0) json_response(['ok'=>false,'message'=>'El proyecto no existe o ya fue eliminado.'],404);
json_response(['ok'=>true]);