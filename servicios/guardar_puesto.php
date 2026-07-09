<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);

$name = trim((string) ($_POST['name'] ?? ''));
$name = preg_replace('/\s+/', ' ', $name) ?? '';

if ($name === '') {
    json_response(['ok' => false, 'message' => 'Ingrese el nombre del puesto de trabajo.'], 400);
}

if (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') > 160 : strlen($name) > 160) {
    json_response(['ok' => false, 'message' => 'El nombre del puesto es demasiado largo.'], 400);
}

try {
    $stmt = db()->prepare('INSERT INTO positions (name, status) VALUES (:name, 1) ON DUPLICATE KEY UPDATE status = 1, id = LAST_INSERT_ID(id)');
    $stmt->execute(['name' => $name]);
    $positionId = (int) db()->lastInsertId();

    json_response(['ok' => true, 'id' => $positionId, 'text' => $name]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => 'No se pudo guardar el puesto de trabajo.'], 400);
}