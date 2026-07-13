<?php
$currentPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$dashboardActive = str_contains($currentPath, '/panel.php') || str_ends_with($currentPath, '/index.php');
$controlPersonalOpen = str_contains($currentPath, '/modulos/aliados/') || str_contains($currentPath, '/modulos/control_personal/');
$requisitosOpen = str_contains($currentPath, '/modulos/requisitos/');
$maquinariaOpen = str_contains($currentPath, '/modulos/maquinaria/');
$empresaOpen = str_contains($currentPath, '/modulos/empresa/');
$usuarioOpen = str_contains($currentPath, '/modulos/usuario/');
$isAdmin = is_admin();
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
        <?php if ($isAdmin): ?>
            <a class="nav-link <?= $dashboardActive ? 'active' : '' ?>" href="<?= APP_URL ?>/panel.php"><i class="fa-solid fa-house"></i><span>Dashboard</span></a>
        <?php endif; ?>

        <button class="nav-link nav-parent <?= $controlPersonalOpen ? 'active' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#controlPersonalMenu" aria-expanded="<?= $controlPersonalOpen ? 'true' : 'false' ?>" aria-controls="controlPersonalMenu">
            <i class="fa-solid fa-people-group"></i><span>Control de personal</span><i class="fa-solid fa-chevron-down nav-caret"></i>
        </button>
        <div class="collapse <?= $controlPersonalOpen ? 'show' : '' ?>" id="controlPersonalMenu">
            <div class="submenu">
                <?php if ($isAdmin): ?>
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/control_personal/dashboard_asistencia.php"><i class="fa-solid fa-chart-line"></i><span>Dashboard de asistencia</span></a>
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/aliados/personal.php"><i class="fa-solid fa-users"></i><span>Personal</span></a>
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/control_personal/horarios.php"><i class="fa-solid fa-clock"></i><span>Horarios</span></a>
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/control_personal/puntos_marcacion.php"><i class="fa-solid fa-location-dot"></i><span>Puntos de marcación</span></a>
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/control_personal/asignaciones.php"><i class="fa-solid fa-user-check"></i><span>Asignaciones</span></a>
                <?php endif; ?>
                <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/control_personal/control_asistencia.php"><i class="fa-solid fa-camera"></i><span>Control de asistencia</span></a>
                <?php if ($isAdmin): ?>
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/control_personal/reportes.php"><i class="fa-solid fa-file-export"></i><span>Reportes</span></a>
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/aliados/control_asistencia.php"><i class="fa-solid fa-calendar-check"></i><span>Control de asistencia_old</span></a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isAdmin): ?>
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

            <button class="nav-link nav-parent <?= $empresaOpen ? 'active' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#empresaMenu" aria-expanded="<?= $empresaOpen ? 'true' : 'false' ?>" aria-controls="empresaMenu">
                <i class="fa-solid fa-building"></i><span>Empresa</span><i class="fa-solid fa-chevron-down nav-caret"></i>
            </button>
            <div class="collapse <?= $empresaOpen ? 'show' : '' ?>" id="empresaMenu">
                <div class="submenu">
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/empresa/datos_generales.php"><i class="fa-solid fa-address-card"></i><span>Datos generales</span></a>
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/empresa/documentos.php"><i class="fa-solid fa-file-lines"></i><span>Documentos</span></a>
                </div>
            </div>

            <a class="nav-link <?= $usuarioOpen ? 'active' : '' ?>" href="<?= APP_URL ?>/modulos/usuario/usuarios.php"><i class="fa-solid fa-users-gear"></i><span>Usuarios</span></a>
        <?php endif; ?>

        <a class="nav-link sidebar-logout" href="<?= APP_URL ?>/salir.php"><i class="fa-solid fa-right-from-bracket"></i><span>Cerrar sesión</span></a>
    </nav>
    <div class="sidebar-footer">
        <strong>© <?= date('Y') ?> Life Maquinarias</strong>
        <span>Todos los derechos reservados.</span>
    </div>
</aside>
