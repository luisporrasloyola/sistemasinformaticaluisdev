<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.puntos_marcacion');

$locations = db()->query('SELECT * FROM attendance_locations WHERE status = 1 ORDER BY name')->fetchAll();

require __DIR__ . '/../../includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<div class="page-title">
    <div>
        <h1>Puntos de marcación</h1>
        <p>Lugares autorizados para registrar asistencia.</p>
    </div>
    <button class="btn btn-primary" type="button" id="newLocationBtn"><i class="fa-solid fa-plus me-2"></i>Nuevo punto</button>
</div>

<div class="work-panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle data-table" id="locationsTable">
            <thead>
            <tr>
                <th>Lugar</th>
                <th>Coordenadas</th>
                <th>Dirección</th>
                <th>Radio</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($locations as $location): ?>
                <tr>
                    <td><?= e($location['name']) ?></td>
                    <td><?= e($location['latitude'] . ', ' . $location['longitude']) ?></td>
                    <td><?= e($location['address'] ?? '') ?></td>
                    <td><?= (int) $location['radius_meters'] ?> metros</td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-primary js-edit-location" type="button"
                            data-id="<?= (int) $location['id'] ?>"
                            data-name="<?= e($location['name']) ?>"
                            data-latitude="<?= e((string) $location['latitude']) ?>"
                            data-longitude="<?= e((string) $location['longitude']) ?>"
                            data-address="<?= e($location['address'] ?? '') ?>"
                            data-radius="<?= (int) $location['radius_meters'] ?>"
                            title="Editar"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-sm btn-outline-danger js-delete-location" type="button" data-id="<?= (int) $location['id'] ?>" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="locationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form class="modal-content needs-validation" id="locationForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="locationModalTitle">Nuevo punto de marcación</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="locationId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nombre del lugar</label>
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
                    <div class="col-md-8">
                        <label class="form-label">Dirección</label>
                        <input class="form-control" name="address" id="locationAddress" maxlength="255">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Radio permitido: <span id="locationRadiusLabel">100 metros</span></label>
                        <input class="form-range" type="range" name="radius_meters" id="locationRadius" min="50" max="1000" step="10" value="100">
                    </div>
                    <div class="col-md-12">
                        <div class="attendance-map" id="locationMap"></div>
                        <div class="form-text">Puede hacer clic en el mapa para tomar coordenadas. La dirección se intentará completar automáticamente.</div>
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
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
