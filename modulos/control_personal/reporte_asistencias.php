<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_module_access('control_personal.reporte_asistencias');

$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');
$dateFrom = trim((string) ($_GET['desde'] ?? $defaultFrom));
$dateTo = trim((string) ($_GET['hasta'] ?? $today));
$workerId = (int) ($_GET['trabajador_id'] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = $today;
if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

$catalog = attendance_report_build($dateFrom, $dateTo, 0)['workers'];
$report = $workerId > 0 ? attendance_report_build($dateFrom, $dateTo, $workerId) : null;
$worker = $report['worker'] ?? null;
$assignment = $report['assignment'] ?? null;
$summary = $report['summary'] ?? [];
$rows = $report['individual_rows'] ?? [];
$note = $report['note'] ?? null;
$query = http_build_query(['desde' => $dateFrom, 'hasta' => $dateTo, 'trabajador_id' => $workerId]);

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
            <label class="form-label">Desde</label>
            <input class="form-control" type="date" name="desde" value="<?= e($dateFrom) ?>" required>
        </div>
        <div class="col-md-6 col-xl-3">
            <label class="form-label">Hasta</label>
            <input class="form-control" type="date" name="hasta" value="<?= e($dateTo) ?>" required>
        </div>
        <div class="col-xl-4">
            <label class="form-label">Trabajador</label>
            <select class="form-select" name="trabajador_id" required>
                <option value="">Seleccione un trabajador</option>
                <?php foreach ($catalog as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" <?= $workerId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['full_name'] . ' - ' . $item['document_number']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-xl-2 d-grid">
            <button class="btn btn-primary text-nowrap" type="submit"><i class="fa-solid fa-magnifying-glass me-2"></i>Generar</button>
        </div>
    </div>
</form>

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
        <div><span>Lugar de marcación</span><strong><?= e($assignment['location_name'] ?? 'Sin lugar asignado') ?></strong></div>
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
        <div><span>Horas extras estimadas</span><strong><?= e(attendance_report_minutes_label((int) $summary['overtime_minutes'])) ?></strong></div>
    </div>

    <div class="individual-report-section-title"><h3>Detalle diario</h3><p>Marcaciones y novedades del periodo seleccionado.</p></div>
    <div class="table-responsive">
        <table class="table align-middle individual-report-table">
            <thead><tr><th>Fecha</th><th>Día</th><th>Horario</th><th>Lugar</th><th>Entrada</th><th>Salida</th><th>Tardanza</th><th>H. extras</th><th>Estado de asistencia</th><th>Estado de jornada</th><th>Observación</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e(date('d/m/Y', strtotime($row['date']))) ?></td><td><?= e($row['weekday']) ?></td><td class="attendance-time-cell text-nowrap"><?= e($row['schedule']) ?></td><td><?= e($row['location']) ?></td>
                    <td class="attendance-time-cell"><?= e($row['entry']) ?></td><td class="attendance-time-cell"><?= e($row['exit']) ?></td>
                    <td><?= $row['late_minutes'] > 0 ? e(attendance_report_minutes_label((int) $row['late_minutes'])) : '-' ?></td>
                    <td><?= $row['overtime_minutes'] > 0 ? e(attendance_report_minutes_label((int) $row['overtime_minutes'])) : '-' ?></td>
                    <td><span class="attendance-report-state <?= e($row['state_class']) ?>"><strong><?= e($row['state_code']) ?></strong><?= e($row['state_label']) ?></span></td>
                    <td><span class="journey-state <?= e($row['journey_class']) ?>"><?= e($row['journey_label']) ?></span></td>
                    <td class="report-observation-cell"><?= e($row['observation']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-4">No hay jornadas para este trabajador en el periodo seleccionado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="individual-report-bottom">
        <form id="attendanceReportNoteForm" class="report-note-card report-note-card-full">
            <input type="hidden" name="worker_id" value="<?= (int) $worker['id'] ?>"><input type="hidden" name="date_from" value="<?= e($dateFrom) ?>"><input type="hidden" name="date_to" value="<?= e($dateTo) ?>">
            <label for="reportObservation">Observación general del responsable</label>
            <textarea id="reportObservation" name="observation" rows="4" maxlength="3000" placeholder="Registre aclaraciones, incidencias justificadas o comentarios para este reporte."><?= e($note['observation'] ?? '') ?></textarea>
            <div><small><?= $note ? 'Última actualización: ' . e(date('d/m/Y H:i', strtotime($note['updated_at']))) : 'Esta observación aparecerá en el PDF.' ?></small><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar observación</button></div>
        </form>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
