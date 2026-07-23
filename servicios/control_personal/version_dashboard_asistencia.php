<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

require_module_access('control_personal.dashboard');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$version = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM attendance_marks')->fetchColumn();

json_response([
    'ok' => true,
    'version' => $version,
]);
