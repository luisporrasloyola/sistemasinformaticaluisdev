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

function is_gestor_role(): bool
{
    return current_user_role() === 'Gestor';
}

function current_user_can_module(string $moduleKey): bool
{
    if (is_admin()) {
        return true;
    }

    if (is_personal_role()) {
        return in_array($moduleKey, ['control_personal', 'control_personal.control_asistencia'], true);
    }

    if (!is_gestor_role()) {
        return false;
    }

    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    require_once __DIR__ . '/../config/database.php';
    try {
        $stmt = db()->prepare('SELECT 1 FROM user_module_permissions WHERE user_id = :user_id AND module_key = :module_key AND can_access = 1 LIMIT 1');
        $stmt->execute(['user_id' => $userId, 'module_key' => $moduleKey]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function current_user_can_document(string $scopeKey, int $catalogId, string $mode = 'view'): bool
{
    if (is_admin()) {
        return true;
    }

    if (!is_gestor_role() || $catalogId <= 0) {
        return false;
    }

    $column = match ($mode) {
        'upload' => 'can_upload',
        'manage' => 'can_manage_catalog',
        default => 'can_view',
    };
    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    require_once __DIR__ . '/../config/database.php';
    try {
        $stmt = db()->prepare("SELECT 1 FROM user_document_permissions WHERE user_id = :user_id AND scope_key = :scope_key AND catalog_id = :catalog_id AND {$column} = 1 LIMIT 1");
        $stmt->execute(['user_id' => $userId, 'scope_key' => $scopeKey, 'catalog_id' => $catalogId]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function filter_allowed_documents(string $scopeKey, array $rows, string $idKey = 'id', string $mode = 'view'): array
{
    if (is_admin()) {
        return $rows;
    }
    return array_values(array_filter($rows, static fn(array $row): bool => current_user_can_document($scopeKey, (int) ($row[$idKey] ?? 0), $mode)));
}

function current_user_can_manage_scope(string $scopeKey): bool
{
    if (is_admin()) {
        return true;
    }

    if (!is_gestor_role()) {
        return false;
    }

    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    require_once __DIR__ . '/../config/database.php';
    try {
        $stmt = db()->prepare('SELECT 1 FROM user_document_permissions WHERE user_id = :user_id AND scope_key = :scope_key AND can_manage_catalog = 1 LIMIT 1');
        $stmt->execute(['user_id' => $userId, 'scope_key' => $scopeKey]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function require_module_access(string $moduleKey): void
{
    require_login();
    if (!current_user_can_module($moduleKey)) {
        http_response_code(403);
        exit('No tiene permisos para acceder a esta seccion.');
    }
}

function require_any_module_access(array $moduleKeys): void
{
    require_login();
    foreach ($moduleKeys as $moduleKey) {
        if (current_user_can_module((string) $moduleKey)) {
            return;
        }
    }
    http_response_code(403);
    exit('No tiene permisos para acceder a esta seccion.');
}

function default_user_landing_path(): string
{
    if (is_personal_role()) {
        return 'modulos/control_personal/control_asistencia.php';
    }

    $paths = [
        'dashboard' => 'panel.php',
        'control_personal.dashboard' => 'modulos/control_personal/dashboard_asistencia.php',
        'control_personal.personal' => 'modulos/aliados/personal.php',
        'control_personal.calendario_laboral' => 'modulos/control_personal/calendario_laboral.php',
        'control_personal.horarios' => 'modulos/control_personal/horarios.php',
        'control_personal.puntos_marcacion' => 'modulos/control_personal/puntos_marcacion.php',
        'control_personal.asignaciones' => 'modulos/control_personal/asignaciones.php',
        'control_personal.control_asistencia' => 'modulos/control_personal/control_asistencia.php',
        'control_personal.reportes' => 'modulos/control_personal/reportes.php',
        'control_personal.reporte_asistencias' => 'modulos/control_personal/reporte_asistencias.php',
        'requisitos.pmi_individual' => 'modulos/requisitos/pmi_individual.php',
        'requisitos.pmi_masivo' => 'modulos/requisitos/pmi_masivo.php',
        'maquinaria.datos_generales' => 'modulos/maquinaria/datos_generales.php',
        'maquinaria.documentos' => 'modulos/maquinaria/documentos.php',
        'empresa.datos_generales' => 'modulos/empresa/datos_generales.php',
        'empresa.documentos' => 'modulos/empresa/documentos.php',
        'empresa.seguridad' => 'modulos/empresa/seguridad.php',
        'empresa.calidad' => 'modulos/empresa/calidad.php',
        'empresa.medio_ambiente' => 'modulos/empresa/medio_ambiente.php',
        'usuarios' => 'modulos/usuario/usuarios.php',
    ];

    foreach ($paths as $moduleKey => $path) {
        if (current_user_can_module($moduleKey)) {
            return $path;
        }
    }

    return 'modulos/control_personal/control_asistencia.php';
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



