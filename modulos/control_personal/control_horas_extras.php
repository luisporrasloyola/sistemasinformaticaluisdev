<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_once __DIR__ . '/../../includes/cobranzas_valorizacion_periodo.php';

require_role('Administrador');
require_module_access('control_personal.control_horas_extras');

$periodError = '';
try {
    $period = valorizacion_periodo($_GET, date('Y-m'));
} catch (InvalidArgumentException $error) {
    $periodError = $error->getMessage();
    $period = valorizacion_periodo([], date('Y-m'));
}
$filterMonth = $period['month'];
$companyFilterRequested = array_key_exists('empresa_id', $_GET);
$companyId = max(0, (int) ($_GET['empresa_id'] ?? 0));
$workerSearch = mb_substr(trim(is_string($_GET['trabajador'] ?? null) ? $_GET['trabajador'] : ''), 0, 100);
$page = max(1, (int) ($_GET['pagina'] ?? 1));
$perPage = 20;
$dateFrom = $period['start'];
$dateTo = $period['end'];
if ($period['mode'] === 'mes' && $filterMonth === date('Y-m')) $dateTo = min($dateTo, date('Y-m-d'));
$month = substr($dateTo, 0, 7);
$periodDays = (int) ((new DateTimeImmutable($dateFrom))->diff(new DateTimeImmutable($dateTo))->days ?? 0) + 1;
$periodLabel = 'Del ' . date('d/m/Y', strtotime($dateFrom)) . ' al ' . date('d/m/Y', strtotime($dateTo));
$periodQuery = $period['mode'] === 'mes'
    ? ['periodo' => 'mes', 'mes' => $filterMonth]
    : ['periodo' => 'fechas', 'desde' => $period['from'], 'hasta' => $period['to']];
if ($workerSearch !== '') $periodQuery['trabajador'] = $workerSearch;

