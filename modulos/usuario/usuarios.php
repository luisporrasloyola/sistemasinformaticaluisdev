<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

$users = db()->query("SELECT u.id, u.name, u.email, u.role, u.status, u.worker_id, w.full_name AS worker_name, w.document_number
    FROM users u
    LEFT JOIN workers w ON w.id = u.worker_id
    ORDER BY u.name")->fetchAll();
$workers = db()->query("SELECT w.id, w.full_name, w.document_number, w.email, c.name AS company
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    ORDER BY w.full_name")->fetchAll();
$currentUser = current_user();
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Usuarios</h1>
        <p>Gesti&oacute;n de accesos al sistema.</p>
    </div>
    <button class="btn btn-primary" type="button" id="nuevoUsuarioBtn"><i class="fa-solid fa-plus me-2"></i>Nuevo usuario</button>
</div>

<div class="work-panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle data-table" id="usuariosTable">
            <thead>
            <tr>
                <th>Nombre completo</th>
                <th>Correo electr&oacute;nico</th>
                <th>Rol</th>
                <th>Personal vinculado</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= e($user['name']) ?></td>
                    <td><?= e($user['email']) ?></td>
                    <td><span class="badge text-bg-primary"><?= e($user['role']) ?></span></td>
                    <td><?= e($user['worker_name'] ? $user['worker_name'] . ' - ' . $user['document_number'] : '') ?></td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-primary js-editar-usuario" type="button"
                            data-id="<?= (int) $user['id'] ?>"
                            data-name="<?= e($user['name']) ?>"
                            data-email="<?= e($user['email']) ?>"
                            data-role="<?= e($user['role']) ?>"
                            data-worker-id="<?= (int) ($user['worker_id'] ?? 0) ?>"
                            title="Editar"><i class="fa-solid fa-pen"></i></button>
                        <?php if (!$currentUser || (int) $currentUser['id'] !== (int) $user['id']): ?>
                            <button class="btn btn-sm btn-outline-danger js-eliminar-usuario" type="button" data-id="<?= (int) $user['id'] ?>" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="usuarioModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content needs-validation" id="usuarioForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="usuarioModalTitle">Nuevo usuario</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="usuarioId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nombre completo</label>
                        <input class="form-control" name="name" id="usuarioName" maxlength="120" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Correo electr&oacute;nico</label>
                        <input class="form-control" type="email" name="email" id="usuarioEmail" maxlength="160" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Rol</label>
                        <select class="form-select" name="role" id="usuarioRole" required>
                            <option value="Administrador">Administrador</option>
                            <option value="Personal">Personal</option>
                        </select>
                    </div>
                    <div class="col-md-6 d-none" id="usuarioWorkerGroup">
                        <label class="form-label">Trabajador vinculado</label>
                        <select class="form-select" name="worker_id" id="usuarioWorkerId">
                            <option value="">Seleccione</option>
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?= (int) $worker['id'] ?>"
                                    data-name="<?= e($worker['full_name']) ?>"
                                    data-email="<?= e($worker['email'] ?? '') ?>">
                                    <?= e($worker['full_name'] . ' - ' . $worker['document_number'] . (!empty($worker['company']) ? ' - ' . $worker['company'] : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Obligatorio para rol Personal. No se permitir&aacute;n dos usuarios para el mismo trabajador.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contrase&ntilde;a</label>
                        <input class="form-control" type="password" name="password" id="usuarioPassword" minlength="8" autocomplete="new-password">
                        <div class="form-text" id="usuarioPasswordHelp">M&iacute;nimo 8 caracteres.</div>
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
