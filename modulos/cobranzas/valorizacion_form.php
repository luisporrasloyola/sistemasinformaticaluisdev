<section class="work-panel cv-panel cv-entry" id="cvFormModal" hidden aria-labelledby="cvFormTitle">
    <form method="post" enctype="multipart/form-data" action="<?= e(APP_URL . '/' . $pageUrl) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="record_id" id="cvId">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <div><h2 class="h5 mb-1" id="cvFormTitle">Revisar valorización</h2><p class="small text-muted mb-0" id="cvSelectedFile">Revise los datos detectados antes de guardar.</p></div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="cvCancel">Cancelar</button>
        </div>
        <?php if ($error !== '' && ($_POST['action'] ?? '') === 'save' && ($_POST['inline_edit'] ?? '') !== '1'): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
        <div class="cv-hidden-source"><label class="form-label" for="cvFile">Archivo original</label><input class="form-control" type="file" id="cvFile" name="adjunto" accept="image/jpeg,image/png,image/webp,application/pdf"><div class="form-text"></div><div id="cvPreviewHost"></div></div>
        <div class="cv-review-fields">
            <div id="cvReviewSuggestions"></div>
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label" for="cvEquipment">Equipo (equipo + placa/serie)</label><input class="form-control" id="cvEquipment" name="equipo" maxlength="120" required placeholder="Ejemplo: Camión Grúa D8X-884"></div>
                <div class="col-md-3"><label class="form-label" for="cvOperator">Operador <span class="text-muted fw-normal">(Elaborado por)</span></label><input class="form-control" id="cvOperator" name="operador" maxlength="150" placeholder="Nombre del operador"></div>
                <div class="col-md-3"><label class="form-label" for="cvDate">Fecha del servicio</label><input class="form-control" type="date" id="cvDate" name="fecha_servicio" required></div>
                <div class="col-md-3"><label class="form-label" for="cvTotal">Horas en total</label><input class="form-control" id="cvTotal" readonly value="—"></div>
                <div class="col-md-3"><label class="form-label" for="cvStart">Hora de inicio</label><input class="form-control" type="time" id="cvStart" name="hora_inicio" required></div>
                <div class="col-md-3"><label class="form-label" for="cvEnd">Hora final</label><input class="form-control" type="time" id="cvEnd" name="hora_final" required></div>
                <div class="col-md-6"><label class="form-label" for="cvNotes">Observaciones <span class="text-muted fw-normal">(manual)</span></label><textarea class="form-control" id="cvNotes" name="observaciones" rows="1" maxlength="2000" placeholder="Opcional"></textarea></div>
            </div>
            <div id="cvTimeFeedback" class="small mt-2" role="status" aria-live="polite"></div>
        </div>
        <div class="d-flex justify-content-end mt-3"><button type="submit" class="btn btn-primary">Guardar registro</button></div>
    </form>
</section>
