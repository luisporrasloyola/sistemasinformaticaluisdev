<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = 28800; // 8 horas
    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    ini_set('session.cookie_lifetime', (string) $sessionLifetime);
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($isSecure) {
        ini_set('session.cookie_secure', '1');
    }

    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . APP_URL . '/' . ltrim($path, '/'));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_is_valid(?string $token): bool
{
    return (bool) $token
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function refresh_csrf_token(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): void
{
    if (!csrf_is_valid($token)) {
        refresh_csrf_token();
        http_response_code(419);
        exit("La sesi\u{00F3}n del formulario venci\u{00F3} por seguridad. Actualice la p\u{00E1}gina e int\u{00E9}ntelo nuevamente.");
    }
}

function require_login(): void
{
    if (empty($_SESSION['user'])) {
        redirect('ingreso.php');
    }
}

function current_user_role(): string
{
    return (string) ($_SESSION['user']['role'] ?? 'Administrador');
}

function current_user_worker_id(): ?int
{
    $workerId = $_SESSION['user']['worker_id'] ?? null;
    return $workerId ? (int) $workerId : null;
}

function is_admin(): bool
{
    return current_user_role() === 'Administrador';
}

function is_personal_role(): bool
{
    return current_user_role() === 'Personal';
}

function require_role(array|string $roles): void
{
    require_login();
    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array(current_user_role(), $allowed, true)) {
        http_response_code(403);
        exit('No tiene permisos para acceder a esta seccion.');
    }
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}



