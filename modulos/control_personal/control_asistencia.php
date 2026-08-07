<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.control_asistencia');

$isAdmin = is_admin();
$currentWorkerId = current_user_worker_id();
$requestedWorkerId = $isAdmin ? (int) ($_GET['worker_id'] ?? 0) : 0;
$workers = [];
$markingLocations = db()->query("SELECT id, name FROM attendance_locations WHERE status=1 ORDER BY name")->fetchAll();

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

            <div class="mb-3 d-none" id="markProgramField">
                <label class="form-label" for="markProgramId">Jornada programada para hoy</label>
                <select class="form-select" id="markProgramId"></select>
                <small class="text-muted">Selecciona la jornada que deseas registrar.</small>
            </div>

            <div class="attendance-status-stack mb-3" id="markStatusPanel">
                <span class="badge text-bg-secondary">Seleccione trabajador / cargando asignación</span>
            </div>

            <div class="attendance-availability-notice d-none" id="markAvailabilityNotice" role="status">
                <i class="fa-regular fa-clock"></i>
                <div><strong>Marcación de entrada aún no disponible</strong><span id="markAvailabilityText"></span></div>
            </div>

            <div class="alert alert-info d-none" id="activeTripPanel" role="status">
                <strong><i class="fa-solid fa-route me-2"></i>Desplazamiento en curso</strong>
                <div class="small mt-1" id="activeTripText"></div>
            </div>

            <div class="attendance-no-assignment d-none" id="markEmptyState" role="status">
                <span class="attendance-no-assignment-icon"><i class="fa-solid fa-user-clock"></i></span>
                <div>
                    <strong id="markEmptyStateTitle">Sin asignación activa</strong>
                    <p id="markEmptyStateText">No tienes un horario ni un lugar de marcación asignados. Comunícate con el administrador para poder registrar tu asistencia.</p>
                </div>
            </div>

            <dl class="info-list" id="markAssignmentDetails">
                <dt>Trabajador</dt><dd id="markWorkerName">-</dd>
                <dt>Lugar de entrada</dt><dd id="markLocationName">-</dd>
                <dt id="markExitLocationLabel">Lugar de salida</dt><dd id="markExitLocationName">-</dd>
                <dt>Horario</dt><dd id="markScheduleName">-</dd>
                <dt>Actividad</dt><dd id="markActivity">-</dd>
                <dt>Fecha</dt><dd id="markWorkDate">-</dd>
                <dt>Hora de entrada</dt>
                <dd class="attendance-time-value">
                    <span class="attendance-time-main">
                        <span id="markEntryOfficial">-</span>
                        <span class="attendance-time-separator">|</span>
                        <span>Ventana: <span id="markEntryWindow">-</span></span>
                    </span>
                    <small class="d-block text-muted" id="markEntryTolerance"></small>
                </dd>
                <dt>Hora de salida</dt>
                <dd class="attendance-time-value">
                    <span class="attendance-time-main">
                        <span id="markExitOfficial">-</span>
                        <span class="attendance-time-separator">|</span>
                        <span>Salida válida desde: <span id="markExitWindow">-</span></span>
                    </span>
                </dd>
                <dt>Radio permitido</dt><dd id="markRadius">-</dd>
            </dl>

            <label class="form-label">Observaciones</label>
            <textarea class="form-control mb-3" id="markObservations" rows="3" disabled></textarea>

            <div class="d-grid gap-2 mb-3">
                <button class="btn btn-success" type="button" id="markEntryBtn" disabled><i class="fa-solid fa-right-to-bracket me-2"></i>Marcar entrada</button>
            </div>

            <section class="attendance-current-work d-none" id="currentWorkActionPanel">
                <div class="attendance-current-work-head"><span class="attendance-current-work-icon"><i class="fa-solid fa-helmet-safety"></i></span><div><strong>Trabajo actual</strong><small>Finaliza únicamente la actividad de este lugar.</small></div></div>
                <div class="attendance-current-work-summary"><span><small>Lugar</small><b id="currentWorkLocation">-</b></span><span><small>Actividad</small><b id="currentWorkActivity">-</b></span></div>
                <button class="btn attendance-finish-work-btn" type="button" id="finishLocationWorkBtn"><i class="fa-solid fa-circle-check"></i><span>Finalizar trabajo en este lugar</span></button>
                <p><i class="fa-solid fa-circle-info"></i> Tu jornada laboral continuará activa y el administrador recibirá una alerta.</p>
            </section>

            <section class="attendance-mobility-actions d-none" id="mobilityActionPanel">
                <div class="d-grid gap-2">
                <button class="btn btn-warning d-none" type="button" id="startTripBtn"><i class="fa-solid fa-route me-2"></i>Iniciar desplazamiento</button>
                <button class="btn btn-outline-primary d-none" type="button" id="addTripStopBtn"><i class="fa-solid fa-location-dot me-2"></i>Registrar visita</button>
                <button class="btn btn-outline-success d-none" type="button" id="finishTripBtn"><i class="fa-solid fa-flag-checkered me-2"></i>Confirmar llegada</button>
                <button class="btn attendance-return-work-btn d-none" type="button" id="returnWithoutArrivalBtn"><span class="attendance-return-work-icon"><i class="fa-solid fa-house-circle-check"></i></span><span><strong>Regresé a mi lugar de trabajo</strong><small>Confirmar regreso mediante GPS</small></span><i class="fa-solid fa-chevron-right ms-auto"></i></button>
                <button class="btn btn-primary" type="button" id="markExitBtn" disabled><i class="fa-solid fa-right-from-bracket me-2"></i>Marcar salida</button>
                </div>
            </section>
            <div class="form-text mt-2" id="markPermissionHelp">Seleccione un trabajador con asignación activa para marcar.</div>
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
                            <span>Se habilitará cuando exista una asignación activa.</span>
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
                            <span>Se mostrará el lugar de marcación asignado.</span>
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
                        <th>Distancia</th>
                        <th>Estado</th>
                        <th>Foto</th>
                    </tr>
                    </thead>
                    <tbody id="recentAttendanceMarks">
                        <tr><td colspan="8" class="text-muted text-center py-4">Seleccione un trabajador para consultar sus registros recientes.</td></tr>
                    </tbody>
                </table>
            </div></div>
            <div class="tab-pane fade" id="recent-trips-pane" role="tabpanel" aria-labelledby="recent-trips-tab">
                <div class="table-responsive">
                    <table class="table table-hover align-middle attendance-recent-table attendance-trips-table">
                        <thead><tr><th>Fecha</th><th>Inicio</th><th>Fin</th><th>Duración</th><th>Origen</th><th>Destino</th><th>Motivo</th><th>Estado</th></tr></thead>
                        <tbody id="recentAttendanceTrips"><tr><td colspan="8" class="text-muted text-center py-4">Seleccione un trabajador para consultar sus desplazamientos.</td></tr></tbody>
                    </table>
                </div>
            </div></div>
        </div>
    </div>
