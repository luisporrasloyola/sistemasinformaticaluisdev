<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_module_access('requisitos.pmi_individual');

$id = (int) ($_GET['id'] ?? 0);
require_personal_own_worker($id, false);
$stmt = db()->prepare("SELECT w.*, c.name AS company FROM workers w LEFT JOIN companies c ON c.id = w.company_id WHERE w.id = :id");
$stmt->execute(['id' => $id]);
$worker = $stmt->fetch();

if (!$worker) {
    json_response(['ok' => false], 404);
}

$stmt = db()->prepare('SELECT p.id, p.name FROM worker_positions wp JOIN positions p ON p.id = wp.position_id WHERE wp.worker_id = :id ORDER BY p.name');
$stmt->execute(['id' => $id]);
$positions = $stmt->fetchAll();

$bankAccounts = [];
try {
    $stmt = db()->prepare('SELECT b.name AS bank_name, a.account_number, a.currency, a.cci FROM worker_bank_accounts a JOIN worker_bank_catalog b ON b.id = a.bank_id WHERE a.worker_id = :id ORDER BY a.id');
    $stmt->execute(['id' => $id]);
    $bankAccounts = $stmt->fetchAll();
} catch (PDOException $e) {
    if ($e->getCode() !== '42S02') {
        throw $e;
    }
}

json_response(['ok' => true, 'worker' => $worker, 'positions' => $positions, 'bank_accounts' => $bankAccounts, 'app_url' => APP_URL]);

