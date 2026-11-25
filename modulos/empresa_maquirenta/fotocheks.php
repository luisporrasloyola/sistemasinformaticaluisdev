<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa_maquirenta.fotocheks');

$empresas = db()->query('SELECT id, razon_social, ruc FROM empresas_maquirenta WHERE status=1 ORDER BY razon_social')->fetchAll();
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Fotocheks</h1>
        <p>Control de fotocheks de Empresa Maquirenta.</p>
    </div>
</div>

<div class="work-panel mb-3">
    <label class="form-label">Buscar por razón social o RUC</label>
    <select class="form-select" id="maquirentaFotocheksCompanySearch">
        <option value=""></option>
        <?php foreach ($empresas as $empresa): ?>
            <option value="<?= (int) $empresa['id'] ?>"><?= e($empresa['razon_social'] . ' - ' . $empresa['ruc']) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="row g-3 d-none" id="maquirentaFotocheksWorkspace">
    <div class="col-lg-3 machine-profile-col">
        <div class="work-panel h-100">
            <div class="worker-card text-center">
                <img id="maquirentaFotocheksPhoto" src="<?= APP_URL ?>/recursos/imagen_referencial.php" alt="Foto empresa Maquirenta">
                <div class="mt-2">
                    <button class="btn btn-sm btn-outline-primary" type="button" id="changeMaquirentaFotocheksPhotoBtn">Clic para cambiar foto</button>
                    <input class="d-none" type="file" id="maquirentaFotocheksPhotoInput" accept="image/png,image/jpeg,image/webp">
                </div>
            </div>
            <dl class="info-list mt-3">
                <dt>Razón social</dt>
                <dd id="maquirentaFotocheksName"></dd>
                <dt>RUC</dt>
                <dd id="maquirentaFotocheksRuc"></dd>
                <dt>Dirección</dt>
                <dd id="maquirentaFotocheksAddress"></dd>
            </dl>
        </div>
    </div>
    <div class="col-lg-9 machine-table-col">
        <div class="work-panel h-100">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
                <h2 class="mb-0">Fotocheks</h2>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-outline-primary" type="button" id="downloadSelectedMaquirentaFotocheksBtn"><i class="fa-solid fa-file-zipper me-2"></i>Descargar seleccionados</button>
                    <button class="btn btn-outline-primary" type="button" id="downloadMaquirentaFotocheksBtn"><i class="fa-solid fa-download me-2"></i>Descargar todo</button>
                    <button class="btn btn-primary" type="button" id="addMaquirentaFotocheksBtn"><i class="fa-solid fa-plus me-2"></i>Agregar fotocheck</button>
                </div>
            </div>
            <form id="maquirentaFotocheksDocumentSearchForm" class="mb-3" role="search">
                <label class="visually-hidden" for="maquirentaFotocheksDocumentSearchInput">Buscar por fotocheck</label>
                <div class="input-group" style="max-width:420px">
                    <input class="form-control" id="maquirentaFotocheksDocumentSearchInput" type="search" placeholder="Escriba el nombre del fotocheck" autocomplete="off" aria-controls="maquirentaFotocheksTable">
                    <button class="btn btn-outline-primary" type="submit"><i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i>Ver</button>
                </div>
                <div class="small text-muted mt-1" id="maquirentaFotocheksDocumentSearchFeedback" role="status" aria-live="polite"></div>
            </form>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="maquirentaFotocheksTable">
                    <thead>
                        <tr>
                            <th>Seleccionar</th>
                            <th>Fotocheck</th>
                            <th>F. Registro</th>
                            <th>F. Inicio</th>
                            <th>F. Fin</th>
                            <th>Estado</th>
                            <th>Registrado por</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="maquirentaFotocheksModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content needs-validation" id="maquirentaFotocheksForm" novalidate enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title" id="maquirentaFotocheksModalTitle">Agregar fotocheck</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="maquirentaFotocheksId">
                <input type="hidden" name="empresa_maquirenta_id" id="maquirentaFotocheksCompanyId">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Fotocheck</label>
                        <div class="input-group">
                            <select class="form-select" name="documento_id" id="maquirentaFotocheksSelect" required></select>
                            <?php if (current_user_can_manage_scope('empresa_maquirenta.fotocheks')): ?>
                                <button class="btn btn-outline-primary" type="button" id="newMaquirentaFotocheksCatalogBtn" title="Agregar documento"><i class="fa-solid fa-plus"></i></button>
                                <button class="btn btn-outline-danger" type="button" id="deleteMaquirentaFotocheksCatalogBtn" title="Eliminar documento"><i class="fa-solid fa-trash"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">F. Registro</label>
                        <input class="form-control" type="date" name="fecha_registro" id="maquirentaFotocheksRegistration" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">F. Inicio</label>
                        <input class="form-control" type="date" name="fecha_inicio" id="maquirentaFotocheksStart" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">F. Fin</label>
                        <input class="form-control" type="date" name="fecha_fin" id="maquirentaFotocheksEnd" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Observaciones</label>
                        <textarea class="form-control" name="observaciones" id="maquirentaFotocheksObservations" rows="3"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Adjunto (PDF o imagen)</label>
                        <input class="form-control" type="file" name="pdf" id="maquirentaFotocheksFile" accept="application/pdf,image/jpeg,image/png,image/webp">
                        <small class="text-muted d-block mt-1">Formatos permitidos: PDF, JPG, PNG y WEBP.</small>
                        <div class="file-current mt-2 d-none" id="maquirentaFotocheksCurrentFile"></div>
                        <div class="upload-progress mt-2 d-none" id="maquirentaFotocheksProgress">
                            <div class="progress progress-thin">
                                <div class="progress-bar" style="width:0%"></div>
                            </div>
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
<script src="<?= APP_URL ?>/recursos/js/empresa_maquirenta_fotocheks.js?v=<?= filemtime(__DIR__ . '/../../recursos/js/empresa_maquirenta_fotocheks.js') ?>"></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