</div>

<div class="modal fade" id="myScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header bg-primary text-white"><div><h5 class="modal-title">Mi programación</h5><small>Calendario de jornadas, horarios, lugares e indicaciones.</small></div><button class="btn-close btn-close-white" type="button" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="myScheduleContent">
            <div class="text-center text-muted py-5" id="myScheduleLoading"><i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando programación...</div>
            <div class="d-none flex-wrap gap-2 mb-3" id="myScheduleLegend">
                <span class="badge" style="background:#16a34a">Horario habitual</span>
                <span class="badge" style="background:#f97316">Programado</span>
                <span class="badge" style="background:#0f766e"><i class="fa-solid fa-route me-1"></i>Recorrido de trabajo</span>
                <span class="badge" style="background:#2563eb">VAC Vacaciones</span>
                <span class="badge" style="background:#7c3aed">PER Permiso</span>
                <span class="badge" style="background:#334155">D Descanso</span>
                <span class="badge" style="background:#0891b2">FER Feriado</span>
                <span class="badge" style="background:#78716c">NL No laborable</span>
            </div>
            <div class="d-none" id="myScheduleCalendar"></div>
            <div class="d-none mt-3 my-schedule-detail" id="myScheduleDetail"></div>
        </div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>

<style>
#myScheduleCalendar .fc-toolbar-title{font-size:1.15rem}#myScheduleCalendar .fc-event{cursor:pointer;border-radius:5px;padding:2px 4px}#myScheduleCalendar .fc-day-today{background:#eff6ff!important}
.my-schedule-detail{overflow:hidden;border:1px solid #dbe4f0;border-radius:16px;background:#f8fafc;box-shadow:0 10px 28px rgba(15,23,42,.08)}
.my-schedule-detail>.d-flex:first-child{margin:0!important;padding:18px 20px;background:linear-gradient(135deg,#eff6ff 0%,#f8fafc 70%);border-bottom:1px solid #dbeafe}.my-schedule-detail>.row{margin:0;padding:18px 20px 20px}
.my-schedule-detail-header{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;padding:18px 20px;background:linear-gradient(135deg,#eff6ff 0%,#f8fafc 70%);border-bottom:1px solid #dbeafe}
.my-schedule-detail-date{color:#2563eb;font-size:.82rem;font-weight:800;text-transform:capitalize}.my-schedule-detail-title{margin:3px 0 0;color:#0f172a;font-size:1.2rem;font-weight:800}
.my-schedule-detail-state{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border-radius:999px;color:#fff;font-size:.78rem;font-weight:800;box-shadow:0 5px 12px rgba(15,23,42,.12)}
.my-schedule-detail-body{padding:18px 20px 20px}.my-schedule-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.my-schedule-detail-item{min-height:78px;padding:12px 14px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}.my-schedule-detail-item.wide{grid-column:span 2}
.my-schedule-detail-label{display:block;margin-bottom:5px;color:#64748b;font-size:.72rem;font-weight:800;letter-spacing:.035em;text-transform:uppercase}
.my-schedule-detail-value{display:flex;align-items:flex-start;gap:8px;color:#0f172a;font-size:.92rem;font-weight:700;line-height:1.4}.my-schedule-detail-value i{margin-top:3px;color:#2563eb}
.my-schedule-indications{margin-top:14px;padding:15px 16px;border:1px solid #fed7aa;border-left:4px solid #f97316;border-radius:12px;background:#fff7ed}
.my-schedule-indications-title{display:flex;align-items:center;gap:8px;margin-bottom:10px;color:#9a3412;font-size:.78rem;font-weight:800;text-transform:uppercase}
.my-schedule-indications-list{display:grid;gap:8px;margin:0;padding:0;list-style:none}.my-schedule-indications-list li{display:flex;align-items:flex-start;gap:9px;color:#431407;font-weight:600;line-height:1.4}
.my-schedule-indications-number{display:inline-flex;flex:0 0 24px;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#f97316;color:#fff;font-size:.72rem;font-weight:800}
.my-schedule-route{margin-top:14px;padding:16px;border:1px solid #99f6e4;border-radius:14px;background:#f0fdfa}.my-schedule-route-title{display:flex;align-items:center;gap:8px;margin-bottom:13px;color:#0f766e;font-size:.8rem;font-weight:800;text-transform:uppercase}.my-schedule-route-list{display:grid;gap:0;margin:0;padding:0;list-style:none}.my-schedule-route-stop{position:relative;display:grid;grid-template-columns:30px minmax(0,1fr);gap:10px;padding-bottom:14px}.my-schedule-route-stop:last-child{padding-bottom:0}.my-schedule-route-stop:not(:last-child)::after{content:"";position:absolute;left:14px;top:28px;bottom:0;width:2px;background:#99f6e4}.my-schedule-route-number{position:relative;z-index:1;display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#0f766e;color:#fff;font-size:.75rem;font-weight:800}.my-schedule-route-stop:first-child .my-schedule-route-number{background:#16a34a}.my-schedule-route-place{color:#0f172a;font-size:.92rem;font-weight:800}.my-schedule-route-meta{display:flex;flex-wrap:wrap;gap:6px 12px;margin-top:3px;color:#64748b;font-size:.78rem}.my-schedule-route-meta span{display:inline-flex;align-items:center;gap:5px}
@media(max-width:767.98px){#myScheduleCalendar .fc-header-toolbar{align-items:stretch;gap:.5rem;flex-direction:column}#myScheduleCalendar .fc-toolbar-chunk{display:flex;justify-content:center}}
@media(max-width:767.98px){.my-schedule-detail-grid{grid-template-columns:1fr}.my-schedule-detail-item.wide{grid-column:auto}.my-schedule-detail-header,.my-schedule-detail-body{padding:15px}}
</style>

<div class="modal fade" id="finishLocationWorkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="finishLocationWorkForm">
        <div class="modal-header bg-warning-subtle"><div><h5 class="modal-title">Finalizar trabajo en este lugar</h5><small>Esta acción no registra la salida ni termina la jornada laboral.</small></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="alert alert-primary py-2"><i class="fa-solid fa-circle-info me-2"></i>Al confirmar, el administrador sabrá que estás disponible para recibir un nuevo destino.</div>
            <div class="mb-3"><label class="form-label">Lugar actual</label><input class="form-control bg-light fw-semibold" id="finishWorkLocation" readonly></div>
            <div class="mb-3"><label class="form-label">Trabajo realizado</label><input class="form-control" id="finishWorkActivity" name="activity" maxlength="255" required placeholder="Ej.: Trabajos de izaje"></div>
            <div><label class="form-label">Observación <span class="text-muted fw-normal">(opcional)</span></label><textarea class="form-control" name="observations" rows="3" maxlength="500" placeholder="Detalle breve del trabajo terminado"></textarea></div>
        </div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-warning" type="submit"><i class="fa-solid fa-check me-2"></i>Confirmar trabajo finalizado</button></div>
    </form></div>
</div>

<div class="modal fade" id="tripModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="tripForm">
        <div class="modal-header bg-warning-subtle"><div><h5 class="modal-title" id="tripModalTitle">Iniciar desplazamiento</h5><small id="tripModalDescription">Esta acción no finaliza tu jornada laboral.</small></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <input type="hidden" name="action" id="tripAction" value="iniciar">
            <div class="mb-3" id="tripOriginField">
                <label class="form-label" for="tripOrigin">Origen</label>
                <div class="input-group">
                    <span class="input-group-text bg-primary-subtle text-primary"><i class="fa-solid fa-location-dot"></i></span>
                    <input class="form-control bg-light fw-semibold" id="tripOrigin" type="text" value="-" readonly aria-describedby="tripOriginHelp">
                </div>
                <div class="form-text" id="tripOriginHelp">Lugar de marcación asignado para esta jornada.</div>
            </div>
            <div class="mb-3 d-none" id="tripMainDestinationField"><label class="form-label" for="tripMainDestination">Destino principal</label><div class="input-group"><span class="input-group-text bg-warning-subtle text-warning-emphasis"><i class="fa-solid fa-flag-checkered"></i></span><input class="form-control bg-light fw-semibold" id="tripMainDestination" type="text" value="-" readonly></div><div class="form-text">Destino indicado al iniciar este desplazamiento.</div></div>
            <div class="mb-3" id="tripRegisteredDestinationField"><label class="form-label" id="tripDestinationLabel">Destino</label><select class="form-select" name="destination_location_id" id="tripDestination" required><option value="">Seleccione un lugar</option><?php foreach ($markingLocations as $location): ?><option value="<?= (int) $location['id'] ?>"><?= e($location['name']) ?></option><?php endforeach; ?></select><div class="form-text">La ubicación se validará con el radio configurado del lugar.</div></div>
            <div class="mb-3 d-none" id="tripFreeDestinationField"><label class="form-label" for="tripDestinationText">Destino</label><input class="form-control" type="text" name="destination" id="tripDestinationText" maxlength="180" placeholder="Ej.: Municipalidad de Lima"><div class="form-text">Puede escribir cualquier destino ocasional. No necesita registrarlo previamente como lugar de marcación.</div></div>
            <div class="mb-3" id="tripReasonField"><label class="form-label">Motivo del desplazamiento</label><textarea class="form-control" name="reason" rows="2" maxlength="255" placeholder="Ej.: Entrega de documentación"></textarea></div>
            <div class="mb-0 d-none" id="tripActivityField"><label class="form-label">Actividad realizada</label><textarea class="form-control" name="activity" rows="2" maxlength="255" placeholder="Ej.: Entrega de documentos y recepción del cargo"></textarea><div class="form-text">Describa brevemente la gestión realizada en este punto.</div></div>
            <div class="alert alert-light border small mt-3 mb-0"><i class="fa-solid fa-location-crosshairs me-2 text-primary"></i>Se registrará tu ubicación actual como evidencia del recorrido.</div>
        </div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="fa-solid fa-check me-2"></i>Registrar</button></div>
    </form></div>
</div>

<div class="modal fade" id="attendancePhotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="attendancePhotoModalTitle">Foto de marcación</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body bg-light text-center">
                <img class="img-fluid rounded" id="attendancePhotoModalImage" src="" alt="Foto de marcación" style="max-height: 72vh; object-fit: contain;">
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="scheduleArrivalMapModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered"><div class="modal-content">
        <div class="modal-header border-0 pb-2">
            <div><h5 class="modal-title" id="scheduleArrivalMapTitle">Llegada registrada</h5><small class="text-muted" id="scheduleArrivalMapMessage">Ubicación validada mediante GPS.</small></div>
            <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body pt-1">
            <div id="scheduleArrivalEvidenceMap" class="rounded-3 border" style="height:340px"></div>
        </div>
        <div class="modal-footer border-0 pt-0"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>

<div class="modal fade" id="tripStopMapModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="modal-header bg-primary text-white"><div><h5 class="modal-title" id="tripStopMapTitle">Evidencia de la visita</h5><small>Ubicación y momento registrados durante el recorrido.</small></div><button class="btn-close btn-close-white" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body">
            <div class="row g-3 mb-3">
                <div class="col-md-5"><div class="border rounded-3 bg-light p-3 h-100"><small class="text-muted d-block">Punto visitado</small><strong id="tripStopMapDestination">-</strong></div></div>
                <div class="col-md-3"><div class="border rounded-3 bg-light p-3 h-100"><small class="text-muted d-block">Fecha y hora</small><strong id="tripStopMapDateTime">-</strong></div></div>
                <div class="col-md-4"><div class="border rounded-3 bg-light p-3 h-100"><small class="text-muted d-block">Coordenadas registradas</small><strong id="tripStopMapCoordinates">-</strong></div></div>
                <div class="col-md-4" id="tripStopMapDistanceBox"><div class="border rounded-3 bg-light p-3 h-100"><small class="text-muted d-block">Validación del lugar</small><strong id="tripStopMapDistance">-</strong></div></div>
                <div class="col-md-4" id="tripStopMapCompletionBox"><div class="border rounded-3 bg-light p-3 h-100"><small class="text-muted d-block">Trabajo finalizado</small><strong id="tripStopMapCompletion">-</strong></div></div>
                <div class="col-md-4" id="tripStopMapAddressBox"><div class="border rounded-3 bg-light p-3 h-100"><small class="text-muted d-block">Dirección</small><span id="tripStopMapAddress">-</span></div></div>
                <div class="col-12"><div class="border rounded-3 bg-light p-3"><small class="text-muted d-block">Actividad realizada</small><span id="tripStopMapActivity">-</span></div></div>
                <div class="col-12" id="tripStopMapObservationBox"><div class="border rounded-3 bg-light p-3"><small class="text-muted d-block">Observación</small><span id="tripStopMapObservation">-</span></div></div>
            </div>
            <div id="tripStopEvidenceMap" class="rounded-3 border" style="height:380px"></div>
        </div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>

<script>
window.CONTROL_PERSONAL_IS_PERSONAL = <?= is_personal_role() ? 'true' : 'false' ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.15/locales-all.global.min.js"></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
