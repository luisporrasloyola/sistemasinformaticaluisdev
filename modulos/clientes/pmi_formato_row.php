<?php
$formatStatus = !empty($document['end_date'])
    ? status_alert_document_status((string) $document['end_date'], 'clientes.pmi_formatos', (int) $document['catalog_id'])
    : ['label' => 'SIN VENCIMIENTO', 'class' => 'text-bg-secondary'];
$formatHasFile = !empty($document['file_path']);
?>
<td><input class="form-check-input format-document-check" type="checkbox" value="<?= (int) $document['id'] ?>" data-has-file="<?= $formatHasFile ? '1' : '0' ?>" aria-label="Seleccionar <?= e($document['name']) ?>"></td>
<td><strong><?= e($document['name']) ?></strong></td>
<td><?= e($document['registration_date']) ?></td>
<td><?= e($document['start_date'] ?: '—') ?></td>
<td><?= e($document['end_date'] ?: '—') ?></td>
<td><span class="badge <?= e($formatStatus['class']) ?>"><?= e($formatStatus['label']) ?></span></td>
<td><?= e($document['registered_by']) ?></td>
<td class="text-nowrap">
    <button class="btn btn-sm btn-outline-primary edit-format-document" type="button" data-document='<?= e(json_encode($document, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>' title="Editar"><i class="fa-solid fa-pen"></i></button>
    <?php if ($formatHasFile): ?>
        <button class="btn btn-sm btn-outline-secondary preview-base-document" type="button" data-file-path="<?= e($document['file_path']) ?>" data-document-name="<?= e($document['name']) ?>" data-document-id="<?= (int) $document['id'] ?>" data-download-type="formatos" title="Previsualizar"><i class="fa-solid fa-eye"></i></button>
        <a class="btn btn-sm btn-outline-success" href="<?= APP_URL ?>/modulos/clientes/pmi_descargar.php?tipo=formatos&cliente_id=<?= $clientId ?>&ids[]=<?= (int) $document['id'] ?>" title="Descargar"><i class="fa-solid fa-download"></i></a>
    <?php else: ?><button class="btn btn-sm btn-outline-secondary" type="button" disabled title="Sin archivo"><i class="fa-solid fa-eye"></i></button><?php endif; ?>
    <form class="d-inline" method="post" action="<?= APP_URL ?>/modulos/clientes/pmi_formatos_guardar.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_document"><input type="hidden" name="client_id" value="<?= $clientId ?>"><input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit" title="Eliminar"><i class="fa-solid fa-trash"></i></button></form>
</td>
