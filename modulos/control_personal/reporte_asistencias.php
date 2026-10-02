<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_module_access('control_personal.reporte_asistencias');

$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');
$dateFrom = trim((string) ($_GET['desde'] ?? $defaultFrom));
$dateTo = trim((string) ($_GET['hasta'] ?? $today));
$personalView = is_personal_role();
$workerId = $personalView ? (int) (current_user_worker_id() ?? 0) : (int) ($_GET['trabajador_id'] ?? 0);
$companyId = $personalView ? 0 : max(0, (int) ($_GET['empresa_id'] ?? 0));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = $today;
if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

$companies = $personalView ? [] : db()->query('SELECT id, name FROM companies ORDER BY name')->fetchAll();
$companyIds = array_map('intval', array_column($companies, 'id'));
if ($companyId > 0 && !in_array($companyId, $companyIds, true)) $companyId = 0;
$catalogWhere = $personalView ? ' WHERE id = :worker_id' : '';
$catalogStmt = db()->prepare('SELECT id, company_id, full_name, document_number FROM workers' . $catalogWhere . ' ORDER BY full_name');
$catalogStmt->execute($personalView ? ['worker_id' => $workerId] : []);
$catalog = $catalogStmt->fetchAll();
if (!$personalView && $companyId > 0 && $workerId > 0) {
    $selectedWorker = array_values(array_filter($catalog, static fn(array $item): bool => (int) $item['id'] === $workerId))[0] ?? null;
    if (!$selectedWorker || (int) $selectedWorker['company_id'] !== $companyId) $workerId = 0;
}
$report = $workerId > 0 ? attendance_report_build($dateFrom, $dateTo, $workerId) : null;
$worker = $report['worker'] ?? null;
$assignment = $report['assignment'] ?? null;
$summary = $report['summary'] ?? [];
$rows = $report['individual_rows'] ?? [];
$rowsPerPage = 20;
$totalRows = count($rows);
$totalPages = max(1, (int) ceil($totalRows / $rowsPerPage));
$currentPage = min($totalPages, max(1, (int) ($_GET['pagina'] ?? 1)));
$visibleRows = array_slice($rows, ($currentPage - 1) * $rowsPerPage, $rowsPerPage);
$earlyOvertimeAuthorizations = [];
if ($worker && !$personalView) {
    try {
        $authorizationQuery = db()->prepare('SELECT work_date, authorized_by_name, authorized_at FROM attendance_early_overtime_authorizations WHERE worker_id = :worker_id AND work_date BETWEEN :date_from AND :date_to AND is_authorized = 1');
        $authorizationQuery->execute(['worker_id' => $workerId, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
        foreach ($authorizationQuery->fetchAll() as $authorization) {
            $earlyOvertimeAuthorizations[(string) $authorization['work_date']] = $authorization;
        }
    } catch (Throwable $error) {
        $earlyOvertimeAuthorizations = [];
    }
}
$note = $report['note'] ?? null;
$trips = $report['trips'] ?? [];
$tripsByDate = attendance_report_trips_by_date($trips);
$query = http_build_query(['desde' => $dateFrom, 'hasta' => $dateTo, 'empresa_id' => $companyId, 'trabajador_id' => $workerId]);

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title attendance-report-title">
    <div>
        <h1>Reporte individual de asistencia</h1>
        <p>Consulta, revisa y genera el informe detallado de cada trabajador.</p>
    </div>
    <?php if ($worker): ?>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-success" href="<?= APP_URL ?>/servicios/control_personal/descargar_reporte_asistencia_excel.php?<?= e($query) ?>"><i class="fa-solid fa-file-excel me-2"></i>Descargar Excel</a>
            <a class="btn btn-danger" href="<?= APP_URL ?>/servicios/control_personal/descargar_reporte_asistencia.php?<?= e($query) ?>"><i class="fa-solid fa-file-pdf me-2"></i>Descargar PDF</a>
        </div>
    <?php endif; ?>
</div>

<form class="dashboard-filters attendance-report-filters attendance-report-filters-simple" method="get">
    <div class="row g-3 align-items-end">
        <div class="col-md-6 col-xl-2">
            <label class="form-label">Desde</label>
            <input class="form-control" type="date" name="desde" value="<?= e($dateFrom) ?>" required>
        </div>
        <div class="col-md-6 col-xl-2">
            <label class="form-label">Hasta</label>
            <input class="form-control" type="date" name="hasta" value="<?= e($dateTo) ?>" required>
        </div>
        <?php if (!$personalView): ?>
        <div class="col-md-6 col-xl-3">
            <label class="form-label" for="attendanceReportCompany">Empresa</label>
            <select class="form-select select2-searchable" id="attendanceReportCompany" name="empresa_id" data-placeholder="Buscar empresa" data-no-results="No se encontraron empresas">
                <option value="0">Todas las empresas</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?= (int) $company['id'] ?>" <?= $companyId === (int) $company['id'] ? 'selected' : '' ?>><?= e($company['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-md-6 col-xl-<?= $personalView ? '6' : '3' ?>">
            <label class="form-label">Trabajador</label>
            <?php if ($personalView): ?><input type="hidden" name="trabajador_id" value="<?= $workerId ?>"><?php endif; ?>
            <select class="form-select select2-searchable" id="attendanceReportWorker" name="trabajador_id" <?= $personalView ? 'disabled' : '' ?> data-placeholder="Buscar trabajador" data-no-results="No se encontraron trabajadores" required>
                <option value="">Seleccione un trabajador</option>
                <?php foreach ($catalog as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" data-company-id="<?= (int) $item['company_id'] ?>" <?= $workerId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['full_name'] . ' - ' . $item['document_number']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-xl-2 d-grid">
            <button class="btn btn-primary text-nowrap" type="submit"><i class="fa-solid fa-magnifying-glass me-2"></i>Generar</button>
        </div>
    </div>
</form>
<?php if (!$personalView): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const company = document.getElementById('attendanceReportCompany');
    const worker = document.getElementById('attendanceReportWorker');
    if (!company || !worker) return;
    const workers = Array.from(worker.options).slice(1).map((option) => ({
        value: option.value,
        label: option.textContent.trim(),
        companyId: option.dataset.companyId || '0'
    }));
    const refreshWorkers = (keepSelection = false) => {
        const companyId = company.value;
        const selectedId = keepSelection ? worker.value : '';
        const visible = companyId === '0' ? workers : workers.filter((item) => item.companyId === companyId);
        worker.replaceChildren(new Option('Seleccione un trabajador', ''));
        visible.forEach((item) => worker.add(new Option(item.label, item.value, false, item.value === selectedId)));
        if (!visible.some((item) => item.value === selectedId)) worker.value = '';
        if (window.jQuery && window.jQuery.fn.select2) window.jQuery(worker).trigger('change.select2');
    };
    refreshWorkers(true);
    if (window.jQuery) {
        window.jQuery(company).on('change.attendanceReport', () => refreshWorkers(false));
    } else {
        company.addEventListener('change', () => refreshWorkers(false));
    }
});
</script>
<?php endif; ?>

<?php if (!$worker): ?>
    <section class="work-panel attendance-report-empty">
        <div class="attendance-report-empty-icon"><i class="fa-solid fa-file-circle-check"></i></div>
        <h2>Seleccione un trabajador</h2>
        <p>Indique el periodo y el trabajador para preparar su reporte individual de asistencia y descargarlo en PDF.</p>
    </section>
<?php else: ?>
<section class="work-panel individual-report-preview">
    <div class="individual-report-heading">
        <div>
            <span class="report-eyebrow">REPORTE INDIVIDUAL</span>
            <h2><?= e($worker['full_name']) ?></h2>
            <p><?= e(date('d/m/Y', strtotime($dateFrom))) ?> al <?= e(date('d/m/Y', strtotime($dateTo))) ?></p>
        </div>
        <span class="report-record-count"><?= count($rows) ?> días registrados</span>
    </div>

    <div class="individual-report-profile">
        <div><span>Documento</span><strong><?= e(($worker['document_type'] ?: 'Documento') . ': ' . $worker['document_number']) ?></strong></div>
        <div><span>Empresa</span><strong><?= e($worker['company'] ?: 'Sin empresa') ?></strong></div>
        <div><span>Cargo</span><strong><?= e($worker['positions'] ?: 'Sin cargo registrado') ?></strong></div>
        <div><span>Horario</span><strong><?= e($assignment['schedule_name'] ?? 'Sin horario asignado') ?></strong></div>
        <div><span>Lugar asignado</span><strong><?= e($assignment['location_name'] ?? 'Sin lugar asignado') ?></strong></div>
    </div>

    <div class="individual-report-metrics">
        <div><span>Días laborables</span><strong><?= (int) $summary['workdays'] ?></strong></div>
        <div class="metric-success"><span>Asistencias</span><strong><?= (int) $summary['attendances'] ?></strong></div>
        <div class="metric-warning"><span>Tardanzas</span><strong><?= (int) $summary['late'] ?></strong></div>
        <div class="metric-danger"><span>Faltas</span><strong><?= (int) $summary['absent'] ?></strong></div>
        <div class="metric-vacation"><span>Vacaciones</span><strong><?= (int) $summary['vacations'] ?></strong></div>
        <div><span>Horas trabajadas</span><strong><?= e(attendance_report_minutes_label((int) $summary['worked_minutes'])) ?></strong></div>
    </div>

    <div class="individual-report-indicators">
        <div><span>Puntualidad</span><strong><?= e((string) $summary['punctuality']) ?>%</strong></div>
        <div><span>Jornadas finalizadas</span><strong><?= e((string) $summary['compliance']) ?>%</strong></div>
        <div><span>Minutos de tardanza</span><strong><?= (int) $summary['late_minutes'] ?> min</strong></div>
        <div><span>Extra por entrada autorizada</span><strong><?= e(attendance_report_minutes_label((int) $summary['early_overtime_minutes'])) ?></strong></div>
        <div><span>Extra por salida (tras 15 min)</span><strong><?= e(attendance_report_minutes_label((int) $summary['exit_overtime_minutes'])) ?></strong></div>
        <div><span>Horas extras totales</span><strong><?= e(attendance_report_minutes_label((int) $summary['overtime_minutes'])) ?></strong></div>
    </div>

    <div class="individual-report-section-title" id="detalle-diario"><h3>Detalle diario</h3><p>Marcaciones y novedades del periodo seleccionado.</p></div>
    <?php if (!$personalView): ?>
    <div class="report-early-overtime-toolbar" id="reportEarlyOvertimeToolbar" data-worker-id="<?= (int) $workerId ?>" data-date-from="<?= e($dateFrom) ?>" data-date-to="<?= e($dateTo) ?>">
        <div><strong>Entrada anticipada autorizada</strong><small>Marque las fechas que desea autorizar o cuya autorización desea retirar. Las casillas se limpian al guardar; el estado aparece junto a cada fecha. La selección no afecta las horas extra de salida. Si la salida supera los 15 minutos de tolerancia, se cuenta todo el tiempo desde la hora de salida programada.</small><small class="report-early-overtime-status" id="earlyOvertimeSelectionStatus" role="status"></small></div>
        <button class="btn btn-primary" type="button" id="saveEarlyOvertimeSelection"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar selección</button>
    </div>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table align-middle individual-report-table">
            <thead><tr><?php if (!$personalView): ?><th class="report-early-overtime-select"><label class="report-early-overtime-check"><input type="checkbox" id="selectAllEarlyOvertime" aria-label="Seleccionar todas las jornadas elegibles"><span>Sel.</span></label></th><?php endif; ?><th>Fecha</th><th>Día</th><th>Horario</th><th>Tolerancia</th><th>Proyecto</th><th>Lugar de entrada</th><th>Lugar de salida</th><th>Entrada</th><th>Salida</th><th>Tardanza</th><th>Horas extras</th><th>Horas trabajadas</th><th>Estado de asistencia</th><th>Estado de jornada</th></tr></thead>
            <tbody>
            <?php $selectionShown = []; foreach ($visibleRows as $row): $dayTrips = $tripsByDate[(string) $row['date']] ?? []; $earlyEligible = (bool) ($row['early_overtime_eligible'] ?? false) && !isset($selectionShown[(string) $row['date']]); if ($earlyEligible) $selectionShown[(string) $row['date']] = true; $earlyAuthorized = isset($earlyOvertimeAuthorizations[(string) $row['date']]); ?>
                <tr>
                    <?php if (!$personalView): ?><td class="report-early-overtime-select"><?php if ($earlyEligible): ?><input class="form-check-input early-overtime-day" type="checkbox" value="<?= e($row['date']) ?>" data-authorized="<?= $earlyAuthorized ? '1' : '0' ?>" aria-label="<?= $earlyAuthorized ? 'Retirar' : 'Autorizar' ?> entrada anticipada del <?= e(date('d/m/Y', strtotime($row['date']))) ?>" title="<?= $earlyAuthorized ? e('Autorizado por ' . $earlyOvertimeAuthorizations[(string) $row['date']]['authorized_by_name'] . ' el ' . $earlyOvertimeAuthorizations[(string) $row['date']]['authorized_at'] . '. Marque para retirar.') : 'Marque para autorizar la entrada anticipada' ?>"><?php else: ?><span class="text-muted" title="No hay entrada anticipada elegible">—</span><?php endif; ?></td><?php endif; ?><td><?= e(date('d/m/Y', strtotime($row['date']))) ?><?php if ($earlyAuthorized): ?><small class="report-early-overtime-badge">Entrada autorizada</small><?php endif; ?></td><td><?= e($row['weekday']) ?></td><td class="attendance-time-cell text-nowrap"><?= e($row['schedule']) ?></td><td class="text-nowrap"><?= $row['tolerance_minutes'] !== null ? (int)$row['tolerance_minutes'].' min' : '-' ?></td><td><?= e($row['project'] ?? '-') ?></td><td><span class="report-route-inline"><span><?= e($row['entry_location']) ?></span><?php foreach ($dayTrips as $trip): ?><span class="report-route-step"><span class="report-route-arrow" aria-hidden="true">→</span><?= e($trip['first_destination']) ?></span><?php endforeach; ?></span></td><td><?= e($row['exit_location']) ?></td>
                    <td class="attendance-time-cell"><?= e($row['entry']) ?><?php if ($row['entry_administrative']): ?><small class="d-block text-primary">Administrativa</small><?php endif; ?></td><td class="attendance-time-cell"><?= e($row['exit']) ?><?php if ($row['exit_administrative']): ?><small class="d-block text-primary">Administrativa</small><?php endif; ?></td>
                    <td><?= $row['late_minutes'] > 0 ? e(attendance_report_minutes_label((int) $row['late_minutes'])) : '-' ?></td>
                    <td class="report-overtime-cell"><?= $row['overtime_minutes'] > 0 ? e(attendance_report_minutes_label((int) $row['overtime_minutes'])) : '-' ?><?php if ($row['overtime_minutes'] > 0): ?><small>Entrada: <?= e(attendance_report_minutes_label((int) $row['early_overtime_minutes'])) ?><br>Salida: <?= e(attendance_report_minutes_label((int) $row['exit_overtime_minutes'])) ?></small><?php endif; ?></td>
                    <td class="attendance-time-cell text-nowrap"><?= $row['entry'] !== '-' && $row['exit'] !== '-' ? e(attendance_report_minutes_label((int) $row['worked_minutes'])) : '-' ?></td>
                    <td><span class="attendance-report-state report-state-code-only <?= e($row['state_class']) ?>" title="<?= e($row['state_label']) ?>" aria-label="<?= e($row['state_label']) ?>"><strong><?= e($row['state_code']) ?></strong></span></td>
                    <td><span class="journey-state <?= e($row['journey_class']) ?>"><?= e($row['journey_label']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="<?= $personalView ? 14 : 15 ?>" class="text-center text-muted py-4">No hay jornadas para este trabajador en el periodo seleccionado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav class="report-pagination" aria-label="Páginas del detalle diario">
        <span>Mostrando <?= (($currentPage - 1) * $rowsPerPage) + 1 ?>–<?= min($currentPage * $rowsPerPage, $totalRows) ?> de <?= $totalRows ?> registros</span>
        <div class="report-pagination-actions">
            <?php if ($currentPage > 1): ?><a class="btn btn-outline-secondary btn-sm" href="?<?= e($query) ?>&amp;pagina=<?= $currentPage - 1 ?>#detalle-diario">Anterior</a><?php endif; ?>
            <span>Página <?= $currentPage ?> de <?= $totalPages ?></span>
            <?php if ($currentPage < $totalPages): ?><a class="btn btn-outline-secondary btn-sm" href="?<?= e($query) ?>&amp;pagina=<?= $currentPage + 1 ?>#detalle-diario">Siguiente</a><?php endif; ?>
        </div>
    </nav>
    <?php endif; ?>

    <?php if ($trips): ?>
    <div class="individual-report-section-title mt-4"><h3>Desplazamientos laborales</h3><p>Recorridos realizados entre lugares durante la jornada laboral.</p></div>
    <div class="table-responsive">
        <table class="table align-middle individual-report-table">
            <thead><tr><th>Fecha</th><th>Horario</th><th>Inicio</th><th>Fin</th><th>Duración</th><th>Origen</th><th>Destino</th><th>Proyecto</th><th>Estado</th></tr></thead>
            <tbody><?php foreach ($trips as $trip): ?><tr>
                <td><?= e(date('d/m/Y', strtotime($trip['trip_date']))) ?></td>
                <td class="text-nowrap"><?= e($trip['schedule_label']) ?></td>
                <td><?= e(date('H:i', strtotime($trip['started_at']))) ?></td>
                <td><?= $trip['ended_at'] ? e(date('H:i', strtotime($trip['ended_at']))) : '-' ?></td>
                <td class="text-nowrap"><?= e($trip['duration_label']) ?></td>
                <td><?= e($trip['location_name']) ?></td><td><?= e($trip['first_destination']) ?></td><td><?= e($trip['project_name'] ?: (($trip['status'] ?? '') !== 'finalizado' ? 'Pendiente' : '-')) ?><?php if (($trip['completion_type'] ?? '') === 'returned_without_arrival'): ?><small class="d-block text-warning-emphasis mt-1"><strong>Llegada no confirmada:</strong> <?= e($trip['exception_reason'] ?: 'Sin detalle') ?></small><?php endif; ?></td>
                <?php
                    $tripIncident = ($trip['completion_type'] ?? '') === 'returned_without_arrival';
                    $tripRegistered = ($trip['status'] ?? '') === 'registrado';
                    $tripBadgeClass = $tripRegistered || (($trip['status'] ?? '') === 'finalizado' && !$tripIncident) ? 'text-bg-success' : 'text-bg-warning';
                    $tripStatusLabel = $tripRegistered ? 'Registrado' : (($trip['status'] ?? '') !== 'finalizado' ? 'En curso' : ($tripIncident ? 'Regreso con incidencia' : 'Finalizado'));
                ?>
                <td><span class="badge <?= e($tripBadgeClass) ?>"><?= e($tripStatusLabel) ?></span></td>
            </tr><?php endforeach; ?></tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!$personalView): ?>
    <div class="individual-report-bottom">
        <form id="attendanceReportNoteForm" class="report-note-card report-note-card-full">
            <input type="hidden" name="worker_id" value="<?= (int) $worker['id'] ?>"><input type="hidden" name="date_from" value="<?= e($dateFrom) ?>"><input type="hidden" name="date_to" value="<?= e($dateTo) ?>">
            <label for="reportObservation">Observación general del responsable</label>
            <textarea id="reportObservation" name="observation" rows="4" maxlength="3000" placeholder="Registre aclaraciones, incidencias justificadas o comentarios para este reporte."><?= e($note['observation'] ?? '') ?></textarea>
            <div><small><?= $note ? 'Última actualización: ' . e(date('d/m/Y H:i', strtotime($note['updated_at']))) : 'Esta observación aparecerá en el PDF.' ?></small><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar observación</button></div>
        </form>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
