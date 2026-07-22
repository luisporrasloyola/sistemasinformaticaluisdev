<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_calendar.php';
require_module_access('control_personal.calendario_laboral');

$selectedMonth = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$stmt = db()->prepare("SELECT acd.*, c.name AS company_name, w.full_name AS worker_name,
        u.name AS created_by_name
    FROM attendance_calendar_days acd
    LEFT JOIN companies c ON c.id = acd.company_id
    LEFT JOIN workers w ON w.id = acd.worker_id
    LEFT JOIN users u ON u.id = acd.created_by_user_id
    WHERE acd.status = 1
      AND acd.event_type IN ('holiday', 'non_working', 'vacation', 'permission', 'rest')
      AND acd.calendar_date <= :month_end
      AND COALESCE(acd.end_date, acd.calendar_date) >= :month_start
    ORDER BY acd.calendar_date, acd.id");
$stmt->execute(['month_start' => $monthStart, 'month_end' => $monthEnd]);
$calendarDays = $stmt->fetchAll();

$workers = db()->query("SELECT w.id, w.full_name, w.document_number, c.name AS company
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    ORDER BY w.full_name")->fetchAll();

$dayNames = [
    1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves',
    5 => 'Viernes', 6 => 'Sabado', 7 => 'Domingo',
];

function calendar_scope_label(array $event): string
{
    return match ((string) $event['scope_type']) {
        'company' => 'Empresa: ' . (string) ($event['company_name'] ?? '-'),
        'worker' => 'Personal: ' . (string) ($event['worker_name'] ?? '-'),
        default => 'Todo el personal',
    };
}

function calendar_badge_class(string $eventType): string
{
    return match ($eventType) {
        'vacation' => 'legend-vacation',
        'permission' => 'legend-permission',
        'rest' => 'legend-rest',
        'holiday' => 'legend-holiday',
        'non_working' => 'legend-non-working',
        default => '',
    };
}

function calendar_event_code(string $eventType): string
{
    return match ($eventType) {
        'vacation' => 'VAC',
        'permission' => 'PER',
        'rest' => 'D',
        'holiday' => 'FER',
        'non_working' => 'NL',
        default => '-',
    };
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Calendario laboral</h1>
        <p>Vacaciones, permisos, descansos y d&iacute;as especiales del personal.</p>
    </div>
    <div class="d-flex gap-2 align-items-end flex-wrap">
        <form class="d-flex gap-2 align-items-end" method="get">
            <div>
                <label class="form-label small fw-bold text-muted">Mes</label>
                <input class="form-control" type="month" name="mes" value="<?= e($selectedMonth) ?>">
            </div>
            <button class="btn btn-outline-primary" type="submit" title="Filtrar mes"><i class="fa-solid fa-filter"></i></button>
        </form>
        <button class="btn btn-primary" type="button" id="newCalendarDayBtn"><i class="fa-solid fa-plus me-2"></i>Nuevo d&iacute;a</button>
    </div>
</div>

<div class="work-panel">
    <div class="attendance-matrix-legend mb-3">
        <span class="legend-vacation"><strong>VAC</strong>Vacaciones</span>
        <span class="legend-permission"><strong>PER</strong>Permiso</span>
        <span class="legend-rest"><strong>D</strong>Descanso</span>
        <span class="legend-holiday"><strong>FER</strong>Feriado</span>
        <span class="legend-non-working"><strong>NL</strong>No laborable</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle" id="calendarDaysTable">
            <thead>
            <tr>
                <th>Fecha o periodo</th>
                <th>D&iacute;a</th>
                <th>Tipo</th>
                <th>Motivo</th>
                <th>Aplica a</th>
                <th>Registrado por</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($calendarDays as $event): ?>
                <?php
                $eventType = (string) $event['event_type'];
                $dayNumber = (int) date('N', strtotime((string) $event['calendar_date']));
                $eventEndDate = (string) ($event['end_date'] ?: $event['calendar_date']);
                $isRange = $eventEndDate !== (string) $event['calendar_date'];
                $periodDays = (new DateTimeImmutable((string) $event['calendar_date']))
                    ->diff(new DateTimeImmutable($eventEndDate))->days + 1;
                ?>
                <tr>
                    <td><strong><?= e(date('d/m/Y', strtotime((string) $event['calendar_date']))) ?></strong><?= $isRange ? ' - ' . e(date('d/m/Y', strtotime($eventEndDate))) : '' ?></td>
                    <td><?= $isRange ? (int) $periodDays . ' d&iacute;as' : e($dayNames[$dayNumber]) ?></td>
                    <td>
                        <span class="calendar-event-chip <?= calendar_badge_class($eventType) ?>">
                            <strong><?= e(calendar_event_code($eventType)) ?></strong>
                            <?= e(attendance_calendar_event_label($eventType)) ?>
                        </span>
                    </td>
                    <td><?= e($event['name']) ?></td>
                    <td><?= e(calendar_scope_label($event)) ?></td>
                    <td><?= e($event['created_by_name'] ?? '-') ?></td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-primary js-edit-calendar-day" type="button"
                            data-id="<?= (int) $event['id'] ?>"
                            data-date="<?= e($event['calendar_date']) ?>"
                            data-end-date="<?= e($eventEndDate) ?>"
                            data-event-type="<?= e($eventType) ?>"
                            data-name="<?= e($event['name']) ?>"
                            data-scope-type="<?= e($event['scope_type']) ?>"
                            data-company-id="<?= (int) ($event['company_id'] ?? 0) ?>"
                            data-worker-id="<?= (int) ($event['worker_id'] ?? 0) ?>"
                            title="Editar"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-sm btn-outline-danger js-delete-calendar-day" type="button" data-id="<?= (int) $event['id'] ?>" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$calendarDays): ?>
                <tr><td colspan="7" class="text-muted text-center py-4">No hay d&iacute;as especiales registrados en este mes.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="calendarDayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content needs-validation" id="calendarDayForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="calendarDayModalTitle">Nuevo d&iacute;a especial</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="calendarDayId">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Tipo</label>
                        <select class="form-select" name="event_type" id="calendarEventType" required>
                            <option value="vacation">Vacaciones</option>
                            <option value="permission">Permiso</option>
                            <option value="rest">Descanso</option>
                            <option value="holiday">Feriado</option>
                            <option value="non_working">No laborable</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" id="calendarDateLabel">Fecha</label>
                        <input class="form-control" type="date" name="calendar_date" id="calendarDate" required>
                    </div>
                    <div class="col-md-4 d-none" id="calendarEndDateField">
                        <label class="form-label">Fecha final</label>
                        <input class="form-control" type="date" name="end_date" id="calendarEndDate">
                    </div>
                    <div class="col-md-4" id="calendarScopeField">
                        <label class="form-label">Aplicar a</label>
                        <select class="form-select" name="scope_type" id="calendarScopeType" required>
                            <option value="all">Todo el personal</option>
                            <option value="worker">Un trabajador</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Motivo o nombre</label>
                        <input class="form-control" name="name" id="calendarDayName" maxlength="180" placeholder="Ej. Fiestas Patrias" required>
                    </div>
                    <div class="col-12 d-none" id="calendarWorkerField">
                        <label class="form-label">Personal</label>
                        <select class="form-select" name="worker_id" id="calendarWorkerId">
                            <option value="">Seleccione</option>
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?= (int) $worker['id'] ?>"><?= e($worker['full_name'] . ' - ' . $worker['document_number'] . (!empty($worker['company']) ? ' - ' . $worker['company'] : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
