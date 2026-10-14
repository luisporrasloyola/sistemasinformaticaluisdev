<?php
$hasFile = !empty($document['file_path']);
$status = !empty($document['end_date'])
    ? status_alert_document_status((string) $document['end_date'], 'clientes.pmi_base', (int) ($document['catalog_id'] ?? 0))
    : ['label' => 'SIN VENCIMIENTO', 'class' => 'text-bg-secondary'];
?>
<td><input class="form-check-input base-document-check" type="checkbox" value="<?= (int) $document['id'] ?>" data-has-file="<?= $hasFile ? '1' : '0' ?>" aria-label="Seleccionar <?= e($document['name']) ?>"></td>
<td><strong><?= e($document['name']) ?></strong></td>
<td><?= e($document['registration_date'] ?: substr((string) $document['created_at'], 0, 10)) ?></td>
<td><?= e($document['start_date'] ?: '—') ?></td>
<td><?= e($document['end_date'] ?: '—') ?></td>
<td><span class="badge <?= e($status['class']) ?>"><?= e($status['label']) ?></span></td>
<td><?= e($document['registered_by']) ?></td>
<td class="text-nowrap">
    <button class="btn btn-sm btn-outline-primary edit-base-document" type="button" data-document='<?= e(json_encode($document, JSON_UNESCAPED_UNICODE)) ?>' title="Editar"><i class="fa-solid fa-pen"></i></button>
    <?php if ($hasFile): ?><button class="btn btn-sm btn-outline-secondary preview-base-document" type="button" data-file-path="<?= e($document['file_path']) ?>" data-document-name="<?= e($document['name']) ?>" data-document-id="<?= (int) $document['id'] ?>" title="Previsualizar" aria-label="Previsualizar <?= e($document['name']) ?>"><i class="fa-solid fa-eye"></i></button><a class="btn btn-sm btn-outline-success" href="<?= APP_URL ?>/modulos/clientes/pmi_descargar.php?cliente_id=<?= $clientId ?>&ids[]=<?= (int) $document['id'] ?>" title="Descargar"><i class="fa-solid fa-download"></i></a><?php else: ?><button class="btn btn-sm btn-outline-secondary" type="button" disabled title="Sin archivo"><i class="fa-solid fa-eye"></i></button><?php endif; ?>
    <form method="post" action="<?= APP_URL ?>/modulos/clientes/pmi_guardar.php" class="d-inline" onsubmit="return confirm('¿Eliminar este requisito?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_base"><input type="hidden" name="client_id" value="<?= $clientId ?>"><input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit" title="Eliminar"><i class="fa-solid fa-trash"></i></button></form>
</td>
