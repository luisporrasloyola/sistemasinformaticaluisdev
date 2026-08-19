<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_module_access('empresa_maquirenta.pms');
require __DIR__ . '/../../includes/header.php';
?>
<style>
.pms-table th {
    white-space: nowrap;
}
.pms-actions, .pms-row-actions {
    display: flex;
    gap: .55rem;
    flex-wrap: wrap;
}
.pms-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}
.pms-toolbar-copy {
    min-width: 190px;
    flex: 1 1 auto;
}
.pms-toolbar-copy h2 {
    white-space: nowrap;
}
.pms-toolbar .pms-actions {
    flex: 0 0 auto;
    flex-wrap: nowrap;
    gap: .45rem;
}
.pms-toolbar .pms-actions .btn {
    white-space: nowrap;
    padding-left: .75rem;
    padding-right: .75rem;
    font-size: .9rem;
}
@media(max-width: 991.98px) {
    .pms-toolbar {
        align-items: flex-start;
        flex-wrap: wrap;
    }
    .pms-toolbar .pms-actions {
        flex-wrap: wrap;
    }
}
.pms-row-actions .btn {
    width: 36px;
    height: 36px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.document-fixed {
    background: #f1f5f9!important;
    font-weight: 700;
}
.pms-select-field .select2-container {
    width: 100%!important;
}
.pms-select-field .select2-container--bootstrap4 .select2-selection--single {
    height: 38px!important;
    border: 1px solid #cbd5e1!important;
    border-radius: 7px!important;
    background: #fff!important;
    box-shadow: none!important;
    padding: 0 38px 0 12px!important;
    display: flex!important;
    align-items: center!important;
}
.pms-select-field .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered {
    padding: 0!important;
    line-height: 36px!important;
    color: #334155!important;
}
.pms-select-field .select2-container--bootstrap4 .select2-selection--single .select2-selection__placeholder {
    color: #64748b!important;
}
.pms-select-field .select2-container--bootstrap4 .select2-selection--single .select2-selection__arrow {
    height: 36px!important;
    right: 10px!important;
    top: 0!important;
}
.pms-select-field .select2-container--bootstrap4.select2-container--focus .select2-selection,
.pms-select-field .select2-container--bootstrap4.select2-container--open .select2-selection {
    border-color: #80b3ff!important;
    box-shadow: 0 0 0 .2rem rgba(13,110,253,.2)!important;
}
.select2-container--open .select2-dropdown {
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    overflow: hidden;
    box-shadow: 0 10px 25px rgba(15,23,42,.14);
}
.select2-search--dropdown {
    padding: 8px;
}
.select2-search--dropdown .select2-search__field {
    border: 1px solid #cbd5e1!important;
    border-radius: 6px!important;
    padding: 8px 10px!important;
    outline: none;
}
.select2-search--dropdown .select2-search__field:focus {
    border-color: #80b3ff!important;
    box-shadow: 0 0 0 .15rem rgba(13,110,253,.15);
}
</style>

<div class="page-title">
    <div>
        <h1>PMS</h1>
        <p>Central Térmica Ventanilla</p>
    </div>
</div>

<section class="work-panel" id="pmsDocumentsPanel">
    <div class="pms-toolbar">
        <div class="pms-toolbar-copy">
            <h2 class="mb-1">PMS</h2>
            <p class="text-muted mb-0">Listado de documentos PMS registrados.</p>
        </div>
        <div class="pms-actions">
            <button class="btn btn-outline-primary" type="button" id="downloadSelectedPmsBtn" disabled><i class="fa-solid fa-file-zipper me-2"></i>Descargar seleccionados</button>
            <button class="btn btn-outline-primary" type="button" id="downloadAllPmsBtn" disabled><i class="fa-solid fa-download me-2"></i>Descargar todo</button>
            <button class="btn btn-primary" type="button" id="addPmsDocumentBtn"><i class="fa-solid fa-plus me-2"></i>Agregar documentos</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle pms-table mb-0">
            <thead>
                <tr>
                    <th style="width: 50px;" class="text-center">Seleccionar</th>
                    <th>Rango semanal</th>
                    <th>Nro. PMS</th>
                    <th>Estado</th>
                    <th>Registrado por</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="pmsDocumentsBody">
                <tr>
                    <td colspan="6" class="text-center py-4 text-muted"><i class="fa-regular fa-folder-open me-2"></i>No hay documentos registrados.</td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<div class="modal fade" id="pmsDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" id="pmsDocumentForm" enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title" id="pmsModalTitle">Agregar documentos</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="pmsId">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Documento</label>
                        <input class="form-control document-fixed" value="PMS" readonly>
                    </div>
                    <div class="col-md-8 pms-select-field">
                        <label class="form-label">Rango semanal</label>
                        <select class="form-select" id="pmsWeeklyRange" required>
                            <option value="">Seleccione un rango semanal</option>
                        </select>
                        <input type="hidden" name="rango_inicio" id="pmsRangeStart">
                        <input type="hidden" name="rango_fin" id="pmsRangeEnd">
                    </div>
                    <div class="col-md-4 pms-select-field">
                        <label class="form-label">Nro. PMS</label>
                        <select class="form-select" name="nro_pms" id="pmsNumber" required>
                            <option value="">Seleccione PMS</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Observaciones</label>
                        <textarea class="form-control" name="observaciones" id="pmsObservations" rows="4" placeholder="Ingrese una observación..."></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Adjunto (PDF, imagen o Excel)</label>
                        <input class="form-control" type="file" name="adjunto" id="pmsAttachment" accept="application/pdf,image/jpeg,image/png,image/webp,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                        <small class="text-muted d-block mt-1">Formatos permitidos: PDF, JPG, PNG, WEBP, XLS y XLSX.</small>
                        <div class="mt-2 d-none" id="pmsCurrentFile"></div>
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
window.pmsMaquirentaConfig = {
    serviceUrl: <?= json_encode(APP_URL . '/servicios/empresa_maquirenta/pms.php') ?>,
    csrfToken: <?= json_encode(csrf_token()) ?>
};
</script>
<script src="<?= APP_URL ?>/recursos/js/empresa_maquirenta_pms.js?v=<?= filemtime(__DIR__ . '/../../recursos/js/empresa_maquirenta_pms.js') ?>"></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
