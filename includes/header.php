<?php
require_once __DIR__ . '/security.php';
$user = current_user();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://rsms.me">
    <link href="https://rsms.me/inter/inter.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="<?= APP_URL ?>/recursos/css/sistema.css?v=<?= filemtime(__DIR__ . '/../recursos/css/sistema.css') ?>" rel="stylesheet">
    <script>window.APP_URL = '<?= APP_URL ?>';</script>
</head>
<body>
<div class="app-shell">
    <?php if ($user): ?>
        <?php require __DIR__ . '/sidebar.php'; ?>
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    <?php endif; ?>
    <main class="app-main <?= $user ? '' : 'auth-main' ?>">
        <?php if ($user): ?>
            <header class="topbar">
                <button class="sidebar-toggle-btn" id="sidebarToggle" type="button" aria-label="Alternar menú"><i class="fa-solid fa-bars"></i></button>
                <div class="topbar-spacer"></div>

                <div class="topbar-notif-container" id="obsNotifContainer">
                    <button class="topbar-notif-btn" id="obsNotifBtn" type="button" aria-label="Observaciones">
                        <i class="fa-solid fa-comment-dots"></i>
                        <span class="topbar-notif-badge" id="obsNotifBadge" style="display: none;">0</span>
                    </button>
                    <div class="topbar-notif-dropdown" id="obsNotifDropdown">
                        <div class="notif-dropdown-header">
                            Observaciones recientes
                        </div>
                        <div class="notif-search-container">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="text" id="obsNotifSearchInput" placeholder="Buscar observación...">
                        </div>
                        <div class="notif-dropdown-list" id="obsNotifList">
                            <div class="notif-loading text-center p-3 text-muted">
                                <i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando...
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="topbar-notif-container" id="notifContainer">
                    <button class="topbar-notif-btn" id="notifBellBtn" type="button" aria-label="Notificaciones">
                        <i class="fa-solid fa-bell"></i>
                        <span class="topbar-notif-badge" id="notifBadge" style="display: none;">0</span>
                    </button>
                    <div class="topbar-notif-dropdown" id="notifDropdown">
                        <div class="notif-dropdown-header">
                            Notificaciones recientes
                        </div>
                        <div class="notif-search-container">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="text" id="notifSearchInput" placeholder="Buscar por nombre...">
                        </div>
                        <div class="notif-dropdown-list" id="notifList">
                            <div class="notif-loading text-center p-3 text-muted">
                                <i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando...
                            </div>
                        </div>
                    </div>
                </div>

                <div class="topbar-user">
                    <span class="topbar-avatar"><img src="https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=100&h=100&q=80" alt="Usuario"></span>
                    <div>
                        <strong><?= e($user['name']) ?></strong>
                        <span><?= e($user['email']) ?></span>
                    </div>
                </div>
            </header>
<?php endif; ?>
        <section class="<?= $user ? 'content-wrap' : 'auth-wrap' ?>">


