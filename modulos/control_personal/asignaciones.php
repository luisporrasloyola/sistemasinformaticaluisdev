<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.asignaciones');

$assignments = db()->query("SELECT aa.*, w.full_name, w.document_number, l.name AS location_name, s.name AS schedule_name,
        u.name AS registered_by_name,
        GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') AS positions
    FROM attendance_assignments aa
    JOIN workers w ON w.id = aa.worker_id
    JOIN attendance_locations l ON l.id = aa.location_id
    JOIN attendance_schedules s ON s.id = aa.schedule_id
    LEFT JOIN users u ON u.id = aa.created_by_user_id
    LEFT JOIN worker_positions wp ON wp.worker_id = w.id
    LEFT JOIN positions p ON p.id = wp.position_id
    WHERE aa.status = 1 AND (aa.valid_until IS NULL OR aa.valid_until >= CURDATE())
    GROUP BY aa.id
    ORDER BY l.name, s.name, w.full_name")->fetchAll();

$assignmentGroups = [];
foreach ($assignments as $assignment) {
    $groupKey = (int) $assignment['location_id'] . '-' . (int) $assignment['schedule_id'];
    if (!isset($assignmentGroups[$groupKey])) {
        $assignmentGroups[$groupKey] = [
            'location' => $assignment['location_name'],
            'schedule' => $assignment['schedule_name'],
            'assignments' => [],
        ];
    }
    $assignmentGroups[$groupKey]['assignments'][] = $assignment;
}

$workers = db()->query("SELECT w.id, w.full_name, w.document_number, c.name AS company
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    ORDER BY w.full_name")->fetchAll();
$locations = db()->query('SELECT id, name FROM attendance_locations WHERE status = 1 ORDER BY name')->fetchAll();
$schedules = db()->query('SELECT id, name FROM attendance_schedules WHERE status = 1 ORDER BY name')->fetchAll();
$activeAssignmentPeriods = array_map(static fn(array $assignment): array => [
    'worker_id' => (int) $assignment['worker_id'],
    'valid_from' => (string) $assignment['valid_from'],
    'valid_until' => $assignment['valid_until'] ?: null,
], db()->query('SELECT worker_id, valid_from, valid_until FROM attendance_assignments WHERE status = 1')->fetchAll());

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Asignaciones</h1>
        <p>Relación entre trabajador, lugar, horario y actividad.</p>
    </div>
    <button class="btn btn-primary" type="button" id="newAssignmentBtn"><i class="fa-solid fa-plus me-2"></i>Nueva asignación</button>
</div>

<div class="work-panel assignment-groups-panel">
    <div class="assignment-groups-toolbar">
        <div>
            <strong><?= count($assignmentGroups) ?> grupo<?= count($assignmentGroups) === 1 ? '' : 's' ?> de asignaci&oacute;n</strong>
            <small class="text-muted d-block"><?= count($assignments) ?> asignaci<?= count($assignments) === 1 ? '&oacute;n vigente' : 'ones vigentes' ?></small>
        </div>
        <div class="assignment-groups-actions">
            <div class="input-group input-group-sm assignment-group-search">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input class="form-control" type="search" id="assignmentGroupSearch" placeholder="Buscar personal, lugar, horario o actividad">
            </div>
            <button class="btn btn-sm btn-outline-secondary" type="button" id="expandAssignmentGroups">Expandir todos</button>
            <button class="btn btn-sm btn-outline-secondary" type="button" id="collapseAssignmentGroups">Contraer</button>
        </div>
    </div>

    <div class="assignment-groups" id="assignmentGroups">
    <?php foreach ($assignmentGroups as $groupIndex => $group): ?>
        <?php
        $groupId = 'assignmentGroup' . preg_replace('/[^a-zA-Z0-9]/', '', (string) $groupIndex);
        $colorIndex = (array_search($groupIndex, array_keys($assignmentGroups), true) % 6) + 1;
        $searchParts = [$group['location'], $group['schedule']];
        foreach ($group['assignments'] as $row) $searchParts[] = implode(' ', [$row['full_name'], $row['document_number'], $row['positions'] ?? '', $row['activity'] ?? '', $row['instructions'] ?? '']);
        $groupAssignmentIds = array_map(static fn(array $row): int => (int) $row['id'], $group['assignments']);
        $groupValidFromValues = array_values(array_unique(array_column($group['assignments'], 'valid_from')));
        $groupValidUntilValues = array_values(array_unique(array_map(static fn(array $row): string => (string) ($row['valid_until'] ?? ''), $group['assignments'])));
        $groupValidityUniform = count($groupValidFromValues) === 1 && count($groupValidUntilValues) === 1;
        $groupValidFrom = count($groupValidFromValues) === 1 ? $groupValidFromValues[0] : '';
        $groupValidUntil = count($groupValidUntilValues) === 1 ? $groupValidUntilValues[0] : '';
        ?>
        <section class="assignment-group assignment-group-color-<?= $colorIndex ?>"
            data-search="<?= e(strtolower(implode(' ', $searchParts))) ?>"
            data-group-search="<?= e(strtolower($group['location'] . ' ' . $group['schedule'])) ?>">
            <button class="assignment-group-header <?= $groupIndex === array_key_first($assignmentGroups) ? '' : 'collapsed' ?>" type="button"
                data-bs-toggle="collapse" data-bs-target="#<?= e($groupId) ?>" aria-expanded="<?= $groupIndex === array_key_first($assignmentGroups) ? 'true' : 'false' ?>">
                <span class="assignment-group-symbol assignment-group-symbol-schedule"><i class="fa-regular fa-clock"></i></span>
                <span class="assignment-group-heading assignment-group-heading-schedule">
                    <span class="assignment-group-label">Horario</span>
                    <strong><?= e($group['schedule']) ?></strong>
                </span>
                <span class="assignment-group-divider"></span>
                <span class="assignment-group-symbol assignment-group-symbol-location"><i class="fa-solid fa-location-dot"></i></span>
                <span class="assignment-group-heading assignment-group-heading-location">
                    <span class="assignment-group-label">Lugar de marcaci&oacute;n</span>
                    <strong><?= e($group['location']) ?></strong>
                </span>
                <span class="assignment-group-count"><b><?= count($group['assignments']) ?></b> trabajador<?= count($group['assignments']) === 1 ? '' : 'es' ?></span>
                <i class="fa-solid fa-chevron-down assignment-group-chevron"></i>
            </button>
            <div class="collapse <?= $groupIndex === array_key_first($assignmentGroups) ? 'show' : '' ?>" id="<?= e($groupId) ?>">
                <div class="assignment-group-bulkbar">
                    <div><strong>Vigencia del grupo</strong><small>Actualice el periodo de los <?= count($group['assignments']) ?> trabajadores sin modificar sus marcaciones.</small></div>
                    <button class="btn btn-sm btn-outline-primary js-group-validity" type="button"
                        data-assignment-ids="<?= e(implode(',', $groupAssignmentIds)) ?>"
                        data-count="<?= count($group['assignments']) ?>"
                        data-location="<?= e($group['location']) ?>"
                        data-schedule="<?= e($group['schedule']) ?>"
                        data-uniform="<?= $groupValidityUniform ? '1' : '0' ?>"
                        data-valid-from="<?= e($groupValidFrom) ?>"
                        data-valid-until="<?= e($groupValidUntil) ?>">
                        <i class="fa-solid fa-calendar-days me-2"></i>Cambiar vigencia del grupo
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 assignment-group-table">
                        <thead><tr><th>Personal</th><th>Puesto</th><th>Actividad</th><th>Vigencia</th><th>Registrado por</th><th>Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach ($group['assignments'] as $assignment): ?>
                            <tr class="assignment-member-row" data-search="<?= e(strtolower(implode(' ', [
                                $assignment['full_name'],
                                $assignment['document_number'],
                                $assignment['positions'] ?? '',
                                $assignment['activity'] ?? '',
                                $assignment['instructions'] ?? '',
                                $assignment['registered_by_name'] ?? '',
                            ]))) ?>">
                                <td><strong><?= e($assignment['full_name']) ?></strong><small class="text-muted d-block"><?= e($assignment['document_number']) ?></small></td>
                                <td><?= e($assignment['positions'] ?: 'Sin puesto registrado') ?></td>
                                <td><?= e($assignment['activity'] ?: 'Sin actividad especificada') ?><?php if (!empty($assignment['instructions'])): ?><small class="text-muted d-block mt-1"><i class="fa-solid fa-circle-info me-1"></i><?= e($assignment['instructions']) ?></small><?php endif; ?></td>
                                <td><div class="small fw-semibold">Desde <?= e(date('d/m/Y', strtotime($assignment['valid_from']))) ?></div><div class="small text-muted"><?= $assignment['valid_until'] ? 'Hasta ' . e(date('d/m/Y', strtotime($assignment['valid_until']))) : 'Sin fecha de finalizaci&oacute;n' ?></div></td>
                                <td><?= e($assignment['registered_by_name'] ?: 'No registrado') ?></td>
                                <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-success js-assignment-calendar" type="button"
                            data-worker-id="<?= (int) $assignment['worker_id'] ?>"
                            data-schedule-id="<?= (int) $assignment['schedule_id'] ?>"
                            data-worker-name="<?= e($assignment['full_name']) ?>"
                            data-schedule-name="<?= e($assignment['schedule_name']) ?>"
                            data-location-name="<?= e($assignment['location_name']) ?>"
                            title="Ver calendario de jornadas de <?= e($assignment['full_name']) ?>"
                            aria-label="Ver calendario de jornadas de <?= e($assignment['full_name']) ?>">
                            <i class="fa-regular fa-calendar-days"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-primary js-edit-assignment" type="button"
                            data-id="<?= (int) $assignment['id'] ?>"
                            data-worker-id="<?= (int) $assignment['worker_id'] ?>"
                            data-location-id="<?= (int) $assignment['location_id'] ?>"
                            data-schedule-id="<?= (int) $assignment['schedule_id'] ?>"
                            data-activity="<?= e($assignment['activity'] ?? '') ?>"
                            data-instructions="<?= e($assignment['instructions'] ?? '') ?>"
                            data-valid-from="<?= e($assignment['valid_from']) ?>"
                            data-valid-until="<?= e($assignment['valid_until'] ?? '') ?>"
                            title="Editar"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-sm btn-outline-secondary js-assignment-history" type="button"
                            data-worker-id="<?= (int) $assignment['worker_id'] ?>"
                            data-worker-name="<?= e($assignment['full_name']) ?>"
                            title="Ver historial"><i class="fa-solid fa-clock-rotate-left"></i></button>
                        <button class="btn btn-sm btn-outline-danger js-delete-assignment" type="button"
                            data-id="<?= (int) $assignment['id'] ?>"
                            title="Desactivar"><i class="fa-solid fa-power-off"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
    <?php if (!$assignmentGroups): ?><div class="text-center text-muted py-5"><i class="fa-solid fa-users-slash fs-3 mb-3 d-block"></i>No existen asignaciones vigentes.</div><?php endif; ?>
    <?php if ($assignmentGroups): ?><div class="text-center text-muted py-5 d-none" id="assignmentSearchEmpty"><i class="fa-solid fa-magnifying-glass fs-3 mb-3 d-block"></i>No se encontraron asignaciones que coincidan con la b&uacute;squeda.</div><?php endif; ?>
    </div>
