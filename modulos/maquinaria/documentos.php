<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('maquinaria.documentos');
$stmtMaquinarias = db()->query("SELECT m.id, m.equipo, m.serie_placa, c.name AS empresa
    FROM maquinarias m
    LEFT JOIN companies c ON c.id = m.company_id
    WHERE m.estado = 1
    ORDER BY m.equipo, m.serie_placa");
$maquinarias = $stmtMaquinarias->fetchAll();
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Documentos de maquinaria</h1>
        <p>Control documentario por equipo.</p>
    </div>
</div>

<div class="work-panel mb-3">
    <label class="form-label">Buscar por equipo, serie o placa</label>
    <select class="form-select" id="machineSearch">
            <option value=""></option>
            <?php foreach ($maquinarias as $maquinaria): ?>
                <option value="<?= (int) $maquinaria['id'] ?>"><?= e($maquinaria['equipo'] . ' - ' . $maquinaria['serie_placa'] . (!empty($maquinaria['empresa']) ? ' - ' . $maquinaria['empresa'] : '')) ?></option>
            <?php endforeach; ?>
        </select>
</div>

<div class="row g-3 d-none" id="machineDocumentsWorkspace">
    <div class="col-lg-3 col-xl-3 machine-profile-col">
        <div class="work-panel h-100">
            <div class="worker-card text-center">
                <img id="machinePhoto" src="<?= APP_URL ?>/recursos/imagen_referencial.php" alt="Foto maquinaria">
                <div class="mt-2">
                    <button class="btn btn-sm btn-outline-primary" type="button" id="changeMachinePhotoBtn">Clic para cambiar foto</button>
                    <input class="d-none" type="file" id="machinePhotoInput" accept="image/png,image/jpeg,image/webp">
                </div>
            </div>
            <dl class="info-list mt-3">
                <dt>Equipo</dt><dd id="machineEquipo"></dd>
                <dt>Empresa</dt><dd id="machineEmpresa"></dd>
                <dt>Serie o Placa</dt><dd id="machineSerie"></dd>
                <dt>A&ntilde;o</dt><dd id="machineAnio"></dd>
            </dl>
        </div>
    </div>
    <div class="col-lg-9 col-xl-9 machine-table-col">
        <div class="work-panel h-100">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
                <h2 class="mb-0">Documentos</h2>
                <div class="d-flex gap-2 flex-wrap"><button class="btn btn-outline-primary" type="button" id="downloadSelectedMachineDocumentsBtn"><i class="fa-solid fa-file-zipper me-2"></i>Descargar seleccionados</button><button class="btn btn-outline-primary" type="button" id="downloadMachineDocumentsBtn"><i class="fa-solid fa-download me-2"></i>Descargar todo</button><button class="btn btn-primary" type="button" id="addMachineDocumentBtn"><i class="fa-solid fa-plus me-2"></i>Agregar documentos</button></div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="machineDocumentsTable">
                    <thead>
                    <tr>
                        <th>Seleccionar</th>
                        <th>Documentos</th>
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

<div class="modal fade" id="machineDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content needs-validation" id="machineDocumentForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="machineDocumentModalTitle">Agregar documentos</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="machineDocumentId">
                <input type="hidden" name="maquinaria_id" id="machineDocumentMachineId">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">Documentos</label>
                        <div class="input-group">
                            <select class="form-select" name="documento_id" id="machineDocumentSelect" required></select>
                            <?php if (current_user_can_manage_scope('maquinaria.documentos')): ?>
                                <button class="btn btn-outline-primary" type="button" id="newMachineCatalogDocumentBtn" title="Agregar documento"><i class="fa-solid fa-plus"></i></button>
                                <button class="btn btn-outline-danger" type="button" id="deleteMachineCatalogDocumentBtn" title="Eliminar documento"><i class="fa-solid fa-trash"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">F. Registro</label>
                        <input class="form-control" type="date" name="fecha_registro" id="machineRegistrationDate" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">F. Inicio</label>
                        <input class="form-control" type="date" name="fecha_inicio" id="machineStartDate" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">F. Fin</label>
                        <input class="form-control" type="date" name="fecha_fin" id="machineEndDate" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Observaciones</label>
                        <textarea class="form-control" name="observaciones" id="machineObservations" rows="3"></textarea>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Adjunto PDF</label>
                        <input class="form-control" type="file" name="pdf" id="machinePdfInput" accept="application/pdf">
                        <div class="file-current mt-2 d-none" id="machineCurrentPdf"></div>
                        <div class="upload-progress mt-2 d-none" id="machineUploadProgress">
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





