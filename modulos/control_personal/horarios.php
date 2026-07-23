<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.horarios');

$schedules = db()->query('SELECT * FROM attendance_schedules WHERE status = 1 ORDER BY name')->fetchAll();
$selectedScheduleId = (int) ($_GET['id'] ?? ($schedules[0]['id'] ?? 0));
$selectedSchedule = null;
$scheduleDays = [];

if ($selectedScheduleId > 0) {
    $stmt = db()->prepare('SELECT * FROM attendance_schedules WHERE id = :id AND status = 1');
    $stmt->execute(['id' => $selectedScheduleId]);
    $selectedSchedule = $stmt->fetch();

    if ($selectedSchedule) {
        $stmt = db()->prepare('SELECT * FROM attendance_schedule_days WHERE schedule_id = :id AND status = 1 ORDER BY day_of_week');
        $stmt->execute(['id' => $selectedScheduleId]);
        foreach ($stmt->fetchAll() as $day) {
            $scheduleDays[(int) $day['day_of_week']] = $day;
        }
    }
}

$days = [
    1 => 'Lunes',
    2 => 'Martes',
    3 => 'Miércoles',
    4 => 'Jueves',
    5 => 'Viernes',
    6 => 'Sábado',
    7 => 'Domingo',
];

function short_time(?string $time): string
{
    return $time ? substr($time, 0, 5) : '';
}

function schedule_day_minutes(array $day): int
{
    $entry = strtotime('2000-01-01 ' . ($day['entry_time'] ?? $day['entry_start'] ?? '00:00'));
    $exit = strtotime('2000-01-01 ' . ($day['exit_time'] ?? $day['exit_start'] ?? '00:00'));
    if ($entry === false || $exit === false) return 0;
    if ($exit < $entry) $exit += 86400;
    $minutes = max(0, (int) floor(($exit - $entry) / 60));
    if (!empty($day['break_start']) && !empty($day['break_end'])) {
        $breakStart = strtotime('2000-01-01 ' . $day['break_start']);
        $breakEnd = strtotime('2000-01-01 ' . $day['break_end']);
        if ($breakStart !== false && $breakEnd !== false) {
            if ($breakEnd < $breakStart) $breakEnd += 86400;
            $minutes -= max(0, (int) floor(($breakEnd - $breakStart) / 60));
        }
    }
    return max(0, $minutes);
}

function schedule_advance_minutes(?string $entryTime, ?string $entryStart): int
{
    if (!$entryTime || !$entryStart) return 0;
    $official = ((int) substr($entryTime, 0, 2) * 60) + (int) substr($entryTime, 3, 2);
    $start = ((int) substr($entryStart, 0, 2) * 60) + (int) substr($entryStart, 3, 2);
    $difference = $official - $start;
    if ($difference < 0) $difference += 1440;
    return min(180, max(0, $difference));
}

$configuredDays = count($scheduleDays);
$weeklyMinutes = array_sum(array_map('schedule_day_minutes', $scheduleDays));
$weeklyHoursLabel = intdiv($weeklyMinutes, 60) . ' h ' . str_pad((string) ($weeklyMinutes % 60), 2, '0', STR_PAD_LEFT) . ' min';

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Horarios</h1>
        <p>Catálogo y configuración semanal de horarios.</p>
    </div>
    <button class="btn btn-primary" type="button" id="newScheduleBtn"><i class="fa-solid fa-plus me-2"></i>Nuevo horario</button>
</div>

