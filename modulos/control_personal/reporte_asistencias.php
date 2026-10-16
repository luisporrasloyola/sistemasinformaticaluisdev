<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_once __DIR__ . '/../../includes/attendance_report_closure.php';
require_module_access('control_personal.reporte_asistencias');

$month = trim((string) ($_GET['mes'] ?? substr((string) ($_GET['desde'] ?? date('Y-m')), 0, 7)));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$dateFrom = $month . '-01';
$dateTo = (new DateTimeImmutable($dateFrom))->format('Y-m-t');
$personalView = is_personal_role();
$workerId = $personalView ? (int) (current_user_worker_id() ?? 0) : (int) ($_GET['trabajador_id'] ?? 0);
$companyId = $personalView ? 0 : max(0, (int) ($_GET['empresa_id'] ?? 0));

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
$closure = null;
$closureError = false;
if ($worker) {
    try {
        $closure = attendance_report_closure_status($workerId, $month);
        $closureError = (bool) ($closure['schema_missing'] ?? false);
    } catch (Throwable $error) {
        $closureError = true;
    }
}
$reportLocked = (bool) ($closure['fully_closed'] ?? false);
$canEditReport = !$personalView && !$reportLocked;
$assignment = $report['assignment'] ?? null;
$summary = $report['summary'] ?? [];
$monthlyDays = $report['monthly_day_summary'][$month] ?? [];
$hoursBook = $worker ? attendance_report_build_hours_ledger($workerId, $month, $report['daily_classification']) : null;
$monthlyHours = $hoursBook['current'] ?? [];
$hempAdjustment = $month === '2026-10' ? ($hoursBook['opening_adjustment'] ?? null) : null;
$canEditHemp = $worker && $month === '2026-10' && is_admin() && !$reportLocked && !$closureError
    && ($hoursBook['adjustment_schema_available'] ?? false);
