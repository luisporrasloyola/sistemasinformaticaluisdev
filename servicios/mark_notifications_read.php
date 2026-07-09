<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'ok' => false,
        'message' => 'Método no permitido.',
    ], 405);
}

try {
    db()->query('UPDATE notifications SET is_read = 1 WHERE is_read = 0');
    json_response([
        'ok' => true,
        'message' => 'Notificaciones marcadas como leídas.',
    ]);
} catch (Exception $e) {
    json_response([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}
