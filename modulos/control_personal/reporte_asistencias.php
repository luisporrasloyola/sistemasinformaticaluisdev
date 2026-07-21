<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_calendar.php';

require_module_access('control_personal.reporte_asistencias');

$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');
$dateFrom = trim((string) ($_GET['desde'] ?? $defaultFrom));
$dateTo = trim((string) ($_GET['hasta'] ?? $today));
$workerId = (int) ($_GET['trabajador_id'] ?? 0);
$companyId = (int) ($_GET['empresa_id'] ?? 0);
$attendanceStatus = trim((string) ($_GET['estado'] ?? ''));
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

function attendance_report_time(?string $time): string
{
    return $time ? substr($time, 0, 5) : '-';
}

function attendance_report_state(string $key): array
{
    return match ($key) {
        'attended' => ['label' => 'Asistió', 'class' => 'state-attended'],
        'late' => ['label' => 'Asistió', 'class' => 'state-attended'],
        'absent' => ['label' => 'Faltó', 'class' => 'state-absent'],
        'vacation' => ['label' => 'Vacaciones', 'class' => 'state-vacation'],
        'holiday' => ['label' => 'Feriado', 'class' => 'state-holiday'],
        'non_working' => ['label' => 'No laborable', 'class' => 'state-non-working'],
        'incomplete' => ['label' => 'Marcación incompleta', 'class' => 'state-incomplete'],
        default => ['label' => 'Pendiente', 'class' => 'state-pending'],
    };
}

