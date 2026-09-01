<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_calendar.php';
require_module_access('control_personal.dashboard');

$attendanceLiveVersion = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM attendance_marks')->fetchColumn();

$today = date('Y-m-d');
$defaultRangeStart = date('Y-m-01');
$defaultRangeEnd = date('Y-m-t');
$rangeStart = (string) ($_GET['desde'] ?? $defaultRangeStart);
$rangeEnd = (string) ($_GET['hasta'] ?? $defaultRangeEnd);
$selectedCompanyId = max(0, (int) ($_GET['empresa_id'] ?? 0));
$selectedWorkerId = max(0, (int) ($_GET['trabajador_id'] ?? 0));

$dashboardCompanies = db()->query('SELECT id, name FROM companies ORDER BY name')->fetchAll();
$dashboardWorkers = db()->query("SELECT w.id, w.company_id, w.full_name, w.document_number, c.name AS company
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    ORDER BY w.full_name")->fetchAll();
$attendanceLocations = db()->query("SELECT id, name FROM attendance_locations WHERE status = 1 ORDER BY name")->fetchAll();

if ($selectedWorkerId > 0) {
    $selectedWorker = array_values(array_filter(
        $dashboardWorkers,
        static fn (array $worker): bool => (int) $worker['id'] === $selectedWorkerId
    ))[0] ?? null;

    if ($selectedWorker === null
        || ($selectedCompanyId > 0 && (int) $selectedWorker['company_id'] !== $selectedCompanyId)) {
        $selectedWorkerId = 0;
    }
}

$isValidDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

if (!$isValidDate($rangeStart)) {
    $rangeStart = $defaultRangeStart;
}
if (!$isValidDate($rangeEnd)) {
    $rangeEnd = $defaultRangeEnd;
}
if ($rangeStart > $rangeEnd) {
    [$rangeStart, $rangeEnd] = [$rangeEnd, $rangeStart];
}

$rangeDates = [];
$cursor = new DateTimeImmutable($rangeStart);
$lastDate = new DateTimeImmutable($rangeEnd);
while ($cursor <= $lastDate) {
    $rangeDates[] = $cursor->format('Y-m-d');
    $cursor = $cursor->modify('+1 day');
}
$rangeSpansMonths = substr($rangeStart, 0, 7) !== substr($rangeEnd, 0, 7);

$monthStart = $rangeStart;
$monthEnd = $rangeEnd;
$daysInMonth = count($rangeDates);
$weekdayAbbreviations = [
    1 => 'LU',
    2 => 'MA',
    3 => 'MI',
    4 => 'JU',
    5 => 'VI',
    6 => 'SA',
    7 => 'DO',
];

function cp_time(?string $time): string
{
    return $time ? substr($time, 0, 5) : '-';
}

function cp_attendance_label(?string $status): string
{
    return match ($status) {
        'puntual' => 'Puntual',
        'tardanza' => 'Tardanza',
        'salida_valida' => 'Salida',
        'salida_anticipada' => 'Salida anticipada',
        'fuera_del_radio' => 'Fuera de radio',
        'dentro_del_radio' => 'Dentro del radio',
        default => $status ? ucfirst(str_replace('_', ' ', $status)) : 'Pendiente',
    };
}

function cp_badge_class(?string $status): string
{
    return match ($status) {
        'puntual', 'completo' => 'text-bg-success',
        'salida_valida' => 'text-bg-primary',
        'tardanza', 'en_jornada' => 'text-bg-warning',
        'salida_anticipada' => 'text-bg-early-exit',
        'fuera_del_radio', 'ausente' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
}

$activeAssignments = (int) db()->query('SELECT COUNT(*) FROM attendance_assignments WHERE status = 1 AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE())')->fetchColumn();
$activeWorkers = (int) db()->query('SELECT COUNT(DISTINCT worker_id) FROM attendance_assignments WHERE status = 1 AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE())')->fetchColumn();

$stmt = db()->prepare("SELECT
        SUM(mark_type = 'entrada') AS entradas,
        SUM(mark_type = 'salida') AS salidas,
        SUM(mark_type = 'entrada' AND schedule_status = 'tardanza') AS tardanzas,
        SUM(within_radius = 0) AS fuera_radio
    FROM attendance_marks
    WHERE mark_date = :today");
$stmt->execute(['today' => $today]);
$todayStats = $stmt->fetch() ?: [];

$entriesToday = (int) ($todayStats['entradas'] ?? 0);
$exitsToday = (int) ($todayStats['salidas'] ?? 0);
$lateToday = (int) ($todayStats['tardanzas'] ?? 0);
$outsideToday = (int) ($todayStats['fuera_radio'] ?? 0);
$absentToday = max(0, $activeWorkers - $entriesToday);

$stmt = db()->prepare("SELECT
        aa.id AS assignment_id,
        aa.schedule_id,
        aa.location_id AS assignment_location_id,
        DATE(aa.created_at) AS assignment_start_date,
        aa.activity,
        w.id AS worker_id,
        w.company_id,
        w.full_name,
        w.document_number,
        c.name AS company,
        l.name AS location_name,
        s.name AS schedule_name,
        entrada.mark_time AS entry_time,
        entrada.final_status AS entry_status,
        entrada.distance_meters AS entry_distance,
        salida.mark_time AS exit_time,
        salida.final_status AS exit_status
    FROM workers w
    LEFT JOIN attendance_assignments aa ON aa.id = (
        SELECT MAX(active_assignment.id)
        FROM attendance_assignments active_assignment
        WHERE active_assignment.worker_id = w.id
          AND active_assignment.status = 1
    )
    LEFT JOIN companies c ON c.id = w.company_id
    LEFT JOIN attendance_locations l ON l.id = aa.location_id
    LEFT JOIN attendance_schedules s ON s.id = aa.schedule_id
    LEFT JOIN attendance_marks entrada ON entrada.worker_id = aa.worker_id
        AND entrada.mark_date = :today_entry
        AND entrada.mark_type = 'entrada'
    LEFT JOIN attendance_marks salida ON salida.worker_id = aa.worker_id
        AND salida.mark_date = :today_exit
        AND salida.mark_type = 'salida'
    ORDER BY w.full_name");
$stmt->execute(['today_entry' => $today, 'today_exit' => $today]);
$todayRows = $stmt->fetchAll();

$stmt = db()->prepare("SELECT mark_date, mark_type, schedule_status
    FROM attendance_marks
    WHERE mark_date >= DATE_SUB(:today_from, INTERVAL 6 DAY) AND mark_date <= :today_to
    ORDER BY mark_date");
$stmt->execute(['today_from' => $today, 'today_to' => $today]);
$trend = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime($today . " -{$i} day"));
    $trend[$date] = ['entrada' => 0, 'salida' => 0, 'tardanza' => 0];
}
foreach ($stmt->fetchAll() as $row) {
    $date = (string) $row['mark_date'];
    if (!isset($trend[$date])) {
        continue;
    }
    $trend[$date][(string) $row['mark_type']]++;
    if ($row['mark_type'] === 'entrada' && $row['schedule_status'] === 'tardanza') {
        $trend[$date]['tardanza']++;
    }
}
$maxTrendValue = max(1, ...array_values(array_map(static fn(array $row): int => max($row), $trend)));

$scheduleDaysBySchedule = [];
$scheduleDayRows = db()->query('SELECT schedule_id, day_of_week
    FROM attendance_schedule_days
    WHERE status = 1')->fetchAll();
foreach ($scheduleDayRows as $scheduleDayRow) {
    $scheduleDaysBySchedule[(int) $scheduleDayRow['schedule_id']][(int) $scheduleDayRow['day_of_week']] = true;
}
$programmedDaysByWorker = [];
try {
    $programStmt = db()->prepare("SELECT worker_id,program_date FROM attendance_programs
        WHERE status='programada' AND program_date BETWEEN :date_from AND :date_to");
    $programStmt->execute(['date_from'=>min($monthStart,$today),'date_to'=>max($monthEnd,$today)]);
    foreach ($programStmt->fetchAll() as $programRow) {
        $programmedDaysByWorker[(int)$programRow['worker_id']][(string)$programRow['program_date']] = true;
    }
} catch (Throwable $error) {
    $programmedDaysByWorker = [];
}

$calendarEvents = attendance_calendar_events_between($monthStart, $monthEnd);
$todayCalendarEvents = $today >= $monthStart && $today <= $monthEnd
    ? $calendarEvents
    : attendance_calendar_events_between($today, $today);

$absentToday = 0;
$todayWeekday = (int) date('N', strtotime($today));
foreach ($todayRows as $todayRow) {
    if (empty($todayRow['assignment_id']) || $today < (string) $todayRow['assignment_start_date']) {
        continue;
    }
    $todayEvent = attendance_calendar_resolve_event(
        $todayCalendarEvents,
        $today,
        (int) $todayRow['worker_id'],
        (int) ($todayRow['company_id'] ?? 0)
    );
    $todayEventType = (string) ($todayEvent['event_type'] ?? '');
    $hasScheduleToday = !attendance_calendar_is_non_working_event($todayEventType)
        && (isset($programmedDaysByWorker[(int)$todayRow['worker_id']][$today])
            || isset($scheduleDaysBySchedule[(int) $todayRow['schedule_id']][$todayWeekday]));
    if ($hasScheduleToday && empty($todayRow['entry_time'])) {
        $absentToday++;
    }
}

$stmt = db()->prepare("SELECT
        w.id AS worker_id,
        w.company_id,
        w.full_name,
        c.name AS company,
        aa.schedule_id,
        aa.location_id AS assignment_location_id,
        DATE(aa.created_at) AS assignment_start_date,
        am.mark_date,
        am.mark_type,
        am.mark_time,
        am.final_status,
        am.location_id AS mark_location_id,
        l.name AS location_name
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    LEFT JOIN attendance_assignments aa ON aa.id = (
        SELECT MAX(active_assignment.id)
        FROM attendance_assignments active_assignment
        WHERE active_assignment.worker_id = w.id
          AND active_assignment.status = 1
    )
    LEFT JOIN attendance_marks am ON am.worker_id = w.id
        AND am.mark_date BETWEEN :month_start AND :month_end
    LEFT JOIN attendance_locations l ON l.id = am.location_id
    WHERE (:filter_company = 0 OR w.company_id = :company_id)
      AND (:filter_worker = 0 OR w.id = :worker_id)
    ORDER BY w.full_name, am.mark_date, am.mark_type, am.mark_time, am.id");
$stmt->execute([
    'month_start' => $monthStart,
    'month_end' => $monthEnd,
    'filter_company' => $selectedCompanyId,
    'company_id' => $selectedCompanyId,
    'filter_worker' => $selectedWorkerId,
    'worker_id' => $selectedWorkerId,
]);
$matrixRows = [];
foreach ($stmt->fetchAll() as $row) {
    $workerId = (int) $row['worker_id'];
    $matrixRows[$workerId] ??= [
        'name' => (string) $row['full_name'],
        'company' => (string) ($row['company'] ?? ''),
        'worker_id' => $workerId,
        'company_id' => (int) ($row['company_id'] ?? 0),
        'schedule_id' => (int) ($row['schedule_id'] ?? 0),
        'assignment_location_id' => (int) ($row['assignment_location_id'] ?? 0),
        'assignment_start_date' => (string) ($row['assignment_start_date'] ?? ''),
        'days' => [],
    ];

    if (!empty($row['mark_date'])) {
        $markDate = (string) $row['mark_date'];
        $markType = (string) $row['mark_type'];
        $hasExtremeMark = isset($matrixRows[$workerId]['days'][$markDate][$markType]);
        if ($markType !== 'entrada' || !$hasExtremeMark) {
            $matrixRows[$workerId]['days'][$markDate][$markType] = [
                'time' => cp_time($row['mark_time'] ?? null),
                'status' => (string) ($row['final_status'] ?? ''),
                'location' => (string) ($row['location_name'] ?? ''),
                'location_id' => (int) ($row['mark_location_id'] ?? 0),
            ];
        }
    }
}

$manualAdjustmentsByWorkerDate = [];
try {
    $manualAuditStmt = db()->prepare("SELECT a.worker_id, a.mark_date, a.created_at, u.name AS administrator
        FROM attendance_manual_adjustments a
        LEFT JOIN users u ON u.id = a.adjusted_by_user_id
        WHERE a.mark_date BETWEEN :date_from AND :date_to
          AND a.id = (
              SELECT MAX(last_adjustment.id)
              FROM attendance_manual_adjustments last_adjustment
              WHERE last_adjustment.worker_id = a.worker_id
                AND last_adjustment.mark_date = a.mark_date
          )");
    $manualAuditStmt->execute(['date_from' => $monthStart, 'date_to' => $monthEnd]);
    foreach ($manualAuditStmt->fetchAll() as $auditRow) {
        $manualAdjustmentsByWorkerDate[(int) $auditRow['worker_id']][(string) $auditRow['mark_date']] = $auditRow;
    }
} catch (Throwable $error) {
    $manualAdjustmentsByWorkerDate = [];
}
$matrixSummary = [];
foreach ($matrixRows as $workerId => $worker) {
    $matrixSummary[$workerId] = [
        'name' => (string) $worker['name'],
        'company' => (string) $worker['company'],
        'attendances' => 0,
        'late' => 0,
        'early_exit' => 0,
        'late_early_exit' => 0,
        'absences' => 0,
    ];
}

$attendancePeriodTotals = [
    'attendances' => 0,
    'late' => 0,
    'early_exit' => 0,
    'late_early_exit' => 0,
    'absences' => 0,
];

foreach ($matrixRows as $workerId => $worker) {
    foreach ($rangeDates as $cellDate) {
        $cell = $worker['days'][$cellDate] ?? [];
        $entry = $cell['entrada'] ?? null;
        $exit = $cell['salida'] ?? null;
        $weekdayNumber = (int) date('N', strtotime($cellDate));
        $scheduleId = (int) $worker['schedule_id'];
        $assignmentStartDate = (string) $worker['assignment_start_date'];
        $calendarEvent = attendance_calendar_resolve_event(
            $calendarEvents,
            $cellDate,
            (int) $worker['worker_id'],
            (int) $worker['company_id']
        );
        $isNonWorkingDay = attendance_calendar_is_non_working_event((string) ($calendarEvent['event_type'] ?? ''));
        $isAssignedPeriod = $scheduleId > 0
            && $assignmentStartDate !== ''
            && $cellDate >= $assignmentStartDate;
        $isWeeklyScheduled = isset($scheduleDaysBySchedule[$scheduleId][$weekdayNumber]);
        $isExplicitlyProgrammed = isset($programmedDaysByWorker[(int)$worker['worker_id']][$cellDate]);
        $isScheduledDay = !$isNonWorkingDay && ($isExplicitlyProgrammed || ($isAssignedPeriod && $isWeeklyScheduled));
        $isAbsence = !$entry && !$exit && $cellDate < $today && $isScheduledDay;
        $isLate = ($entry['status'] ?? '') === 'tardanza';
        $isEarlyExit = ($exit['status'] ?? '') === 'salida_anticipada';

        $attendanceCode = '';
        if ($isAbsence) {
            $attendanceCode = 'F';
        } elseif ($entry || $exit) {
            if ($isLate && $isEarlyExit) {
                $attendanceCode = 'ATSA';
            } elseif ($isLate) {
                $attendanceCode = 'T';
            } elseif ($isEarlyExit) {
                $attendanceCode = 'ASA';
            } else {
                $attendanceCode = 'A';
            }
        }

        $summaryKey = match ($attendanceCode) {
            'A' => 'attendances',
            'T' => 'late',
            'ASA' => 'early_exit',
            'ATSA' => 'late_early_exit',
            'F' => 'absences',
            default => null,
        };

        if ($summaryKey !== null) {
            $matrixSummary[$workerId][$summaryKey]++;
            $attendancePeriodTotals[$summaryKey]++;
        }
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title dashboard-title">
    <div>
        <h1>Dashboard de asistencia</h1>
        <p>Resumen operativo de asistencias, faltas, tardanzas y cobertura diaria.</p>
    </div>
    <form class="attendance-dashboard-filters" method="get">
        <div class="attendance-filter-company">
            <label class="form-label small fw-bold text-muted" for="attendanceCompanyFilter">Por empresa</label>
            <select class="form-select select2-searchable" id="attendanceCompanyFilter" name="empresa_id"
                data-placeholder="Todas las empresas" data-no-results="No se encontraron empresas">
                <option value="0">Todas las empresas</option>
                <?php foreach ($dashboardCompanies as $company): ?>
                    <option value="<?= (int) $company['id'] ?>" <?= $selectedCompanyId === (int) $company['id'] ? 'selected' : '' ?>>
                        <?= e($company['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="attendance-filter-worker">
            <label class="form-label small fw-bold text-muted" for="attendanceWorkerFilter">Por trabajador</label>
            <select class="form-select select2-searchable" id="attendanceWorkerFilter" name="trabajador_id"
                data-placeholder="Todo el personal" data-no-results="No se encontraron trabajadores">
                <option value="0">Todo el personal</option>
                <?php foreach ($dashboardWorkers as $worker): ?>
                    <option value="<?= (int) $worker['id'] ?>" data-company-id="<?= (int) $worker['company_id'] ?>"
                        <?= $selectedWorkerId === (int) $worker['id'] ? 'selected' : '' ?>>
                        <?= e($worker['full_name'] . ' - ' . $worker['document_number']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label small fw-bold text-muted" for="attendanceDateFrom">Desde</label>
            <input class="form-control" id="attendanceDateFrom" type="date" name="desde" value="<?= e($rangeStart) ?>" required>
        </div>
        <div>
            <label class="form-label small fw-bold text-muted" for="attendanceDateTo">Hasta</label>
            <input class="form-control" id="attendanceDateTo" type="date" name="hasta" value="<?= e($rangeEnd) ?>" required>
        </div>
        <button class="btn btn-primary attendance-filter-submit" type="submit"><i class="fa-solid fa-filter me-2"></i>Filtrar</button>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const companyField = document.getElementById('attendanceCompanyFilter');
    const workerField = document.getElementById('attendanceWorkerFilter');
    if (!companyField || !workerField) return;

    const workers = Array.from(workerField.options).slice(1).map((option) => ({
        value: option.value,
        label: option.textContent.trim(),
        companyId: option.dataset.companyId || '0'
    }));

    const refreshWorkers = (keepSelection = false) => {
        const companyId = companyField.value;
        const previousValue = keepSelection ? workerField.value : '0';
        const visibleWorkers = companyId === '0'
            ? workers
            : workers.filter((worker) => worker.companyId === companyId);

        workerField.replaceChildren(new Option('Todo el personal', '0'));
        visibleWorkers.forEach((worker) => {
            workerField.add(new Option(
                worker.label,
                worker.value,
                false,
                worker.value === previousValue
            ));
        });

        if (!visibleWorkers.some((worker) => worker.value === previousValue)) {
            workerField.value = '0';
        }

        if (window.jQuery && window.jQuery.fn.select2) {
            window.jQuery(workerField).trigger('change.select2');
        }
    };

    refreshWorkers(true);
    if (window.jQuery) {
        window.jQuery(companyField).on('change.attendanceDashboard', () => refreshWorkers(false));
    } else {
        companyField.addEventListener('change', () => refreshWorkers(false));
    }
});
</script>

<div class="attendance-kpi-grid attendance-kpi-grid-five mb-3" id="attendanceLiveKpis" data-live-version="<?= $attendanceLiveVersion ?>">
    <div class="attendance-kpi-card kpi-green">
        <span>Asistencias</span>
        <strong><?= $attendancePeriodTotals['attendances'] ?></strong>
        <small>Jornadas sin incidencias</small>
        <i class="fa-solid fa-circle-check"></i>
    </div>
    <div class="attendance-kpi-card kpi-red">
        <span>Faltas</span>
        <strong><?= $attendancePeriodTotals['absences'] ?></strong>
        <small>Ausencias del periodo</small>
        <i class="fa-solid fa-user-xmark"></i>
    </div>
    <div class="attendance-kpi-card kpi-yellow">
        <span>Tardanzas</span>
        <strong><?= $attendancePeriodTotals['late'] ?></strong>
        <small>Entradas fuera de tolerancia</small>
        <i class="fa-solid fa-clock"></i>
    </div>
    <div class="attendance-kpi-card kpi-orange">
        <span>Asisti&oacute; con salida anticipada</span>
        <strong><?= $attendancePeriodTotals['early_exit'] ?></strong>
        <small>Salidas antes del horario</small>
        <i class="fa-solid fa-arrow-right-from-bracket"></i>
    </div>
    <div class="attendance-kpi-card kpi-rose">
        <span>Asistió con tardanza y salida anticipada</span>
        <strong><?= $attendancePeriodTotals['late_early_exit'] ?></strong>
        <small>Jornadas con ambas incidencias</small>
        <i class="fa-solid fa-triangle-exclamation"></i>
    </div>
</div>

<div class="attendance-dashboard-content">
<div class="row g-3 attendance-dashboard-secondary d-none" aria-hidden="true">
    <div class="col-xl-7">
        <div class="work-panel h-100">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <h2 class="mb-0">Estado de hoy</h2>
                <span class="badge text-bg-light border">Salidas: <?= $exitsToday ?> | Fuera de radio: <?= $outsideToday ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle dashboard-table">
                    <thead>
                    <tr>
                        <th>Personal</th>
                        <th>Empresa</th>
                        <th>Lugar</th>
                        <th>Entrada</th>
                        <th>Salida</th>
                        <th>Estado</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($todayRows as $row): ?>
                        <?php
                        $todayEvent = attendance_calendar_resolve_event(
                            $todayCalendarEvents,
                            $today,
                            (int) $row['worker_id'],
                            (int) ($row['company_id'] ?? 0)
                        );
                        $todayEventType = (string) ($todayEvent['event_type'] ?? '');
                        $hasScheduleToday = !empty($row['assignment_id'])
                            && $today >= (string) $row['assignment_start_date']
                            && !attendance_calendar_is_non_working_event($todayEventType)
                            && (isset($programmedDaysByWorker[(int)$row['worker_id']][$today])
                                || isset($scheduleDaysBySchedule[(int) $row['schedule_id']][$todayWeekday]));
                        $status = empty($row['assignment_id']) ? 'sin_asignar' : ($hasScheduleToday ? 'ausente' : 'sin_horario');
                        $label = empty($row['assignment_id'])
                            ? 'Sin asignar'
                            : ($hasScheduleToday
                                ? 'Sin entrada'
                                : ($todayEvent
                                    ? attendance_calendar_event_label((string) $todayEvent['event_type'])
                                    : 'Sin horario'));
                        if ($hasScheduleToday && !empty($row['entry_time']) && !empty($row['exit_time'])) {
                            $status = $row['entry_status'] === 'tardanza' ? 'tardanza' : 'completo';
                            $label = $row['entry_status'] === 'tardanza' ? 'Tardanza' : 'Completo';
                        } elseif ($hasScheduleToday && !empty($row['entry_time'])) {
                            $status = $row['entry_status'] === 'tardanza' ? 'tardanza' : 'en_jornada';
                            $label = $row['entry_status'] === 'tardanza' ? 'Tardanza' : 'En jornada';
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($row['full_name']) ?></strong>
                                <span class="text-muted small d-block"><?= e($row['document_number']) ?></span>
                            </td>
                            <td><?= e($row['company'] ?: '-') ?></td>
                            <td><?= e($row['location_name'] ?: '-') ?></td>
                            <td><?= e(cp_time($row['entry_time'] ?? null)) ?></td>
                            <td><?= e(cp_time($row['exit_time'] ?? null)) ?></td>
                            <td><span class="badge <?= cp_badge_class($status) ?>"><?= e($label) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$todayRows): ?>
                        <tr><td colspan="6" class="text-muted">No hay personal registrado.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="work-panel h-100">
            <h2>Últimos 7 días</h2>
            <div class="attendance-mini-bars">
                <?php foreach ($trend as $date => $values): ?>
                    <div class="mini-bar-row">
                        <span><?= e(date('d/m', strtotime($date))) ?></span>
                        <div class="mini-bar-track">
                            <i class="bar-entry" style="width: <?= (int) (($values['entrada'] / $maxTrendValue) * 100) ?>%"></i>
                            <i class="bar-late" style="width: <?= (int) (($values['tardanza'] / $maxTrendValue) * 100) ?>%"></i>
                        </div>
                        <strong><?= (int) $values['entrada'] ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="attendance-legend mt-3">
                <span><i class="legend-entry"></i>Entradas</span>
                <span><i class="legend-late"></i>Tardanzas</span>
            </div>
        </div>
    </div>
</div>

<div class="work-panel attendance-dashboard-matrix" id="attendanceLiveMatrix">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h2 class="mb-0">Matriz mensual</h2>
        <span class="text-muted small"><?= e(date('d/m/Y', strtotime($monthStart))) ?> - <?= e(date('d/m/Y', strtotime($monthEnd))) ?></span>
    </div>
    <div class="attendance-matrix-legend mb-3" aria-label="Leyenda de la matriz mensual">
        <span class="legend-attended"><strong>A</strong> Asisti&oacute;</span>
        <span class="legend-absent"><strong>F</strong> Falta</span>
        <span class="legend-attendance-warning"><strong>T</strong> Tarde</span>
        <span class="legend-early-exit"><strong>ASA</strong> Asisti&oacute; con salida anticipada</span>
        <span class="legend-attendance-critical"><strong>ATSA</strong> Asistió con tardanza y salida anticipada</span>
        <span class="legend-vacation"><strong>VAC</strong> Vacaciones</span>
        <span class="legend-permission"><strong>PER</strong> Permiso</span>
        <span class="legend-rest"><strong>D</strong> Descanso</span>
        <span class="legend-holiday"><strong>FER</strong> Feriado</span>
        <span class="legend-non-working"><strong>NL</strong> No laborable</span>
    </div>
    <div class="table-responsive attendance-matrix-wrap">
                <table class="table table-sm attendance-matrix" style="--matrix-days: <?= count($rangeDates) ?>;">
            <thead>
            <tr>
                <th class="matrix-person">Personal</th>
                <?php foreach ($rangeDates as $dayDate): ?>
                    <?php
                    $weekdayNumber = (int) date('N', strtotime($dayDate));
                    $dayLabel = $rangeSpansMonths ? date('j/n', strtotime($dayDate)) : date('j', strtotime($dayDate));
                    ?>
                    <th class="matrix-day-heading">
                        <span class="matrix-weekday"><?= e($weekdayAbbreviations[$weekdayNumber]) ?></span>
                        <span class="matrix-day-number"><?= e($dayLabel) ?></span>
                    </th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($matrixRows as $worker): ?>
                <tr>
                    <td class="matrix-person">
                        <strong><?= e($worker['name']) ?></strong>
                        <span><?= e($worker['company']) ?></span>
                    </td>
                    <?php foreach ($rangeDates as $cellDate): ?>
                        <?php
                        $cell = $worker['days'][$cellDate] ?? [];
                        $entry = $cell['entrada'] ?? null;
                        $exit = $cell['salida'] ?? null;
                        $weekdayNumber = (int) date('N', strtotime($cellDate));
                        $scheduleId = (int) $worker['schedule_id'];
                        $assignmentStartDate = (string) $worker['assignment_start_date'];
                        $calendarEvent = attendance_calendar_resolve_event(
                            $calendarEvents,
                            $cellDate,
                            (int) $worker['worker_id'],
                            (int) $worker['company_id']
                        );
                        $calendarEventType = (string) ($calendarEvent['event_type'] ?? '');
                        $isNonWorkingDay = attendance_calendar_is_non_working_event($calendarEventType);
                        $isAssignedPeriod = $scheduleId > 0
                            && $assignmentStartDate !== ''
                            && $cellDate >= $assignmentStartDate;
                        $isWeeklyScheduled = isset($scheduleDaysBySchedule[$scheduleId][$weekdayNumber]);
                        $isExplicitlyProgrammed = isset($programmedDaysByWorker[(int)$worker['worker_id']][$cellDate]);
                        $isRestDay = $isAssignedPeriod
                            && !$calendarEvent
                            && !$isWeeklyScheduled
                            && !$isExplicitlyProgrammed;
                        $isScheduledDay = !$isNonWorkingDay && ($isExplicitlyProgrammed || ($isAssignedPeriod && $isWeeklyScheduled));
                        $isAbsence = !$entry && !$exit && $cellDate < $today && $isScheduledDay;
                        $isLate = ($entry['status'] ?? '') === 'tardanza';
                        $isEarlyExit = ($exit['status'] ?? '') === 'salida_anticipada';
                        $attendanceCode = '';
                        $attendanceLabel = '';
                        $incidents = [];

                        if ($isAbsence) {
                            $attendanceCode = 'F';
                            $attendanceLabel = 'Faltó';
                            $incidents[] = 'No registró asistencia';
                        } elseif ($entry || $exit) {
                            if ($isLate) $incidents[] = 'Tardanza';
                            if ($isEarlyExit) $incidents[] = 'Salida anticipada';
                            if (!$entry) $incidents[] = 'Entrada no registrada';
                            if (!$exit) $incidents[] = 'Salida no registrada';

                            if ($isLate && $isEarlyExit) {
                                $attendanceCode = 'ATSA';
                                $attendanceLabel = 'Asistió con tardanza y salida anticipada';
                            } elseif ($isLate) {
                                $attendanceCode = 'T';
                                $attendanceLabel = 'Asistió con tardanza';
                            } elseif ($isEarlyExit) {
                                $attendanceCode = 'ASA';
                                $attendanceLabel = 'Asistió con salida anticipada';
                            } else {
                                $attendanceCode = 'A';
                                $attendanceLabel = 'Asistió sin incidencias';
                            }
                        }

                        $cellClass = match ($attendanceCode) {
                            'F' => 'matrix-absent',
                            'T' => 'matrix-attendance-warning',
                            'ASA' => 'matrix-early-exit',
                            'ATSA' => 'matrix-attendance-critical',
                            'A' => 'matrix-ok',
                            default => match ($calendarEventType) {
                                'vacation' => 'matrix-vacation',
                                'permission' => 'matrix-permission',
                                'rest' => 'matrix-rest',
                                'holiday' => 'matrix-holiday',
                                'non_working' => 'matrix-non-working',
                                default => 'matrix-empty',
                            },
                        };
                        if (!$isAssignedPeriod && $attendanceCode === '' && !$calendarEvent) {
                            $cellClass = 'matrix-empty';
                        }
                        $calendarLabel = $calendarEvent
                            ? attendance_calendar_event_label($calendarEventType)
                            : (!$isAssignedPeriod
                                ? 'Sin asignación activa para esta fecha'
                                : ($isRestDay ? 'Sin horario configurado' : 'Sin marcaciones'));
                        $detailLabel = $attendanceLabel ?: $calendarLabel;
                        $detailIncidents = $calendarEvent
                            ? (string) ($calendarEvent['name'] ?? 'Sin incidencias')
                            : (!$isAssignedPeriod
                                ? 'No aplica'
                                : ($incidents ? implode(' / ', $incidents) : 'Sin incidencias'));
                        $manualAudit = $manualAdjustmentsByWorkerDate[(int) $worker['worker_id']][$cellDate] ?? null;
                        $entryLocation = (string) ($entry['location'] ?? '');
                        $exitLocation = (string) ($exit['location'] ?? '');
                        $detailLocation = $entryLocation !== '' && $exitLocation !== '' && $entryLocation !== $exitLocation
                            ? $entryLocation . ' → ' . $exitLocation
                            : ($exitLocation ?: ($entryLocation ?: '-'));                        ?>
                        <td class="<?= e($cellClass) ?> js-attendance-matrix-cell"
                            role="button"
                            tabindex="0"
                            data-date="<?= e(date('d/m/Y', strtotime($cellDate))) ?>"
                            data-date-iso="<?= e($cellDate) ?>"
                            data-worker-id="<?= (int) $worker['worker_id'] ?>"
                            data-manual-enabled="<?= is_admin() ? '1' : '0' ?>"
                            data-adjusted-by="<?= e((string) ($manualAudit['administrator'] ?? '')) ?>"
                            data-adjusted-at="<?= $manualAudit ? e(date('d/m/Y H:i', strtotime((string) $manualAudit['created_at']))) : '' ?>"                            data-worker="<?= e($worker['name']) ?>"
                            data-company="<?= e($worker['company']) ?>"
                            data-entry="<?= e($entry['time'] ?? '-') ?>"
                            data-exit="<?= e($exit['time'] ?? '-') ?>"
                            data-location="<?= e($detailLocation) ?>"
                            data-location-id="<?= (int) ($exit['location_id'] ?? $entry['location_id'] ?? $worker['assignment_location_id']) ?>"
                            data-code="<?= e($attendanceCode ?: attendance_calendar_event_abbreviation($calendarEventType)) ?>"
                            data-status="<?= e(strip_tags($detailLabel)) ?>"
                            data-incidents="<?= e($detailIncidents) ?>"
                            aria-label="Ver detalle de <?= e($worker['name']) ?> del <?= e(date('d/m/Y', strtotime($cellDate))) ?>">
                            <?php if ($attendanceCode !== ''): ?>
                                <span title="<?= e(strip_tags($attendanceLabel)) ?>"><?= e($attendanceCode) ?></span>
                            <?php elseif ($isNonWorkingDay): ?>
                                <span title="<?= e($calendarEvent['name'] ?? '') ?>"><?= e(attendance_calendar_event_abbreviation($calendarEventType)) ?></span>
                            <?php elseif (!$isAssignedPeriod): ?>
                                <span title="Sin asignación activa para esta fecha">-</span>
                            <?php elseif ($isRestDay): ?>
                                <span title="Sin horario configurado">-</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$matrixRows): ?>
                <tr><td colspan="<?= $daysInMonth + 1 ?>" class="text-muted">No hay datos para mostrar.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<section class="work-panel attendance-monthly-summary" id="attendanceLiveSummary" aria-labelledby="attendanceMonthlySummaryTitle">
        <div class="attendance-monthly-summary-header">
            <div>
                <h3 id="attendanceMonthlySummaryTitle">Resumen por fechas seleccionadas</h3>
                <p>Totales por trabajador durante el periodo seleccionado.</p>
            </div>
            <span><?= count($matrixSummary) ?> trabajador<?= count($matrixSummary) === 1 ? '' : 'es' ?></span>
        </div>

        <div class="table-responsive attendance-monthly-summary-wrap">
            <table class="attendance-monthly-summary-table">
                <thead>
                <tr>
                    <th>Personal</th>
                    <th>Asistencias</th>
                    <th>Tardanzas</th>
                    <th>Faltas</th>
                    <th>Asisti&oacute; con salida anticipada</th>
                    <th>Asistió con tardanza y salida anticipada</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($matrixSummary as $summary): ?>
                    <tr>
                        <td class="attendance-summary-worker">
                            <strong><?= e($summary['name']) ?></strong>
                            <span><?= e($summary['company']) ?></span>
                        </td>
                        <td>
                            <span class="attendance-summary-metric metric-attendance">
                                <i class="fa-solid fa-check" aria-hidden="true"></i>
                                <strong><?= (int) $summary['attendances'] ?></strong>
                            </span>
                        </td>
                        <td>
                            <span class="attendance-summary-metric metric-late">
                                <i class="fa-solid fa-clock" aria-hidden="true"></i>
                                <strong><?= (int) $summary['late'] ?></strong>
                            </span>
                        </td>
                        <td>
                            <span class="attendance-summary-metric metric-absence">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                <strong><?= (int) $summary['absences'] ?></strong>
                            </span>
                        </td>
                        <td>
                            <span class="attendance-summary-metric metric-early-exit">
                                <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
                                <strong><?= (int) $summary['early_exit'] ?></strong>
                            </span>
                        </td>
                        <td>
                            <span class="attendance-summary-metric metric-late-early-exit">
                                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                                <strong><?= (int) $summary['late_early_exit'] ?></strong>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$matrixSummary): ?>
                    <tr>
                        <td colspan="6" class="attendance-summary-empty">No hay datos para resumir.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="modal fade" id="attendanceMatrixDetailModal" tabindex="-1" aria-labelledby="attendanceMatrixDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable attendance-matrix-modal-dialog">
        <div class="modal-content attendance-matrix-detail-modal">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="attendanceMatrixDetailTitle">Detalle de asistencia</h2>
                    <small class="text-muted" id="matrixDetailDate"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="attendance-detail-layout">
                    <section class="attendance-detail-overview" aria-label="Resumen de asistencia">
                        <div class="attendance-detail-person"><span class="attendance-detail-person-icon"><i class="fa-solid fa-user-check"></i></span><div><strong id="matrixDetailWorker"></strong><span id="matrixDetailCompany"></span></div></div>
                        <div class="attendance-detail-status"><span class="badge" id="matrixDetailBadge"></span><strong id="matrixDetailStatus"></strong></div>
                        <dl class="attendance-detail-grid mb-0">
                            <dt><i class="fa-regular fa-clock"></i> Entrada</dt><dd id="matrixDetailEntry"></dd>
                            <dt><i class="fa-solid fa-arrow-right-from-bracket"></i> Salida</dt><dd id="matrixDetailExit"></dd>
                            <dt><i class="fa-solid fa-location-dot"></i> Ruta</dt><dd id="matrixDetailLocation"></dd>
                            <dt><i class="fa-solid fa-circle-info"></i> Incidencias</dt><dd id="matrixDetailIncidents"></dd>
                        </dl>
                        <?php if (is_admin()): ?><div class="attendance-manual-audit d-none" id="matrixManualAudit"><span><i class="fa-solid fa-shield-halved"></i></span><div><small>Última actualización administrativa</small><strong id="matrixManualAuditUser"></strong><time id="matrixManualAuditDate"></time></div></div><?php endif; ?>
                    </section>
                    <?php if (is_admin()): ?>
                    <form id="attendanceManualCorrectionForm" class="attendance-manual-form">
                        <input type="hidden" name="worker_id" id="matrixManualWorkerId"><input type="hidden" name="mark_date" id="matrixManualDate">
                        <div class="attendance-manual-heading"><span><i class="fa-solid fa-user-clock"></i></span><div><strong>Corrección administrativa</strong><small>Corrige la primera entrada y la última salida; los puntos de ruta se conservan.</small></div></div>
                        <fieldset class="attendance-result-choice"><legend>Resultado de asistencia</legend><div class="attendance-result-options">
                            <label class="attendance-result-option result-on-time" for="matrixManualResultPunctual"><input type="radio" name="attendance_result" id="matrixManualResultPunctual" value="puntual" required><span><i class="fa-solid fa-circle-check"></i><strong>Asistió sin incidencias</strong></span></label>
                            <label class="attendance-result-option result-late" for="matrixManualResultLate"><input type="radio" name="attendance_result" id="matrixManualResultLate" value="tardanza" required><span><i class="fa-solid fa-clock"></i><strong>Asistió con tardanza</strong></span></label>
                        </div></fieldset>
                        <div class="attendance-manual-fields">
                            <div><label class="form-label" for="matrixManualEntry">Primera entrada</label><input class="form-control" type="time" name="entry_time" id="matrixManualEntry"></div>
                            <div><label class="form-label" for="matrixManualExit">Última salida</label><input class="form-control" type="time" name="exit_time" id="matrixManualExit"></div>
                            <div class="attendance-manual-location"><label class="form-label" for="matrixManualLocation">Lugar para marcación faltante</label><select class="form-select select2-searchable" name="location_id" id="matrixManualLocation" required data-placeholder="Buscar lugar de marcación" data-no-results="No se encontraron lugares"><option value="">Seleccione un lugar</option><?php foreach ($attendanceLocations as $location): ?><option value="<?= (int) $location['id'] ?>"><?= e($location['name']) ?></option><?php endforeach; ?></select><small class="attendance-location-help">Las ubicaciones ya registradas y los puntos de ruta no serán modificados.</small></div>
                            <div class="attendance-manual-reason"><label class="form-label" for="matrixManualReason">Motivo de la corrección</label><textarea class="form-control" name="reason" id="matrixManualReason" rows="2" maxlength="500" required placeholder="Ej.: El servidor no estuvo disponible durante el ingreso."></textarea></div>
                        </div>
                        <div class="attendance-manual-actions"><small><i class="fa-solid fa-lock"></i> El cambio quedará auditado con su usuario y fecha.</small><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-1"></i> Guardar corrección</button></div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
