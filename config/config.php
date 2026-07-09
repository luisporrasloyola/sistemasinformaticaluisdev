<?php
declare(strict_types=1);

define('APP_NAME', 'Life Maquinarias');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$appRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
$basePath = '';

if ($documentRoot !== '') {
    $normalizedRoot = str_replace('\\', '/', rtrim($documentRoot, DIRECTORY_SEPARATOR));
    $normalizedApp = str_replace('\\', '/', rtrim($appRoot, DIRECTORY_SEPARATOR));
    if (str_starts_with($normalizedApp, $normalizedRoot)) {
        $relativePath = trim(substr($normalizedApp, strlen($normalizedRoot)), '/');
        $basePath = $relativePath !== '' ? '/' . $relativePath : '';
    }
}

if ($basePath === '' && basename($appRoot) !== 'public_html') {
    $basePath = '/' . basename($appRoot);
}

define('APP_URL', $scheme . '://' . $host . $basePath);
define('DB_HOST', getenv('LIFEMAQUINARIAS_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('LIFEMAQUINARIAS_DB_NAME') ?: 'lifemaquinarias');
define('DB_USER', getenv('LIFEMAQUINARIAS_DB_USER') ?: 'root');
define('DB_PASS', getenv('LIFEMAQUINARIAS_DB_PASS') ?: '');
define('UPLOAD_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'archivos');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);

date_default_timezone_set('America/Lima');
