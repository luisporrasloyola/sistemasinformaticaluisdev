<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_projects.php';
require_module_access('control_personal.control_asistencia');
ensure_quick_attendance_marking_schema();

$isAdmin = is_admin();
$currentWorkerId = current_user_worker_id();
$requestedWorkerId = $isAdmin ? (int) ($_GET['worker_id'] ?? ($currentWorkerId ?: 0)) : 0;
$workers = [];
$markingLocations = db()->query("SELECT id, name, latitude, longitude, radius_meters FROM attendance_locations WHERE status=1 ORDER BY name")->fetchAll();
$markingSchedules = db()->query("SELECT id, name FROM attendance_schedules WHERE status=1 ORDER BY name")->fetchAll();
$markingProjects = db()->query("SELECT id, name FROM attendance_projects WHERE status=1 ORDER BY name")->fetchAll();

if ($isAdmin) {
    $workers = db()->query("SELECT w.id, w.full_name, w.document_number, c.name AS company
        FROM workers w
        LEFT JOIN companies c ON c.id = w.company_id
        ORDER BY w.full_name")->fetchAll();
}

require __DIR__ . '/../../includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<div class="page-title">
    <div>
        <h1>Control de asistencia</h1>
        <p>Marcación mediante GPS, cámara y validación de horario.</p>
    </div>
    <button class="btn btn-outline-primary" type="button" id="viewMyScheduleBtn"><i class="fa-solid fa-calendar-days me-2"></i>Mi programación</button>
</div>

<div class="row g-3 attendance-marking-layout">
    <div class="col-xl-4">
        <div class="work-panel h-100 attendance-marking-panel">
            <h2>Marcación</h2>
            <?php if ($isAdmin): ?>
                <label class="form-label">Trabajador</label>
                <div class="mb-3">
                    <select class="form-select" id="markWorkerId" data-placeholder="Buscar trabajador">
                        <option value=""></option>
                        <?php foreach ($workers as $worker): ?>
                            <option value="<?= (int) $worker['id'] ?>" <?= $requestedWorkerId === (int) $worker['id'] ? 'selected' : '' ?>><?= e($worker['full_name'] . ' - ' . $worker['document_number'] . (!empty($worker['company']) ? ' - ' . $worker['company'] : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" id="markWorkerId" value="<?= (int) $currentWorkerId ?>">
            <?php endif; ?>

            <div class="attendance-quick-selection">
                <div class="mb-3"><label class="form-label" for="markScheduleSelect">Horario</label><select class="form-select marking-selector" id="markScheduleSelect" data-placeholder="Seleccione un horario"><option value=""></option><?php foreach ($markingSchedules as $schedule): ?><option value="<?= (int)$schedule['id'] ?>"><?= e($schedule['name']) ?></option><?php endforeach; ?></select></div>
                <div class="mb-3"><label class="form-label" for="markLocationSelect">Lugar de marcación</label><select class="form-select marking-selector" id="markLocationSelect" data-placeholder="Seleccione un lugar"><option value=""></option><?php foreach ($markingLocations as $location): ?><option value="<?= (int)$location['id'] ?>" data-latitude="<?= e((string)$location['latitude']) ?>" data-longitude="<?= e((string)$location['longitude']) ?>" data-radius="<?= (int)$location['radius_meters'] ?>"><?= e($location['name']) ?></option><?php endforeach; ?></select></div>
                <div class="mb-3"><label class="form-label" for="markProjectSelect">Proyecto</label><select class="form-select marking-selector" id="markProjectSelect" data-placeholder="Seleccione un proyecto"><option value=""></option><?php foreach ($markingProjects as $project): ?><option value="<?= (int)$project['id'] ?>"><?= e($project['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="attendance-status-stack mb-3" id="markStatusPanel"><span class="badge text-bg-secondary">Cargando jornada</span></div>

            <div class="d-grid gap-3 attendance-primary-mark-action">
                <button class="btn btn-success" type="button" id="markEntryBtn" disabled><i class="fa-solid fa-circle-check me-2"></i>Registrar marcación</button>
                <button class="btn btn-outline-primary d-none" type="button" id="markChangeSelectionBtn"><i class="fa-solid fa-pen-to-square me-2"></i>Cambiar lugar / proyecto</button>
                <button class="btn btn-primary" type="button" id="markExitBtn" disabled><i class="fa-solid fa-right-from-bracket me-2"></i>Marcar salida</button>
            </div>
            <div class="form-text mt-3" id="markPermissionHelp">Seleccione lugar, horario y proyecto para registrar su asistencia.</div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="work-panel mb-3 attendance-capture-panel">
            <div class="row g-3">
                <div class="col-lg-5">
                    <h2>Vista de cámara</h2>
                    <div class="camera-box">
                        <video id="markCamera" autoplay playsinline muted></video>
                        <canvas id="markCanvas" class="d-none"></canvas>
                        <div class="attendance-media-empty" id="markCameraEmpty">
                            <i class="fa-solid fa-camera"></i>
                            <strong>Cámara no disponible</strong>
                            <span>Se habilitará al registrar una marcación.</span>
                        </div>
                    </div>
                    <img class="mark-photo-preview d-none mt-2" id="markPhotoPreview" alt="Foto capturada">
                </div>
                <div class="col-lg-7">
                    <h2>Mapa</h2>
                    <div class="attendance-map-wrap">
                        <div class="attendance-map" id="markMap"></div>
                        <div class="attendance-media-empty attendance-map-empty" id="markMapEmpty">
                            <i class="fa-solid fa-location-dot"></i>
                            <strong>Mapa no disponible</strong>
                            <span>Se mostrará el lugar de marcación seleccionado.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="work-panel">
            <div class="mb-3">
                <h2 class="mb-1">Actividad reciente</h2>
                <p class="text-muted small mb-0">Consulta las marcaciones y los desplazamientos laborales del trabajador.</p>
            </div>
            <ul class="nav nav-tabs" id="attendanceActivityTabs" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active" id="recent-marks-tab" data-bs-toggle="tab" data-bs-target="#recent-marks-pane" type="button" role="tab"><i class="fa-solid fa-clock me-2"></i>Marcaciones recientes</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" id="recent-trips-tab" data-bs-toggle="tab" data-bs-target="#recent-trips-pane" type="button" role="tab"><i class="fa-solid fa-route me-2"></i>Desplazamientos laborales</button></li>
            </ul>
            <div class="tab-content pt-3">
            <div class="tab-pane fade show active" id="recent-marks-pane" role="tabpanel" aria-labelledby="recent-marks-tab">
            <div class="table-responsive">
                <table class="table table-hover align-middle attendance-recent-table">
                    <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Tipo</th>
                        <th>Trabajador</th>
                        <th>Lugar</th>
                        <th>Proyecto</th>
                        <th>Estado</th>
                        <th>Foto</th>
                    </tr>
                    </thead>
                    <tbody id="recentAttendanceMarks">
                        <tr><td colspan="8" class="text-muted text-center py-4">Seleccione un trabajador para consultar sus registros recientes.</td></tr>
                    </tbody>
                </table>
                <div class="attendance-table-pagination" id="recentMarksPagination"></div>
            </div></div>
            <div class="tab-pane fade" id="recent-trips-pane" role="tabpanel" aria-labelledby="recent-trips-tab">
                <div class="table-responsive">
                    <table class="table table-hover align-middle attendance-recent-table attendance-trips-table">
                        <thead><tr><th>Fecha</th><th>Inicio</th><th>Fin</th><th>Duración</th><th>Origen</th><th>Destino</th><th>Proyecto</th><th>Estado</th><th>Foto</th></tr></thead>
                        <tbody id="recentAttendanceTrips"><tr><td colspan="9" class="text-muted text-center py-4">Seleccione un trabajador para consultar sus desplazamientos.</td></tr></tbody>
                    </table>
                    <div class="attendance-table-pagination" id="recentTripsPagination"></div>
                </div>
            </div></div>
        </div>
    </div>
</div>

<div class="modal fade" id="myScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <div><h5 class="modal-title mb-0">Mi programación</h5><small>Calendario de jornadas, horarios, lugares y proyectos.</small></div>
            <button class="btn-close btn-close-white" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body" id="myScheduleContent">
            <div class="text-center text-muted py-5" id="myScheduleLoading"><i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando programación...</div>
            <div class="d-none flex-wrap gap-2 mb-3" id="myScheduleLegend">
                <span class="badge" style="background:#16a34a">Horario habitual</span>
                <span class="badge" style="background:#f97316">Programación especial</span>
                <span class="badge" style="background:#2563eb">Calendario laboral</span>
            </div>
            <div class="d-none" id="myScheduleCalendar"></div>
            <div class="alert alert-light border d-none mt-3 mb-0" id="myScheduleDetail"></div>
        </div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>
<style>
#myScheduleCalendar .fc-toolbar-title{font-size:1.15rem}#myScheduleCalendar .fc-event{cursor:pointer;border-radius:5px;padding:2px 4px}#myScheduleCalendar .fc-day-today{background:#eff6ff!important}
@media(max-width:767.98px){#myScheduleCalendar .fc-header-toolbar{align-items:stretch;gap:.5rem;flex-direction:column}#myScheduleCalendar .fc-toolbar-chunk{display:flex;justify-content:center}}
</style>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.15/locales-all.global.min.js"></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
