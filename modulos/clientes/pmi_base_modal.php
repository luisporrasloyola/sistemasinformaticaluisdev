<div class="modal fade" id="baseDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" method="post" action="<?= APP_URL ?>/modulos/clientes/pmi_guardar.php" enctype="multipart/form-data">
            <div class="modal-header"><h2 class="modal-title h5" id="baseDocumentTitle">Agregar documentos</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_base">
                <input type="hidden" name="client_id" value="<?= $clientId ?>">
                <input type="hidden" name="document_id" id="baseDocumentId">
                <div class="mb-3">
                    <label class="form-label" for="baseDocumentCatalog">Documento</label>
                    <select class="form-select" id="baseDocumentCatalog" name="catalog_id" required><option></option></select>
                    <div class="d-flex gap-1 mt-1">
                        <button class="btn btn-outline-primary" type="button" id="addBaseCatalog" title="Crear nombre de documento" aria-label="Crear nombre de documento"><i class="fa-solid fa-plus"></i></button>
                        <button class="btn btn-outline-danger" type="button" id="deleteBaseCatalog" title="Eliminar nombre seleccionado" aria-label="Eliminar nombre seleccionado"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-4"><label class="form-label" for="baseDocumentRegistration">F. Registro</label><input class="form-control" type="date" id="baseDocumentRegistration" name="registration_date" required></div>
                    <div class="col-md-4"><label class="form-label" for="baseDocumentStart">F. Inicio</label><input class="form-control" type="date" id="baseDocumentStart" name="start_date"></div>
                    <div class="col-md-4"><label class="form-label" for="baseDocumentEnd">F. Fin</label><input class="form-control" type="date" id="baseDocumentEnd" name="end_date"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="baseDocumentObservation">Nueva observación</label>
                    <textarea class="form-control" id="baseDocumentObservation" name="observation" rows="3" maxlength="3000" placeholder="Escriba una nueva observación..."></textarea>
                    <small class="text-muted">La nueva observación se agregará al historial sin reemplazar las anteriores.</small>
                    <div class="border rounded bg-light p-2 mt-2 d-none" id="baseObservationHistory"><strong class="small">Historial de observaciones</strong><div class="small mt-2" id="baseObservationList"></div></div>
                </div>
                <div>
                    <label class="form-label" for="baseDocumentFile">Adjunto (PDF o imagen)</label>
                    <input class="form-control" type="file" id="baseDocumentFile" name="file" accept="application/pdf,image/jpeg,image/png,image/webp">
                    <small class="text-muted">Formatos permitidos: PDF, JPG, PNG y WEBP. Máximo 10 MB.</small>
                    <div class="small mt-2 d-none" id="baseCurrentFile"></div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar</button></div>
        </form>
    </div>
</div>
