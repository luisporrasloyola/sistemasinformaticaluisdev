<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_projects.php';
require_module_access('control_personal.proyectos');
verify_csrf($_POST['csrf_token'] ?? null);
ensure_attendance_projects_schema();

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
if ($name === '' || mb_strlen($name) > 180) {
    json_response(['ok' => false, 'message' => 'Ingrese un nombre de proyecto válido.'], 400);
}

try {
    if ($id > 0) {
        $stmt = db()->prepare('UPDATE attendance_projects SET name=:name, status=1, created_by_user_id=:user_id WHERE id=:id');
        $stmt->execute(['name'=>$name, 'user_id'=>(int)(current_user()['id'] ?? 0) ?: null, 'id'=>$id]);
        if ($stmt->rowCount() === 0) {
            $exists = db()->prepare('SELECT 1 FROM attendance_projects WHERE id=:id LIMIT 1');
            $exists->execute(['id'=>$id]);
            if (!$exists->fetchColumn()) json_response(['ok'=>false,'message'=>'El proyecto no existe.'],404);
        }
    } else {
        $stmt = db()->prepare('INSERT INTO attendance_projects (name,status,created_by_user_id) VALUES (:name,1,:user_id)
            ON DUPLICATE KEY UPDATE status=1, created_by_user_id=VALUES(created_by_user_id), id=LAST_INSERT_ID(id)');
        $stmt->execute(['name'=>$name, 'user_id'=>(int)(current_user()['id'] ?? 0) ?: null]);
        $id = (int) db()->lastInsertId();
    }
    json_response(['ok'=>true,'id'=>$id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') json_response(['ok'=>false,'message'=>'Ya existe un proyecto con ese nombre.'],409);
    json_response(['ok'=>false,'message'=>'No se pudo guardar el proyecto.'],400);
}