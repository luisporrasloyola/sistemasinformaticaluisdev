<?php
require_once __DIR__ . '/../../includes/security.php';
require_module_access('requisitos.pmi_individual');
$personalView = is_personal_role();
$pmiWorkerId = $personalView ? (int) (current_user_worker_id() ?? 0) : max(0, (int) ($_GET['worker_id'] ?? 0));
$pmiWorkerLabel = '';
if ($pmiWorkerId) {
    require_once __DIR__ . '/../../config/database.php';
    $stmt = db()->prepare("SELECT CONCAT(w.full_name, ' - ', w.document_number, ' - ', COALESCE(c.name, 'Sin empresa'))
        FROM workers w LEFT JOIN companies c ON c.id = w.company_id WHERE w.id = :id");
    $stmt->execute(['id' => $pmiWorkerId]);
    $pmiWorkerLabel = (string) $stmt->fetchColumn();
}
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Requisitos PMI Individual</h1>
        <p>Control documentario por trabajador y puesto.</p>
    </div>
</div>

<div class="work-panel mb-3">
    <label class="form-label"><?= $personalView ? 'Mi información' : 'Buscar por nombre, DNI / documento o empresa' ?></label>
    <select class="form-select" id="workerSearch" <?= $personalView ? 'disabled' : '' ?>><?php if ($pmiWorkerId): ?><option value="<?= $pmiWorkerId ?>" selected><?= e($pmiWorkerLabel) ?></option><?php endif; ?></select>
</div>

<div class="row g-3 d-none" id="requirementsWorkspace">
    <div class="col-lg-3 col-xl-3 requirements-profile-col">
        <div class="work-panel h-100">
            <div class="worker-card text-center">
                <img id="workerPhoto" src="<?= APP_URL ?>/recursos/imagen_referencial.php" alt="Foto trabajador">
                <label class="btn btn-sm btn-outline-primary mt-2 <?= $personalView ? 'd-none' : '' ?>">
                    Clic para cambiar foto
                    <input class="d-none" id="quickPhotoInput" type="file" accept="image/png,image/jpeg,image/webp">
                </label>
            </div>
            <dl class="info-list mt-3">
                <dt>Documento</dt><dd id="workerDocument"></dd>
                <dt>Trabajador</dt><dd id="workerName"></dd>
                <dt>Empresa</dt><dd id="workerCompany"></dd>
                <dt>Puesto de trabajo</dt><dd id="workerPositions"></dd>
            </dl>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="workerActive" disabled>
                <label class="form-check-label" for="workerActive">Activo</label>
            </div>
            <label class="form-label">Select de Puesto de Trabajo</label>
            <select class="form-select" id="positionSelect"></select>
        </div>
    </div>
    <div class="col-lg-9 col-xl-9 requirements-table-col">
        <div class="work-panel h-100">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
                <h2 class="mb-0">Requisitos</h2>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-outline-primary" type="button" id="downloadSelectedRequirementsBtn"><i class="fa-solid fa-file-zipper me-2"></i>Descargar seleccionados</button>
                    <button class="btn btn-outline-primary" type="button" id="downloadRequirementsBtn"><i class="fa-solid fa-download me-2"></i>Descargar todo</button>
                    <button class="btn btn-outline-primary <?= $personalView ? 'd-none' : '' ?>" type="button" id="createRequirementFolderBtn"><i class="fa-solid fa-folder-plus me-2"></i>Crear carpeta</button>
                    <button class="btn btn-primary <?= $personalView ? 'd-none' : '' ?>" type="button" id="addRequirementBtn"><i class="fa-solid fa-plus me-2"></i>Agregar Requisito</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="requirementsTable">
                    <thead>
                    <tr>
                        <th><input class="form-check-input" type="checkbox" id="selectAllRequirements" aria-label="Seleccionar todos los registros visibles"></th>
                        <th>Requisito</th>
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

<div class="modal fade" id="requirementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content needs-validation" id="requirementForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="requirementModalTitle">Agregar Requisito</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="requirementId">
                <input type="hidden" name="worker_id" id="requirementWorkerId">
                <input type="hidden" name="position_id" id="requirementPositionId">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">Requisito</label>
                        <div class="input-group">
                            <select class="form-select" name="requirement_id" id="requirementSelect" required></select>
                            <?php if (current_user_can_manage_scope('requisitos.pmi_individual')): ?>
                                <button class="btn btn-outline-primary" type="button" id="newCatalogRequirementBtn" title="Agregar requisito"><i class="fa-solid fa-plus"></i></button>
                                <button class="btn btn-outline-danger" type="button" id="deleteCatalogRequirementBtn" title="Eliminar requisito"><i class="fa-solid fa-trash"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">F. Registro</label>
                        <input class="form-control" type="date" name="registration_date" id="registrationDate" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">F. Inicio</label>
                        <input class="form-control" type="date" name="start_date" id="startDate" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">F. Fin</label>
                        <input class="form-control" type="date" name="end_date" id="endDate" required>
                    </div>
                    <div class="col-md-12" id="requirementObservationBlock">
                        <div class="requirement-observation-heading">
                            <label class="form-label mb-0" id="requirementObservationLabel">Nueva observación</label>
                            <span class="requirement-observation-state d-none" id="requirementObservationState"></span>
                        </div>
                        <textarea class="form-control mt-2" name="observations" id="observations" rows="3" maxlength="3000" placeholder="Escriba una nueva observación..."></textarea>
                        <small class="text-muted d-block mt-1">La nueva observación se agregará al historial sin reemplazar las anteriores.</small>
                        <div class="requirement-audit-box observation-history-box mt-2 d-none" id="requirementAuditBox">
                            <h6 id="requirementObservationHistoryTitle">Historial de observaciones</h6>
                            <div id="requirementAuditList"></div>
                        </div>
                    </div>
                    <div class="col-md-12 d-none" id="requirementDuplicatePositionsBlock">
                        <div class="border rounded p-3 bg-light">
                            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                                <div><strong>Aplicar cambios a otros puestos</strong><small class="d-block text-muted">Solo se actualizarán los registros que seleccione.</small></div>
                                <label class="form-check-label small" for="requirementDuplicateSelectAll"><input class="form-check-input me-1" type="checkbox" id="requirementDuplicateSelectAll"> Marcar todos</label>
                            </div>
                            <div id="requirementDuplicatePositions" class="d-grid gap-2"></div>
                            <small class="text-muted d-block mt-2">Sin selección, se editará únicamente este registro.</small>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Adjunto (PDF o imagen)</label>
                        <input class="form-control" type="file" name="pdf" id="pdfInput" accept="application/pdf,image/jpeg,image/png,image/webp">
                        <small class="text-muted d-block mt-1">Formatos permitidos: PDF, JPG, PNG y WEBP.</small>
                        <div class="file-current mt-2 d-none" id="currentPdf"></div>
                        <div class="upload-progress mt-2 d-none" id="requirementUploadProgress">
                            <div class="progress progress-thin">
                                <div class="progress-bar" role="progressbar" style="width: 0%" aria-valuemin="0" aria-valuemax="100"></div>
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
<div class="modal fade" id="requirementFilePreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div><h5 class="modal-title" id="requirementFilePreviewTitle">Vista previa del archivo</h5><small class="text-muted" id="requirementFilePreviewName"></small></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body requirement-file-preview-body" id="requirementFilePreviewBody"></div>
            <div class="modal-footer">
                <a class="btn btn-outline-primary" id="requirementFilePreviewOpen" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square me-2"></i>Abrir en otra pestaña</a>
                <a class="btn btn-primary" id="requirementFilePreviewDownload" download><i class="fa-solid fa-download me-2"></i>Descargar</a>
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="replicateRequirementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><h5 class="modal-title"><i class="fa-solid fa-copy me-2 text-primary"></i>Replicar registro</h5><small class="text-muted">Seleccione uno o varios puestos de destino.</small></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="replicateRequirementId">
                <div class="replicate-record-summary mb-3" id="replicateRecordSummary"></div>
                <label class="form-label fw-semibold">Puestos de trabajo</label>
                <div class="replicate-position-list" id="replicatePositionList"></div>
                <div class="small text-muted mt-2"><i class="fa-solid fa-circle-info me-1"></i>Los puestos donde el requisito ya existe no pueden seleccionarse.</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="button" id="confirmReplicateRequirementBtn"><i class="fa-solid fa-copy me-2"></i>Replicar registro</button>
            </div>
        </div>
    </div>
</div>
<style>
.requirement-folder-header .requirement-folder-heading{text-align:left!important;width:auto!important;background:#e8f1ff!important;padding:.6rem .85rem!important;border-left:4px solid #3b6fec;border-top:1px solid #cbdcff;border-right:1px solid #cbdcff;border-bottom:1px solid #cbdcff}
.requirement-folder-header:not(.is-open) .requirement-folder-heading{border-radius:.6rem}
.requirement-folder-header.is-open .requirement-folder-heading{border-radius:.6rem .6rem 0 0;border-bottom-color:#dce8fb}
.requirement-folder-header .btn-sm{background:#fff}
.requirement-folder-toggle{display:inline-flex;align-items:center;gap:.65rem;padding:.1rem 0;border:0;background:transparent;color:#173b7a;text-align:left;cursor:pointer}
.requirement-folder-icon{display:inline-grid;place-items:center;flex:none;width:2rem;height:2rem;border-radius:.45rem;background:#d5e4ff;color:#2459bf}
.requirement-folder-label{display:flex;flex-direction:column;gap:.05rem}
.requirement-folder-label strong{font-size:.9rem}
.requirement-folder-label small{font-size:.73rem;color:#49658d}
.requirement-folder-toggle:hover strong{text-decoration:underline}
.requirement-folder-toggle:focus-visible{outline:2px solid #2563eb;outline-offset:3px;border-radius:3px}
.requirement-folder-item>td{background:#f6f9ff!important;border-bottom-color:#dce8fb!important}
.requirement-folder-item:hover>td{background:#edf4ff!important}
.requirement-folder-item>td:first-child{border-left:4px solid #3b6fec}
.requirement-folder-item>td:last-child{border-right:1px solid #cbdcff}
.requirement-folder-item>td:nth-child(2){padding-left:1.1rem!important;font-weight:600;color:#234a83}
.requirement-folder-item.is-last>td{border-bottom:1px solid #cbdcff!important}
.requirement-folder-item.is-last>td:first-child{border-bottom-left-radius:.6rem}
.requirement-folder-item.is-last>td:last-child{border-bottom-right-radius:.6rem}
.requirement-folder-gap>td{height:.65rem;padding:0!important;border:0!important;background:#fff!important}
.requirement-ungrouped-item:first-of-type>td{border-top-color:#e2e8f0}
.requirement-file-preview-body{min-height:260px;background:#f4f7fb;text-align:center}.requirement-file-preview-body iframe{display:block;width:100%;height:min(72vh,850px);border:0;background:#fff}.requirement-file-preview-body img{display:block;max-width:100%;max-height:72vh;margin:auto;object-fit:contain}
.replicate-record-summary{padding:.75rem .9rem;border:1px solid #cfe0ff;border-radius:.65rem;background:#f3f7ff}.replicate-record-summary strong{display:block;color:#173b7a}.replicate-position-list{display:grid;gap:.5rem}.replicate-position-option{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.7rem .8rem;border:1px solid #d9e2ef;border-radius:.6rem;background:#fff}.replicate-position-option:has(input:checked){border-color:#4d83f3;background:#eef4ff}.replicate-position-option.is-disabled{background:#f5f6f8;color:#7b8492}.replicate-position-option label{display:flex;align-items:center;gap:.6rem;margin:0;cursor:pointer;flex:1}
</style>
<script>
window.pmiPersonalReadOnly = <?= $personalView ? 'true' : 'false' ?>;
window.pmiPersonalWorkerId = <?= $pmiWorkerId ?>;
window.canManageRequirementObservations = <?= (is_admin() || is_gestor_role()) ? 'true' : 'false' ?>;
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>




