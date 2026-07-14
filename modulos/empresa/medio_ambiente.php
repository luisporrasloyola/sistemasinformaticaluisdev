<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa.medio_ambiente');

$empresas = db()->query('SELECT id, razon_social, ruc FROM empresas WHERE status = 1 ORDER BY razon_social')->fetchAll();
$catalogo = filter_allowed_documents('empresa.medio_ambiente', db()->query('SELECT id, nombre FROM empresa_medio_ambiente_catalogo WHERE estado = 1 ORDER BY id')->fetchAll(), 'id', 'upload');
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Medio ambiente de empresa</h1>
        <p>Control documentario ambiental por empresa.</p>
    </div>
</div>

<div class="company-generic-module" data-company-module="medio_ambiente" data-module-title="Medio ambiente">
    <div class="work-panel mb-3">
        <label class="form-label">Buscar por razon social o RUC</label>
        <select class="form-select js-company-generic-search">
            <option value=""></option>
            <?php foreach ($empresas as $empresa): ?>
                <option value="<?= (int) $empresa['id'] ?>"><?= e($empresa['razon_social'] . ' - ' . $empresa['ruc']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="row g-3 d-none js-company-generic-workspace">
        <div class="col-lg-3 col-xl-3 machine-profile-col">
            <div class="work-panel h-100">
                <div class="worker-card text-center">
                    <img class="js-company-generic-photo" src="<?= APP_URL ?>/recursos/imagen_referencial.php" alt="Foto empresa">
                    <div class="mt-2">
                        <button class="btn btn-sm btn-outline-primary js-company-generic-photo-btn" type="button">Clic para cambiar foto</button>
                        <input class="d-none js-company-generic-photo-input" type="file" accept="image/png,image/jpeg,image/webp">
                    </div>
                </div>
                <dl class="info-list mt-3">
                    <dt>Razon Social</dt><dd class="js-company-generic-name"></dd>
                    <dt>RUC</dt><dd class="js-company-generic-ruc"></dd>
                    <dt>Direccion</dt><dd class="js-company-generic-address"></dd>
                </dl>
            </div>
        </div>
        <div class="col-lg-9 col-xl-9 machine-table-col">
            <div class="work-panel h-100">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
                    <h2 class="mb-0">Medio ambiente</h2>
                    <div class="d-flex gap-2 flex-wrap">
                        <button class="btn btn-outline-primary js-company-generic-download-selected" type="button"><i class="fa-solid fa-file-zipper me-2"></i>Descargar seleccionados</button>
                        <button class="btn btn-outline-primary js-company-generic-download-all" type="button"><i class="fa-solid fa-download me-2"></i>Descargar todo</button>
                        <button class="btn btn-primary js-company-generic-add" type="button"><i class="fa-solid fa-plus me-2"></i>Agregar documentos</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>Seleccionar</th><th>Documentos</th><th>F. Registro</th><th>F. Inicio</th><th>F. Fin</th><th>Estado</th><th>Acciones</th></tr></thead>
                        <tbody class="js-company-generic-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade js-company-generic-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form class="modal-content needs-validation js-company-generic-form" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title js-company-generic-modal-title">Agregar documentos</h5>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="module" value="medio_ambiente">
                    <input type="hidden" name="id" class="js-company-generic-id">
                    <input type="hidden" name="empresa_id" class="js-company-generic-company-id">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Documentos</label>
                            <div class="input-group">
                                <select class="form-select js-company-generic-select" name="documento_id" required>
                                    <option value="">Seleccione</option>
                                    <?php foreach ($catalogo as $documento): ?>
                                        <option value="<?= (int) $documento['id'] ?>"><?= e($documento['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (current_user_can_manage_scope('empresa.medio_ambiente')): ?>
                                    <button class="btn btn-outline-primary js-company-generic-new-catalog" type="button" title="Agregar documento"><i class="fa-solid fa-plus"></i></button>
                                    <button class="btn btn-outline-danger js-company-generic-delete-catalog" type="button" title="Eliminar documento"><i class="fa-solid fa-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-4"><label class="form-label">F. Registro</label><input class="form-control js-company-generic-registration" type="date" name="fecha_registro" required></div>
                        <div class="col-md-4"><label class="form-label">F. Inicio</label><input class="form-control js-company-generic-start" type="date" name="fecha_inicio" required></div>
                        <div class="col-md-4"><label class="form-label">F. Fin</label><input class="form-control js-company-generic-end" type="date" name="fecha_fin" required></div>
                        <div class="col-md-12"><label class="form-label">Observaciones</label><textarea class="form-control js-company-generic-observations" name="observaciones" rows="3"></textarea></div>
                        <div class="col-md-12">
                            <label class="form-label">Adjunto PDF</label>
                            <input class="form-control js-company-generic-pdf-input" type="file" name="pdf" accept="application/pdf">
                            <div class="file-current mt-2 d-none js-company-generic-current-pdf"></div>
                            <div class="upload-progress mt-2 d-none js-company-generic-progress">
                                <div class="progress progress-thin"><div class="progress-bar" role="progressbar" style="width: 0%" aria-valuemin="0" aria-valuemax="100"></div></div>
                                <small class="text-muted">Subiendo archivo: 0%</small>
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
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
