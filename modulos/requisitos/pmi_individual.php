<?php
require_once __DIR__ . '/../../includes/security.php';
require_module_access('requisitos.pmi_individual');
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Requisitos PMI Individual</h1>
        <p>Control documentario por trabajador y puesto.</p>
    </div>
</div>

<div class="work-panel mb-3">
    <label class="form-label">Buscar por nombre o DNI / documento</label>
    <select class="form-select" id="workerSearch"></select>
</div>

<div class="row g-3 d-none" id="requirementsWorkspace">
    <div class="col-lg-3 col-xl-3 requirements-profile-col">
        <div class="work-panel h-100">
            <div class="worker-card text-center">
                <img id="workerPhoto" src="<?= APP_URL ?>/recursos/imagen_referencial.php" alt="Foto trabajador">
                <label class="btn btn-sm btn-outline-primary mt-2">
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
                    <button class="btn btn-primary" type="button" id="addRequirementBtn"><i class="fa-solid fa-plus me-2"></i>Agregar Requisito</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="requirementsTable">
                    <thead>
                    <tr>
                        <th>Seleccionar</th>
                        <th>Requisito</th>
                        <th>F. Registro</th>
                        <th>F. Inicio</th>
                        <th>F. Fin</th>
                        <th>Estado</th>
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
                    <div class="col-md-12">
                        <label class="form-label">Observaciones</label>
                        <textarea class="form-control" name="observations" id="observations" rows="3"></textarea>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Adjunto PDF</label>
                        <input class="form-control" type="file" name="pdf" id="pdfInput" accept="application/pdf">
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
<?php require __DIR__ . '/../../includes/footer.php'; ?>