$workers = db()->query("SELECT w.id, w.full_name, w.document_number, w.company_id, c.name AS company
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    ORDER BY w.full_name")->fetchAll();
$companies = db()->query('SELECT id, name FROM companies ORDER BY name')->fetchAll();

$assignmentConditions = ['aa.status = 1'];
$assignmentParams = [];
if ($workerId > 0) {
    $assignmentConditions[] = 'w.id = :worker_id';
    $assignmentParams['worker_id'] = $workerId;
}
if ($companyId > 0) {
    $assignmentConditions[] = 'w.company_id = :company_id';
    $assignmentParams['company_id'] = $companyId;
}

$assignmentSql = "SELECT
        aa.id AS assignment_id,
        aa.schedule_id,
        DATE(aa.created_at) AS assignment_start_date,
        w.id AS worker_id,
        w.company_id,
        w.full_name,
        w.document_number,
        c.name AS company
    FROM workers w
    JOIN attendance_assignments aa ON aa.id = (
        SELECT MAX(active_assignment.id)
        FROM attendance_assignments active_assignment
        WHERE active_assignment.worker_id = w.id
          AND active_assignment.status = 1
    )
    LEFT JOIN companies c ON c.id = w.company_id
    WHERE " . implode(' AND ', $assignmentConditions) . "
    ORDER BY w.full_name";
$stmt = db()->prepare($assignmentSql);
$stmt->execute($assignmentParams);
$assignedWorkers = $stmt->fetchAll();

$scheduleDaysBySchedule = [];
$scheduleRows = db()->query("SELECT schedule_id, day_of_week, entry_time, entry_start, entry_end,
        exit_time, exit_start, exit_end, tolerance_minutes
    FROM attendance_schedule_days
    WHERE status = 1")->fetchAll();
foreach ($scheduleRows as $scheduleRow) {
    $scheduleDaysBySchedule[(int) $scheduleRow['schedule_id']][(int) $scheduleRow['day_of_week']] = $scheduleRow;
}

$markConditions = ['am.mark_date BETWEEN :marks_from AND :marks_to'];
$markParams = ['marks_from' => $dateFrom, 'marks_to' => $dateTo];
if ($workerId > 0) {
    $markConditions[] = 'am.worker_id = :marks_worker_id';
    $markParams['marks_worker_id'] = $workerId;
}
if ($companyId > 0) {
    $markConditions[] = 'w.company_id = :marks_company_id';
    $markParams['marks_company_id'] = $companyId;
}
$stmt = db()->prepare("SELECT am.worker_id, am.mark_date, am.mark_type, am.mark_time,
        am.schedule_status, am.final_status
    FROM attendance_marks am
    JOIN workers w ON w.id = am.worker_id
    WHERE " . implode(' AND ', $markConditions) . "
    ORDER BY am.mark_date, am.mark_time");
$stmt->execute($markParams);
$marksByWorkerAndDate = [];
foreach ($stmt->fetchAll() as $mark) {
    $marksByWorkerAndDate[(int) $mark['worker_id']][(string) $mark['mark_date']][(string) $mark['mark_type']] = $mark;
}

$calendarEvents = attendance_calendar_events_between($dateFrom, $dateTo);
$rows = [];
$periodStart = new DateTimeImmutable($dateFrom);
$periodEnd = new DateTimeImmutable($dateTo);

foreach ($assignedWorkers as $worker) {
    $assignmentStart = new DateTimeImmutable((string) $worker['assignment_start_date']);
    $cursor = $assignmentStart > $periodStart ? $assignmentStart : $periodStart;

    while ($cursor <= $periodEnd) {
        $date = $cursor->format('Y-m-d');
        $weekday = (int) $cursor->format('N');
        $workerMarks = $marksByWorkerAndDate[(int) $worker['worker_id']][$date] ?? [];
        $entry = $workerMarks['entrada'] ?? null;
        $exit = $workerMarks['salida'] ?? null;
        $calendarEvent = attendance_calendar_resolve_event(
            $calendarEvents,
            $date,
            (int) $worker['worker_id'],
            (int) ($worker['company_id'] ?? 0)
        );
        $eventType = (string) ($calendarEvent['event_type'] ?? '');
        $hasSchedule = isset($scheduleDaysBySchedule[(int) $worker['schedule_id']][$weekday]);

        if ($entry || $exit) {
            if (!$entry) {
                $stateKey = 'incomplete';
            } elseif (($entry['schedule_status'] ?? '') === 'tardanza'
                || ($entry['final_status'] ?? '') === 'tardanza') {
                $stateKey = 'late';
            } else {
                $stateKey = 'attended';
            }
        } elseif (in_array($eventType, ['holiday', 'non_working', 'vacation'], true)) {
            $stateKey = match ($eventType) {
                'holiday' => 'holiday',
                'vacation' => 'vacation',
                default => 'non_working',
            };
        } elseif (!$hasSchedule) {
            $stateKey = 'non_working';
        } elseif ($date < $today) {
            $stateKey = 'absent';
        } else {
            $stateKey = 'pending';
        }

        $incidents = [];
        if ($entry && (($entry['schedule_status'] ?? '') === 'tardanza'
            || ($entry['final_status'] ?? '') === 'tardanza')) {
            $incidents[] = 'Tardanza';
        }
        if ($exit && (($exit['schedule_status'] ?? '') === 'salida_anticipada'
            || ($exit['final_status'] ?? '') === 'salida_anticipada')) {
            $incidents[] = 'Salida anticipada';
        }
        if ($entry && !$exit && $date < $today) {
            $incidents[] = 'Salida no registrada';
        }
        if (!$entry && $exit) {
            $incidents[] = 'Entrada no registrada';
        }

        if ($attendanceStatus === '' || $attendanceStatus === $stateKey) {
            $state = attendance_report_state($stateKey);
            $rows[] = [
                'date' => $date,
                'worker' => (string) $worker['full_name'],
                'document' => (string) $worker['document_number'],
                'company' => (string) ($worker['company'] ?? ''),
                'entry' => attendance_report_time($entry['mark_time'] ?? null),
                'exit' => attendance_report_time($exit['mark_time'] ?? null),
                'state_key' => $stateKey,
                'state_label' => $state['label'],
                'state_class' => $state['class'],
                'incidents' => $incidents,
            ];
        }

        $cursor = $cursor->modify('+1 day');
    }
}

usort($rows, static function (array $left, array $right): int {
    $dateOrder = strcmp((string) $right['date'], (string) $left['date']);
    return $dateOrder !== 0 ? $dateOrder : strcasecmp((string) $left['worker'], (string) $right['worker']);
});

$totals = [
    'evaluated' => 0,
    'attendances' => 0,
    'late' => 0,
    'absent' => 0,
];
foreach ($rows as $row) {
    $key = (string) $row['state_key'];
    if (in_array($key, ['attended', 'late', 'absent', 'incomplete'], true)) {
        $totals['evaluated']++;
    }
    if (in_array($key, ['attended', 'late'], true)) {
        $totals['attendances']++;
    }
    if ($key === 'late') {
        $totals['late']++;
    }
    if ($key === 'absent') {
        $totals['absent']++;
    }
}

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_asistencias_' . $dateFrom . '_' . $dateTo . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Fecha', 'Trabajador', 'Documento', 'Empresa', 'Entrada', 'Salida', 'Estado de asistencia', 'Incidencias']);
    foreach ($rows as $row) {
        fputcsv($output, [
            date('d/m/Y', strtotime((string) $row['date'])),
            $row['worker'],
            $row['document'],
            $row['company'],
            $row['entry'],
            $row['exit'],
            $row['state_label'],
            $row['incidents'] ? implode(' · ', $row['incidents']) : '-',
        ]);
    }
    fclose($output);
    exit;
}

