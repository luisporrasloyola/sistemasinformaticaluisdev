<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_login();

$users = db()->query("SELECT id, name, email, role, status FROM users ORDER BY name")->fetchAll();
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
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= e($user['name']) ?></td>
                    <td><?= e($user['email']) ?></td>
                    <td><span class="badge text-bg-primary"><?= e($user['role']) ?></span></td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-primary js-editar-usuario" type="button"
                            data-id="<?= (int) $user['id'] ?>"
                            data-name="<?= e($user['name']) ?>"
                            data-email="<?= e($user['email']) ?>"
                            data-role="<?= e($user['role']) ?>"
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
                        </select>
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
