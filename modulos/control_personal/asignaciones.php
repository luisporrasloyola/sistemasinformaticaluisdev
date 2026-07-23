<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.asignaciones');

$assignments = db()->query("SELECT aa.*, w.full_name, w.document_number, l.name AS location_name, s.name AS schedule_name,
        GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') AS positions
    FROM attendance_assignments aa
    JOIN workers w ON w.id = aa.worker_id
    JOIN attendance_locations l ON l.id = aa.location_id
    JOIN attendance_schedules s ON s.id = aa.schedule_id
    LEFT JOIN worker_positions wp ON wp.worker_id = w.id
    LEFT JOIN positions p ON p.id = wp.position_id
    WHERE aa.status = 1
    GROUP BY aa.id
    ORDER BY w.full_name")->fetchAll();

$workers = db()->query("SELECT w.id, w.full_name, w.document_number, c.name AS company
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    ORDER BY w.full_name")->fetchAll();
$locations = db()->query('SELECT id, name FROM attendance_locations WHERE status = 1 ORDER BY name')->fetchAll();
$schedules = db()->query('SELECT id, name FROM attendance_schedules WHERE status = 1 ORDER BY name')->fetchAll();

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Asignaciones</h1>
        <p>Relación entre trabajador, lugar, horario y actividad.</p>
    </div>
    <button class="btn btn-primary" type="button" id="newAssignmentBtn"><i class="fa-solid fa-plus me-2"></i>Nueva asignación</button>
</div>

<div class="work-panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle data-table" id="assignmentsTable">
            <thead>
            <tr>
                <th>Personal</th>
                <th>Puesto</th>
                <th>Lugar de marcaci&oacute;n</th>
                <th>Horario</th>
                <th>Actividad</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($assignments as $assignment): ?>
                <tr>
                    <td><?= e($assignment['full_name'] . ' - ' . $assignment['document_number']) ?></td>
                    <td><?= e($assignment['positions'] ?? '') ?></td>
                    <td><?= e($assignment['location_name']) ?></td>
                    <td><?= e($assignment['schedule_name']) ?></td>
                    <td><?= e($assignment['activity'] ?? '') ?></td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-primary js-edit-assignment" type="button"
                            data-id="<?= (int) $assignment['id'] ?>"
                            data-worker-id="<?= (int) $assignment['worker_id'] ?>"
                            data-location-id="<?= (int) $assignment['location_id'] ?>"
                            data-schedule-id="<?= (int) $assignment['schedule_id'] ?>"
                            data-activity="<?= e($assignment['activity'] ?? '') ?>"
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
                            <option value="worker">Un trabajador</option>
                            <option value="selected">Seleccionar trabajadores</option>
                        </select>
                    </div>
                    <div class="col-md-12" id="assignmentConflictField">
                        <div class="assignment-safety-card">
                            <div class="assignment-safety-icon"><i class="fa-solid fa-shield-halved"></i></div>
                            <div class="flex-grow-1">
                                <label class="form-label" for="assignmentConflictPolicy">Si el trabajador ya tiene una asignación activa</label>
                                <select class="form-select" name="conflict_policy" id="assignmentConflictPolicy">
                                    <option value="skip">Conservarla y omitir al trabajador (recomendado)</option>
                                    <option value="replace">Finalizar la anterior y crear una nueva asignación</option>
                                </select>
                                <small class="text-muted d-block mt-2">Las asignaciones anteriores se conservarán como historial para reportes y marcaciones.</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-12 d-none" id="assignmentWorkerField">
                        <label class="form-label">Personal</label>
                        <select class="form-select" name="worker_id" id="assignmentWorkerId" required>
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
                                    <label class="assignment-worker-option" data-search="<?= e(strtolower($workerLabel)) ?>">
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
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar</button>
            </div>
        </form>
    </div>
</div>

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
<?php require __DIR__ . '/../../includes/footer.php'; ?>
