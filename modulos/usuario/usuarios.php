<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';
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
$permissionModules = permission_modules_catalog();
$permissionCatalogItems = permission_catalog_items();
$permissionsByUser = [];
foreach ($users as $user) {
    $permissionsByUser[(string) $user['id']] = permission_payload_for_user((int) $user['id'], (string) $user['role']);
}
require __DIR__ . '/../../includes/header.php';
?>
<script>
window.usuarioPermisos = <?= json_encode([
    'modules' => $permissionModules,
    'catalogs' => $permissionCatalogItems,
    'users' => $permissionsByUser,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
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
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form class="modal-content needs-validation" id="usuarioForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="usuarioModalTitle">Nuevo usuario</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="usuarioId">
                <ul class="nav nav-pills usuario-tabs mb-3" id="usuarioTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="usuarioDatosTab" data-bs-toggle="pill" data-bs-target="#usuarioDatosPane" type="button" role="tab">Datos de usuario</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="usuarioPermisosTab" data-bs-toggle="pill" data-bs-target="#usuarioPermisosPane" type="button" role="tab">Permisos</button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="usuarioDatosPane" role="tabpanel" tabindex="0">
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
                                    <option value="Gestor">Gestor</option>
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
                    <div class="tab-pane fade" id="usuarioPermisosPane" role="tabpanel" tabindex="0">
                        <div class="permission-role-note mb-3" id="usuarioPermissionNote"></div>
                        <div class="permission-section mb-3">
                            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                                <div>
                                    <h6 class="mb-1">Acceso por m&oacute;dulos</h6>
                                    <small class="text-muted">Seleccione los m&oacute;dulos y subm&oacute;dulos visibles para el usuario.</small>
                                </div>
                                <label class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" id="usuarioSelectAllModules">
                                    <span class="form-check-label">Acceso total</span>
                                </label>
                            </div>
                            <div class="permission-module-grid">
                                <?php foreach ($permissionModules as $moduleKey => $module): ?>
                                    <div class="permission-card">
                                        <label class="form-check permission-parent">
                                            <input class="form-check-input usuario-module-permission" type="checkbox" name="module_permissions[]" value="<?= e($moduleKey) ?>" data-parent="<?= e($moduleKey) ?>">
                                            <span class="form-check-label"><?= e($module['label']) ?></span>
                                        </label>
                                        <?php if (!empty($module['children'])): ?>
                                            <div class="permission-child-list">
                                                <?php foreach ($module['children'] as $childKey => $childLabel): ?>
                                                    <label class="form-check">
                                                        <input class="form-check-input usuario-module-permission" type="checkbox" name="module_permissions[]" value="<?= e($childKey) ?>" data-parent="<?= e($moduleKey) ?>">
                                                        <span class="form-check-label"><?= e($childLabel) ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="permission-section">
                            <div class="mb-2">
                                <h6 class="mb-1">Requisitos y documentos permitidos</h6>
                                <small class="text-muted">Defina qu&eacute; cat&aacute;logos puede visualizar o subir el usuario.</small>
                            </div>
                            <div class="accordion permission-accordion" id="usuarioDocumentPermissions">
                                <?php $scopeIndex = 0; ?>
                                <?php foreach ($permissionCatalogItems as $scopeKey => $scope): ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header">
                                            <div class="permission-scope-header">
                                                <button class="accordion-button <?= $scopeIndex > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#permissionScope<?= $scopeIndex ?>" aria-expanded="<?= $scopeIndex === 0 ? 'true' : 'false' ?>">
                                                    <?= e($scope['label']) ?>
                                                </button>
                                                <label class="form-check form-switch permission-scope-all" onclick="event.stopPropagation()">
                                                    <input class="form-check-input usuario-document-scope-all" type="checkbox" data-scope="<?= e($scopeKey) ?>">
                                                    <span class="form-check-label">Acceso total</span>
                                                </label>
                                            </div>
                                        </h2>
                                        <div id="permissionScope<?= $scopeIndex ?>" class="accordion-collapse collapse <?= $scopeIndex === 0 ? 'show' : '' ?>" data-bs-parent="#usuarioDocumentPermissions">
                                            <div class="accordion-body p-0">
                                                <div class="table-responsive">
                                                    <table class="table table-sm align-middle mb-0 permission-table">
                                                        <thead>
                                                        <tr>
                                                            <th>Documento / requisito</th>
                                                            <th class="text-center">Visualizar</th>
                                                            <th class="text-center">Subir / editar</th>
                                                            <th class="text-center">Agregar / eliminar</th>
                                                        </tr>
                                                        </thead>
                                                        <tbody>
                                                        <?php foreach ($scope['items'] as $item): ?>
                                                            <tr>
                                                                <td><?= e($item['name']) ?></td>
                                                                <td class="text-center">
                                                                    <input class="form-check-input usuario-document-view" type="checkbox" name="document_view_permissions[<?= e($scopeKey) ?>][]" value="<?= (int) $item['id'] ?>" data-scope="<?= e($scopeKey) ?>" data-catalog-id="<?= (int) $item['id'] ?>">
                                                                </td>
                                                                <td class="text-center">
                                                                    <input class="form-check-input usuario-document-upload" type="checkbox" name="document_upload_permissions[<?= e($scopeKey) ?>][]" value="<?= (int) $item['id'] ?>" data-scope="<?= e($scopeKey) ?>" data-catalog-id="<?= (int) $item['id'] ?>">
                                                                </td>
                                                                <td class="text-center">
                                                                    <input class="form-check-input usuario-document-manage" type="checkbox" name="document_manage_permissions[<?= e($scopeKey) ?>][]" value="<?= (int) $item['id'] ?>" data-scope="<?= e($scopeKey) ?>" data-catalog-id="<?= (int) $item['id'] ?>">
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($scope['items'])): ?>
                                                            <tr><td colspan="4" class="text-muted text-center py-3">No hay cat&aacute;logos activos.</td></tr>
                                                        <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php $scopeIndex++; ?>
                                <?php endforeach; ?>
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
<?php require __DIR__ . '/../../includes/footer.php'; ?>
