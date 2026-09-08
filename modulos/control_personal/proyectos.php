<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_projects.php';
require_module_access('control_personal.proyectos');
ensure_attendance_projects_schema();

$projects = db()->query("SELECT id, name FROM attendance_projects WHERE status = 1 ORDER BY name")->fetchAll();
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Proyectos</h1>
        <p>Gestión de proyectos para el control de personal.</p>
    </div>
    <button class="btn btn-primary" type="button" id="newProjectBtn"><i class="fa-solid fa-plus me-2"></i>Nuevo proyecto</button>
</div>

<div class="work-panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle data-table" id="projectsTable">
            <thead><tr><th>Nombre del proyecto</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($projects as $project): ?>
                <tr>
                    <td><?= e($project['name']) ?></td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-primary js-edit-project" type="button" data-id="<?= (int) $project['id'] ?>" data-name="<?= e($project['name']) ?>" title="Editar"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-sm btn-outline-danger js-delete-project" type="button" data-id="<?= (int) $project['id'] ?>" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="projectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content needs-validation" id="projectForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="projectModalTitle">Nuevo proyecto</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="projectId">
                <label class="form-label" for="projectName">Nombre del proyecto</label>
                <input class="form-control" name="name" id="projectName" maxlength="180" autocomplete="off" required>
                <div class="invalid-feedback">Ingrese el nombre del proyecto.</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>