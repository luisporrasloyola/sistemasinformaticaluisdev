<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$q = '%' . trim((string) ($_GET['q'] ?? '')) . '%';
$stmt = db()->prepare("SELECT m.id, CONCAT(m.equipo, ' - ', m.serie_placa, COALESCE(CONCAT(' - ', c.name), '')) AS text
    FROM maquinarias m
    LEFT JOIN companies c ON c.id = m.company_id
    WHERE m.estado = 1 AND (m.equipo LIKE :q_equipo OR m.serie_placa LIKE :q_serie OR c.name LIKE :q_company)
    ORDER BY m.equipo, m.serie_placa
    LIMIT 30");
$stmt->execute(['q_equipo' => $q, 'q_serie' => $q, 'q_company' => $q]);
json_response(['results' => $stmt->fetchAll()]);