$statusOptions = [
    'attended' => 'Asistió',
    'late' => 'Asistió con tardanza',
    'absent' => 'Faltó',
    'vacation' => 'Vacaciones',
    'holiday' => 'Feriado',
    'non_working' => 'No laborable',
    'incomplete' => 'Marcación incompleta',
    'pending' => 'Pendiente',
];
$exportQuery = $_GET;
$exportQuery['exportar'] = 'csv';

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title dashboard-title">
    <div>
        <h1>Reporte de asistencias</h1>
        <p>Resumen diario de asistencia, puntualidad y novedades por trabajador.</p>
    </div>
    <a class="btn btn-success" href="<?= APP_URL ?>/modulos/control_personal/reporte_asistencias.php?<?= e(http_build_query($exportQuery)) ?>">
        <i class="fa-solid fa-file-csv me-2"></i>Exportar CSV
    </a>
</div>

<form class="dashboard-filters attendance-report-filters" method="get">
    <div class="row g-3 align-items-end">
        <div class="col-sm-6 col-xl-2">
            <label class="form-label">Desde</label>
            <input class="form-control" type="date" name="desde" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-sm-6 col-xl-2">
            <label class="form-label">Hasta</label>
            <input class="form-control" type="date" name="hasta" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-md-6 col-xl-3">
            <label class="form-label">Trabajador</label>
            <select class="form-select" name="trabajador_id">
                <option value="0">Todos los trabajadores</option>
                <?php foreach ($workers as $worker): ?>
                    <option value="<?= (int) $worker['id'] ?>" <?= $workerId === (int) $worker['id'] ? 'selected' : '' ?>>
                        <?= e($worker['full_name'] . ' - ' . $worker['document_number']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6 col-xl-2">
            <label class="form-label">Empresa</label>
            <select class="form-select" name="empresa_id">
                <option value="0">Todas las empresas</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?= (int) $company['id'] ?>" <?= $companyId === (int) $company['id'] ? 'selected' : '' ?>><?= e($company['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6 col-xl-3">
            <label class="form-label">Estado de asistencia</label>
            <select class="form-select" name="estado">
                <option value="">Todos los estados</option>
                <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $attendanceStatus === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter me-2"></i>Aplicar filtros</button>
            <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/modulos/control_personal/reporte_asistencias.php">Limpiar</a>
        </div>
    </div>
</form>

<div class="attendance-kpi-grid mb-3">
    <div class="attendance-kpi-card kpi-blue">
        <span>Jornadas evaluadas</span><strong><?= $totals['evaluated'] ?></strong><small>Días laborables revisados</small><i class="fa-solid fa-calendar-check"></i>
    </div>
    <div class="attendance-kpi-card kpi-green">
        <span>Asistencias</span><strong><?= $totals['attendances'] ?></strong><small>Incluye ingresos con tardanza</small><i class="fa-solid fa-user-check"></i>
    </div>
    <div class="attendance-kpi-card kpi-orange">
        <span>Tardanzas</span><strong><?= $totals['late'] ?></strong><small>Ingresos fuera de tolerancia</small><i class="fa-solid fa-clock"></i>
    </div>
    <div class="attendance-kpi-card kpi-red">
        <span>Faltas</span><strong><?= $totals['absent'] ?></strong><small>Con horario y sin marcación</small><i class="fa-solid fa-user-xmark"></i>
    </div>
</div>

<div class="work-panel attendance-summary-panel">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h2 class="mb-1">Detalle diario</h2>
            <p class="text-muted mb-0">Un registro por trabajador y día.</p>
        </div>
        <span class="attendance-summary-count"><?= count($rows) ?> registros</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle dashboard-table data-table attendance-summary-table" data-order='[[0,"desc"]]'>
            <thead>
            <tr>
                <th>Fecha</th>
                <th>Trabajador</th>
                <th>Empresa</th>
                <th>Entrada</th>
                <th>Salida</th>
                <th>Estado de asistencia</th>
                <th>Incidencias</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td data-order="<?= e($row['date']) ?>"><?= e(date('d/m/Y', strtotime((string) $row['date']))) ?></td>
                    <td><strong><?= e($row['worker']) ?></strong><small class="d-block text-muted"><?= e($row['document']) ?></small></td>
                    <td><?= e($row['company']) ?></td>
                    <td class="attendance-time-cell"><?= e($row['entry']) ?></td>
                    <td class="attendance-time-cell"><?= e($row['exit']) ?></td>
                    <td><span class="attendance-state <?= e($row['state_class']) ?>"><span class="attendance-state-dot"></span><?= e($row['state_label']) ?></span></td>
                    <td>
                        <?php if ($row['incidents']): ?>
                            <span class="attendance-incidence"><span class="attendance-incidence-dot"></span><?= e(implode(' · ', $row['incidents'])) ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No se encontraron asistencias con los filtros seleccionados.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
