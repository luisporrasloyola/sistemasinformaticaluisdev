<?php
$recordId = (int) $record['id'];
$formId = 'cvInline' . $recordId;
$isImage = (bool) preg_match('/\.(jpg|jpeg|png|webp)$/i', (string) $record['archivo_path']);
$startValue = substr((string) $record['hora_inicio'], 0, 5);
$endValue = substr((string) $record['hora_final'], 0, 5);
$displayMinutes = valor_minutes($startValue, $endValue);
$editingFailure = $error !== '' && ($_POST['action'] ?? '') === 'save' && ($_POST['inline_edit'] ?? '') === '1' && (int) ($_POST['record_id'] ?? 0) === $recordId;
$rowEquipment = $editingFailure ? (string) ($_POST['equipo'] ?? '') : (string) $record['equipo_completo'];
$rowDate = $editingFailure ? (string) ($_POST['fecha_servicio'] ?? '') : (string) $record['fecha_servicio'];
$rowStart = $editingFailure ? (string) ($_POST['hora_inicio'] ?? '') : $startValue;
$rowEnd = $editingFailure ? (string) ($_POST['hora_final'] ?? '') : $endValue;
$rowNotes = $editingFailure ? (string) ($_POST['observaciones'] ?? '') : (string) ($record['observaciones'] ?? '');
?>
<tr class="cv-record<?= $editingFailure ? ' cv-editing' : '' ?>">
    <td class="fw-semibold"><span class="cv-view"><?= e($record['equipo_completo']) ?></span><input class="form-control form-control-sm cv-input" form="<?= $formId ?>" name="equipo" value="<?= e($rowEquipment) ?>" maxlength="120" required aria-label="Equipo y placa o serie"></td>
    <td><span class="cv-view"><?= e(date('d/m/Y', strtotime((string) $record['fecha_servicio']))) ?></span><input class="form-control form-control-sm cv-input" form="<?= $formId ?>" type="date" name="fecha_servicio" value="<?= e($rowDate) ?>" required aria-label="Fecha del servicio"></td>
    <td><span class="cv-view"><?= e($startValue) ?></span><input class="form-control form-control-sm cv-input cv-inline-time" form="<?= $formId ?>" type="time" name="hora_inicio" value="<?= e($rowStart) ?>" required aria-label="Hora de inicio"></td>
    <td><span class="cv-view"><?= e($endValue) ?></span><input class="form-control form-control-sm cv-input cv-inline-time" form="<?= $formId ?>" type="time" name="hora_final" value="<?= e($rowEnd) ?>" required aria-label="Hora final"></td>
    <td class="fw-semibold"><span class="cv-view"><?= $displayMinutes === null ? '<span class="text-warning">Revisar horas</span>' : sprintf('%02d:%02d', intdiv($displayMinutes, 60), $displayMinutes % 60) ?></span><output class="cv-input cv-inline-total">—</output></td>
    <td><span class="cv-view"><?= $record['observaciones'] ? nl2br(e((string) $record['observaciones'])) : '<span class="text-muted">—</span>' ?></span><textarea class="form-control form-control-sm cv-input" form="<?= $formId ?>" name="observaciones" rows="2" maxlength="2000" aria-label="Observaciones"><?= e($rowNotes) ?></textarea></td>
    <td><div class="cv-row-actions">
        <button class="cv-action-btn cv-action-edit cv-inline-edit cv-view" type="button" aria-label="Editar esta fila"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
        <button class="cv-action-btn cv-action-file cv-file cvOpenFile<?= $isImage ? '' : ' cv-action-file-pdf' ?>" type="button" data-kind="<?= $isImage ? 'image' : 'pdf' ?>" data-url="<?= e(APP_URL . '/servicios/cobranzas/valorizacion_archivo.php?id=' . $recordId) ?>" aria-label="Ver archivo original"><i class="fa-solid <?= $isImage ? 'fa-file-image' : 'fa-file-pdf' ?>" aria-hidden="true"></i></button>
        <form id="<?= $formId ?>" class="cv-inline-form" method="post" action="<?= e(APP_URL . '/' . $pageUrl) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="inline_edit" value="1"><input type="hidden" name="record_id" value="<?= $recordId ?>"></form>
        <button class="btn btn-sm btn-primary cv-input cv-inline-save" type="submit" form="<?= $formId ?>" title="Guardar cambios" aria-label="Guardar cambios"><i class="fa-solid fa-check" aria-hidden="true"></i></button>
        <button class="btn btn-sm btn-outline-secondary cv-input cv-inline-cancel" type="button" title="Cancelar edición" aria-label="Cancelar edición"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        <form method="post" class="cvDelete cv-view" action="<?= e(APP_URL . '/' . $pageUrl) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="record_id" value="<?= $recordId ?>"><button class="cv-action-btn cv-action-delete" type="submit" aria-label="Eliminar registro"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></form>
    </div></td>
</tr>
