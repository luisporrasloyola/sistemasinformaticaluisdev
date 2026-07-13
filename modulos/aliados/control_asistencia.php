<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/asistencia.php';
require_role('Administrador');
ensure_attendance_schema();

$records = db()->query('SELECT * FROM attendance_control ORDER BY fecha DESC, nombre_apellido ASC')->fetchAll();
$months = [];
foreach ($records as $record) {
    $month = substr((string) $record['fecha'], 0, 7);
    if ($month) {
        $months[$month] = $month;
    }
}
krsort($months);

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Control de asistencia</h1>
        <p>Registro y seguimiento de asistencia del personal aliado.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-primary" type="button" id="importAttendanceBtn"><i class="fa-solid fa-file-import me-2"></i>Importar</button>
        <button class="btn btn-primary" type="button" id="newAttendanceBtn"><i class="fa-solid fa-plus me-2"></i>Nuevo</button>
    </div>
</div>

<div class="work-panel mb-3 attendance-filters">
    <div class="row g-2">
        <div class="col-md-2">
            <label class="form-label">Fecha</label>
            <input class="form-control attendance-filter" type="date" id="attendanceFilterDate" data-filter="date">
        </div>
        <div class="col-md-2">
            <label class="form-label">Mes</label>
            <select class="form-select attendance-filter" id="attendanceFilterMonth" data-filter="month">
                <option value="">Todos</option>
                <?php foreach ($months as $month): ?>
                    <option value="<?= e($month) ?>"><?= e(date('m/Y', strtotime($month . '-01'))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Nombre y Apellido</label>
            <input class="form-control attendance-filter" id="attendanceFilterName" data-filter="name" placeholder="Buscar">
        </div>
        <div class="col-md-2">
            <label class="form-label">Actividad</label>
            <input class="form-control attendance-filter" id="attendanceFilterActivity" data-filter="activity" placeholder="Buscar">
        </div>
        <div class="col-md-2">
            <label class="form-label">Empresa / Proyecto</label>
            <input class="form-control attendance-filter" id="attendanceFilterCompany" data-filter="company" placeholder="Buscar">
        </div>
        <div class="col-md-1">
            <label class="form-label">Puesto</label>
            <input class="form-control attendance-filter" id="attendanceFilterPosition" data-filter="position" placeholder="Buscar">
        </div>
        <div class="col-md-1">
            <label class="form-label">Calificación</label>
            <select class="form-select attendance-filter" id="attendanceFilterRating" data-filter="rating">
                <option value="">Todas</option>
                <option value="ASISTIÓ">ASISTIÓ</option>
                <option value="DESCANSO">DESCANSO</option>
                <option value="FALTÓ">FALTÓ</option>
            </select>
        </div>
    </div>
</div>

<div class="work-panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle" id="attendanceTable">
            <thead>
            <tr>
                <th>Fecha</th>
                <th>Nombre y Apellido</th>
                <th>Elige el lugar y actividad que te encuentras realizando</th>
                <th>Nombre de empresa / Proyecto</th>
                <th>Puesto</th>
                <th>Calificación</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($records as $record): ?>
                <?php $rating = attendance_rating((string) $record['lugar_actividad']); ?>
                <tr data-date="<?= e($record['fecha']) ?>"
                    data-month="<?= e(substr((string) $record['fecha'], 0, 7)) ?>"
                    data-name="<?= e(mb_strtolower((string) $record['nombre_apellido'], 'UTF-8')) ?>"
                    data-activity="<?= e(mb_strtolower((string) $record['lugar_actividad'], 'UTF-8')) ?>"
                    data-company="<?= e(mb_strtolower((string) ($record['empresa_proyecto'] ?? ''), 'UTF-8')) ?>"
                    data-position="<?= e(mb_strtolower((string) ($record['puesto'] ?? ''), 'UTF-8')) ?>"
                    data-rating="<?= e($rating['label']) ?>">
                    <td><?= e(date('d/m/Y', strtotime((string) $record['fecha']))) ?></td>
                    <td><?= e($record['nombre_apellido']) ?></td>
                    <td class="attendance-activity-cell"><?= e($record['lugar_actividad']) ?></td>
                    <td><?= e($record['empresa_proyecto'] ?? '') ?></td>
                    <td><?= e($record['puesto'] ?? '') ?></td>
                    <td><span class="badge <?= e($rating['class']) ?>"><?= e($rating['label']) ?></span></td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-primary js-edit-attendance" type="button"
                            data-id="<?= (int) $record['id'] ?>"
                            data-fecha="<?= e($record['fecha']) ?>"
                            data-nombre="<?= e($record['nombre_apellido']) ?>"
                            data-actividad="<?= e($record['lugar_actividad']) ?>"
                            data-empresa="<?= e($record['empresa_proyecto'] ?? '') ?>"
                            data-puesto="<?= e($record['puesto'] ?? '') ?>"
                            title="Editar"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-sm btn-outline-danger js-delete-attendance" type="button" data-id="<?= (int) $record['id'] ?>" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="attendanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content needs-validation" id="attendanceForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="attendanceModalTitle">Nuevo registro</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="attendanceId">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Fecha</label>
                        <input class="form-control" type="date" name="fecha" id="attendanceDate" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Nombre y Apellido</label>
                        <input class="form-control" name="nombre_apellido" id="attendanceName" maxlength="180" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Elige el lugar y actividad que te encuentras realizando</label>
                        <textarea class="form-control" name="lugar_actividad" id="attendanceActivity" rows="3" required></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nombre de empresa / Proyecto</label>
                        <input class="form-control" name="empresa_proyecto" id="attendanceCompany" maxlength="180">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Puesto</label>
                        <input class="form-control" name="puesto" id="attendancePosition" maxlength="160">
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

<div class="modal fade" id="attendanceImportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content needs-validation" id="attendanceImportForm" enctype="multipart/form-data" novalidate>
            <div class="modal-header">
                <h5 class="modal-title">Importar control de asistencia</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <a class="btn btn-outline-primary w-100 mb-3" href="<?= APP_URL ?>/servicios/descargar_formato_asistencia.php"><i class="fa-solid fa-download me-2"></i>Descargar formato de ejemplo para importar</a>
                <label class="form-label">Archivo Excel completado</label>
                <input class="form-control" type="file" name="excel" id="attendanceExcelFile" accept=".xlsx" required>
                <div class="form-text">Use el formato con las columnas: Fecha, Nombre y Apellido, Actividad, Empresa / Proyecto y Puesto.</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-file-import me-2"></i>Importar</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
