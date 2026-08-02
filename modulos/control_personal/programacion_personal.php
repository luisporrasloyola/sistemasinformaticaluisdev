<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.programacion');

$selectedScheduleId = max(0, (int) ($_GET['schedule_id'] ?? 0));
$selectedWorkerId = max(0, (int) ($_GET['worker_id'] ?? 0));

$workers = db()->query("SELECT w.id, w.full_name, w.document_number, c.name AS company_name
    FROM workers w LEFT JOIN companies c ON c.id = w.company_id
    WHERE w.status = 1 ORDER BY w.full_name")->fetchAll();
$journeyWorkers = db()->query("SELECT DISTINCT w.id, w.full_name, w.document_number
    FROM attendance_assignments aa JOIN workers w ON w.id = aa.worker_id
    WHERE aa.status = 1 AND (aa.valid_until IS NULL OR aa.valid_until >= CURDATE()) ORDER BY w.full_name")->fetchAll();
$schedules = db()->query("SELECT id, name FROM attendance_schedules WHERE status = 1 ORDER BY name")->fetchAll();
$assignments = db()->query("SELECT aa.id, aa.worker_id, aa.activity, aa.valid_from, aa.valid_until, w.full_name, w.document_number,
        l.name AS location_name, s.name AS schedule_name
    FROM attendance_assignments aa
    JOIN workers w ON w.id = aa.worker_id
    JOIN attendance_locations l ON l.id = aa.location_id
    JOIN attendance_schedules s ON s.id = aa.schedule_id
    WHERE aa.status = 1 AND (aa.valid_until IS NULL OR aa.valid_until >= CURDATE()) ORDER BY w.full_name, s.name, l.name")->fetchAll();
$programs = db()->query("SELECT ap.id, ap.assignment_id, ap.worker_id, ap.program_date, ap.entry_time,
        ap.entry_start, ap.exit_time, ap.tolerance_minutes, ap.schedule_source, ap.activity, ap.notes, ap.status, w.full_name, w.document_number,
        l.name AS location_name, s.name AS schedule_name,
        (SELECT GROUP_CONCAT(CONCAT(aps.destination, IF(aps.activity IS NULL OR aps.activity='', '', CONCAT(' - ',aps.activity))) ORDER BY aps.stop_order SEPARATOR '\n')
         FROM attendance_program_stops aps WHERE aps.program_id=ap.id) AS stops_text
    FROM attendance_programs ap
    JOIN workers w ON w.id = ap.worker_id
    JOIN attendance_assignments aa ON aa.id = ap.assignment_id
    JOIN attendance_locations l ON l.id = aa.location_id
    JOIN attendance_schedules s ON s.id = aa.schedule_id
    WHERE ap.program_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
    ORDER BY ap.program_date, ap.entry_time")->fetchAll();

$events = array_map(static function (array $row): array {
    $cancelled = $row['status'] === 'cancelada';
    $endDate = (string) $row['program_date'];
    if ((string) $row['exit_time'] <= (string) $row['entry_time']) {
        $endDate = date('Y-m-d', strtotime($endDate . ' +1 day'));
    }
    return [
        'id' => (string) $row['id'],
        'title' => $row['full_name'] . ' · ' . substr((string) $row['entry_time'], 0, 5) . ' - ' . substr((string) $row['exit_time'], 0, 5),
        'start' => $row['program_date'] . 'T' . $row['entry_time'],
        'end' => $endDate . 'T' . $row['exit_time'],
        'backgroundColor' => $cancelled ? '#64748b' : '#f97316',
        'borderColor' => $cancelled ? '#475569' : '#c2410c',
        'textColor' => '#ffffff',
        'display' => 'block',
        'classNames' => [$cancelled ? 'personnel-program-cancelled' : 'personnel-program-scheduled'],
        'extendedProps' => [
            'assignmentId' => (int) $row['assignment_id'], 'workerId' => (int) $row['worker_id'],
            'worker' => $row['full_name'], 'document' => $row['document_number'],
            'location' => $row['location_name'], 'schedule' => $row['schedule_name'],
            'scheduleSource' => $row['schedule_source'], 'entryStart' => substr((string) $row['entry_start'], 0, 5),
            'entryTime' => substr((string) $row['entry_time'], 0, 5), 'exitTime' => substr((string) $row['exit_time'], 0, 5),
            'tolerance' => (int) $row['tolerance_minutes'],
            'activity' => $row['activity'] ?? '', 'notes' => $row['notes'] ?? '', 'status' => $row['status'],
            'stops' => $row['stops_text'] ?? '',
        ],
    ];
}, $programs);

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Calendario y programación de jornadas</h1>
        <p>Consulta las jornadas reales y programa excepciones para fechas específicas.</p>
    </div>
</div>

<div class="schedule-view-tabs" role="tablist" aria-label="Vistas de jornadas">
    <button class="schedule-view-tab active" type="button" data-journey-module-view="calendar" aria-selected="true"><i class="fa-solid fa-calendar-days"></i><span><strong>Calendario de jornadas</strong><small>Horarios, personal y fechas reales</small></span></button>
    <button class="schedule-view-tab" type="button" data-journey-module-view="extraordinary" aria-selected="false"><i class="fa-solid fa-calendar-plus"></i><span><strong>Programación extraordinaria</strong><small>Excepciones para una fecha específica</small></span></button>
</div>

<div class="work-panel" id="journeysCalendarView">
    <div class="journeys-calendar-head">
        <div><h2 class="mb-1">Calendario de jornadas</h2><p class="text-muted mb-0">Jornadas generadas desde las plantillas, asignaciones y calendario laboral.</p></div>
        <div class="journeys-calendar-filters">
            <div><label class="form-label" for="journeysScheduleFilter">Plantilla de horario</label><select class="form-select select2-searchable" id="journeysScheduleFilter" data-placeholder="Buscar plantilla"><option value="all">Todas las plantillas</option><?php foreach ($schedules as $schedule): ?><option value="<?= (int) $schedule['id'] ?>" <?= $selectedScheduleId === (int) $schedule['id'] ? 'selected' : '' ?>><?= e($schedule['name']) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label" for="journeysWorkerFilter">Trabajador</label><select class="form-select select2-searchable" id="journeysWorkerFilter" data-placeholder="Buscar trabajador"><option value="all">Todo el personal</option><?php foreach ($journeyWorkers as $worker): ?><option value="<?= (int) $worker['id'] ?>" <?= $selectedWorkerId === (int) $worker['id'] ? 'selected' : '' ?>><?= e($worker['full_name'] . ' - ' . $worker['document_number']) ?></option><?php endforeach; ?></select></div>
        </div>
    </div>
    <div class="journeys-calendar-legend" aria-label="Leyenda"><span class="legend-regular">Horario habitual</span><span class="legend-extraordinary">Jornada extraordinaria</span><span class="legend-special">Calendario laboral</span></div>
    <div class="journeys-calendar-guide"><i class="fa-solid fa-circle-info"></i><span>Seleccione una jornada para revisar sus detalles. Puede excluir una fecha habitual sin modificar la plantilla semanal.</span></div>
    <div id="scheduleJourneysCalendar"></div>
</div>

<div class="d-none" id="extraordinaryProgrammingView">
<div class="page-title programming-view-title">
    <div><h2>Programación extraordinaria</h2><p>Cree jornadas que reemplazan el horario habitual solamente en la fecha seleccionada.</p></div>
    <button class="btn btn-primary" type="button" id="newProgramBtn"><i class="fa-solid fa-plus me-2"></i>Nueva jornada extraordinaria</button>
</div>

<div class="alert alert-primary d-flex align-items-start gap-3 shadow-sm" role="alert">
    <i class="fa-solid fa-circle-info fs-4 mt-1"></i>
    <div><strong>Utilice este calendario solo para jornadas extraordinarias.</strong><br><span class="small">Los horarios habituales ya se generan desde las plantillas. Lo registrado aquí reemplaza únicamente la jornada de la fecha seleccionada.</span></div>
</div>

<div class="work-panel">
    <div class="row g-3 align-items-end mb-3">
        <div class="col-lg-5">
            <label class="form-label" for="programWorkerFilter">Filtrar por trabajador</label>
            <select class="form-select select2-searchable" id="programWorkerFilter">
                <option value="">Todo el personal</option>
                <?php foreach ($workers as $worker): ?>
                    <option value="<?= (int) $worker['id'] ?>"><?= e($worker['full_name'] . ' - ' . $worker['document_number']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-7 text-lg-end"><span class="badge rounded-pill text-bg-warning px-3 py-2"><i class="fa-solid fa-calendar-check me-2"></i><?= count($programs) ?> jornadas extraordinarias</span></div>
    </div>
    <div id="personnelProgramCalendar"></div>
</div>
</div>

<div class="modal fade" id="scheduleJourneyDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><div><h5 class="modal-title">Detalle de la jornada</h5><small class="text-muted" id="journeyDetailDate"></small></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body"><div class="journey-detail-status" id="journeyDetailStatus"></div><dl class="journey-detail-list"><dt>Trabajador</dt><dd id="journeyDetailWorker">-</dd><dt>Horario</dt><dd id="journeyDetailSchedule">-</dd><dt>Entrada y salida</dt><dd id="journeyDetailHours">-</dd><dt>Lugar</dt><dd id="journeyDetailLocation">-</dd><dt>Actividad</dt><dd id="journeyDetailActivity">-</dd></dl><div class="alert alert-light border small mb-0" id="journeyDetailHelp"></div></div>
        <div class="modal-footer"><button class="btn btn-outline-danger d-none me-auto" type="button" id="excludeJourneyDateBtn"><i class="fa-solid fa-calendar-xmark me-2"></i>Excluir solo esta fecha</button><button class="btn btn-outline-primary d-none me-auto" type="button" id="restoreJourneyDateBtn"><i class="fa-solid fa-rotate-left me-2"></i>Restaurar jornada</button><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>

<div class="modal fade" id="personnelProgramModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" id="personnelProgramForm">
            <div class="modal-header bg-primary text-white">
                <div><h5 class="modal-title mb-0">Programar jornada extraordinaria</h5><small>Configure una excepción para una fecha específica.</small></div>
                <button class="btn-close btn-close-white" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="personnelProgramId">
                <input type="hidden" name="schedule_source" value="extraordinary">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Asignación activa</label>
                        <select class="form-select select2-searchable" name="assignment_id" id="personnelProgramAssignment" required>
                            <option value="">Seleccione trabajador, horario y lugar</option>
                            <?php foreach ($assignments as $assignment): ?>
                                <option value="<?= (int) $assignment['id'] ?>" data-worker-id="<?= (int) $assignment['worker_id'] ?>">
                                    <?= e($assignment['full_name'] . ' - ' . $assignment['document_number'] . ' | ' . $assignment['schedule_name'] . ' | ' . $assignment['location_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Fecha</label><input class="form-control" type="date" name="program_date" id="personnelProgramDate" required></div>
                    <div class="col-12" id="extraordinaryScheduleFields">
                        <div class="schedule-rule-intro">
                            <i class="fa-solid fa-calendar-day"></i>
                            <div><strong>Configure el horario extraordinario</strong><span>Estas reglas se aplicarán exclusivamente a la fecha seleccionada.</span></div>
                        </div>
                        <section class="schedule-rule-section schedule-rule-entry">
                            <div class="schedule-rule-section-title"><i class="fa-solid fa-right-to-bracket"></i><div><h6>Regla de entrada</h6><p>Defina la hora oficial y el margen permitido.</p></div></div>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label">Hora de entrada</label><input class="form-control" type="time" name="extra_entry_time" id="programExtraEntry" required><div class="form-text">Inicio oficial de esta jornada.</div></div>
                                <div class="col-md-4"><label class="form-label">Puede marcar antes</label><div class="input-group"><input class="form-control" type="number" name="extra_entry_advance" id="programExtraAdvance" min="0" max="360" value="30" required><span class="input-group-text">min</span></div><div class="form-text">Anticipación permitida.</div></div>
                                <div class="col-md-4"><label class="form-label">Tolerancia</label><div class="input-group"><input class="form-control" type="number" name="extra_tolerance" id="programExtraTolerance" min="0" max="180" value="0" required><span class="input-group-text">min</span></div><div class="form-text">Después de este margen será tardanza.</div></div>
                            </div>
                            <div class="schedule-rule-preview schedule-rule-preview-entry"><i class="fa-regular fa-clock"></i><span id="programEntryRulePreview">Complete la hora de entrada para calcular la ventana.</span></div>
                        </section>
                        <section class="schedule-rule-section schedule-rule-exit">
                            <div class="schedule-rule-section-title"><i class="fa-solid fa-right-from-bracket"></i><div><h6>Regla de salida</h6><p>Defina la hora oficial de finalización.</p></div></div>
                            <div class="row g-3"><div class="col-md-6"><label class="form-label">Hora de salida</label><input class="form-control" type="time" name="extra_exit_time" id="programExtraExit" required><div class="form-text">Toda salida anterior se considera anticipada.</div></div></div>
                            <div class="schedule-rule-preview schedule-rule-preview-exit"><i class="fa-solid fa-circle-info"></i><span id="programExitRulePreview">Complete la hora de salida.</span></div>
                        </section>
                    </div>
                    <div class="col-12"><label class="form-label">Actividad</label><input class="form-control" name="activity" id="personnelProgramActivity" maxlength="180" placeholder="Ej.: Supervisión en campo"></div>
                    <div class="col-12">
                        <label class="form-label">Indicaciones (opcional)</label>
                        <textarea class="form-control" name="notes" id="personnelProgramNotes" rows="4" maxlength="500" placeholder="Escriba las indicaciones para esta jornada"></textarea>
                        <small class="text-muted">Agregue información útil para orientar al trabajador durante esta jornada.</small>
                    </div>
                </div>
                <div class="alert alert-warning small mt-3 mb-0"><i class="fa-solid fa-circle-info me-2"></i>Esta programación tendrá prioridad solo en la fecha seleccionada. No modificará la plantilla semanal ni las demás jornadas.</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-danger me-auto d-none" type="button" id="cancelProgramBtn"><i class="fa-solid fa-trash-can me-2"></i>Eliminar programación</button>
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar</button>
            </div>
        </form>
    </div>
</div>

<style>
#personnelProgramCalendar{min-height:650px}.fc .fc-toolbar-title{font-size:1.25rem}.fc .fc-button-primary{background:#2563eb;border-color:#2563eb}.fc-event{cursor:pointer;border-radius:6px;padding:2px 4px}.fc-day-today{background:#eff6ff!important}
#personnelProgramCalendar .personnel-program-scheduled{background:#f97316!important;border-color:#c2410c!important;color:#fff!important;box-shadow:0 2px 5px rgba(194,65,12,.22)}
#personnelProgramCalendar .personnel-program-scheduled .fc-event-main,#personnelProgramCalendar .personnel-program-scheduled .fc-event-time,#personnelProgramCalendar .personnel-program-scheduled .fc-event-title{color:#fff!important}
</style>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>window.PERSONNEL_PROGRAM_EVENTS = <?= json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
