<?php
$currentPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$dashboardActive = str_contains($currentPath, '/panel.php') || str_ends_with($currentPath, '/index.php');
$aliadosOpen = str_contains($currentPath, '/modulos/aliados/');
$requisitosOpen = str_contains($currentPath, '/modulos/requisitos/');
$maquinariaOpen = str_contains($currentPath, '/modulos/maquinaria/');
$usuarioOpen = str_contains($currentPath, '/modulos/usuario/');
?>
<aside class="sidebar" id="sidebar">
    <div class="brand sidebar-brand">
        <span class="brand-mark"><i class="fa-solid fa-truck-fast"></i></span>
        <div class="brand-copy">
            <div class="brand-title">Life</div>
            <small>Maquinarias</small>
        </div>
    </div>
    <div class="nav-caption">Menú principal</div>
    <nav class="nav flex-column sidebar-menu">
        <a class="nav-link <?= $dashboardActive ? 'active' : '' ?>" href="<?= APP_URL ?>/panel.php"><i class="fa-solid fa-house"></i><span>Dashboard</span></a>
        <button class="nav-link nav-parent <?= $aliadosOpen ? 'active' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#aliadosMenu" aria-expanded="<?= $aliadosOpen ? 'true' : 'false' ?>" aria-controls="aliadosMenu">
            <i class="fa-solid fa-handshake"></i><span>Aliados</span><i class="fa-solid fa-chevron-down nav-caret"></i>
        </button>
        <div class="collapse <?= $aliadosOpen ? 'show' : '' ?>" id="aliadosMenu">
            <div class="submenu">
                <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/aliados/personal.php"><i class="fa-solid fa-users"></i><span>Personal</span></a>
                <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/aliados/control_asistencia.php"><i class="fa-solid fa-calendar-check"></i><span>Control de asistencia</span></a>
            </div>
        </div>

        <button class="nav-link nav-parent <?= $requisitosOpen ? 'active' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#requisitosMenu" aria-expanded="<?= $requisitosOpen ? 'true' : 'false' ?>" aria-controls="requisitosMenu">
            <i class="fa-solid fa-folder-open"></i><span>Requisitos</span><i class="fa-solid fa-chevron-down nav-caret"></i>
        </button>
        <div class="collapse <?= $requisitosOpen ? 'show' : '' ?>" id="requisitosMenu">
            <div class="submenu">
                <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/requisitos/pmi_individual.php"><i class="fa-solid fa-file-shield"></i><span>PMI Individual</span></a>
                <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/requisitos/pmi_masivo.php"><i class="fa-solid fa-file-import"></i><span>Requisito PMI Masivo</span></a>
            </div>
        </div>

        <button class="nav-link nav-parent <?= $maquinariaOpen ? 'active' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#maquinariaMenu" aria-expanded="<?= $maquinariaOpen ? 'true' : 'false' ?>" aria-controls="maquinariaMenu">
            <i class="fa-solid fa-truck-pickup"></i><span>Maquinaria</span><i class="fa-solid fa-chevron-down nav-caret"></i>
        </button>
        <div class="collapse <?= $maquinariaOpen ? 'show' : '' ?>" id="maquinariaMenu">
            <div class="submenu">
                <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/maquinaria/datos_generales.php"><i class="fa-solid fa-clipboard-list"></i><span>Datos generales</span></a>
                <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/maquinaria/documentos.php"><i class="fa-solid fa-file-lines"></i><span>Documentos</span></a>
            </div>
        </div>

        <a class="nav-link <?= $usuarioOpen ? 'active' : '' ?>" href="<?= APP_URL ?>/modulos/usuario/usuarios.php"><i class="fa-solid fa-users-gear"></i><span>Usuarios</span></a>
        <a class="nav-link sidebar-logout" href="<?= APP_URL ?>/salir.php"><i class="fa-solid fa-right-from-bracket"></i><span>Cerrar sesión</span></a>
    </nav>
    <div class="sidebar-footer">
        <strong>© <?= date('Y') ?> Life Maquinarias</strong>
        <span>Todos los derechos reservados.</span>
    </div>
</aside>