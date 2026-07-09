<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT wr.*, rc.name AS requirement
    FROM worker_requirements wr
    JOIN requirements_catalog rc ON rc.id = wr.requirement_id
    WHERE wr.id = :id");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    json_response(['ok' => false], 404);
}

json_response(['ok' => true, 'row' => $row]);