<div class="row g-3">
    <div class="col-12">
        <div class="work-panel schedule-selector-panel">
            <div class="schedule-management-toolbar">
                <div class="schedule-picker">
                    <label class="form-label" for="scheduleSelector">Horario seleccionado</label>
                    <form method="get">
                        <select class="form-select" id="scheduleSelector" name="id" <?= !$schedules ? 'disabled' : '' ?>>
                            <?php if (!$schedules): ?><option>No hay horarios registrados</option><?php endif; ?>
                            <?php foreach ($schedules as $schedule): ?>
                                <option value="<?= (int) $schedule['id'] ?>" <?= (int) $schedule['id'] === $selectedScheduleId ? 'selected' : '' ?>><?= e($schedule['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <?php if ($selectedSchedule): ?>
                    <div class="schedule-selected-actions">
                        <button class="btn btn-outline-primary js-edit-schedule" type="button" data-id="<?= (int) $selectedSchedule['id'] ?>" data-name="<?= e($selectedSchedule['name']) ?>"><i class="fa-solid fa-pen me-2"></i>Editar nombre</button>
                        <button class="btn btn-outline-danger js-delete-schedule" type="button" data-id="<?= (int) $selectedSchedule['id'] ?>"><i class="fa-solid fa-trash me-2"></i>Eliminar</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="work-panel h-100">
            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                <div><h2 class="mb-1">Configuración semanal</h2><?php if ($selectedSchedule): ?><p class="text-muted mb-0"><?= e($selectedSchedule['name']) ?></p><?php endif; ?></div>
            </div>
            <?php if ($selectedSchedule): ?>
                <div class="schedule-overview">
                    <div class="schedule-overview-configured"><i class="fa-solid fa-calendar-check"></i><span>Días configurados</span><strong><?= $configuredDays ?></strong></div>
                    <div class="schedule-overview-pending"><i class="fa-solid fa-calendar-minus"></i><span>Días sin horario</span><strong><?= 7 - $configuredDays ?></strong></div>
                    <div class="schedule-overview-hours"><i class="fa-solid fa-clock"></i><span>Horas semanales</span><strong><?= e($weeklyHoursLabel) ?></strong></div>
                </div>
                <div class="schedule-color-legend" aria-label="Leyenda de colores">
                    <span class="schedule-legend-entry"><i class="fa-solid fa-right-to-bracket"></i>Entrada</span>
                    <span class="schedule-legend-exit"><i class="fa-solid fa-right-from-bracket"></i>Salida</span>
                    <span class="schedule-legend-empty"><i class="fa-regular fa-calendar-xmark"></i>Sin horario</span>
                </div>
                <div class="weekly-schedule-grid">
                    <?php foreach ($days as $number => $label): $day = $scheduleDays[$number] ?? null; ?>
                        <div class="weekly-day <?= $day ? 'weekly-day-configured' : 'weekly-day-empty' ?>">
                            <div class="weekly-day-head">
                                <strong><?= e($label) ?></strong>
                                <button class="btn btn-sm btn-outline-primary js-config-schedule-day" type="button"
                                    data-schedule-id="<?= (int) $selectedScheduleId ?>"
                                    data-day="<?= (int) $number ?>"
                                    data-day-label="<?= e($label) ?>"
                                    data-entry-time="<?= e(short_time($day['entry_time'] ?? $day['entry_start'] ?? null)) ?>"
                                    data-entry-advance="<?= schedule_advance_minutes($day['entry_time'] ?? $day['entry_start'] ?? null, $day['entry_start'] ?? null) ?>"
                                    data-exit-time="<?= e(short_time($day['exit_time'] ?? $day['exit_start'] ?? null)) ?>"
                                    data-tolerance="<?= (int) ($day['tolerance_minutes'] ?? 0) ?>"
                                    title="Configurar"><i class="fa-solid fa-gear"></i></button>
                            </div>
                            <?php if ($day): ?>
                                <div class="schedule-time-block schedule-time-entry"><span>Entrada</span><strong><?= e(short_time($day['entry_time'] ?? $day['entry_start'])) ?></strong><small>Puede marcar desde <?= e(short_time($day['entry_start'])) ?> · Puntual hasta <?= e(short_time($day['entry_end'])) ?></small></div>
                                <div class="schedule-time-block schedule-time-exit"><span>Salida</span><strong><?= e(short_time($day['exit_time'] ?? $day['exit_start'])) ?></strong><small>Antes de esta hora se considera salida anticipada.</small></div>
                                <div class="schedule-tolerance"><i class="fa-regular fa-clock me-1"></i>Tolerancia: <?= (int) $day['tolerance_minutes'] ?> min</div>
                            <?php else: ?>
                                <div class="empty-day"><i class="fa-regular fa-calendar-xmark"></i><span>Sin horario</span><small>Configure este día si corresponde laborar.</small></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-info mb-0">Cree un horario para configurar su semana laboral.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content needs-validation" id="scheduleForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="scheduleModalTitle">Nuevo horario</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="scheduleId">
                <label class="form-label">Nombre del horario</label>
                <input class="form-control" name="name" id="scheduleName" maxlength="160" required>
                <div class="invalid-feedback">Ingrese el nombre del horario.</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="scheduleDayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content needs-validation schedule-day-modal-content" id="scheduleDayForm" novalidate>
            <div class="modal-header schedule-day-modal-header">
                <h5 class="modal-title" id="scheduleDayModalTitle">Configurar día</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body schedule-rule-modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="schedule_id" id="scheduleDayScheduleId">
                <input type="hidden" name="day_of_week" id="scheduleDayNumber">
                <div class="schedule-rule-intro">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    <div><strong>Configure las reglas del día</strong><span>Las ventanas de marcación se calcularán automáticamente.</span></div>
                </div>
                <section class="schedule-rule-section schedule-rule-entry">
                    <div class="schedule-rule-section-title"><i class="fa-solid fa-right-to-bracket"></i><div><h6>Regla de entrada</h6><p>Defina la hora oficial y el margen permitido.</p></div></div>
                    <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Hora de entrada</label>
                        <input class="form-control" type="time" name="entry_time" id="entryTime" required>
                        <div class="form-text">Inicio oficial de la jornada.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Puede marcar antes</label>
                        <div class="input-group"><input class="form-control" type="number" name="entry_advance_minutes" id="entryAdvanceMinutes" min="0" max="180" value="30" required><span class="input-group-text">min</span></div>
                        <div class="form-text">Anticipación permitida.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tolerancia</label>
                        <div class="input-group"><input class="form-control" type="number" name="tolerance_minutes" id="toleranceMinutes" min="0" max="180" value="0" required><span class="input-group-text">min</span></div>
                        <div class="form-text">Después de este margen será tardanza.</div>
                    </div>
                    </div>
                    <div class="schedule-rule-preview schedule-rule-preview-entry"><i class="fa-regular fa-clock"></i><span id="entryRulePreview">Complete la hora de entrada para calcular la ventana.</span></div>
                </section>
                <section class="schedule-rule-section schedule-rule-exit">
                    <div class="schedule-rule-section-title"><i class="fa-solid fa-right-from-bracket"></i><div><h6>Regla de salida</h6><p>Defina la hora oficial de finalización.</p></div></div>
                    <div class="row g-3"><div class="col-md-6">
                        <label class="form-label">Hora de salida</label>
                        <input class="form-control" type="time" name="exit_time" id="exitTime" required>
                        <div class="form-text">Toda salida anterior se considera anticipada.</div>
                    </div></div>
                    <div class="schedule-rule-preview schedule-rule-preview-exit"><i class="fa-solid fa-circle-info"></i><span id="exitRulePreview">Complete la hora de salida.</span></div>
                </section>
            </div>
            <div class="modal-footer justify-content-between">
                <button class="btn btn-outline-danger" type="button" id="clearScheduleDayBtn"><i class="fa-solid fa-trash me-2"></i>Quitar día</button>
                <div>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
