<?php
$currentPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$dashboardActive = str_contains($currentPath, '/panel.php') || str_ends_with($currentPath, '/index.php');
$controlPersonalOpen = str_contains($currentPath, '/modulos/aliados/') || str_contains($currentPath, '/modulos/control_personal/');
$requisitosOpen = str_contains($currentPath, '/modulos/requisitos/');
$maquinariaOpen = str_contains($currentPath, '/modulos/maquinaria/');
$empresaOpen = str_contains($currentPath, '/modulos/empresa/');
$usuarioOpen = str_contains($currentPath, '/modulos/usuario/');
$configuracionOpen = str_contains($currentPath, '/modulos/configuracion/');
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

        <?php if ($isAdmin || is_personal_role()): ?>
        <button class="nav-link nav-parent <?= $controlPersonalOpen ? 'active' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#controlPersonalMenu" aria-expanded="<?= $controlPersonalOpen ? 'true' : 'false' ?>" aria-controls="controlPersonalMenu">
            <i class="fa-solid fa-people-group"></i><span>Control de personal</span><i class="fa-solid fa-chevron-down nav-caret"></i>
        </button>
        <div class="collapse <?= $controlPersonalOpen ? 'show' : '' ?>" id="controlPersonalMenu">
            <div class="submenu">
                <?php if ($isAdmin): ?>
                    <a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/dashboard_asistencia.php"><i class="fa-solid fa-chart-line"></i><span>Dashboard de asistencia</span></a>
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/aliados/personal.php"><i class="fa-solid fa-users"></i><span>Personal</span></a>
                    <a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/horarios.php"><i class="fa-solid fa-clock"></i><span>Horarios</span></a>
                    <a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/calendario_laboral.php"><i class="fa-solid fa-calendar-days"></i><span>Calendario laboral</span></a>
                    <a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/puntos_marcacion.php"><i class="fa-solid fa-location-dot"></i><span>Lugares de marcación</span></a>
                    <a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/asignaciones.php"><i class="fa-solid fa-user-check"></i><span>Asignaciones</span></a>
                <?php endif; ?>
                <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/control_personal/control_asistencia.php"><i class="fa-solid fa-camera"></i><span>Control de asistencia</span></a>
                <?php if ($isAdmin): ?>
                    <a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/reportes.php"><i class="fa-solid fa-file-export"></i><span>Reporte de marcaciones</span></a>
                    <a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/reporte_asistencias.php"><i class="fa-solid fa-clipboard-check"></i><span>Reporte de asistencias</span></a>
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/aliados/control_asistencia.php"><i class="fa-solid fa-calendar-check"></i><span>Control de asistencia_old</span></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

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
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/empresa/seguridad.php"><i class="fa-solid fa-shield-halved"></i><span>Seguridad</span></a>
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/empresa/calidad.php"><i class="fa-solid fa-award"></i><span>Calidad</span></a>
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/empresa/medio_ambiente.php"><i class="fa-solid fa-leaf"></i><span>Medio ambiente</span></a>
                </div>
            </div>

            <a class="nav-link <?= $usuarioOpen ? 'active' : '' ?>" href="<?= APP_URL ?>/modulos/usuario/usuarios.php"><i class="fa-solid fa-users-gear"></i><span>Usuarios</span></a>
            <button class="nav-link nav-parent <?= $configuracionOpen ? 'active' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#configuracionMenu" aria-expanded="<?= $configuracionOpen ? 'true' : 'false' ?>" aria-controls="configuracionMenu">
                <i class="fa-solid fa-gear"></i><span>Configuración</span><i class="fa-solid fa-chevron-down nav-caret"></i>
            </button>
            <div class="collapse <?= $configuracionOpen ? 'show' : '' ?>" id="configuracionMenu">
                <div class="submenu">
                    <a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/configuracion/alertas_estado.php"><i class="fa-solid fa-triangle-exclamation"></i><span>Alertas del estado</span></a>
                </div>
            </div>
        <?php endif; ?>

        <?php if (is_gestor_role()): ?>
            <?php if (current_user_can_module('dashboard')): ?>
                <a class="nav-link <?= $dashboardActive ? 'active' : '' ?>" href="<?= APP_URL ?>/panel.php"><i class="fa-solid fa-house"></i><span>Dashboard</span></a>
            <?php endif; ?>
            <?php if (current_user_can_module('control_personal')): ?>
                <button class="nav-link nav-parent <?= $controlPersonalOpen ? 'active' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#controlPersonalMenuGestor" aria-expanded="<?= $controlPersonalOpen ? 'true' : 'false' ?>" aria-controls="controlPersonalMenuGestor"><i class="fa-solid fa-people-group"></i><span>Control de personal</span><i class="fa-solid fa-chevron-down nav-caret"></i></button>
                <div class="collapse <?= $controlPersonalOpen ? 'show' : '' ?>" id="controlPersonalMenuGestor"><div class="submenu">
                    <?php if (current_user_can_module('control_personal.dashboard')): ?><a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/dashboard_asistencia.php"><i class="fa-solid fa-chart-line"></i><span>Dashboard de asistencia</span></a><?php endif; ?>
                    <?php if (current_user_can_module('control_personal.personal')): ?><a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/aliados/personal.php"><i class="fa-solid fa-users"></i><span>Personal</span></a><?php endif; ?>
                    <?php if (current_user_can_module('control_personal.horarios')): ?><a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/horarios.php"><i class="fa-solid fa-clock"></i><span>Horarios</span></a><?php endif; ?>
                    <?php if (current_user_can_module('control_personal.calendario_laboral')): ?><a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/calendario_laboral.php"><i class="fa-solid fa-calendar-days"></i><span>Calendario laboral</span></a><?php endif; ?>
                    <?php if (current_user_can_module('control_personal.puntos_marcacion')): ?><a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/puntos_marcacion.php"><i class="fa-solid fa-location-dot"></i><span>Puntos de marcacion</span></a><?php endif; ?>
                    <?php if (current_user_can_module('control_personal.asignaciones')): ?><a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/asignaciones.php"><i class="fa-solid fa-user-check"></i><span>Asignaciones</span></a><?php endif; ?>
                    <?php if (current_user_can_module('control_personal.control_asistencia')): ?><a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/control_personal/control_asistencia.php"><i class="fa-solid fa-camera"></i><span>Control de asistencia</span></a><?php endif; ?>
                    <?php if (current_user_can_module('control_personal.reportes')): ?><a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/reportes.php"><i class="fa-solid fa-file-export"></i><span>Reporte de marcaciones</span></a><?php endif; ?>
                    <?php if (current_user_can_module('control_personal.reporte_asistencias')): ?><a class="nav-link sub-link js-development-link" href="<?= APP_URL ?>/modulos/control_personal/reporte_asistencias.php"><i class="fa-solid fa-clipboard-check"></i><span>Reporte de asistencias</span></a><?php endif; ?>
                </div></div>
            <?php endif; ?>
            <?php if (current_user_can_module('requisitos')): ?>
                <button class="nav-link nav-parent <?= $requisitosOpen ? 'active' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#requisitosMenuGestor" aria-expanded="<?= $requisitosOpen ? 'true' : 'false' ?>" aria-controls="requisitosMenuGestor"><i class="fa-solid fa-folder-open"></i><span>Requisitos</span><i class="fa-solid fa-chevron-down nav-caret"></i></button>
                <div class="collapse <?= $requisitosOpen ? 'show' : '' ?>" id="requisitosMenuGestor"><div class="submenu">
                    <?php if (current_user_can_module('requisitos.pmi_individual')): ?><a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/requisitos/pmi_individual.php"><i class="fa-solid fa-file-shield"></i><span>PMI Individual</span></a><?php endif; ?>
                    <?php if (current_user_can_module('requisitos.pmi_masivo')): ?><a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/requisitos/pmi_masivo.php"><i class="fa-solid fa-file-import"></i><span>Requisito PMI Masivo</span></a><?php endif; ?>
                </div></div>
            <?php endif; ?>
            <?php if (current_user_can_module('maquinaria')): ?>
                <button class="nav-link nav-parent <?= $maquinariaOpen ? 'active' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#maquinariaMenuGestor" aria-expanded="<?= $maquinariaOpen ? 'true' : 'false' ?>" aria-controls="maquinariaMenuGestor"><i class="fa-solid fa-truck-pickup"></i><span>Maquinaria</span><i class="fa-solid fa-chevron-down nav-caret"></i></button>
                <div class="collapse <?= $maquinariaOpen ? 'show' : '' ?>" id="maquinariaMenuGestor"><div class="submenu">
                    <?php if (current_user_can_module('maquinaria.datos_generales')): ?><a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/maquinaria/datos_generales.php"><i class="fa-solid fa-clipboard-list"></i><span>Datos generales</span></a><?php endif; ?>
                    <?php if (current_user_can_module('maquinaria.documentos')): ?><a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/maquinaria/documentos.php"><i class="fa-solid fa-file-lines"></i><span>Documentos</span></a><?php endif; ?>
                </div></div>
            <?php endif; ?>
            <?php if (current_user_can_module('empresa')): ?>
                <button class="nav-link nav-parent <?= $empresaOpen ? 'active' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#empresaMenuGestor" aria-expanded="<?= $empresaOpen ? 'true' : 'false' ?>" aria-controls="empresaMenuGestor"><i class="fa-solid fa-building"></i><span>Empresa</span><i class="fa-solid fa-chevron-down nav-caret"></i></button>
                <div class="collapse <?= $empresaOpen ? 'show' : '' ?>" id="empresaMenuGestor"><div class="submenu">
                    <?php if (current_user_can_module('empresa.datos_generales')): ?><a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/empresa/datos_generales.php"><i class="fa-solid fa-address-card"></i><span>Datos generales</span></a><?php endif; ?>
                    <?php if (current_user_can_module('empresa.documentos')): ?><a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/empresa/documentos.php"><i class="fa-solid fa-file-lines"></i><span>Documentos</span></a><?php endif; ?>
                    <?php if (current_user_can_module('empresa.seguridad')): ?><a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/empresa/seguridad.php"><i class="fa-solid fa-shield-halved"></i><span>Seguridad</span></a><?php endif; ?>
                    <?php if (current_user_can_module('empresa.calidad')): ?><a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/empresa/calidad.php"><i class="fa-solid fa-award"></i><span>Calidad</span></a><?php endif; ?>
                    <?php if (current_user_can_module('empresa.medio_ambiente')): ?><a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/empresa/medio_ambiente.php"><i class="fa-solid fa-leaf"></i><span>Medio ambiente</span></a><?php endif; ?>
                </div></div>
            <?php endif; ?>
            <?php if (current_user_can_module('usuarios')): ?>
                <a class="nav-link <?= $usuarioOpen ? 'active' : '' ?>" href="<?= APP_URL ?>/modulos/usuario/usuarios.php"><i class="fa-solid fa-users-gear"></i><span>Usuarios</span></a>
            <?php endif; ?>
            <?php if (current_user_can_module('configuracion')): ?>
                <button class="nav-link nav-parent <?= $configuracionOpen ? 'active' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#configuracionMenuGestor" aria-expanded="<?= $configuracionOpen ? 'true' : 'false' ?>" aria-controls="configuracionMenuGestor"><i class="fa-solid fa-gear"></i><span>Configuración</span><i class="fa-solid fa-chevron-down nav-caret"></i></button>
                <div class="collapse <?= $configuracionOpen ? 'show' : '' ?>" id="configuracionMenuGestor"><div class="submenu">
                    <?php if (current_user_can_module('configuracion.alertas_estado')): ?><a class="nav-link sub-link" href="<?= APP_URL ?>/modulos/configuracion/alertas_estado.php"><i class="fa-solid fa-triangle-exclamation"></i><span>Alertas del estado</span></a><?php endif; ?>
                </div></div>
            <?php endif; ?>
        <?php endif; ?>

        <a class="nav-link sidebar-logout" href="<?= APP_URL ?>/salir.php"><i class="fa-solid fa-right-from-bracket"></i><span>Cerrar sesión</span></a>
    </nav>
    <div class="sidebar-footer">
        <strong>© <?= date('Y') ?> Life Maquinarias</strong>
        <span>Todos los derechos reservados.</span>
    </div>
</aside>
