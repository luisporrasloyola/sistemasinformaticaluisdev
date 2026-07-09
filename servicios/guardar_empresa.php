<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);

$name = trim((string) ($_POST['name'] ?? ''));
$name = preg_replace('/\s+/', ' ', $name) ?? '';

if ($name === '') {
    json_response(['ok' => false, 'message' => 'Ingrese el nombre de la empresa.'], 400);
}

if (mb_strlen($name, 'UTF-8') > 160) {
    json_response(['ok' => false, 'message' => 'El nombre de la empresa es demasiado largo.'], 400);
}

try {
    $stmt = db()->prepare('INSERT INTO companies (name, status) VALUES (:name, 1) ON DUPLICATE KEY UPDATE status = 1, id = LAST_INSERT_ID(id)');
    $stmt->execute(['name' => $name]);
    $companyId = (int) db()->lastInsertId();

    json_response(['ok' => true, 'id' => $companyId, 'text' => $name]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => 'No se pudo guardar la empresa.'], 400);
}