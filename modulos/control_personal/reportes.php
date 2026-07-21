<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.reportes');

$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');
$dateFrom = (string) ($_GET['desde'] ?? $defaultFrom);
$dateTo = (string) ($_GET['hasta'] ?? $today);
$workerId = (int) ($_GET['trabajador_id'] ?? 0);
$companyId = (int) ($_GET['empresa_id'] ?? 0);
$locationId = (int) ($_GET['punto_id'] ?? 0);
$status = trim((string) ($_GET['estado'] ?? ''));
$export = (string) ($_GET['exportar'] ?? '') === 'csv';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = $defaultFrom;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = $today;
}
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

function report_time(?string $time): string
{
    return $time ? substr($time, 0, 5) : '';
}

function report_status_label(?string $status): string
{
    return match ($status) {
        'puntual' => 'Puntual',
        'tardanza' => 'Tardanza',
        'salida_valida' => 'Salida valida',
        'salida_anticipada' => 'Salida anticipada',
        'fuera_del_radio' => 'Fuera de radio',
        'dentro_del_radio' => 'Dentro del radio',
        default => $status ? ucfirst(str_replace('_', ' ', $status)) : '',
    };
}

function report_badge_class(?string $status): string
{
    return match ($status) {
        'puntual', 'salida_valida' => 'text-bg-success',
        'tardanza', 'salida_anticipada' => 'text-bg-warning',
        'fuera_del_radio' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
}

$conditions = ['am.mark_date BETWEEN :desde AND :hasta'];
$params = [
    'desde' => $dateFrom,
    'hasta' => $dateTo,
];

if ($workerId > 0) {
    $conditions[] = 'am.worker_id = :worker_id';
    $params['worker_id'] = $workerId;
}
if ($companyId > 0) {
    $conditions[] = 'w.company_id = :company_id';
    $params['company_id'] = $companyId;
}
if ($locationId > 0) {
    $conditions[] = 'am.location_id = :location_id';
    $params['location_id'] = $locationId;
}
if ($status !== '') {
    $conditions[] = 'am.final_status = :status';
    $params['status'] = $status;
}

$whereSql = implode(' AND ', $conditions);
$sql = "SELECT
        am.*,
        w.full_name,
        w.document_number,
        c.name AS company,
        l.name AS location_name,
        s.name AS schedule_name
    FROM attendance_marks am
    JOIN workers w ON w.id = am.worker_id
    LEFT JOIN companies c ON c.id = w.company_id
    JOIN attendance_locations l ON l.id = am.location_id
    JOIN attendance_schedules s ON s.id = am.schedule_id
    WHERE {$whereSql}
    ORDER BY am.marked_at DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_asistencia_' . $dateFrom . '_' . $dateTo . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Fecha', 'Hora', 'Trabajador', 'Documento', 'Empresa', 'Tipo', 'Punto', 'Horario', 'Distancia m', 'Precision m', 'Estado', 'Observaciones']);
    foreach ($rows as $row) {
        fputcsv($out, [
            date('d/m/Y', strtotime((string) $row['mark_date'])),
            report_time($row['mark_time'] ?? null),
            $row['full_name'],
            $row['document_number'],
            $row['company'],
            ucfirst((string) $row['mark_type']),
            $row['location_name'],
            $row['schedule_name'],
            round((float) $row['distance_meters'], 2),
            round((float) $row['accuracy_meters'], 2),
            report_status_label($row['final_status'] ?? ''),
            $row['observations'],
        ]);
    }
    fclose($out);
    exit;
}

