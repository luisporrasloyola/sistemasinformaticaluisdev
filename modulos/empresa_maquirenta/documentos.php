<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa_maquirenta.documentos');

$empresas = db()->query("SELECT id, razon_social, ruc FROM empresas_maquirenta WHERE status = 1 ORDER BY razon_social")->fetchAll();
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Central Térmica Ventanilla</h1>
        <p>Control documentario de Empresa Maquirenta.</p>
    </div>
</div>

<div class="work-panel mb-3">
    <label class="form-label">Buscar por razón social o RUC</label>
    <select class="form-select" id="maquirentaDocumentCompanySearch">
        <option value=""></option>
        <?php foreach ($empresas as $empresa): ?>
            <option value="<?= (int) $empresa['id'] ?>"><?= e($empresa['razon_social'] . ' - ' . $empresa['ruc']) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="row g-3 d-none" id="maquirentaDocumentsWorkspace">
    <div class="col-lg-3 col-xl-3 machine-profile-col">
        <div class="work-panel h-100">
            <div class="worker-card text-center">
                <img id="maquirentaDocumentPhoto" src="<?= APP_URL ?>/recursos/imagen_referencial.php" alt="Foto empresa Maquirenta">
                <div class="mt-2">
                    <button class="btn btn-sm btn-outline-primary" type="button" id="changeMaquirentaDocumentPhotoBtn">Clic para cambiar foto</button>
                    <input class="d-none" type="file" id="maquirentaDocumentPhotoInput" accept="image/png,image/jpeg,image/webp">
                </div>
            </div>
            <dl class="info-list mt-3">
                <dt>Razón social</dt><dd id="maquirentaDocumentName"></dd>
                <dt>RUC</dt><dd id="maquirentaDocumentRuc"></dd>
                <dt>Dirección</dt><dd id="maquirentaDocumentAddress"></dd>
            </dl>
        </div>
    </div>
    <div class="col-lg-9 col-xl-9 machine-table-col">
        <div class="work-panel h-100">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
                <h2 class="mb-0">Central Térmica Ventanilla</h2>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-outline-primary" type="button" id="downloadSelectedMaquirentaDocumentsBtn"><i class="fa-solid fa-file-zipper me-2"></i>Descargar seleccionados</button>
                    <button class="btn btn-outline-primary" type="button" id="downloadMaquirentaDocumentsBtn"><i class="fa-solid fa-download me-2"></i>Descargar todo</button>
                    <button class="btn btn-primary" type="button" id="addMaquirentaDocumentBtn"><i class="fa-solid fa-plus me-2"></i>Agregar documentos</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="maquirentaDocumentsTable">
                    <thead><tr><th>Seleccionar</th><th>Documentos</th><th>F. Registro</th><th>F. Inicio</th><th>F. Fin</th><th>Estado</th><th>Registrado por</th><th>Acciones</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="maquirentaDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content needs-validation" id="maquirentaDocumentForm" novalidate enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title" id="maquirentaDocumentModalTitle">Agregar documentos</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="maquirentaDocumentId">
                <input type="hidden" name="empresa_maquirenta_id" id="maquirentaDocumentCompanyId">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">Documentos</label>
                        <div class="input-group">
                            <select class="form-select" name="documento_id" id="maquirentaDocumentSelect" required></select>
                            <?php if (current_user_can_manage_scope('empresa_maquirenta.documentos')): ?>
                                <button class="btn btn-outline-primary" type="button" id="newMaquirentaCatalogDocumentBtn" title="Agregar documento"><i class="fa-solid fa-plus"></i></button>
                                <button class="btn btn-outline-danger" type="button" id="deleteMaquirentaCatalogDocumentBtn" title="Eliminar documento"><i class="fa-solid fa-trash"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-12 d-none" id="maquirentaSegmentValueField">
                        <label class="form-label" id="maquirentaSegmentValueLabel" for="maquirentaSegmentValue">Segmento</label>
                        <input class="form-control" name="segmento_valor" id="maquirentaSegmentValue" maxlength="80">
                        <select class="form-select d-none" id="maquirentaSegmentMonth">
                            <option value="">Seleccione un mes</option>
                            <option value="1">Enero</option><option value="2">Febrero</option><option value="3">Marzo</option>
                            <option value="4">Abril</option><option value="5">Mayo</option><option value="6">Junio</option>
                            <option value="7">Julio</option><option value="8">Agosto</option><option value="9">Septiembre</option>
                            <option value="10">Octubre</option><option value="11">Noviembre</option><option value="12">Diciembre</option>
                        </select>
                        <small class="text-muted" id="maquirentaSegmentHelp"></small>
                    </div>
                    <div class="col-md-6 d-none" id="maquirentaSegmentYearField">
                        <label class="form-label" for="maquirentaSegmentYear">Año</label>
                        <input class="form-control" type="number" name="periodo_anio" id="maquirentaSegmentYear" min="2000" max="2100">
                    </div>
                    <div class="col-md-4"><label class="form-label">F. Registro</label><input class="form-control" type="date" name="fecha_registro" id="maquirentaRegistrationDate" required></div>
                    <div class="col-md-4"><label class="form-label">F. Inicio</label><input class="form-control" type="date" name="fecha_inicio" id="maquirentaStartDate" required></div>
                    <div class="col-md-4"><label class="form-label">F. Fin</label><input class="form-control" type="date" name="fecha_fin" id="maquirentaEndDate" required></div>
                    <div class="col-md-12"><label class="form-label">Observaciones</label><textarea class="form-control" name="observaciones" id="maquirentaDocumentObservations" rows="3"></textarea></div>
                    <div class="col-md-12">
                        <label class="form-label">Adjunto (PDF o imagen)</label>
                        <input class="form-control" type="file" name="pdf" id="maquirentaDocumentFile" accept="application/pdf,image/jpeg,image/png,image/webp">
                        <small class="text-muted d-block mt-1">Formatos permitidos: PDF, JPG, PNG y WEBP.</small>
                        <div class="file-current mt-2 d-none" id="maquirentaCurrentFile"></div>
                        <div class="upload-progress mt-2 d-none" id="maquirentaUploadProgress"><div class="progress progress-thin"><div class="progress-bar" role="progressbar" style="width:0%"></div></div><small class="text-muted">Subiendo archivo: 0%</small></div>
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
<script src="<?= APP_URL ?>/recursos/js/empresa_maquirenta_documentos.js?v=<?= filemtime(__DIR__ . '/../../recursos/js/empresa_maquirenta_documentos.js') ?>"></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
