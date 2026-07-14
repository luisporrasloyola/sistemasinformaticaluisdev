<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('requisitos.pmi_masivo');

$today = date('Y-m-d');

$workers = db()->query("SELECT w.id, w.full_name, w.document_number, c.name AS company
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    ORDER BY c.name, w.full_name")->fetchAll();

$workerIds = array_column($workers, 'id');
$positionsByWorker = [];

if ($workerIds) {
    $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
    $stmt = db()->prepare("SELECT wp.worker_id, p.id, p.name
        FROM worker_positions wp
        JOIN positions p ON p.id = wp.position_id
        WHERE wp.worker_id IN ($placeholders)
        ORDER BY p.name");
    $stmt->execute($workerIds);
    foreach ($stmt->fetchAll() as $position) {
        $positionsByWorker[(int) $position['worker_id']][] = $position;
    }
}

$stmt = db()->prepare("SELECT id, name FROM requirements_catalog WHERE name IN ('SCTR', 'VIDA LEY') ORDER BY FIELD(name, 'SCTR', 'VIDA LEY')");
$stmt->execute();
$requirements = $stmt->fetchAll();

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Requisito PMI Masivo</h1>
        <p>Registro masivo de SCTR y Vida Ley para todos los puestos del trabajador.</p>
    </div>
</div>

<form class="work-panel needs-validation" id="pmiMasivoForm" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
        <h2 class="mb-0">Personal disponible</h2>
        <div class="masivo-toolbar">
            <div class="input-group masivo-filtro">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input class="form-control" type="search" id="filtroMasivo" placeholder="Buscar:">
            </div>
            <button class="btn btn-outline-secondary" type="button" id="seleccionarTodosMasivo"><i class="fa-solid fa-check-double me-2"></i>Seleccionar visibles</button>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Aplicar a registros seleccionados</button>
        </div>
    </div>
    <div class="bulk-requirements-panel mb-3">
        <div class="bulk-requirements-title">
            <strong>Aplicación masiva</strong>
            <span class="text-muted small">Seleccione SCTR o VIDA LEY, adjunte su PDF y aplique a los registros seleccionados.</span>
        </div>
        <div class="row g-2 align-items-end">
            <?php foreach ($requirements as $requirement): ?>
                <div class="col-lg-6">
                    <div class="bulk-requirement-item">
                        <div class="form-check mb-2">
                            <input class="form-check-input bulk-requirement-check" type="checkbox" name="bulk_requirements[]" id="bulkRequirement<?= (int) $requirement['id'] ?>" value="<?= (int) $requirement['id'] ?>">
                            <label class="form-check-label fw-bold" for="bulkRequirement<?= (int) $requirement['id'] ?>"><?= e($requirement['name']) ?></label>
                        </div>
                        <input class="form-control bulk-requirement-file" type="file" name="bulk_documents[<?= (int) $requirement['id'] ?>]" accept="application/pdf" disabled>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="row g-2 mt-3">
            <div class="col-md-4">
                <label class="form-label" for="bulkRegistrationDate">F. Registro</label>
                <input class="form-control bulk-date-required" type="date" name="registration_date" id="bulkRegistrationDate" value="<?= e($today) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="bulkStartDate">F. Inicio</label>
                <input class="form-control bulk-date-required" type="date" name="start_date" id="bulkStartDate" value="<?= e($today) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="bulkEndDate">F. Fin</label>
                <input class="form-control bulk-date-required" type="date" name="end_date" id="bulkEndDate" required>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle masivo-table">
            <thead>
            <tr>
                <th>Seleccionar</th>
                <th>Empresa</th>
                <th>Apellido y Nombre</th>
                <th>Nro. Documento</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($workers as $index => $worker): ?>
                <?php
                $positions = $positionsByWorker[(int) $worker['id']] ?? [];
                $positionNames = implode(' ', array_column($positions, 'name'));
                $filterText = trim(($worker['company'] ?? '') . ' ' . $worker['full_name'] . ' ' . $worker['document_number'] . ' ' . $positionNames . ' SCTR VIDA LEY');
                ?>
                <tr data-row="<?= $index ?>" data-filter="<?= e(mb_strtolower($filterText, 'UTF-8')) ?>">
                    <td class="text-center">
                        <input class="form-check-input masivo-check" type="checkbox" name="rows[<?= $index ?>][selected]" value="1">
                        <input type="hidden" name="rows[<?= $index ?>][worker_id]" value="<?= (int) $worker['id'] ?>">
                    </td>
                    <td><?= e($worker['company'] ?? '') ?></td>
                    <td>
                        <strong><?= e($worker['full_name']) ?></strong>
                    </td>
                    <td><?= e($worker['document_number']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>