$workers = db()->query("SELECT w.id, w.full_name, w.document_number, c.name AS company
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    ORDER BY w.full_name")->fetchAll();
$companies = db()->query('SELECT id, name FROM companies ORDER BY name')->fetchAll();
$locations = db()->query('SELECT id, name FROM attendance_locations WHERE status = 1 ORDER BY name')->fetchAll();
$statuses = db()->query('SELECT DISTINCT final_status FROM attendance_marks WHERE final_status IS NOT NULL AND final_status <> "" ORDER BY final_status')->fetchAll();

$totals = [
    'marcaciones' => count($rows),
    'entradas' => 0,
    'salidas' => 0,
    'tardanzas' => 0,
    'fuera_radio' => 0,
];
$daily = [];
foreach ($rows as $row) {
    $date = (string) $row['mark_date'];
    $daily[$date] ??= ['entradas' => 0, 'salidas' => 0, 'tardanzas' => 0, 'fuera_radio' => 0];
    if ($row['mark_type'] === 'entrada') {
        $totals['entradas']++;
        $daily[$date]['entradas']++;
    }
    if ($row['mark_type'] === 'salida') {
        $totals['salidas']++;
        $daily[$date]['salidas']++;
    }
    if ($row['final_status'] === 'tardanza') {
        $totals['tardanzas']++;
        $daily[$date]['tardanzas']++;
    }
    if ($row['final_status'] === 'fuera_del_radio') {
        $totals['fuera_radio']++;
        $daily[$date]['fuera_radio']++;
    }
}
ksort($daily);

$queryForExport = $_GET;
$queryForExport['exportar'] = 'csv';

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title dashboard-title">
    <div>
        <h1>Reportes de asistencia</h1>
        <p>Consulta, filtrado y exportación de marcaciones registradas.</p>
    </div>
    <a class="btn btn-success" href="<?= APP_URL ?>/modulos/control_personal/reportes.php?<?= e(http_build_query($queryForExport)) ?>">
        <i class="fa-solid fa-file-csv me-2"></i>Exportar CSV
    </a>
</div>

<form class="dashboard-filters attendance-report-filters" method="get">
    <div class="row g-3">
        <div class="col-md-3 col-xl-2">
            <label class="form-label">Desde</label>
            <input class="form-control" type="date" name="desde" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-md-3 col-xl-2">
            <label class="form-label">Hasta</label>
            <input class="form-control" type="date" name="hasta" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-md-6 col-xl-3">
            <label class="form-label">Trabajador</label>
            <select class="form-select" name="trabajador_id">
                <option value="0">Todos</option>
                <?php foreach ($workers as $worker): ?>
                    <option value="<?= (int) $worker['id'] ?>" <?= $workerId === (int) $worker['id'] ? 'selected' : '' ?>>
                        <?= e($worker['full_name'] . ' - ' . $worker['document_number']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 col-xl-2">
            <label class="form-label">Empresa</label>
            <select class="form-select" name="empresa_id">
                <option value="0">Todas</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?= (int) $company['id'] ?>" <?= $companyId === (int) $company['id'] ? 'selected' : '' ?>><?= e($company['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 col-xl-2">
            <label class="form-label">Punto</label>
            <select class="form-select" name="punto_id">
                <option value="0">Todos</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?= (int) $location['id'] ?>" <?= $locationId === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 col-xl-1">
            <label class="form-label">Estado</label>
            <select class="form-select" name="estado">
                <option value="">Todos</option>
                <?php foreach ($statuses as $statusRow): ?>
                    <?php $statusValue = (string) $statusRow['final_status']; ?>
                    <option value="<?= e($statusValue) ?>" <?= $status === $statusValue ? 'selected' : '' ?>><?= e(report_status_label($statusValue)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter me-2"></i>Aplicar filtros</button>
            <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/modulos/control_personal/reportes.php">Limpiar</a>
        </div>
    </div>
</form>

<div class="attendance-kpi-grid mb-3">
    <div class="attendance-kpi-card kpi-blue"><span>Marcaciones</span><strong><?= $totals['marcaciones'] ?></strong><small>Registros encontrados</small><i class="fa-solid fa-list-check"></i></div>
    <div class="attendance-kpi-card kpi-green"><span>Entradas</span><strong><?= $totals['entradas'] ?></strong><small>Dentro del rango</small><i class="fa-solid fa-right-to-bracket"></i></div>
    <div class="attendance-kpi-card kpi-sky"><span>Salidas</span><strong><?= $totals['salidas'] ?></strong><small>Dentro del rango</small><i class="fa-solid fa-right-from-bracket"></i></div>
    <div class="attendance-kpi-card kpi-orange"><span>Tardanzas</span><strong><?= $totals['tardanzas'] ?></strong><small>Entradas tardías</small><i class="fa-solid fa-clock"></i></div>
</div>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="work-panel h-100">
            <h2>Resumen por fecha</h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Ent.</th>
                        <th>Sal.</th>
                        <th>Tard.</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($daily as $date => $item): ?>
                        <tr>
                            <td><?= e(date('d/m/Y', strtotime($date))) ?></td>
                            <td><?= (int) $item['entradas'] ?></td>
                            <td><?= (int) $item['salidas'] ?></td>
                            <td><?= (int) $item['tardanzas'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$daily): ?>
                        <tr><td colspan="4" class="text-muted">No hay datos en el rango seleccionado.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="work-panel h-100">
            <h2>Detalle de marcaciones</h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle dashboard-table">
                    <thead>
                    <tr>
                        <th>Fecha/Hora</th>
                        <th>Personal</th>
                        <th>Empresa</th>
                        <th>Tipo</th>
                        <th>Punto</th>
                        <th>Distancia</th>
                        <th>Estado</th>
                        <th>Foto</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e(date('d/m/Y - H:i', strtotime((string) $row['marked_at']))) ?></td>
                            <td>
                                <strong><?= e($row['full_name']) ?></strong>
                                <span class="text-muted small d-block"><?= e($row['document_number']) ?></span>
                            </td>
                            <td><?= e($row['company'] ?? '') ?></td>
                            <td><?= e(ucfirst((string) $row['mark_type'])) ?></td>
                            <td><?= e($row['location_name']) ?></td>
                            <td><?= e((string) round((float) $row['distance_meters'], 2)) ?> m</td>
                            <td><span class="badge <?= report_badge_class($row['final_status'] ?? '') ?>"><?= e(report_status_label($row['final_status'] ?? '')) ?></span></td>
                            <td>
                                <?php if (!empty($row['photo_path'])): ?>
                                    <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= APP_URL . '/' . e($row['photo_path']) ?>"><i class="fa-solid fa-image"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8" class="text-muted">No hay marcaciones para los filtros seleccionados.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
