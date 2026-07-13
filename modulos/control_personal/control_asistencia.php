<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role(['Administrador', 'Personal']);

$isAdmin = is_admin();
$currentWorkerId = current_user_worker_id();
$workers = [];

if ($isAdmin) {
    $workers = db()->query("SELECT w.id, w.full_name, w.document_number, c.name AS company
        FROM workers w
        LEFT JOIN companies c ON c.id = w.company_id
        ORDER BY w.full_name")->fetchAll();
}

$marksSql = "SELECT am.*, w.full_name, w.document_number, l.name AS location_name, s.name AS schedule_name
    FROM attendance_marks am
    JOIN workers w ON w.id = am.worker_id
    JOIN attendance_locations l ON l.id = am.location_id
    JOIN attendance_schedules s ON s.id = am.schedule_id";
$params = [];
if (!$isAdmin) {
    $marksSql .= ' WHERE am.worker_id = :worker_id';
    $params['worker_id'] = (int) $currentWorkerId;
}
$marksSql .= ' ORDER BY am.marked_at DESC LIMIT 80';
$stmt = db()->prepare($marksSql);
$stmt->execute($params);
$marks = $stmt->fetchAll();

require __DIR__ . '/../../includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<div class="page-title">
    <div>
        <h1>Control de asistencia</h1>
        <p>Marcación mediante GPS, cámara y validación de horario.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="work-panel h-100">
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

            <dl class="info-list">
                <dt>Trabajador</dt><dd id="markWorkerName">-</dd>
                <dt>Lugar asignado</dt><dd id="markLocationName">-</dd>
                <dt>Horario</dt><dd id="markScheduleName">-</dd>
                <dt>Actividad</dt><dd id="markActivity">-</dd>
                <dt>Entrada</dt><dd id="markEntryWindow">-</dd>
                <dt>Salida</dt><dd id="markExitWindow">-</dd>
                <dt>Radio permitido</dt><dd id="markRadius">-</dd>
                <dt>Precisión GPS</dt><dd id="markAccuracy">-</dd>
                <dt>Distancia</dt><dd id="markDistance">-</dd>
            </dl>

            <label class="form-label">Observaciones</label>
            <textarea class="form-control mb-3" id="markObservations" rows="3"></textarea>

            <div class="d-grid gap-2">
                <button class="btn btn-success" type="button" id="markEntryBtn"><i class="fa-solid fa-right-to-bracket me-2"></i>Marcar entrada</button>
                <button class="btn btn-primary" type="button" id="markExitBtn"><i class="fa-solid fa-right-from-bracket me-2"></i>Marcar salida</button>
            </div>
            <div class="form-text mt-2">El navegador solicitará permisos de ubicación y cámara solo al marcar.</div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="work-panel mb-3">
            <div class="row g-3">
                <div class="col-lg-5">
                    <h2>Vista de cámara</h2>
                    <div class="camera-box">
                        <video id="markCamera" autoplay playsinline muted></video>
                        <canvas id="markCanvas" class="d-none"></canvas>
                    </div>
                    <img class="mark-photo-preview d-none mt-2" id="markPhotoPreview" alt="Foto capturada">
                </div>
                <div class="col-lg-7">
                    <h2>Mapa</h2>
                    <div class="attendance-map" id="markMap"></div>
                </div>
            </div>
        </div>

        <div class="work-panel">
            <h2>Registros recientes</h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                    <tr>
                        <th>Fecha/Hora</th>
                        <th>Trabajador</th>
                        <th>Tipo</th>
                        <th>Lugar</th>
                        <th>Distancia</th>
                        <th>Estado</th>
                        <th>Foto</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($marks as $mark): ?>
                        <tr>
                            <td><?= e(date('d/m/Y - H:i', strtotime((string) $mark['marked_at']))) ?></td>
                            <td><?= e($mark['full_name']) ?></td>
                            <td><?= e(ucfirst($mark['mark_type'])) ?></td>
                            <td><?= e($mark['location_name']) ?></td>
                            <td><?= e((string) round((float) $mark['distance_meters'], 2)) ?> m</td>
                            <td><span class="badge text-bg-primary"><?= e($mark['final_status']) ?></span></td>
                            <td>
                                <?php if (!empty($mark['photo_path'])): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL . '/' . e($mark['photo_path']) ?>" target="_blank"><i class="fa-solid fa-image"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$marks): ?>
                        <tr><td colspan="7" class="text-muted">No hay marcaciones registradas.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
window.CONTROL_PERSONAL_IS_PERSONAL = <?= is_personal_role() ? 'true' : 'false' ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
