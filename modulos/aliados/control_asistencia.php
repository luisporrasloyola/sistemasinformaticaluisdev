<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/asistencia.php';
require_role('Administrador');
ensure_attendance_schema();

$filters = [
    'date' => trim((string) ($_GET['date'] ?? '')),
    'month' => trim((string) ($_GET['month'] ?? '')),
    'name' => trim((string) ($_GET['name'] ?? '')),
    'activity' => trim((string) ($_GET['activity'] ?? '')),
    'company' => trim((string) ($_GET['company'] ?? '')),
    'position' => trim((string) ($_GET['position'] ?? '')),
    'rating' => trim((string) ($_GET['rating'] ?? '')),
];
if ($filters['date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date'])) $filters['date'] = '';
if ($filters['month'] !== '' && !preg_match('/^\d{4}-\d{2}$/', $filters['month'])) $filters['month'] = '';
if (!in_array($filters['rating'], ['', 'ASISTIÓ', 'DESCANSO', 'FALTÓ'], true)) $filters['rating'] = '';

$allowedPerPage = [10, 25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 25);
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$where = [];
$params = [];
if ($filters['date'] !== '') { $where[] = 'fecha = :filter_date'; $params['filter_date'] = $filters['date']; }
if ($filters['month'] !== '') {
    $where[] = 'fecha >= :month_start AND fecha < DATE_ADD(:month_end, INTERVAL 1 MONTH)';
    $params['month_start'] = $filters['month'] . '-01';
    $params['month_end'] = $filters['month'] . '-01';
}
foreach (['name' => 'nombre_apellido', 'activity' => 'lugar_actividad', 'company' => 'empresa_proyecto', 'position' => 'puesto'] as $key => $column) {
    if ($filters[$key] !== '') { $where[] = "$column LIKE :filter_$key"; $params['filter_' . $key] = '%' . $filters[$key] . '%'; }
}
$restCondition = "(lugar_actividad LIKE '%LIMA STAND BY DESCANSO%' OR lugar_actividad LIKE '%STAND BY DESCANSO%' OR lugar_actividad LIKE '%DESCANSO%' OR lugar_actividad LIKE '%CUENTA DE VACACIONES%' OR lugar_actividad LIKE '%VACACIONES%' OR lugar_actividad LIKE '%A CUENTA DE HORAS%')";
$absenceCondition = "lugar_actividad LIKE '%FALTA INJUSTIFICADA%'";
if ($filters['rating'] === 'FALTÓ') $where[] = $absenceCondition;
elseif ($filters['rating'] === 'DESCANSO') $where[] = "NOT ($absenceCondition) AND $restCondition";
elseif ($filters['rating'] === 'ASISTIÓ') $where[] = "NOT ($absenceCondition) AND NOT $restCondition";

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStatement = db()->prepare('SELECT COUNT(*) FROM attendance_control' . $whereSql);
$countStatement->execute($params);
$totalRecords = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$recordsStatement = db()->prepare('SELECT * FROM attendance_control' . $whereSql . ' ORDER BY fecha DESC, nombre_apellido ASC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$recordsStatement->execute($params);
$records = $recordsStatement->fetchAll();
$months = db()->query("SELECT DISTINCT DATE_FORMAT(fecha, '%Y-%m') AS month FROM attendance_control ORDER BY month DESC")->fetchAll(PDO::FETCH_COLUMN);

function attendance_page_url(int $targetPage): string
{
    $query = $_GET;
    $query['page'] = $targetPage;
    return '?' . http_build_query($query);
}

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

<form class="work-panel mb-3 attendance-filters" id="attendanceFiltersForm" method="get">
    <input type="hidden" name="page" value="1">
    <div class="row g-2">
        <div class="col-md-2"><label class="form-label">Fecha</label><input class="form-control attendance-filter" type="date" id="attendanceFilterDate" name="date" value="<?= e($filters['date']) ?>"></div>
        <div class="col-md-2"><label class="form-label">Mes</label><select class="form-select attendance-filter" id="attendanceFilterMonth" name="month"><option value="">Todos</option><?php foreach ($months as $month): ?><option value="<?= e($month) ?>" <?= $filters['month'] === $month ? 'selected' : '' ?>><?= e(date('m/Y', strtotime($month . '-01'))) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Nombre y Apellido</label><input class="form-control attendance-filter" id="attendanceFilterName" name="name" value="<?= e($filters['name']) ?>" placeholder="Buscar"></div>
        <div class="col-md-2"><label class="form-label">Actividad</label><input class="form-control attendance-filter" id="attendanceFilterActivity" name="activity" value="<?= e($filters['activity']) ?>" placeholder="Buscar"></div>
        <div class="col-md-2"><label class="form-label">Empresa / Proyecto</label><input class="form-control attendance-filter" id="attendanceFilterCompany" name="company" value="<?= e($filters['company']) ?>" placeholder="Buscar"></div>
        <div class="col-md-1"><label class="form-label">Puesto</label><input class="form-control attendance-filter" id="attendanceFilterPosition" name="position" value="<?= e($filters['position']) ?>" placeholder="Buscar"></div>
        <div class="col-md-1"><label class="form-label">Calificación</label><select class="form-select attendance-filter" id="attendanceFilterRating" name="rating"><option value="">Todas</option><?php foreach (['ASISTIÓ', 'DESCANSO', 'FALTÓ'] as $ratingOption): ?><option value="<?= e($ratingOption) ?>" <?= $filters['rating'] === $ratingOption ? 'selected' : '' ?>><?= e($ratingOption) ?></option><?php endforeach; ?></select></div>
    </div>
</form>
<div class="work-panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <form method="get" class="d-flex align-items-center gap-2">
            <?php foreach ($filters as $key => $value): ?><?php if ($value !== ''): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endif; ?><?php endforeach; ?>
            <label for="attendancePerPage" class="text-muted text-nowrap">Mostrar</label>
            <select class="form-select form-select-sm" id="attendancePerPage" name="per_page" style="width:auto"><?php foreach ($allowedPerPage as $option): ?><option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select>
            <span class="text-muted">registros</span>
        </form>
        <span class="text-muted small"><?= $totalRecords > 0 ? e(($offset + 1) . '–' . min($offset + $perPage, $totalRecords) . ' de ' . $totalRecords) : '0 registros' ?></span>
    </div>
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
            <?php if (!$records): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No se encontraron registros con los filtros seleccionados.</td></tr>
            <?php endif; ?>
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
    <?php if ($totalPages > 1): ?>
        <nav class="d-flex justify-content-end mt-3" aria-label="Paginación de asistencia"><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(attendance_page_url(max(1, $page - 1))) ?>">Anterior</a></li>
            <?php $firstPage = max(1, $page - 2); $lastPage = min($totalPages, $page + 2); ?>
            <?php if ($firstPage > 1): ?><li class="page-item"><a class="page-link" href="<?= e(attendance_page_url(1)) ?>">1</a></li><?php if ($firstPage > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><?php endif; ?>
            <?php for ($number = $firstPage; $number <= $lastPage; $number++): ?><li class="page-item <?= $number === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e(attendance_page_url($number)) ?>"><?= $number ?></a></li><?php endfor; ?>
            <?php if ($lastPage < $totalPages): ?><?php if ($lastPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?= e(attendance_page_url($totalPages)) ?>"><?= $totalPages ?></a></li><?php endif; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(attendance_page_url(min($totalPages, $page + 1))) ?>">Siguiente</a></li>
        </ul></nav>
    <?php endif; ?></div>

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
