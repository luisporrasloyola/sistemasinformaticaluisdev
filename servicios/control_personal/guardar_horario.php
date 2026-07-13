<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$name = preg_replace('/\s+/', ' ', $name) ?? '';

if ($name === '') {
    json_response(['ok' => false, 'message' => 'Ingrese el nombre del horario.'], 400);
}

if ((function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 160) {
    json_response(['ok' => false, 'message' => 'El nombre del horario es demasiado largo.'], 400);
}

try {
    if ($id > 0) {
        $stmt = db()->prepare('UPDATE attendance_schedules SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $id]);
    } else {
        $stmt = db()->prepare('INSERT INTO attendance_schedules (name, status) VALUES (:name, 1)
            ON DUPLICATE KEY UPDATE status = 1, id = LAST_INSERT_ID(id)');
        $stmt->execute(['name' => $name]);
        $id = (int) db()->lastInsertId();
    }

    json_response(['ok' => true, 'id' => $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_response(['ok' => false, 'message' => 'Ya existe un horario con ese nombre.'], 409);
    }
    json_response(['ok' => false, 'message' => 'No se pudo guardar el horario.'], 400);
}
