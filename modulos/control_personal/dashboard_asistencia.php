<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_calendar.php';
require_module_access('control_personal.dashboard');

$today = date('Y-m-d');
$selectedMonth = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$daysInMonth = (int) date('t', strtotime($monthStart));
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
        'salida_valida' => 'Salida valida',
        'salida_anticipada' => 'Salida anticipada',
        'fuera_del_radio' => 'Fuera de radio',
        'dentro_del_radio' => 'Dentro del radio',
        default => $status ? ucfirst(str_replace('_', ' ', $status)) : 'Pendiente',
    };
}

function cp_badge_class(?string $status): string
{
    return match ($status) {
        'puntual', 'salida_valida', 'completo' => 'text-bg-success',
        'tardanza', 'salida_anticipada', 'en_jornada' => 'text-bg-warning',
        'fuera_del_radio', 'ausente' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
}

$activeAssignments = (int) db()->query('SELECT COUNT(*) FROM attendance_assignments WHERE status = 1')->fetchColumn();
$activeWorkers = (int) db()->query('SELECT COUNT(DISTINCT worker_id) FROM attendance_assignments WHERE status = 1')->fetchColumn();

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
    $hasScheduleToday = !in_array($todayEventType, ['holiday', 'non_working', 'vacation'], true)
        && isset($scheduleDaysBySchedule[(int) $todayRow['schedule_id']][$todayWeekday]);
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
        DATE(aa.created_at) AS assignment_start_date,
        am.mark_date,
        am.mark_type,
        am.mark_time,
        am.final_status
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
    ORDER BY w.full_name, am.mark_date, am.mark_type");
$stmt->execute([
    'month_start' => $monthStart,
    'month_end' => $monthEnd,
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
        'assignment_start_date' => (string) ($row['assignment_start_date'] ?? ''),
        'days' => [],
    ];

    if (!empty($row['mark_date'])) {
        $day = (int) date('j', strtotime((string) $row['mark_date']));
        $matrixRows[$workerId]['days'][$day][(string) $row['mark_type']] = [
            'time' => cp_time($row['mark_time'] ?? null),
            'status' => (string) ($row['final_status'] ?? ''),
        ];
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title dashboard-title">
    <div>
        <h1>Dashboard de asistencia</h1>
        <p>Resumen operativo de marcaciones, puntualidad y cobertura diaria.</p>
    </div>
    <form class="d-flex gap-2 align-items-end" method="get">
        <div>
            <label class="form-label small fw-bold text-muted">Mes</label>
            <input class="form-control" type="month" name="mes" value="<?= e($selectedMonth) ?>">
        </div>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter me-2"></i>Filtrar</button>
    </form>
</div>

<div class="attendance-kpi-grid mb-3">
    <div class="attendance-kpi-card kpi-blue">
        <span>Asignados activos</span>
        <strong><?= $activeAssignments ?></strong>
        <small><?= $activeWorkers ?> trabajadores</small>
        <i class="fa-solid fa-users"></i>
    </div>
    <div class="attendance-kpi-card kpi-green">
        <span>Entradas de hoy</span>
        <strong><?= $entriesToday ?></strong>
        <small><?= date('d/m/Y') ?></small>
        <i class="fa-solid fa-right-to-bracket"></i>
    </div>
    <div class="attendance-kpi-card kpi-orange">
        <span>Tardanzas</span>
        <strong><?= $lateToday ?></strong>
        <small>Entradas fuera de tolerancia</small>
        <i class="fa-solid fa-clock"></i>
    </div>
    <div class="attendance-kpi-card kpi-red">
        <span>Sin entrada</span>
        <strong><?= $absentToday ?></strong>
        <small>Asignados pendientes</small>
        <i class="fa-solid fa-triangle-exclamation"></i>
    </div>
</div>

<div class="row g-3">
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
                            && !in_array($todayEventType, ['holiday', 'non_working', 'vacation'], true)
                            && isset($scheduleDaysBySchedule[(int) $row['schedule_id']][$todayWeekday]);
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

<div class="work-panel">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h2 class="mb-0">Matriz mensual</h2>
        <span class="text-muted small"><?= e(date('d/m/Y', strtotime($monthStart))) ?> - <?= e(date('d/m/Y', strtotime($monthEnd))) ?></span>
    </div>
    <div class="attendance-matrix-legend mb-3" aria-label="Leyenda de la matriz mensual">
        <span><strong>F</strong> Falta</span>
        <span><strong>FE</strong> Feriado</span>
        <span><strong>NL</strong> No laborable</span>
        <span><strong>V</strong> Vacaciones</span>
    </div>
    <div class="table-responsive attendance-matrix-wrap">
        <table class="table table-sm attendance-matrix">
            <thead>
            <tr>
                <th class="matrix-person">Personal</th>
                <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                    <?php
                    $dayDate = sprintf('%s-%02d', $selectedMonth, $day);
                    $weekdayNumber = (int) date('N', strtotime($dayDate));
                    ?>
                    <th class="matrix-day-heading">
                        <span class="matrix-weekday"><?= e($weekdayAbbreviations[$weekdayNumber]) ?></span>
                        <span class="matrix-day-number"><?= $day ?></span>
                    </th>
                <?php endfor; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($matrixRows as $worker): ?>
                <tr>
                    <td class="matrix-person">
                        <strong><?= e($worker['name']) ?></strong>
                        <span><?= e($worker['company']) ?></span>
                    </td>
                    <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                        <?php
                        $cell = $worker['days'][$day] ?? [];
                        $entry = $cell['entrada'] ?? null;
                        $exit = $cell['salida'] ?? null;
                        $cellDate = sprintf('%s-%02d', $selectedMonth, $day);
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
                        $isVacation = $calendarEventType === 'vacation';
                        $isNonWorkingDay = in_array($calendarEventType, ['holiday', 'non_working', 'vacation'], true);
                        $isAssignedPeriod = $scheduleId > 0
                            && $assignmentStartDate !== ''
                            && $cellDate >= $assignmentStartDate;
                        $isWeeklyScheduled = isset($scheduleDaysBySchedule[$scheduleId][$weekdayNumber]);
                        $isRestDay = $isAssignedPeriod
                            && !$calendarEvent
                            && !$isWeeklyScheduled;
                        $isScheduledDay = $isAssignedPeriod && !$isNonWorkingDay && $isWeeklyScheduled;
                        $isAbsence = !$entry && !$exit && $cellDate < $today && $isScheduledDay;
                        $cellClass = $isAbsence
                            ? 'matrix-absent'
                            : ($entry
                                ? ($entry['status'] === 'tardanza' ? 'matrix-late' : 'matrix-ok')
                                : ($isVacation
                                    ? 'matrix-vacation'
                                    : (($isNonWorkingDay || $isRestDay) ? 'matrix-day-off' : 'matrix-empty')));
                        ?>
                        <td class="<?= e($cellClass) ?>">
                            <?php if ($isAbsence): ?>
                                <span title="Falta: no registró marcación">F</span>
                            <?php elseif ($entry): ?>
                                <span>E <?= e($entry['time']) ?></span>
                            <?php elseif ($isNonWorkingDay): ?>
                                <span title="<?= e($calendarEvent['name'] ?? '') ?>"><?= $calendarEventType === 'holiday' ? 'FE' : ($isVacation ? 'V' : 'NL') ?></span>
                            <?php elseif ($isRestDay): ?>
                                <span title="D&iacute;a sin horario asignado">NL</span>
                            <?php endif; ?>
                            <?php if ($exit): ?>
                                <span>S <?= e($exit['time']) ?></span>
                            <?php endif; ?>
                        </td>
                    <?php endfor; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$matrixRows): ?>
                <tr><td colspan="<?= $daysInMonth + 1 ?>" class="text-muted">No hay datos para mostrar.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
