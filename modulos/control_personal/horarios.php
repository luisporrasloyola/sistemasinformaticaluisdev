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
    <div class="col-xl-4">
        <div class="work-panel h-100">
            <h2>Catálogo</h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                    <tr>
                        <th>Horario</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($schedules as $schedule): ?>
                        <tr class="<?= (int) $schedule['id'] === $selectedScheduleId ? 'table-active' : '' ?>">
                            <td><?= e($schedule['name']) ?></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/modulos/control_personal/horarios.php?id=<?= (int) $schedule['id'] ?>" title="Configurar"><i class="fa-solid fa-calendar-days"></i></a>
                                <button class="btn btn-sm btn-outline-primary js-edit-schedule" type="button" data-id="<?= (int) $schedule['id'] ?>" data-name="<?= e($schedule['name']) ?>" title="Editar"><i class="fa-solid fa-pen"></i></button>
                                <button class="btn btn-sm btn-outline-danger js-delete-schedule" type="button" data-id="<?= (int) $schedule['id'] ?>" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$schedules): ?>
                        <tr><td colspan="2" class="text-muted">No hay horarios registrados.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="work-panel h-100">
            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                <h2 class="mb-0">Horario semanal<?= $selectedSchedule ? ': ' . e($selectedSchedule['name']) : '' ?></h2>
            </div>
            <?php if ($selectedSchedule): ?>
                <div class="weekly-schedule-grid">
                    <?php foreach ($days as $number => $label): $day = $scheduleDays[$number] ?? null; ?>
                        <div class="weekly-day">
                            <div class="weekly-day-head">
                                <strong><?= e($label) ?></strong>
                                <button class="btn btn-sm btn-outline-primary js-config-schedule-day" type="button"
                                    data-schedule-id="<?= (int) $selectedScheduleId ?>"
                                    data-day="<?= (int) $number ?>"
                                    data-day-label="<?= e($label) ?>"
                                    data-entry-time="<?= e(short_time($day['entry_time'] ?? $day['entry_start'] ?? null)) ?>"
                                    data-entry-start="<?= e(short_time($day['entry_start'] ?? null)) ?>"
                                    data-entry-end="<?= e(short_time($day['entry_end'] ?? null)) ?>"
                                    data-exit-time="<?= e(short_time($day['exit_time'] ?? $day['exit_start'] ?? null)) ?>"
                                    data-exit-start="<?= e(short_time($day['exit_start'] ?? null)) ?>"
                                    data-exit-end="<?= e(short_time($day['exit_end'] ?? null)) ?>"
                                    data-tolerance="<?= (int) ($day['tolerance_minutes'] ?? 0) ?>"
                                    title="Configurar"><i class="fa-solid fa-gear"></i></button>
                            </div>
                            <?php if ($day): ?>
                                <div class="schedule-pill schedule-entry">Hora de entrada: <?= e(short_time($day['entry_time'] ?? $day['entry_start'])) ?></div>
                                <small class="text-muted d-block">Marcación: <?= e(short_time($day['entry_start'])) ?> - <?= e(short_time($day['entry_end'])) ?></small>
                                <div class="schedule-pill schedule-exit mt-2">Hora de salida: <?= e(short_time($day['exit_time'] ?? $day['exit_start'])) ?></div>
                                <small class="text-muted d-block">Marcación: <?= e(short_time($day['exit_start'])) ?> - <?= e(short_time($day['exit_end'])) ?></small>
                                <small class="text-muted">Tolerancia: <?= (int) $day['tolerance_minutes'] ?> min</small>
                            <?php else: ?>
                                <div class="empty-day">Sin horario</div>
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
        <form class="modal-content needs-validation" id="scheduleDayForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="scheduleDayModalTitle">Configurar día</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="schedule_id" id="scheduleDayScheduleId">
                <input type="hidden" name="day_of_week" id="scheduleDayNumber">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="fa-solid fa-right-to-bracket text-success"></i>
                    <h6 class="mb-0">Hora de Entrada</h6>
                </div>
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Hora de entrada</label>
                        <input class="form-control" type="time" name="entry_time" id="entryTime" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Marcación desde</label>
                        <input class="form-control" type="time" name="entry_start" id="entryStart" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Marcación hasta</label>
                        <input class="form-control" type="time" name="entry_end" id="entryEnd" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tolerancia (min)</label>
                        <input class="form-control" type="number" name="tolerance_minutes" id="toleranceMinutes" min="0" max="180" value="0" readonly tabindex="-1">
                    </div>
                </div>
                <hr class="my-4">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="fa-solid fa-right-from-bracket text-primary"></i>
                    <h6 class="mb-0">Hora de Salida</h6>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Hora de salida</label>
                        <input class="form-control" type="time" name="exit_time" id="exitTime" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Marcación desde</label>
                        <input class="form-control" type="time" name="exit_start" id="exitStart" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Marcación hasta</label>
                        <input class="form-control" type="time" name="exit_end" id="exitEnd" required>
                    </div>
                </div>
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
