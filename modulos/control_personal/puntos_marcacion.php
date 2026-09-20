<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.puntos_marcacion');
$showHidden = ($_GET['view'] ?? 'active') === 'hidden';
$locationStatus = $showHidden ? 0 : 1;
$hiddenLocationCount = (int) db()->query('SELECT COUNT(*) FROM attendance_locations WHERE status=0')->fetchColumn();

$locations = db()->query("SELECT l.*, u.name AS registered_by_name,
        (SELECT COUNT(*) FROM attendance_location_workers alw WHERE alw.location_id = l.id) AS authorized_workers_count
    FROM attendance_locations l
    LEFT JOIN users u ON u.id = l.created_by_user_id
    WHERE l.status = {$locationStatus}
    ORDER BY l.name")->fetchAll();

$locationWorkers = db()->query("SELECT w.id, w.full_name, w.document_number, COALESCE(c.name, '') AS company
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    ORDER BY w.full_name, w.document_number")->fetchAll();

$locationPersonnelState = [];
foreach ($locations as $location) {
    $locationPersonnelState[(int) $location['id']] = [
        'configured' => (int) $location['personnel_access_configured'] === 1,
        'mode' => (string) $location['personnel_access_mode'],
        'selected' => [],
        'assigned' => [],
        'marked' => [],
        'open' => [],
    ];
}

foreach (db()->query('SELECT location_id, worker_id FROM attendance_location_workers')->fetchAll() as $row) {
    $locationId = (int) $row['location_id'];
    if (isset($locationPersonnelState[$locationId])) $locationPersonnelState[$locationId]['selected'][] = (int) $row['worker_id'];
}
foreach (db()->query("SELECT DISTINCT location_id, worker_id FROM attendance_assignments
    WHERE status=1 AND valid_from<=CURDATE() AND (valid_until IS NULL OR valid_until>=CURDATE())")->fetchAll() as $row) {
    $locationId = (int) $row['location_id'];
    if (isset($locationPersonnelState[$locationId])) $locationPersonnelState[$locationId]['assigned'][] = (int) $row['worker_id'];
}
foreach (db()->query('SELECT DISTINCT location_id, worker_id FROM attendance_marks')->fetchAll() as $row) {
    $locationId = (int) $row['location_id'];
    if (isset($locationPersonnelState[$locationId])) $locationPersonnelState[$locationId]['marked'][] = (int) $row['worker_id'];
}
$todayMarksByWorker = [];
foreach (db()->query("SELECT worker_id, location_id, mark_type FROM attendance_marks
    WHERE mark_date=CURDATE() ORDER BY marked_at, id")->fetchAll() as $row) {
    $todayMarksByWorker[(int) $row['worker_id']] = $row;
}
foreach ($todayMarksByWorker as $workerId => $mark) {
    $locationId = (int) $mark['location_id'];
    if ($mark['mark_type'] === 'entrada' && isset($locationPersonnelState[$locationId])) {
        $locationPersonnelState[$locationId]['open'][] = $workerId;
    }
}
foreach ($locationPersonnelState as &$state) {
    foreach (['selected', 'assigned', 'marked', 'open'] as $key) {
        $state[$key] = array_values(array_unique(array_map('intval', $state[$key])));
    }
}
unset($state);

require __DIR__ . '/../../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(APP_URL) ?>/recursos/vendor/leaflet/leaflet.css?v=1.9.4">
<div class="page-title">
    <div>
        <h1>Lugares de marcación</h1>
        <p>Lugares autorizados para registrar asistencia.</p>
    </div>
        <div class="d-flex flex-wrap gap-2">
        <?php if ($showHidden): ?>
            <a class="btn btn-outline-secondary" href="<?= e(APP_URL) ?>/modulos/control_personal/puntos_marcacion.php"><i class="fa-solid fa-location-dot me-2"></i>Ver activos</a>
        <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= e(APP_URL) ?>/modulos/control_personal/puntos_marcacion.php?view=hidden"><i class="fa-solid fa-eye-slash me-2"></i>Ocultos (<?= $hiddenLocationCount ?>)</a>
            <button class="btn btn-primary" type="button" id="newLocationBtn"><i class="fa-solid fa-plus me-2"></i>Nuevo lugar</button>
        <?php endif; ?>
    </div>
</div>

<div class="work-panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle data-table" id="locationsTable">
            <thead>
            <tr>
                <th>Lugar de marcaci&oacute;n</th>
                <th>Coordenadas</th>
                <th>Dirección</th>
                <th>Referencia</th>
                <th>Radio</th>
                <th>Personal autorizado</th>
                <th>Registrado por</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($locations as $location): ?>
                <?php
                $rowPersonnelState = $locationPersonnelState[(int) $location['id']] ?? [];
                $suggestedWorkerIds = array_values(array_unique(array_merge(
                    (array) ($rowPersonnelState['assigned'] ?? []),
                    (array) ($rowPersonnelState['marked'] ?? [])
                )));
                $suggestedWorkerCount = count($suggestedWorkerIds) ?: count($locationWorkers);
                ?>
                <tr>
                    <td><?= e($location['name']) ?></td>
                    <td><?= e($location['latitude'] . ', ' . $location['longitude']) ?></td>
                    <td><?= e($location['address'] ?? '') ?></td>
                    <td><?= e($location['reference'] ?? '') ?></td>
                    <td><?= (int) $location['radius_meters'] ?> metros</td>
                    <td>
                        <?php if (!(int) $location['personnel_access_configured']): ?>
                            <span class="location-personnel-status is-selected"><i class="fa-solid fa-user-check"></i><?= $suggestedWorkerCount ?> trabajador<?= $suggestedWorkerCount === 1 ? '' : 'es' ?></span>

                        <?php elseif ($location['personnel_access_mode'] === 'all'): ?>
                            <span class="location-personnel-status is-all"><i class="fa-solid fa-users"></i>Todo el personal</span>
                        <?php else: ?>
                            <span class="location-personnel-status is-selected"><i class="fa-solid fa-user-check"></i><?= (int) $location['authorized_workers_count'] ?> trabajador<?= (int) $location['authorized_workers_count'] === 1 ? '' : 'es' ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($location['registered_by_name'] ?: 'No registrado') ?></td>
                    <td class="text-nowrap">
                        <?php if ($showHidden): ?>
                            <button class="btn btn-sm btn-outline-success js-restore-location" type="button"
                                data-id="<?= (int) $location['id'] ?>" data-name="<?= e($location['name']) ?>"
                                title="Restaurar" aria-label="Restaurar <?= e($location['name']) ?>"><i class="fa-solid fa-eye"></i></button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline-success js-location-personnel" type="button"
                                data-location-id="<?= (int) $location['id'] ?>"
                                data-location-name="<?= e($location['name']) ?>"
                                title="Configurar personal autorizado" aria-label="Configurar personal autorizado para <?= e($location['name']) ?>">
                                <i class="fa-solid fa-users"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-primary js-edit-location" type="button"
                                data-id="<?= (int) $location['id'] ?>"
                                data-name="<?= e($location['name']) ?>"
                                data-latitude="<?= e((string)$location['latitude']) ?>"
                                data-longitude="<?= e((string)$location['longitude']) ?>"
                                data-address="<?= e($location['address'] ?? '') ?>"
                                data-reference="<?= e($location['reference'] ?? '') ?>"
                                data-radius="<?= (int) $location['radius_meters'] ?>"
                                title="Editar"><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-sm btn-outline-warning js-hide-location" type="button"
                                data-id="<?= (int) $location['id'] ?>" data-name="<?= e($location['name']) ?>"
                                title="Ocultar" aria-label="Ocultar <?= e($location['name']) ?>"><i class="fa-solid fa-eye-slash"></i></button>
                            <button class="btn btn-sm btn-outline-danger js-delete-location" type="button" data-id="<?= (int) $location['id'] ?>" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="locationPersonnelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content location-personnel-modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title"><i class="fa-solid fa-users me-2"></i>Personal autorizado</h5>
                    <small class="text-muted" id="locationPersonnelModalPlace">Lugar de marcación</small>
                </div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="location-personnel-guide" id="locationPersonnelGuide"></div>
                <div class="location-personnel-toolbar">
                    <div class="input-group input-group-sm location-personnel-search">
                        <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input class="form-control" type="search" id="locationPersonnelSearch" placeholder="Buscar por nombre, documento o empresa">
                    </div>
                    <div class="location-personnel-toolbar-actions">
                        <button class="btn btn-sm btn-outline-primary" type="button" id="locationPersonnelSelectAll"><i class="fa-solid fa-check-double me-1"></i>Seleccionar todos</button>
                        <button class="btn btn-sm btn-outline-secondary" type="button" id="locationPersonnelClearAll"><i class="fa-solid fa-xmark me-1"></i>Deseleccionar todos</button>
                    </div>
                </div>
                <div class="location-personnel-selection-summary">
                    <strong id="locationPersonnelSelectedCount">0 seleccionados</strong>
                    <span id="locationPersonnelProtectionSummary"><i class="fa-solid fa-circle-info"></i>Las etiquetas muestran la relación actual del trabajador con este lugar.</span>
                </div>
                <div class="location-personnel-grid" id="locationPersonnelGrid">
                    <?php foreach ($locationWorkers as $worker): ?>
                        <?php $workerSearch = strtolower(implode(' ', [$worker['full_name'], $worker['document_number'], $worker['company']])); ?>
                        <label class="location-personnel-option" data-worker-id="<?= (int) $worker['id'] ?>" data-search="<?= e($workerSearch) ?>">
                            <input class="form-check-input location-personnel-check" type="checkbox" value="<?= (int) $worker['id'] ?>">
                            <span class="location-personnel-avatar"><i class="fa-solid fa-user"></i></span>
                            <span class="location-personnel-lock d-none" data-protection-lock title="Selección obligatoria"><i class="fa-solid fa-lock"></i></span>
                            <span class="location-personnel-identity">
                                <strong><?= e($worker['full_name']) ?></strong>
                                <small><?= e($worker['document_number'] . ($worker['company'] ? ' · ' . $worker['company'] : '')) ?></small>
                                <span class="location-personnel-badges">
                                    <span class="location-personnel-relation is-assigned d-none" data-relation="assigned"><i class="fa-solid fa-briefcase"></i>Asignación vigente</span>
                                    <span class="location-personnel-relation is-marked d-none" data-relation="marked"><i class="fa-solid fa-clock-rotate-left"></i>Con marcaciones</span>
                                    <span class="location-personnel-relation is-open d-none" data-relation="open"><i class="fa-solid fa-person-walking-arrow-right"></i>Jornada abierta</span>
                                </span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="location-personnel-empty d-none" id="locationPersonnelEmpty"><i class="fa-solid fa-user-slash"></i>No se encontraron trabajadores.</div>
            </div>
            <div class="modal-footer">
                <small class="text-muted me-auto"><i class="fa-solid fa-shield-halved me-1"></i>Solo las jornadas abiertas deben permanecer seleccionadas hasta registrar la salida.</small>
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="button" id="locationPersonnelSave"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar selección</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="locationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered location-modal-dialog">
        <form class="modal-content needs-validation" id="locationForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="locationModalTitle">Nuevo lugar de marcación</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="locationId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Lugar de marcación</label>
                        <input class="form-control" name="name" id="locationName" maxlength="160" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Latitud</label>
                        <input class="form-control" type="number" step="0.00000001" name="latitude" id="locationLatitude" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Longitud</label>
                        <input class="form-control" type="number" step="0.00000001" name="longitude" id="locationLongitude" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Dirección</label>
                        <input class="form-control" name="address" id="locationAddress" maxlength="255">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Referencia</label>
                        <input class="form-control" name="reference" id="locationReference" maxlength="255" placeholder="Ej.: Ingreso por la puerta principal">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Radio permitido: <span id="locationRadiusLabel">100 metros</span></label>
                        <input class="form-range" type="range" name="radius_meters" id="locationRadius" min="50" max="30000" step="10" value="100">
                    </div>
                    <div class="col-md-12">
                        <div class="attendance-map" id="locationMap"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar</button>
            </div>
        </form>
    </div>
</div>
<script>
window.LOCATION_PERSONNEL_STATE = <?= json_encode($locationPersonnelState, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= e(APP_URL) ?>/recursos/js/puntos_marcacion_personal.js?v=<?= (int) @filemtime(__DIR__ . '/../../recursos/js/puntos_marcacion_personal.js') ?>"></script>
<script src="<?= e(APP_URL) ?>/recursos/vendor/leaflet/leaflet.js?v=1.9.4"></script>
<script>
window.addEventListener('load', function () {
    if (typeof window.initControlPersonalLocations === 'function') {
        window.initControlPersonalLocations();
    }
}, { once: true });
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