$companies = db()->query('SELECT id, name FROM companies ORDER BY name')->fetchAll();
$companyIds = array_map('intval', array_column($companies, 'id'));
if (!$companyFilterRequested) {
    foreach ($companies as $company) {
        if (mb_strtolower(trim((string) $company['name'])) === 'life maquinarias') {
            $companyId = (int) $company['id'];
            break;
        }
    }
}
if ($companyId && !in_array($companyId, $companyIds, true)) $companyId = 0;
$whereParts = [];
$whereParams = [];
if ($companyId) {
    $whereParts[] = 'w.company_id = ?';
    $whereParams[] = $companyId;
}
if ($workerSearch !== '') {
    $whereParts[] = "(w.full_name LIKE ? ESCAPE '!' OR w.document_number LIKE ? ESCAPE '!')";
    $searchTerm = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $workerSearch) . '%';
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
}
$where = $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';
$countStmt = db()->prepare('SELECT COUNT(*) FROM workers w' . $where);
$countStmt->execute($whereParams);
$totalWorkers = (int) $countStmt->fetchColumn();
$summary = ['day_marks' => 0, 'marked' => 0, 'rectified' => 0, 'unmarked' => 0];
if ($totalWorkers > 0) {
    $workerScope = $whereParts ? ' AND ' . implode(' AND ', $whereParts) : '';
    $summaryStmt = db()->prepare("SELECT
            COALESCE(SUM(CASE WHEN marks.mark_date = ? THEN 1 ELSE 0 END), 0) AS day_marks,
            COALESCE(SUM(CASE WHEN marks.is_rectified = 0 THEN 1 ELSE 0 END), 0) AS marked,
            COALESCE(SUM(CASE WHEN marks.is_rectified = 1 THEN 1 ELSE 0 END), 0) AS rectified
        FROM (
            SELECT am.worker_id, am.mark_date,
                MAX(CASE WHEN am.location_status = 'registro_administrativo' THEN 1 ELSE 0 END) AS is_rectified
            FROM attendance_marks am
            INNER JOIN workers w ON w.id = am.worker_id
            WHERE am.mark_date BETWEEN ? AND ?{$workerScope}
            GROUP BY am.worker_id, am.mark_date
        ) marks");
    $summaryStmt->execute(array_merge([$dateTo, $dateFrom, $dateTo], $whereParams));
    $summary = array_merge($summary, array_map('intval', $summaryStmt->fetch() ?: []));
    $summary['unmarked'] = max(0, ($totalWorkers * $periodDays) - $summary['marked'] - $summary['rectified']);
}
$totalPages = max(1, (int) ceil($totalWorkers / $perPage));
if ($page > $totalPages) $page = $totalPages;
$workersStmt = db()->prepare('SELECT w.id, w.full_name, w.document_number, c.name AS company
    FROM workers w LEFT JOIN companies c ON c.id = w.company_id' . $where . ' ORDER BY w.full_name LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$workersStmt->execute($whereParams);
$workers = $workersStmt->fetchAll();

$rows = [];
foreach ($workers as $worker) {
    $workerId = (int) $worker['id'];
    $report = attendance_report_build($dateFrom, $dateTo, $workerId);
    $markedByWorker = [];
    $rectified = [];
    $hasMarkOnDay = false;
    foreach (($report['individual_rows'] ?? []) as $dailyRow) {
        if ((string) $dailyRow['date'] === $dateTo) {
            $hasEntry = ($dailyRow['entry'] ?? '-') !== '-';
            $hasExit = ($dailyRow['exit'] ?? '-') !== '-';
            $hasMarkOnDay = $hasMarkOnDay || $hasEntry || $hasExit;
        }
        if (empty($dailyRow['is_workday'])) continue;
        $date = (string) $dailyRow['date'];
        $hasMark = ($dailyRow['entry'] ?? '-') !== '-' || ($dailyRow['exit'] ?? '-') !== '-';
        if (!$hasMark) continue;
        if (!empty($dailyRow['entry_administrative']) || !empty($dailyRow['exit_administrative'])) $rectified[$date] = true;
        else $markedByWorker[$date] = true;
    }
    $workdays = $periodDays;
    foreach (array_keys($rectified) as $date) unset($markedByWorker[$date]);
    $markedCount = count($markedByWorker);
    $rectifiedCount = count($rectified);
    $hoursBook = attendance_report_build_hours_ledger($workerId, $month, $report['daily_classification'] ?? []);
    $closingMinutes = (int) ($hoursBook['current']['closing_minutes'] ?? 0);
    $rows[] = [
        'worker' => $worker,
        'workdays' => $workdays,
        'marked' => $markedCount,
        'rectified' => $rectifiedCount,
        'unmarked' => max(0, $workdays - $markedCount - $rectifiedCount),
        'has_mark_on_day' => $hasMarkOnDay,
        'hours' => attendance_report_hours_label($closingMinutes),
        'days' => attendance_report_days_label($closingMinutes),
    ];
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title attendance-report-title"><div><h1>Control de horas extras</h1><p>Resumen mensual de días y horas vigentes por trabajador.</p></div></div>
<?php if ($periodError !== ''): ?><div class="alert alert-warning" role="alert"><?= e($periodError) ?></div><?php endif; ?>
<form class="dashboard-filters attendance-report-filters attendance-report-filters-simple" id="overtimePeriodForm" method="get"><div class="row g-3 align-items-end"><div class="col-md-6 col-xl-2"><label class="form-label" for="overtimePeriodMode">Periodo</label><select class="form-select" id="overtimePeriodMode" name="periodo"><option value="mes" <?= $period['mode'] === 'mes' ? 'selected' : '' ?>>Por mes</option><option value="fechas" <?= $period['mode'] === 'fechas' ? 'selected' : '' ?>>Entre fechas</option></select></div><div class="col-md-6 col-xl-2" id="overtimeMonthField" <?= $period['mode'] !== 'mes' ? 'hidden' : '' ?>><label class="form-label" for="overtimeMonth">Mes y año</label><input class="form-control" id="overtimeMonth" type="month" name="mes" value="<?= e($filterMonth) ?>" max="<?= e(date('Y-m')) ?>" <?= $period['mode'] === 'mes' ? 'required' : 'disabled' ?>></div><div class="col-md-6 col-xl-3" id="overtimeFromField" <?= $period['mode'] !== 'fechas' ? 'hidden' : '' ?>><label class="form-label" for="overtimeFrom">Desde</label><input class="form-control" id="overtimeFrom" type="date" name="desde" value="<?= e($period['from']) ?>" <?= $period['mode'] === 'fechas' ? 'required' : 'disabled' ?>></div><div class="col-md-6 col-xl-3" id="overtimeToField" <?= $period['mode'] !== 'fechas' ? 'hidden' : '' ?>><label class="form-label" for="overtimeTo">Hasta</label><input class="form-control" id="overtimeTo" type="date" name="hasta" value="<?= e($period['to']) ?>" <?= $period['mode'] === 'fechas' ? 'required' : 'disabled' ?>></div><div class="col-md-6 col-xl-3"><label class="form-label" for="overtimeCompany">Empresa</label><select class="form-select select2-searchable" id="overtimeCompany" name="empresa_id"><option value="0">Todas las empresas</option><?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $companyId === (int) $company['id'] ? 'selected' : '' ?>><?= e((string) $company['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-6 col-xl-3"><label class="form-label" for="overtimeWorkerSearch">Trabajador</label><input class="form-control" id="overtimeWorkerSearch" type="search" name="trabajador" maxlength="100" value="<?= e($workerSearch) ?>" placeholder="Buscar por nombre o documento" autocomplete="off"></div><div class="col-md-6 col-xl-2 d-grid"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass me-2"></i>Consultar</button></div></div></form>
<section class="attendance-hour-purchases mt-3"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2"><div><strong><?= e($periodLabel) ?></strong><span class="d-block text-muted small">Mostrando <?= count($rows) ?> de <?= $totalWorkers ?> trabajador(es). Las tarjetas consideran todos los resultados filtrados.</span></div></div><div class="row g-3 mb-4"><div class="col-sm-6 col-xl-4"><article class="overtime-summary-card overtime-summary-card--check"><div class="overtime-summary-card__heading"><span class="overtime-summary-card__icon"><i class="fa-solid fa-circle-check"></i></span><span>Marcación de día</span></div><strong><?= (int) $summary['day_marks'] ?></strong><small>Personal con marcación el <?= e(date('d/m/Y', strtotime($dateTo))) ?></small></article></div><div class="col-sm-6 col-xl-4"><article class="overtime-summary-card overtime-summary-card--marked"><div class="overtime-summary-card__heading"><span class="overtime-summary-card__icon"><i class="fa-solid fa-user-check"></i></span><span>Marcado por trabajador</span></div><strong><?= (int) $summary['marked'] ?></strong><small>Marcaciones contabilizadas</small></article></div><div class="col-sm-6 col-xl-4"><article class="overtime-summary-card overtime-summary-card--rectified"><div class="overtime-summary-card__heading"><span class="overtime-summary-card__icon"><i class="fa-solid fa-pen-to-square"></i></span><span>Rectificado</span></div><strong><?= (int) $summary['rectified'] ?></strong><small>Correcciones administrativas</small></article></div></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Trabajador</th><th title="Marcación del <?= e(date('d/m/Y', strtotime($dateTo))) ?>">Marcación de día</th><th>Cantidad de días trabajados</th><th>Marcado por trabajador</th><th>Rectificado</th><th>Sin marcación</th><th>Horas vigentes</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No hay trabajadores para los filtros seleccionados.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><strong><?= e((string) $row['worker']['full_name']) ?></strong><small class="d-block text-muted"><?= e((string) ($row['worker']['company'] ?: 'Sin empresa')) ?></small></td><td class="text-center"><?php if ($row['has_mark_on_day']): ?><i class="fa-solid fa-circle-check fa-lg text-success" title="Marcación registrada" aria-label="Marcación registrada"></i><?php endif; ?></td><td><?= (int) $row['workdays'] ?> día(s)</td><td><?= (int) $row['marked'] ?></td><td><?= (int) $row['rectified'] ?></td><td><?= (int) $row['unmarked'] ?></td><td><strong><?= e((string) $row['hours']) ?></strong><small class="d-block text-muted"><?= e((string) $row['days']) ?></small></td></tr><?php endforeach; ?></tbody></table></div><?php if ($totalPages > 1): ?><nav class="mt-3" aria-label="Paginación de trabajadores"><ul class="pagination pagination-sm justify-content-end mb-0"><?php for ($item = 1; $item <= $totalPages; $item++): ?><li class="page-item <?= $item === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($periodQuery + ['empresa_id' => $companyId, 'pagina' => $item])) ?>"><?= $item ?></a></li><?php endfor; ?></ul></nav><?php endif; ?></section>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const mode = document.getElementById('overtimePeriodMode');
    const month = document.getElementById('overtimeMonth');
    const from = document.getElementById('overtimeFrom');
    const to = document.getElementById('overtimeTo');
    const fields = [
        ['mes', document.getElementById('overtimeMonthField'), month],
        ['fechas', document.getElementById('overtimeFromField'), from],
        ['fechas', document.getElementById('overtimeToField'), to]
    ];
    const sync = () => {
        fields.forEach(([expected, wrapper, field]) => {
            const enabled = mode.value === expected;
            wrapper.hidden = !enabled;
            field.disabled = !enabled;
            field.required = enabled;
        });
        if (mode.value !== 'fechas' || !month.value) return;
        if (!from.value) from.value = `${month.value}-01`;
        if (!to.value) {
            const [year, monthNumber] = month.value.split('-').map(Number);
            to.value = `${month.value}-${String(new Date(year, monthNumber, 0).getDate()).padStart(2, '0')}`;
        }
    };
    mode.addEventListener('change', sync);
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