</div>

<div class="modal fade" id="groupValidityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <form class="modal-content needs-validation" id="groupValidityForm" novalidate>
            <div class="modal-header">
                <div><h5 class="modal-title">Cambiar vigencia del grupo</h5><small class="text-muted" id="groupValidityContext"></small></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="assignment_ids" id="groupValidityAssignmentIds">
                <div class="alert alert-primary small"><i class="fa-solid fa-shield-halved me-2"></i>Se aplicar&aacute; el mismo periodo a todas las asignaciones de este grupo. Las marcaciones e historiales se conservar&aacute;n.</div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="groupValidFrom">Fecha de inicio</label><input class="form-control" type="date" name="valid_from" id="groupValidFrom" required></div>
                    <div class="col-md-6"><label class="form-label" for="groupValidUntil">Fecha de finalizaci&oacute;n</label><input class="form-control" type="date" name="valid_until" id="groupValidUntil" required></div>
                    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="no_end" value="1" id="groupNoEnd"><label class="form-check-label" for="groupNoEnd">Sin fecha de finalizaci&oacute;n</label></div></div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Aplicar al grupo</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="assignmentCalendarModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable assignment-calendar-dialog">
        <div class="modal-content">
            <div class="modal-header assignment-calendar-header">
                <div>
                    <h5 class="modal-title"><i class="fa-regular fa-calendar-days me-2"></i>Calendario de jornadas</h5>
                    <small id="assignmentCalendarContext">Cargando programaci&oacute;n...</small>
                </div>
                <button class="btn-close btn-close-white" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="assignment-calendar-guide"><i class="fa-solid fa-circle-info"></i><span>Se muestran los horarios habituales, las programaciones especiales y los d&iacute;as del calendario laboral correspondientes al trabajador.</span></div>
                <div id="assignmentCalendar"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="journeyDateDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content needs-validation" id="journeyDateDetailForm" novalidate>
            <div class="modal-header bg-primary text-white">
                <div><h5 class="modal-title mb-0"><i class="fa-regular fa-calendar-check me-2"></i>Detalle de la jornada</h5><small id="journeyDateDetailContext">Fecha seleccionada</small></div>
                <button class="btn-close btn-close-white" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="assignment_id" id="journeyDetailAssignmentId">
                <input type="hidden" name="journey_date" id="journeyDetailDate">
                <div class="journey-unified-summary d-none" id="journeyUnifiedSummary"></div>
                <div class="alert alert-primary py-2"><i class="fa-solid fa-circle-info me-2"></i>Los cambios se aplicar&aacute;n solamente a esta fecha. La asignaci&oacute;n general y las dem&aacute;s jornadas no cambiar&aacute;n.</div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Personal</label><input class="form-control" id="journeyDetailWorker" readonly></div>
                    <div class="col-md-6"><label class="form-label">Lugar de marcaci&oacute;n</label><input class="form-control" id="journeyDetailLocation" readonly></div>
                    <div class="col-md-6"><label class="form-label">Horario</label><input class="form-control" id="journeyDetailSchedule" readonly></div>
                    <div class="col-md-6"><label class="form-label">Fecha</label><input class="form-control" id="journeyDetailDateLabel" readonly></div>
                    <div class="col-12"><label class="form-label" for="journeyDetailActivity">Actividad</label><input class="form-control" name="activity" id="journeyDetailActivity" maxlength="180" placeholder="Actividad para esta fecha"></div>
                    <div class="col-12"><label class="form-label" for="journeyDetailInstructions">Indicaciones (opcional)</label><textarea class="form-control" name="instructions" id="journeyDetailInstructions" rows="4" maxlength="500" placeholder="Indicaciones específicas para esta jornada"></textarea></div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button class="btn btn-outline-secondary d-none" type="button" id="resetJourneyDateDetail"><i class="fa-solid fa-arrow-rotate-left me-2"></i>Restablecer valores</button>
                <div class="ms-auto d-flex gap-2"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar para esta fecha</button></div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="assignmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content needs-validation" id="assignmentForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="assignmentModalTitle">Nueva asignación</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="assignmentId">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">Aplicar a</label>
                        <select class="form-select" name="scope_type" id="assignmentScopeType" required>
                            <option value="all">Todo el personal</option>
                            <option value="worker" hidden>Edici&oacute;n individual</option>
                            <option value="selected">Seleccionar trabajadores</option>
                        </select>
                    </div>
                    <div class="col-md-12 d-none" id="assignmentAvailabilitySummary" aria-live="polite">
                        <div class="assignment-availability-card">
                            <span class="assignment-availability-icon"><i class="fa-solid fa-users"></i></span>
                            <div><strong id="assignmentAvailabilityTitle"></strong><small id="assignmentAvailabilityDetail"></small></div>
                            <button class="btn btn-sm assignment-availability-view" type="button" id="assignmentAvailableWorkersBtn"><i class="fa-solid fa-list-check"></i><span>Ver disponibles</span></button>
                        </div>
                    </div>
                    <div class="col-md-12 d-none" id="assignmentConflictField">
                        <div class="assignment-safety-card">
                            <div class="assignment-safety-icon"><i class="fa-solid fa-shield-halved"></i></div>
                            <div class="flex-grow-1">
                                <span class="assignment-conflict-heading" id="assignmentConflictHeading">Asignaciones encontradas</span>
                                <input type="hidden" name="conflict_policy" id="assignmentConflictPolicy" value="skip">
                                <div class="assignment-conflict-choices">
                                    <label class="assignment-conflict-choice">
                                        <input type="radio" name="assignment_conflict_choice" value="skip" checked>
                                        <span><strong>Asignar solo a disponibles</strong><small id="assignmentSkipDetail">No modifica asignaciones actuales.</small></span>
                                    </label>
                                    <label class="assignment-conflict-choice is-replace">
                                        <input type="radio" name="assignment_conflict_choice" value="replace">
                                        <span><strong>Reemplazar asignaciones</strong><small id="assignmentReplaceDetail">Cierra las actuales y aplica la nueva.</small></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-12 d-none" id="assignmentWorkerField">
                        <label class="form-label">Personal</label>
                        <select class="form-select select2-searchable" name="worker_id" id="assignmentWorkerId" required
                            data-placeholder="Buscar por nombre, documento o empresa"
                            data-no-results="No se encontraron trabajadores">
                            <option value="">Seleccione</option>
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?= (int) $worker['id'] ?>"><?= e($worker['full_name'] . ' - ' . $worker['document_number'] . (!empty($worker['company']) ? ' - ' . $worker['company'] : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 d-none" id="assignmentWorkersField">
                        <div class="assignment-workers-box">
                            <div class="assignment-workers-toolbar">
                                <div>
                                    <strong>Trabajadores</strong>
                                    <small class="text-muted d-block">Seleccione a quienes se aplicar&aacute; esta asignaci&oacute;n.</small>
                                </div>
                                <label class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" id="assignmentSelectAllWorkers">
                                    <span class="form-check-label">Seleccionar todos</span>
                                </label>
                            </div>
                            <input class="form-control form-control-sm mb-2" type="search" id="assignmentWorkerSearch" placeholder="Buscar por nombre, documento o empresa">
                            <div class="assignment-workers-grid" id="assignmentWorkersGrid">
                                <?php foreach ($workers as $worker): ?>
                                    <?php $workerLabel = $worker['full_name'] . ' - ' . $worker['document_number'] . (!empty($worker['company']) ? ' - ' . $worker['company'] : ''); ?>
                                    <label class="assignment-worker-option" data-search="<?= e(strtolower($workerLabel)) ?>"
                                        data-name="<?= e($worker['full_name']) ?>" data-document="<?= e($worker['document_number']) ?>" data-company="<?= e($worker['company'] ?? '') ?>">
                                        <input class="form-check-input assignment-worker-check" type="checkbox" name="worker_ids[]" value="<?= (int) $worker['id'] ?>">
                                        <span><?= e($workerLabel) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="invalid-feedback d-block d-none" id="assignmentWorkersError">Seleccione al menos un trabajador.</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Lugar de marcación</label>
                        <select class="form-select" name="location_id" id="assignmentLocationId" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= (int) $location['id'] ?>"><?= e($location['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Será el lugar de entrada. Durante la jornada podrá trasladarse y habilitar otro lugar para la salida.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Horario</label>
                        <select class="form-select" name="schedule_id" id="assignmentScheduleId" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($schedules as $schedule): ?>
                                <option value="<?= (int) $schedule['id'] ?>"><?= e($schedule['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Actividad</label>
                        <input class="form-control" name="activity" id="assignmentActivity" maxlength="180">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="assignmentInstructions">Indicaciones (opcional)</label>
                        <textarea class="form-control" name="instructions" id="assignmentInstructions" rows="3" maxlength="500" placeholder="Escriba indicaciones útiles para esta asignación"></textarea>
                        <div class="form-text">Estas indicaciones estarán disponibles para orientar al trabajador.</div>
                    </div>
                    <div class="col-12">
                        <div class="assignment-safety-card align-items-start">
                            <div class="assignment-safety-icon"><i class="fa-solid fa-calendar-check"></i></div>
                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                    <div>
                                        <label class="form-label mb-0">Vigencia de la asignaci&oacute;n</label>
                                        <small class="text-muted d-block">Las jornadas y marcaciones solo estar&aacute;n disponibles dentro de este periodo.</small>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="assignmentNoEnd" name="no_end" value="1" checked>
                                        <label class="form-check-label" for="assignmentNoEnd">Sin fecha final</label>
                                    </div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label" for="assignmentValidFrom">Fecha de inicio</label>
                                        <input class="form-control" type="date" name="valid_from" id="assignmentValidFrom" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="assignmentValidUntil">Fecha de finalizaci&oacute;n</label>
                                        <input class="form-control" type="date" name="valid_until" id="assignmentValidUntil" disabled>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button class="btn btn-sm btn-outline-primary js-validity-preset" type="button" data-preset="month">Fin de mes</button>
                                    <button class="btn btn-sm btn-outline-primary js-validity-preset" type="button" data-preset="year">Fin de a&ntilde;o</button>
                                    <button class="btn btn-sm btn-outline-primary js-validity-preset" type="button" data-preset="6months">6 meses</button>
                                    <button class="btn btn-sm btn-outline-primary js-validity-preset" type="button" data-preset="1year">1 a&ntilde;o</button>
                                    <button class="btn btn-sm btn-outline-primary js-validity-preset" type="button" data-preset="2years">2 a&ntilde;os</button>
                                </div>
                            </div>
                        </div>
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

<script>
window.assignmentActivePeriods = <?= json_encode($activeAssignmentPeriods, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<div class="modal fade" id="assignmentHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Historial de asignaciones</h5>
                    <small class="text-muted" id="assignmentHistoryWorker"></small>
                </div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="assignment-history-list" id="assignmentHistoryList">
                    <div class="text-center text-muted py-4">Cargando historial...</div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
