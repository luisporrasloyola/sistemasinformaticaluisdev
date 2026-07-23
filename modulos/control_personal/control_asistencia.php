<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.control_asistencia');

$isAdmin = is_admin();
$currentWorkerId = current_user_worker_id();
$workers = [];

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
</div>

<div class="row g-3 attendance-marking-layout">
    <div class="col-xl-4">
        <div class="work-panel h-100 attendance-marking-panel">
            <h2>Marcación</h2>
            <?php if ($isAdmin): ?>
                <label class="form-label">Trabajador</label>
                <select class="form-select mb-3" id="markWorkerId">
                    <option value="">Seleccione</option>
                    <?php foreach ($workers as $worker): ?>
                        <option value="<?= (int) $worker['id'] ?>"><?= e($worker['full_name'] . ' - ' . $worker['document_number'] . (!empty($worker['company']) ? ' - ' . $worker['company'] : '')) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="hidden" id="markWorkerId" value="<?= (int) $currentWorkerId ?>">
            <?php endif; ?>

            <div class="attendance-status-stack mb-3" id="markStatusPanel">
                <span class="badge text-bg-secondary">Seleccione trabajador / cargando asignación</span>
            </div>

            <div class="attendance-availability-notice d-none" id="markAvailabilityNotice" role="status">
                <i class="fa-regular fa-clock"></i>
                <div><strong>Marcación de entrada aún no disponible</strong><span id="markAvailabilityText"></span></div>
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
                <dt>Lugar</dt><dd id="markLocationName">-</dd>
                <dt>Horario</dt><dd id="markScheduleName">-</dd>
                <dt>Actividad</dt><dd id="markActivity">-</dd>
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

            <div class="d-grid gap-2">
                <button class="btn btn-success" type="button" id="markEntryBtn" disabled><i class="fa-solid fa-right-to-bracket me-2"></i>Marcar entrada</button>
                <button class="btn btn-primary" type="button" id="markExitBtn" disabled><i class="fa-solid fa-right-from-bracket me-2"></i>Marcar salida</button>
            </div>
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
            <h2>Registros recientes</h2>
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
            </div>
        </div>
    </div>
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

<script>
window.CONTROL_PERSONAL_IS_PERSONAL = <?= is_personal_role() ? 'true' : 'false' ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