if ($canEditHemp) {
    try {
        $laterClosure = db()->prepare("SELECT 1 FROM attendance_report_month_closures
            WHERE worker_id=:worker_id AND report_month>'2026-10'
              AND admin_closed_at IS NOT NULL AND staff_closed_at IS NOT NULL LIMIT 1");
        $laterClosure->execute(['worker_id' => $workerId]);
        $canEditHemp = !$laterClosure->fetchColumn();
    } catch (Throwable $error) {
        $canEditHemp = false;
    }
}
$completedDays = 0;
foreach ($report['daily_classification'] ?? [] as $classifiedDay) {
    if (!empty($classifiedDay['worked']) && !empty($classifiedDay['has_exit'])) $completedDays++;
}
$workedDays = (int) ($monthlyDays['worked'] ?? 0);
$punctuality = $workedDays > 0 ? round(100 * ($workedDays - (int) ($monthlyDays['late'] ?? 0)) / $workedDays) : 0;
$completion = $workedDays > 0 ? round(100 * $completedDays / $workedDays) : 0;
$monthNames = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$monthLabel = $monthNames[(int) substr($month, 5, 2)] . ' ' . substr($month, 0, 4);
$previousDate = (new DateTimeImmutable($dateFrom))->modify('-1 month');
$previousMonthLabel = $monthNames[(int) $previousDate->format('n')] . ' ' . $previousDate->format('Y');
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
$query = http_build_query(['mes' => $month, 'desde' => $dateFrom, 'hasta' => $dateTo, 'empresa_id' => $companyId, 'trabajador_id' => $workerId]);

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
        <div class="col-md-6 col-xl-3">
            <label class="form-label" for="attendanceReportMonth">Mes y año</label>
            <input class="form-control" id="attendanceReportMonth" type="month" name="mes" value="<?= e($month) ?>" required>
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
        <div class="col-md-6 col-xl-<?= $personalView ? '7' : '4' ?>">
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
<section class="work-panel individual-report-preview <?= $reportLocked ? 'report-is-locked' : '' ?>">
    <div class="individual-report-heading attendance-reference-heading">
        <div class="attendance-reference-person">
            <span class="report-eyebrow">REPORTE INDIVIDUAL</span>
            <h2><?= e($worker['full_name']) ?></h2>
            <p><?= e(date('d/m/Y', strtotime($dateFrom))) ?> al <?= e(date('d/m/Y', strtotime($dateTo))) ?></p>
        </div>
        <div class="attendance-reference-profile">
            <div><span>Documento:</span> <?= e(($worker['document_type'] ?: 'Documento') . ' - ' . $worker['document_number']) ?></div>
            <div><span>Empresa:</span> <?= e($worker['company'] ?: 'Sin empresa') ?></div>
            <div><span>Cargo:</span> <?= e($worker['positions'] ?: 'Sin cargo registrado') ?></div>
            <div><span>Horario:</span> <?= e($assignment['schedule_name'] ?? 'Sin horario asignado') ?></div>
        </div>
        <span class="report-record-count"><?= count($report['daily_classification']) ?> días registrados</span>
    </div>

    <div class="attendance-month-rates">
        <div><span>Puntualidad: <strong><?= $punctuality ?>%</strong></span></div>
        <div><span>Jornadas finalizadas: <strong><?= $completion ?>%</strong></span></div>
    </div>

    <div class="attendance-reference-days" aria-label="Resumen de días de <?= e($monthLabel) ?>">
        <div class="attendance-reference-workdays">
            <div class="attendance-reference-worked">Días trabajados: <strong><?= $workedDays ?></strong></div>
            <div class="attendance-reference-worktypes">
                <div>Asistencia regular<strong><?= (int) ($monthlyDays['regular'] ?? 0) ?></strong></div>
                <div>No concluidos<strong><?= (int) ($monthlyDays['incomplete'] ?? 0) ?></strong></div>
                <div>Tardanzas<strong><?= (int) ($monthlyDays['late'] ?? 0) ?></strong></div>
            </div>
        </div>
        <div class="attendance-reference-otherdays">
            <div>Feriados<strong><?= (int) ($monthlyDays['holiday'] ?? 0) ?> días</strong></div>
            <div>No laborables<strong><?= (int) ($monthlyDays['non_working'] ?? 0) ?> días</strong></div>
            <div>Vacaciones<strong><?= (int) ($monthlyDays['vacation'] ?? 0) ?> días</strong></div>
            <div>Faltas<strong><?= (int) ($monthlyDays['absent'] ?? 0) ?> días</strong></div>
        </div>
        <div class="attendance-reference-rest">
            <div class="attendance-reference-rest-total">Descansos: <strong><?= (int) ($monthlyDays['rest'] ?? 0) ?> días</strong></div>
            <div class="attendance-reference-rest-types">
                <div>A cuenta de horas<strong><?= (int) ($monthlyDays['rest_hours'] ?? 0) ?> días</strong></div>
                <div>Régimen laboral<strong><?= (int) ($monthlyDays['rest_regime'] ?? 0) ?> días</strong></div>
            </div>
            <?php if (($monthlyDays['rest_unclassified'] ?? 0) > 0): ?><div class="attendance-reference-rest-extra">Sin clasificar: <?= (int) $monthlyDays['rest_unclassified'] ?></div><?php endif; ?>
        </div>
    </div>
    <?php if (($monthlyDays['pending'] ?? 0) > 0): ?><p class="attendance-hours-note"><?= (int) $monthlyDays['pending'] ?> día(s) pendientes no se cuentan como faltas.</p><?php endif; ?>

    <div class="attendance-worked-total"><span>Horas trabajadas:</span><strong><?= e(attendance_report_hours_label((int) ($monthlyHours['worked_minutes'] ?? 0))) ?></strong></div>
    <div class="attendance-reference-hours">
        <div class="attendance-reference-hour-source">
            <div class="attendance-reference-hour-box">
                <span>H. canjeadas · <?= e($monthLabel) ?></span>
                <strong><?= e(attendance_report_hours_label((int) ($monthlyHours['redeemed_minutes'] ?? 0))) ?></strong>
                <small><?= (int) ($monthlyHours['redeemed_saturday_days'] ?? 0) ?> sábado(s) × 6 h + <?= (int) (($monthlyHours['redeemed_days'] ?? 0) - ($monthlyHours['redeemed_saturday_days'] ?? 0)) ?> otro(s) día(s) × 8 h 30 min</small>
            </div>
            <div class="attendance-reference-hour-box">
                <span>HEMP · <?= e($previousMonthLabel) ?></span>
                <div class="attendance-hemp-value"><strong><?= e(attendance_report_hours_label((int) ($monthlyHours['opening_minutes'] ?? 0))) ?></strong><?php if ($canEditHemp): ?><button class="attendance-hemp-edit" type="button" data-bs-toggle="modal" data-bs-target="#attendanceHempModal" aria-label="Editar HEMP de octubre" title="Editar HEMP de octubre"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><?php endif; ?></div>
                <small><?= $hempAdjustment ? 'Saldo ajustado para octubre; septiembre no cambia' : 'Saldo del mes anterior' ?></small>
            </div>
        </div>
        <div class="attendance-reference-hour-extras">
            <div class="attendance-reference-extra-parts">
                <div>Feriado ×2<strong><?= e(attendance_report_hours_label((int) ($monthlyHours['holiday_overtime_minutes'] ?? 0))) ?></strong></div>
                <div>Extra por entrada<strong><?= e(attendance_report_hours_label((int) ($monthlyHours['early_overtime_minutes'] ?? 0))) ?></strong></div>
                <div>Extra por salida<strong><?= e(attendance_report_hours_label((int) ($monthlyHours['exit_overtime_minutes'] ?? 0))) ?></strong></div>
            </div>
            <div class="attendance-reference-extra-total">
                <span>Horas extras · <?= e($monthLabel) ?></span>
                <strong><?= e(attendance_report_hours_label((int) ($monthlyHours['overtime_minutes'] ?? 0))) ?></strong>
            </div>
        </div>
        <div class="attendance-reference-hour-result">
            <span>Horas extras vigentes</span>
            <strong><?= e(attendance_report_hours_label((int) ($monthlyHours['closing_minutes'] ?? 0))) ?></strong>
            <small>HEMP + extras del mes − horas canjeadas</small>
        </div>
    </div>
    <?php if (($monthlyHours['planned_redemption_days'] ?? 0) > 0): ?>
        <p class="attendance-hours-note"><?= (int) $monthlyHours['planned_redemption_days'] ?> día(s) «A cuenta de horas» programado(s) para fechas futuras; se descontarán al llegar esas fechas.</p>
    <?php endif; ?>

    <div class="individual-report-section-title" id="detalle-diario"><h3>Detalle diario</h3><p>Marcaciones y novedades del periodo seleccionado.</p></div>
    <?php if (!$personalView && !$reportLocked): ?>
    <div class="report-early-overtime-toolbar" id="reportEarlyOvertimeToolbar" data-worker-id="<?= (int) $workerId ?>" data-date-from="<?= e($dateFrom) ?>" data-date-to="<?= e($dateTo) ?>">
        <div><strong>Entrada anticipada autorizada</strong><small>Marque las fechas para autorizar la entrada anticipada. En feriado con duplicación activa, 30 minutos autorizados equivalen a 1 hora extra. La salida se calcula aparte: al superar 15 minutos de tolerancia, se cuenta desde la hora programada y solo se duplica si el interruptor del feriado está activo.</small><small class="report-early-overtime-status" id="earlyOvertimeSelectionStatus" role="status"></small></div>
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
                    <td class="attendance-time-cell"><?= e($row['entry']) ?><?php if ($row['entry_administrative']): ?><small class="d-block text-primary">Administrativa</small><?php endif; ?></td><td class="attendance-time-cell"><?= e($row['exit']) ?><?php if (!empty($row['exit_next_day'])): ?><small class="d-block text-primary">Día siguiente</small><?php endif; ?><?php if ($row['exit_administrative']): ?><small class="d-block text-primary">Administrativa</small><?php endif; ?></td>
                    <td><?= $row['late_minutes'] > 0 ? e(attendance_report_minutes_label((int) $row['late_minutes'])) : '-' ?></td>
                    <td class="report-overtime-cell"><?= $row['overtime_minutes'] > 0 ? e(attendance_report_minutes_label((int) $row['overtime_minutes'])) : '-' ?><?php if ($row['overtime_minutes'] > 0): ?><small><?php if (!empty($row['holiday_overtime_minutes'])): ?>Feriado ×2: <?= e(attendance_report_minutes_label((int) $row['holiday_overtime_minutes'])) ?><br><?php endif; ?>Entrada<?= !empty($row['holiday_double_applied']) && $row['early_overtime_minutes'] > 0 ? ' ×2' : '' ?>: <?= e(attendance_report_minutes_label((int) $row['early_overtime_minutes'])) ?><br>Salida<?= !empty($row['holiday_double_applied']) && $row['exit_overtime_minutes'] > 0 ? ' ×2' : '' ?>: <?= e(attendance_report_minutes_label((int) $row['exit_overtime_minutes'])) ?></small><?php endif; ?></td>
                    <td class="attendance-time-cell text-nowrap"><?= ($row['entry'] !== '-' && $row['exit'] !== '-') || !empty($row['holiday_schedule_credit']) ? e(attendance_report_minutes_label((int) $row['worked_minutes'])) : '-' ?><?php if (($row['entry'] !== '-' && $row['exit'] !== '-') && ($row['calendar_event_type'] ?? '') === 'holiday'): ?><small class="d-block text-primary">Marcación neta</small><?php elseif (!empty($row['holiday_double_applied']) && !empty($row['holiday_schedule_credit'])): ?><small class="d-block text-primary">Horario ×2 por feriado</small><?php endif; ?><?php if (!empty($row['holiday_schedule_credit'])): ?><small class="d-block text-muted">Crédito de feriado según horario</small><?php endif; ?><?php if (!empty($row['lunch_deduction_minutes'])): ?><small class="d-block text-muted">Almuerzo −<?= e(attendance_report_minutes_label((int) $row['lunch_deduction_minutes'])) ?></small><?php endif; ?></td>
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

    <?php if (!$personalView && !$reportLocked): ?>
    <div class="individual-report-bottom">
        <form id="attendanceReportNoteForm" class="report-note-card report-note-card-full">
            <input type="hidden" name="worker_id" value="<?= (int) $worker['id'] ?>"><input type="hidden" name="date_from" value="<?= e($dateFrom) ?>"><input type="hidden" name="date_to" value="<?= e($dateTo) ?>">
            <label for="reportObservation">Observación general del responsable</label>
            <textarea id="reportObservation" name="observation" rows="4" maxlength="3000" placeholder="Registre aclaraciones, incidencias justificadas o comentarios para este reporte."><?= e($note['observation'] ?? '') ?></textarea>
            <div><small><?= $note ? 'Última actualización: ' . e(date('d/m/Y H:i', strtotime($note['updated_at']))) : 'Esta observación aparecerá en el PDF.' ?></small><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar observación</button></div>
        </form>
    </div>
    <?php else: ?>
    <div class="report-note-card report-note-card-full mt-3">
        <label>Observación general del responsable</label>
        <p class="mb-0"><?= nl2br(e($note['observation'] ?? 'Sin observaciones.')) ?></p>
    </div>
    <?php endif; ?>
    <section class="attendance-month-closure" aria-label="Cierre del reporte mensual">
        <div class="attendance-month-closure-heading">
            <h3>Cierre del reporte · <?= e(ucfirst($monthLabel)) ?></h3>
            <p>El administrador y el trabajador confirman por separado. Cuando ambos cierren, este mes no admitirá correcciones.</p>
        </div>
        <?php if ($closureError): ?>
            <p class="alert alert-warning mb-0">No se pudo consultar el cierre. Instale la actualización SQL del cierre mensual.</p>
        <?php else: ?>
            <div class="attendance-month-closure-cards">
                <div class="attendance-month-closure-card <?= !empty($closure['admin_closed_at']) ? 'is-closed' : '' ?>">
                    <span class="attendance-month-closure-role">Administrador</span>
                    <?php if (!empty($closure['admin_closed_at'])): ?>
                        <strong><i class="fa-solid fa-circle-check"></i> Cerrado por el administrador</strong>
                        <small><?= e($closure['admin_name'] ?: 'Administrador') ?> · <?= e(date('d/m/Y H:i', strtotime($closure['admin_closed_at']))) ?></small>
                    <?php elseif (is_admin()): ?>
                        <button class="btn btn-primary js-close-attendance-month" type="button" data-worker-id="<?= (int) $workerId ?>" data-month="<?= e($month) ?>" data-role="administrador">Cerrar como administrador</button>
                    <?php else: ?>
                        <button class="btn btn-outline-secondary" type="button" disabled aria-label="Solo el administrador puede cerrar esta parte">Pendiente: cierre del administrador</button>
                    <?php endif; ?>
                </div>
                <div class="attendance-month-closure-card <?= !empty($closure['staff_closed_at']) ? 'is-closed' : '' ?>">
                    <span class="attendance-month-closure-role">Trabajador</span>
                    <?php if (!empty($closure['staff_closed_at'])): ?>
                        <strong><i class="fa-solid fa-circle-check"></i> Cerrado por el trabajador</strong>
                        <small><?= e($closure['staff_name'] ?: 'Trabajador') ?> · <?= e(date('d/m/Y H:i', strtotime($closure['staff_closed_at']))) ?></small>
                    <?php elseif ($personalView): ?>
                        <button class="btn btn-primary js-close-attendance-month" type="button" data-worker-id="<?= (int) $workerId ?>" data-month="<?= e($month) ?>" data-role="trabajador">Cerrar como trabajador</button>
                    <?php else: ?>
                        <button class="btn btn-outline-secondary" type="button" disabled aria-label="Solo el trabajador puede cerrar esta parte">Pendiente: cierre del trabajador</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($reportLocked): ?><p class="attendance-month-closure-complete"><i class="fa-solid fa-lock"></i> Reporte cerrado por ambas partes. No se permiten correcciones de este mes.</p><?php endif; ?>
        <?php endif; ?>
    </section>
</section>
<?php if ($canEditHemp): ?>
<div class="modal fade" id="attendanceHempModal" tabindex="-1" aria-labelledby="attendanceHempModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title h5" id="attendanceHempModalTitle">Ajustar HEMP de octubre de 2026</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <form id="attendanceHempForm">
            <div class="modal-body">
                <input type="hidden" name="worker_id" value="<?= (int) $workerId ?>"><input type="hidden" name="month" value="2026-10">
                <p class="text-muted small">Corrige solo el saldo inicial de octubre. Las horas extras vigentes y los saldos de los meses siguientes se recalcularán automáticamente; septiembre y las marcaciones no cambian.</p>
                <div class="row g-2"><div class="col-6"><label class="form-label" for="hempHours">Horas</label><input class="form-control" type="number" id="hempHours" name="hours" min="0" max="9999" step="1" value="<?= intdiv(max(0, (int) ($monthlyHours['opening_minutes'] ?? 0)), 60) ?>" required></div><div class="col-6"><label class="form-label" for="hempMinutes">Minutos</label><input class="form-control" type="number" id="hempMinutes" name="minutes" min="0" max="59" step="1" value="<?= max(0, (int) ($monthlyHours['opening_minutes'] ?? 0)) % 60 ?>" required></div></div>
                <label class="form-label mt-3" for="hempReason">Motivo del ajuste</label><textarea class="form-control" id="hempReason" name="reason" maxlength="500" rows="3" required placeholder="Explique por qué se corrige el saldo inicial"></textarea>
                <?php if ($hempAdjustment): ?><p class="small text-muted mt-2 mb-0">Último ajuste: <?= e((string) ($hempAdjustment['adjusted_by'] ?? 'Administrador')) ?> · <?= e(date('d/m/Y H:i', strtotime((string) $hempAdjustment['adjusted_at']))) ?></p><?php endif; ?>
            </div>
            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar ajuste</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
