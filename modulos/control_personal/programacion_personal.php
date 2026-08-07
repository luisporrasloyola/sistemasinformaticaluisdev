<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.programacion');

$selectedScheduleId = max(0, (int) ($_GET['schedule_id'] ?? 0));
$selectedWorkerId = max(0, (int) ($_GET['worker_id'] ?? 0));

$journeyWorkers = db()->query("SELECT DISTINCT w.id, w.full_name, w.document_number
    FROM attendance_assignments aa JOIN workers w ON w.id = aa.worker_id
    WHERE aa.status = 1 AND (aa.valid_until IS NULL OR aa.valid_until >= CURDATE()) ORDER BY w.full_name")->fetchAll();
$schedules = db()->query("SELECT id, name FROM attendance_schedules WHERE status = 1 ORDER BY name")->fetchAll();
$locations = db()->query("SELECT id, name FROM attendance_locations WHERE status = 1 ORDER BY name")->fetchAll();
$assignments = db()->query("SELECT aa.id, aa.worker_id, aa.location_id, aa.schedule_id, aa.activity, aa.valid_from, aa.valid_until, w.full_name, w.document_number,
        l.name AS location_name, s.name AS schedule_name
    FROM attendance_assignments aa
    JOIN workers w ON w.id = aa.worker_id
    JOIN attendance_locations l ON l.id = aa.location_id
    JOIN attendance_schedules s ON s.id = aa.schedule_id
    WHERE aa.status = 1 AND (aa.valid_until IS NULL OR aa.valid_until >= CURDATE()) ORDER BY w.full_name, s.name, l.name")->fetchAll();
$programs = db()->query("SELECT ap.id, ap.assignment_id, ap.worker_id,
        COALESCE(ap.location_id, aa.location_id) AS location_id,
        COALESCE(ap.schedule_id, aa.schedule_id) AS schedule_id, ap.program_date, ap.entry_time,
        ap.entry_start, ap.exit_time, ap.tolerance_minutes, ap.schedule_source, ap.activity, ap.notes, ap.status, w.full_name, w.document_number,
        l.name AS location_name, s.name AS schedule_name,
        EXISTS(SELECT 1 FROM attendance_marks am WHERE am.program_id=ap.id) AS has_program_marks,
        EXISTS(SELECT 1 FROM attendance_trips atp WHERE atp.program_id=ap.id) AS has_program_trips,
        EXISTS(SELECT 1 FROM attendance_work_completions awc WHERE awc.program_id=ap.id) AS has_work_completions,
        (SELECT GROUP_CONCAT(CONCAT(aps.destination, IF(aps.activity IS NULL OR aps.activity='', '', CONCAT(' - ',aps.activity))) ORDER BY aps.stop_order SEPARATOR '\n')
         FROM attendance_program_stops aps WHERE aps.program_id=ap.id) AS stops_text
    FROM attendance_programs ap
    JOIN workers w ON w.id = ap.worker_id
    JOIN attendance_assignments aa ON aa.id = ap.assignment_id
    JOIN attendance_locations l ON l.id = COALESCE(ap.location_id, aa.location_id)
    JOIN attendance_schedules s ON s.id = COALESCE(ap.schedule_id, aa.schedule_id)
    WHERE ap.program_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
    ORDER BY ap.program_date, ap.entry_time")->fetchAll();

$programStops = [];
foreach (db()->query("SELECT aps.program_id, aps.location_id, aps.destination, aps.activity, aps.estimated_time
    FROM attendance_program_stops aps ORDER BY aps.program_id, aps.stop_order")->fetchAll() as $stop) {
    $programStops[(int) $stop['program_id']][] = [
        'locationId' => (int) ($stop['location_id'] ?? 0),
        'destination' => (string) $stop['destination'],
        'activity' => (string) ($stop['activity'] ?? ''),
        'estimatedTime' => $stop['estimated_time'] ? substr((string) $stop['estimated_time'], 0, 5) : '',
    ];
}

$events = array_map(static function (array $row) use ($programStops): array {
    $cancelled = $row['status'] === 'cancelada';
    return [
        'id' => (string) $row['id'],
        'title' => $row['full_name'] . ' · ' . substr((string) $row['entry_time'], 0, 5) . ' - ' . substr((string) $row['exit_time'], 0, 5),
        // La jornada pertenece visualmente a la fecha programada, aunque su
        // hora de salida sea del día siguiente. Las horas reales permanecen
        // en extendedProps para la marcación, edición y los reportes.
        'start' => $row['program_date'],
        'allDay' => true,
        'backgroundColor' => $cancelled ? '#64748b' : '#f97316',
        'borderColor' => $cancelled ? '#475569' : '#c2410c',
        'textColor' => '#ffffff',
        'display' => 'block',
        'classNames' => [$cancelled ? 'personnel-program-cancelled' : 'personnel-program-scheduled'],
        'extendedProps' => [
            'assignmentId' => (int) $row['assignment_id'], 'workerId' => (int) $row['worker_id'],
            'locationId' => (int) $row['location_id'], 'scheduleId' => (int) $row['schedule_id'],
            'worker' => $row['full_name'], 'document' => $row['document_number'],
            'location' => $row['location_name'], 'schedule' => $row['schedule_name'],
            'scheduleSource' => $row['schedule_source'], 'entryStart' => substr((string) $row['entry_start'], 0, 5),
            'entryTime' => substr((string) $row['entry_time'], 0, 5), 'exitTime' => substr((string) $row['exit_time'], 0, 5),
            'tolerance' => (int) $row['tolerance_minutes'],
            'activity' => $row['activity'] ?? '', 'notes' => $row['notes'] ?? '', 'status' => $row['status'],
            'hasProgramMarks' => (bool) $row['has_program_marks'],
            'hasProgramTrips' => (bool) $row['has_program_trips'],
            'hasWorkCompletions' => (bool) $row['has_work_completions'],
            'stops' => $programStops[(int) $row['id']] ?? [],
        ],
    ];
}, $programs);
$specialProgramEvents = array_values(array_filter($events, static fn(array $event): bool => empty($event['extendedProps']['stops'])));
$routeProgramEvents = array_values(array_map(static function (array $event): array {
    $placeCount = count($event['extendedProps']['stops'] ?? []) + 1;
    if (($event['extendedProps']['status'] ?? '') !== 'cancelada') {
        $event['backgroundColor'] = '#0f766e';
        $event['borderColor'] = '#115e59';
        $event['classNames'] = ['personnel-route-scheduled'];
    }
    $event['title'] = ($event['extendedProps']['worker'] ?? 'Trabajador') . ' · '
        . ($event['extendedProps']['entryTime'] ?? '--:--') . ' - ' . ($event['extendedProps']['exitTime'] ?? '--:--')
        . ' · ' . $placeCount . ($placeCount === 1 ? ' lugar' : ' lugares');
    $event['extendedProps']['placeCount'] = $placeCount;
    return $event;
}, array_filter($events, static fn(array $event): bool => !empty($event['extendedProps']['stops']))));

$filterWorkersFromEvents = static function (array $calendarEvents): array {
    $workersById = [];
    foreach ($calendarEvents as $event) {
        $props = $event['extendedProps'] ?? [];
        $workerId = (int) ($props['workerId'] ?? 0);
        if ($workerId <= 0) continue;
        $workersById[$workerId] = [
            'id' => $workerId,
            'full_name' => (string) ($props['worker'] ?? 'Trabajador'),
            'document_number' => (string) ($props['document'] ?? ''),
        ];
    }
    uasort($workersById, static fn(array $a, array $b): int => strcasecmp($a['full_name'], $b['full_name']));
    return array_values($workersById);
};
$specialFilterWorkers = $filterWorkersFromEvents($specialProgramEvents);
$routeFilterWorkers = $filterWorkersFromEvents($routeProgramEvents);

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
    <button class="schedule-view-tab" type="button" data-journey-module-view="extraordinary" aria-selected="false"><i class="fa-solid fa-calendar-plus"></i><span><strong>Programación especial</strong><small>Horarios diferentes para fechas específicas</small></span></button>
    <button class="schedule-view-tab" type="button" data-journey-module-view="routes" aria-selected="false"><i class="fa-solid fa-route"></i><span><strong>Recorridos de trabajo</strong><small>Varios lugares durante una misma jornada</small></span></button>
</div>

<div class="work-panel" id="journeysCalendarView">
    <div class="journeys-calendar-head">
        <div><h2 class="mb-1">Calendario de jornadas</h2><p class="text-muted mb-0">Jornadas generadas desde las plantillas, asignaciones y calendario laboral.</p></div>
        <div class="journeys-calendar-filters">
            <div><label class="form-label" for="journeysScheduleFilter">Plantilla de horario</label><select class="form-select select2-searchable" id="journeysScheduleFilter" data-placeholder="Buscar plantilla"><option value="all">Todas las plantillas</option><?php foreach ($schedules as $schedule): ?><option value="<?= (int) $schedule['id'] ?>" <?= $selectedScheduleId === (int) $schedule['id'] ? 'selected' : '' ?>><?= e($schedule['name']) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label" for="journeysWorkerFilter">Trabajador</label><select class="form-select select2-searchable" id="journeysWorkerFilter" data-placeholder="Buscar trabajador"><option value="all">Todo el personal</option><?php foreach ($journeyWorkers as $worker): ?><option value="<?= (int) $worker['id'] ?>" <?= $selectedWorkerId === (int) $worker['id'] ? 'selected' : '' ?>><?= e($worker['full_name'] . ' - ' . $worker['document_number']) ?></option><?php endforeach; ?></select></div>
        </div>
    </div>
    <div class="journeys-calendar-legend" aria-label="Leyenda"><span class="legend-regular">Horario habitual</span><span class="legend-extraordinary">Programación especial</span><span class="legend-special">Calendario laboral</span></div>
    <div class="journeys-calendar-guide"><i class="fa-solid fa-circle-info"></i><span>Seleccione una jornada para revisar sus detalles. Puede excluir una fecha habitual sin modificar la plantilla semanal.</span></div>
    <div id="scheduleJourneysCalendar"></div>
</div>

<div class="d-none" id="extraordinaryProgrammingView">
<div class="page-title programming-view-title">
    <div><h2>Programación especial</h2><p>Configure un horario diferente para una fecha específica.</p></div>
    <button class="btn btn-primary" type="button" id="newProgramBtn"><i class="fa-solid fa-plus me-2"></i>Nueva programación especial</button>
</div>

<div class="alert alert-primary d-flex align-items-start gap-3 shadow-sm" role="alert">
    <i class="fa-solid fa-circle-info fs-4 mt-1"></i>
    <div><strong>Utilice este calendario para programaciones especiales.</strong><br><span class="small">El horario configurado aquí reemplaza al habitual únicamente en la fecha seleccionada.</span></div>
</div>

<div class="work-panel">
    <div class="row g-3 align-items-end mb-3">
        <div class="col-lg-5">
            <label class="form-label" for="programWorkerFilter">Filtrar por trabajador</label>
            <select class="form-select select2-searchable" id="programWorkerFilter" data-placeholder="Todo el personal">
                <option value="all">Todo el personal</option>
                <?php foreach ($specialFilterWorkers as $worker): ?>
                    <option value="<?= (int) $worker['id'] ?>" <?= $selectedWorkerId === (int) $worker['id'] ? 'selected' : '' ?>><?= e($worker['full_name'] . ' - ' . $worker['document_number']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-7 text-lg-end"><span class="badge rounded-pill text-bg-warning px-3 py-2" id="specialProgramsCount"><i class="fa-solid fa-calendar-check me-2"></i><span><?= count($specialProgramEvents) ?> programaciones especiales</span></span></div>
    </div>
    <div id="personnelProgramCalendar"></div>
</div>
</div>

<div class="d-none" id="routesProgrammingView">
    <div class="page-title programming-view-title">
        <div><h2>Recorridos de trabajo</h2><p>Organice varios lugares y actividades sin dividir la jornada laboral.</p></div>
        <button class="btn btn-primary" type="button" id="newRouteProgramBtn"><i class="fa-solid fa-plus me-2"></i>Nuevo recorrido</button>
    </div>
    <div class="alert alert-primary d-flex align-items-start gap-3 shadow-sm" role="alert"><i class="fa-solid fa-route fs-4 mt-1"></i><div><strong>Una entrada, varios lugares y una sola salida.</strong><br><span class="small">El trabajador podrá confirmar sus llegadas y marcar la salida en el último lugar visitado.</span></div></div>
    <div class="work-panel"><div class="row g-3 align-items-end mb-3"><div class="col-lg-5"><label class="form-label" for="routeWorkerFilter">Filtrar por trabajador</label><select class="form-select select2-searchable" id="routeWorkerFilter" data-placeholder="Todo el personal"><option value="all">Todo el personal</option><?php foreach ($routeFilterWorkers as $worker): ?><option value="<?= (int) $worker['id'] ?>" <?= $selectedWorkerId === (int) $worker['id'] ? 'selected' : '' ?>><?= e($worker['full_name'] . ' - ' . $worker['document_number']) ?></option><?php endforeach; ?></select></div><div class="col-lg-7 text-lg-end"><span class="badge rounded-pill text-bg-primary px-3 py-2" id="routeProgramsCount"><i class="fa-solid fa-route me-2"></i><span><?= count($routeProgramEvents) ?> recorridos</span></span></div></div><div id="personnelRoutesCalendar"></div></div>
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
                <div><h5 class="modal-title mb-0">Nueva programación especial</h5><small>Configure un horario diferente para una fecha específica.</small></div>
                <button class="btn-close btn-close-white" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="personnelProgramId">
                <div class="row g-3">
                    <div class="col-md-8" id="personnelProgramSingleWorkerField">
                        <label class="form-label">Trabajador</label>
                        <select class="form-select select2-searchable" id="personnelProgramSingleAssignment" data-placeholder="Seleccione un trabajador">
                            <option value="">Seleccione un trabajador</option>
                            <?php foreach ($assignments as $assignment): ?>
                                <option value="<?= (int) $assignment['id'] ?>" data-location-id="<?= (int) $assignment['location_id'] ?>" data-schedule-id="<?= (int) $assignment['schedule_id'] ?>"><?= e($assignment['full_name'] . ' - ' . $assignment['document_number'] . ' · ' . $assignment['location_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8 d-none route-workers-picker" id="personnelProgramRouteWorkersField">
                        <div class="route-workers-picker-head"><label class="form-label mb-0" id="personnelProgramWorkersLabel"><i class="fa-solid fa-users me-1"></i>Trabajadores</label><span id="personnelProgramWorkersCount">Ninguno seleccionado</span></div>
                        <select class="form-select select2-searchable" name="assignment_ids[]" id="personnelProgramAssignment" multiple required data-placeholder="Seleccione uno o varios trabajadores">
                            <?php foreach ($assignments as $assignment): ?>
                                <option value="<?= (int) $assignment['id'] ?>"
                                    data-worker-id="<?= (int) $assignment['worker_id'] ?>"
                                    data-selection-label="<?= e($assignment['full_name'] . ' · ' . $assignment['location_name']) ?>"
                                    data-location-id="<?= (int) $assignment['location_id'] ?>"
                                    data-schedule-id="<?= (int) $assignment['schedule_id'] ?>">
                                    <?= e($assignment['full_name'] . ' - ' . $assignment['document_number'] . ' · ' . $assignment['location_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" id="personnelProgramWorkersHelp">Busque por nombre, documento o lugar.</div>
                    </div>
                    <div class="col-md-4"><label class="form-label">Fecha</label><input class="form-control" type="date" name="program_date" id="personnelProgramDate" required></div>
                    <div class="col-12" id="personnelProgramScheduleModeField"><label class="form-label">Horario de la jornada</label><select class="form-select" name="schedule_source" id="personnelProgramScheduleSource"><option value="template">Mantener el horario habitual</option><option value="extraordinary">Definir un horario especial</option></select><div class="form-text">Si solo cambiarán los lugares y actividades, mantenga el horario habitual.</div></div>
                    <div class="col-md-6" id="personnelProgramLocationField"><label class="form-label">Lugar de marcación</label><select class="form-select select2-searchable" name="location_id" id="personnelProgramLocation" required><option value="">Seleccione un lugar</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>"><?= e($location['name']) ?></option><?php endforeach; ?></select><div class="form-text">Se aplicará solamente en esta fecha.</div></div>
                    <div class="col-md-6" id="personnelProgramScheduleField"><label class="form-label">Plantilla de horario</label><select class="form-select select2-searchable" name="schedule_id" id="personnelProgramSchedule" required><option value="">Seleccione una plantilla</option><?php foreach ($schedules as $schedule): ?><option value="<?= (int) $schedule['id'] ?>"><?= e($schedule['name']) ?></option><?php endforeach; ?></select><div class="form-text">Identifica el horario excepcional de esta jornada.</div></div>
                    <div class="col-12" id="extraordinaryScheduleFields">
                        <div class="schedule-rule-intro">
                            <i class="fa-solid fa-calendar-day"></i>
                            <div><strong>Configure el horario especial</strong><span>Estas reglas se aplicarán exclusivamente a la fecha seleccionada.</span></div>
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
                    <div class="col-12" id="personnelProgramActivityField"><label class="form-label">Actividad</label><input class="form-control" name="activity" id="personnelProgramActivity" maxlength="180" placeholder="Ej.: Supervisión en campo"></div>
                    <div class="col-12" id="personnelProgramRouteField">
                        <div class="border rounded-3 p-3 bg-light">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <div><strong><i class="fa-solid fa-route me-2 text-primary"></i>Recorrido de trabajo</strong><small class="text-muted d-block">Agregue los lugares en el orden de visita.</small></div>
                                <button class="btn btn-sm btn-outline-primary text-nowrap" type="button" id="addProgramStopBtn"><i class="fa-solid fa-plus me-1"></i>Agregar lugar</button>
                            </div>
                            <div class="d-grid gap-2" id="programStopsContainer"></div>
                            <div class="program-stops-empty" id="programStopsEmpty"><i class="fa-regular fa-map me-2"></i>Aún no hay lugares agregados.</div>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Indicaciones (opcional)</label>
                        <textarea class="form-control" name="notes" id="personnelProgramNotes" rows="4" maxlength="500" placeholder="Escriba las indicaciones para esta jornada"></textarea>
                        <small class="text-muted" id="personnelProgramNotesHelp">Agregue información útil para orientar al trabajador durante esta jornada.</small>
                    </div>
                </div>
                <div class="alert alert-warning small mt-3 mb-0" id="personnelProgramPriorityNotice"><i class="fa-solid fa-circle-info me-2"></i>Esta programación tendrá prioridad solo en la fecha seleccionada. No modificará la plantilla semanal ni las demás jornadas.</div>
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
#personnelProgramCalendar,#personnelRoutesCalendar{min-height:650px}.fc .fc-toolbar-title{font-size:1.25rem}.fc .fc-button-primary{background:#2563eb;border-color:#2563eb}.fc-event{cursor:pointer;border-radius:6px;padding:2px 4px}.fc-day-today{background:#eff6ff!important}
#personnelProgramCalendar .fc-daygrid-event-harness{min-width:0;overflow:hidden}
#personnelProgramCalendar .fc-daygrid-event{display:block;box-sizing:border-box;width:calc(100% - 4px);max-width:calc(100% - 4px);min-width:0;margin-left:2px;margin-right:2px;overflow:hidden}
#personnelProgramCalendar .fc-daygrid-event .fc-event-main{display:block;min-width:0;overflow:hidden}
#personnelProgramCalendar .fc-daygrid-event .fc-event-title{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#personnelProgramCalendar .personnel-program-scheduled{background:#f97316!important;border-color:#c2410c!important;color:#fff!important;box-shadow:0 2px 5px rgba(194,65,12,.22)}
#personnelRoutesCalendar .personnel-route-scheduled{background:#0f766e!important;border-color:#115e59!important;color:#fff!important;box-shadow:0 2px 5px rgba(15,118,110,.24)}
#personnelProgramCalendar .personnel-program-scheduled .fc-event-main,#personnelProgramCalendar .personnel-program-scheduled .fc-event-time,#personnelProgramCalendar .personnel-program-scheduled .fc-event-title{color:#fff!important}
.route-workers-picker-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:6px}.route-workers-picker-head .form-label{display:flex;align-items:center;color:#1e3a5f;font-weight:800}.route-workers-picker-head>span{padding:3px 8px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.7rem;font-weight:800}.route-workers-picker .select2-container--bootstrap4 .select2-selection--multiple{height:auto!important;min-height:42px!important;padding:4px 6px;border-color:#bfdbfe;background:#fff}.route-workers-picker .select2-selection--multiple .select2-selection__rendered{display:flex!important;flex-wrap:wrap;align-items:center;gap:5px;width:100%;padding:0!important}.route-workers-picker .select2-selection__choice{display:inline-flex!important;align-items:center;max-width:100%;margin:0!important;padding:4px 8px!important;border:0!important;border-radius:7px!important;background:#dbeafe!important;color:#1e40af!important;font-size:.78rem;font-weight:700}.route-workers-picker .select2-selection__choice__remove{margin-right:5px!important;color:#64748b!important}.route-workers-picker .select2-search--inline{flex:1 1 150px;margin:0!important}.route-workers-picker .select2-search__field{width:100%!important;margin:3px 0!important}.program-stops-empty{padding:9px 11px;border:1px dashed #cbd5e1;border-radius:8px;background:#fff;color:#64748b;font-size:.8rem}.program-stop-arrival-label{white-space:nowrap}
</style>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
window.PERSONNEL_PROGRAM_EVENTS = <?= json_encode($specialProgramEvents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.PERSONNEL_ROUTE_EVENTS = <?= json_encode($routeProgramEvents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.PERSONNEL_PROGRAM_LOCATIONS = <?= json_encode(array_map(static fn(array $location): array => ['id' => (int) $location['id'], 'name' => (string) $location['name']], $locations), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
