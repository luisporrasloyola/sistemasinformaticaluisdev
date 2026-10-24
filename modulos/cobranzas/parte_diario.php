<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload.php';
require_module_access('cobranzas.parte_diario');

$clientId = filter_var($_GET['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$client = null;
$equipmentRows = [];
$selectedEquipment = null;
$formError = '';
$recordError = '';
$recordRows = [];
$hasMoreRecords = false;
$recordPage = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
if ($clientId !== false && $clientId !== null) {
    $stmt = db()->prepare('SELECT id, business_name, ruc FROM clients WHERE id = ?');
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();
    if (!$client) {
        http_response_code(404);
        exit('Cliente no encontrado.');
    }

    $equipmentQuery = db()->prepare('SELECT id, nombre FROM cobranzas_parte_diario_maquinarias WHERE client_id = ? ORDER BY id');
    $equipmentQuery->execute([$clientId]);
    $equipmentRows = $equipmentQuery->fetchAll();
    $defaults = ['Camión Grúa', 'Montacargas'];
    $existingNames = array_map(static fn (array $row): string => mb_strtolower((string) $row['nombre'], 'UTF-8'), $equipmentRows);
    $missing = array_values(array_filter($defaults, static fn (string $name): bool => !in_array(mb_strtolower($name, 'UTF-8'), $existingNames, true)));
    if ($missing) {
        $values = implode(', ', array_fill(0, count($missing), '(?, ?)'));
        $parameters = [];
        foreach ($missing as $name) array_push($parameters, $clientId, $name);
        db()->prepare("INSERT INTO cobranzas_parte_diario_maquinarias (client_id, nombre) VALUES {$values} ON DUPLICATE KEY UPDATE id = id")
            ->execute($parameters);
        $equipmentQuery->execute([$clientId]);
        $equipmentRows = $equipmentQuery->fetchAll();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'add_equipment') === 'add_equipment') {
        verify_csrf($_POST['csrf_token'] ?? null);
        $name = trim((string) ($_POST['nombre'] ?? ''));
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
            $formError = 'Ingrese un nombre de maquinaria de hasta 120 caracteres.';
        } elseif (in_array(mb_strtolower($name, 'UTF-8'), array_map(static fn (array $row): string => mb_strtolower((string) $row['nombre'], 'UTF-8'), $equipmentRows), true)) {
            $formError = 'Esta maquinaria ya está registrada para el cliente.';
        } else {
            try {
                $insert = db()->prepare('INSERT INTO cobranzas_parte_diario_maquinarias (client_id, nombre) VALUES (?, ?)');
                $insert->execute([$clientId, $name]);
                redirect('modulos/cobranzas/parte_diario.php?cliente_id=' . $clientId . '&maquinaria_id=' . (int) db()->lastInsertId());
            } catch (PDOException $exception) {
                $formError = $exception->getCode() === '23000'
                    ? 'Esta maquinaria ya está registrada para el cliente.'
                    : 'No se pudo agregar la maquinaria.';
                if ($exception->getCode() !== '23000') error_log('Error al agregar maquinaria de Cobranzas: ' . $exception->getMessage());
            }
        }
    }

    $selectedId = filter_var($_GET['maquinaria_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    foreach ($equipmentRows as $equipment) {
        if ((int) $equipment['id'] === $selectedId) {
            $selectedEquipment = $equipment;
            break;
        }
    }
    if (!$selectedEquipment && $equipmentRows) $selectedEquipment = $equipmentRows[0];

    if ($selectedEquipment) {
        $equipmentId = (int) $selectedEquipment['id'];
        $pageUrl = 'modulos/cobranzas/parte_diario.php?cliente_id=' . $clientId . '&maquinaria_id=' . $equipmentId;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save_record', 'delete_record'], true)) {
            verify_csrf($_POST['csrf_token'] ?? null);
            $recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $existingRecord = null;
            if ($recordId !== false && $recordId !== null) {
                $findRecord = db()->prepare('SELECT id, archivo_path FROM cobranzas_parte_diario_registros WHERE id = ? AND maquinaria_id = ?');
                $findRecord->execute([$recordId, $equipmentId]);
                $existingRecord = $findRecord->fetch();
            }
            if (($_POST['action'] ?? '') === 'delete_record') {
                if (!$existingRecord) {
                    $recordError = 'No se encontró el registro.';
                } else {
                    db()->prepare('DELETE FROM cobranzas_parte_diario_registros WHERE id = ? AND maquinaria_id = ?')->execute([$recordId, $equipmentId]);
                    delete_uploaded_file($existingRecord['archivo_path']);
                    redirect($pageUrl);
                }
            } else {
                $date = (string) ($_POST['fecha'] ?? '');
                $parsedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
                $notes = trim((string) ($_POST['observaciones'] ?? ''));
                if (isset($_POST['record_id']) && $_POST['record_id'] !== '' && ($recordId === false || !$existingRecord)) {
                    $recordError = 'No se encontró el registro.';
                } elseif (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
                    $recordError = 'Seleccione una fecha válida.';
                } elseif (mb_strlen($notes, 'UTF-8') > 2000) {
                    $recordError = 'Las observaciones no pueden superar 2000 caracteres.';
                } else {
                    $newFilePath = null;
                    try {
                        $file = upload_file($_FILES['adjunto'] ?? [], 'cobranzas_parte_diario', document_attachment_mimes());
                        $newFilePath = $file['path'];
                        if ($existingRecord) {
                            $sql = 'UPDATE cobranzas_parte_diario_registros SET fecha = ?, observaciones = ?';
                            $values = [$date, $notes ?: null];
                            if ($newFilePath) {
                                $sql .= ', archivo_path = ?, archivo_nombre_original = ?';
                                array_push($values, $newFilePath, $file['name']);
                            }
                            $sql .= ' WHERE id = ? AND maquinaria_id = ?';
                            array_push($values, $recordId, $equipmentId);
                            db()->prepare($sql)->execute($values);
                            if ($newFilePath) delete_uploaded_file($existingRecord['archivo_path']);
                        } else {
                            db()->prepare('INSERT INTO cobranzas_parte_diario_registros (maquinaria_id, fecha, observaciones, archivo_path, archivo_nombre_original) VALUES (?, ?, ?, ?, ?)')
                                ->execute([$equipmentId, $date, $notes ?: null, $newFilePath, $file['name']]);
                        }
                        redirect($pageUrl);
                    } catch (Throwable $exception) {
                        if ($newFilePath) delete_uploaded_file($newFilePath);
                        $recordError = $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo guardar el registro.';
                        if (!($exception instanceof RuntimeException)) error_log('Error en Parte diario de Cobranzas: ' . $exception->getMessage());
                    }
                }
            }
        }
        $offset = ($recordPage - 1) * 25;
        $recordQuery = db()->prepare('SELECT id, fecha, observaciones, archivo_path FROM cobranzas_parte_diario_registros WHERE maquinaria_id = ? ORDER BY fecha DESC, id DESC LIMIT 26 OFFSET ' . $offset);
        $recordQuery->execute([$equipmentId]);
        $recordRows = $recordQuery->fetchAll();
        $hasMoreRecords = count($recordRows) > 25;
        if ($hasMoreRecords) array_pop($recordRows);
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<style>
.cobranzas-chips{display:flex;gap:.65rem;flex-wrap:wrap;align-items:center}
.cobranzas-chip{--chip-color:#2563eb;--chip-soft:#eff6ff;display:inline-flex;align-items:center;border:1px solid var(--chip-color);background:var(--chip-soft);color:var(--chip-color);border-radius:999px;padding:.6rem 1rem;font-weight:700;text-decoration:none;transition:transform .18s,box-shadow .18s,background-color .18s,color .18s}
.cobranzas-chip:hover{color:var(--chip-color);transform:translateY(-1px);box-shadow:0 4px 10px rgba(15,23,42,.14)}
.cobranzas-chip.active,.cobranzas-chip.active:hover{color:#fff;background:var(--chip-color);border-color:var(--chip-color);box-shadow:0 6px 15px rgba(15,23,42,.2)}
.cobranzas-chip:focus-visible{outline:3px solid rgba(37,99,235,.2);outline-offset:2px}
.cobranzas-file-icon{width:34px;height:36px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #cbd5e1;border-radius:7px;background:#fff;text-decoration:none;font-size:1.15rem;transition:border-color .18s,box-shadow .18s,transform .18s}
.cobranzas-file-icon:hover{border-color:#94a3b8;box-shadow:0 3px 8px rgba(15,23,42,.12);transform:translateY(-1px)}
.cobranzas-file-icon:focus-visible{outline:3px solid rgba(37,99,235,.25);outline-offset:2px}
.cobranzas-record-actions{display:flex;gap:.4rem;flex-wrap:wrap}
.cobranzas-record-table{width:100%;min-width:650px;table-layout:fixed}
.cobranzas-record-table th,.cobranzas-record-table td{overflow-wrap:anywhere}
.cobranzas-record-table td:last-child .cobranzas-record-actions{justify-content:flex-end}
.cobranzas-preview-body{display:flex;align-items:center;justify-content:center;min-height:55vh;background:#f8fafc}
.cobranzas-preview-body iframe{width:100%;height:72vh;border:0;background:#fff}
.cobranzas-preview-body img{display:block;max-width:100%;max-height:72vh;object-fit:contain}
.cobranzas-page-title{margin-bottom:12px}
.cobranzas-panel.work-panel{padding:15px 18px!important;margin-bottom:12px!important}
.cobranzas-panel.work-panel h2{margin-bottom:0!important}
.cobranzas-client-filter{display:flex;align-items:center;gap:14px}
.cobranzas-client-filter .form-label{flex:0 0 65px;margin:0;font-weight:700;line-height:1.3}
.cobranzas-client-select{flex:1;min-width:0}
.cobranzas-client-select .select2-container{width:100%!important}
.cobranzas-workspace.work-panel{padding:0!important;overflow:hidden}
.cobranzas-machine-section{padding:17px 18px 16px}
.cobranzas-machine-header{margin-bottom:12px}
.cobranzas-machine-header .btn{font-size:.86rem;padding:.42rem .75rem}
.cobranzas-record-area{border-top:1px solid #dbe5f1}
.cobranzas-record-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 18px;background:#f8faff;border-bottom:1px solid #e2e8f0}
.cobranzas-record-heading{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.cobranzas-record-heading h2{color:#173456!important}
.cobranzas-selected-machine{display:inline-flex;align-items:center;padding:3px 9px;border:1px solid #bfd4fb;border-radius:999px;background:#edf4ff;color:#1d55b1;font-size:.76rem;font-weight:700}
.cobranzas-record-header .btn{font-size:.86rem;padding:.42rem .82rem}
.cobranzas-record-list{margin:14px 18px 16px;border:1px solid #dce5f0;border-radius:9px!important}
.cobranzas-record-table thead th{background:#f4f7fb;color:#415474;font-size:.73rem;text-transform:uppercase;letter-spacing:.035em;padding:.58rem .65rem}
.cobranzas-record-table tbody td{padding:.48rem .65rem}
.cobranzas-record-table tbody tr:last-child td{border-bottom:0}
.cobranzas-record-actions .btn{padding:.34rem .55rem;font-size:.8rem;border-radius:7px}
.cobranzas-record-area>nav{padding:0 18px 16px}
@media(max-width:700px){.cobranzas-client-filter{align-items:stretch;flex-direction:column;gap:6px}.cobranzas-client-filter .form-label{flex:auto}.cobranzas-panel.work-panel{padding:14px!important}.cobranzas-workspace.work-panel{padding:0!important}.cobranzas-machine-section{padding:14px}.cobranzas-record-header{padding:11px 14px}.cobranzas-record-list{margin:12px 14px}}
</style>
<div class="page-title cobranzas-page-title">
    <div>
        <h1>Parte diario</h1>
        <p>Cobranzas por cliente.</p>
    </div>
</div>
<section class="work-panel cobranzas-panel">
    <div class="cobranzas-client-filter">
        <label class="form-label" for="cobranzasClientSearch">Cliente</label>
        <div class="cobranzas-client-select"><select class="form-select" id="cobranzasClientSearch" aria-label="Buscar cliente por razón social o RUC">
            <option></option>
            <?php if ($client): ?><option value="<?= (int) $client['id'] ?>" selected><?= e($client['business_name'] . ' - ' . $client['ruc']) ?></option><?php endif; ?>
        </select></div>
    </div>
</section>
<?php if ($client): ?>
<section class="work-panel cobranzas-panel cobranzas-workspace">
  <div class="cobranzas-machine-section">
    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap cobranzas-machine-header">
        <h2 class="h5 mb-0">Maquinaria</h2>
        <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#addCobranzasEquipmentModal"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Agregar maquinaria</button>
    </div>
    <nav class="cobranzas-chips" aria-label="Maquinaria del cliente">
        <?php $chipPalette = [['#2563eb', '#eff6ff'], ['#7c3aed', '#f5f3ff'], ['#0891b2', '#ecfeff'], ['#059669', '#ecfdf5'], ['#d97706', '#fffbeb'], ['#dc2626', '#fef2f2'], ['#db2777', '#fdf2f8'], ['#4f46e5', '#eef2ff'], ['#0f766e', '#f0fdfa'], ['#9333ea', '#faf5ff']]; ?>
        <?php foreach ($equipmentRows as $index => $equipment): ?>
            <?php [$chipColor, $chipSoft] = $chipPalette[$index % count($chipPalette)]; ?>
            <a class="cobranzas-chip<?= (int) $selectedEquipment['id'] === (int) $equipment['id'] ? ' active' : '' ?>" style="--chip-color:<?= $chipColor ?>;--chip-soft:<?= $chipSoft ?>" href="<?= e(APP_URL . '/modulos/cobranzas/parte_diario.php?' . http_build_query(['cliente_id' => $clientId, 'maquinaria_id' => (int) $equipment['id']])) ?>"<?= (int) $selectedEquipment['id'] === (int) $equipment['id'] ? ' aria-current="page"' : '' ?>><?= e($equipment['nombre']) ?></a>
        <?php endforeach; ?>
    </nav>
  </div>
<?php if ($selectedEquipment): ?>
<div class="cobranzas-record-area">
    <div class="cobranzas-record-header">
        <div class="cobranzas-record-heading"><h2 class="h5 mb-0">Registros</h2><span class="cobranzas-selected-machine"><?= e($selectedEquipment['nombre']) ?></span></div>
        <button class="btn btn-primary" type="button" id="newCobranzasRecord"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Registrar</button>
    </div>
    <?php if ($recordError !== '' && ($_POST['action'] ?? '') === 'delete_record'): ?><div class="alert alert-danger py-2" role="alert"><?= e($recordError) ?></div><?php endif; ?>
    <div class="table-responsive cobranzas-record-list">
        <table class="table table-hover align-middle mb-0 cobranzas-record-table">
            <colgroup><col style="width:16%"><col style="width:13%"><col style="width:45%"><col style="width:26%"></colgroup>
            <thead><tr><th>Fecha</th><th>Archivo</th><th>Observaciones</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php if (!$recordRows): ?><tr><td colspan="4" class="text-center text-muted py-4">Todavía no hay registros para esta maquinaria.</td></tr><?php endif; ?>
            <?php foreach ($recordRows as $record): ?>
                <?php $fileUrl = APP_URL . '/servicios/cobranzas/parte_diario_archivo.php?id=' . (int) $record['id']; ?>
                <tr>
                    <td class="text-nowrap"><?= e(date('d/m/Y', strtotime($record['fecha']))) ?></td>
                    <td>
                        <?php if ($record['archivo_path']): ?>
                            <?php $isImage = (bool) preg_match('/\.(jpg|jpeg|png|webp)$/i', (string) $record['archivo_path']); ?>
                            <button class="cobranzas-file-icon previewCobranzasFile" type="button" data-url="<?= e($fileUrl) ?>" data-kind="<?= $isImage ? 'image' : 'pdf' ?>" data-date="<?= e(date('d/m/Y', strtotime($record['fecha']))) ?>" title="<?= $isImage ? 'Ver imagen' : 'Ver PDF' ?>" aria-label="<?= $isImage ? 'Ver imagen' : 'Ver PDF' ?> del <?= e(date('d/m/Y', strtotime($record['fecha']))) ?>"><i class="fa-solid <?= $isImage ? 'fa-file-image text-primary' : 'fa-file-pdf text-danger' ?>" aria-hidden="true"></i></button>
                        <?php else: ?><span class="text-muted">Sin adjunto</span><?php endif; ?>
                    </td>
                    <td><?= $record['observaciones'] !== null && $record['observaciones'] !== '' ? nl2br(e($record['observaciones'])) : '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <div class="cobranzas-record-actions">
                            <button class="btn btn-sm btn-outline-primary editCobranzasRecord" type="button" data-id="<?= (int) $record['id'] ?>" data-date="<?= e($record['fecha']) ?>" data-notes="<?= e($record['observaciones']) ?>" data-file="<?= $record['archivo_path'] ? '1' : '0' ?>"><i class="fa-solid fa-pen me-1" aria-hidden="true"></i>Editar</button>
                            <form class="deleteCobranzasRecord" method="post" action="<?= e(APP_URL . '/' . $pageUrl) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_record"><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Eliminar</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($recordPage > 1 || $hasMoreRecords): ?>
        <nav class="d-flex justify-content-end gap-2 mt-3" aria-label="Páginas de registros">
            <?php if ($recordPage > 1): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL . '/' . $pageUrl . '&pagina=' . ($recordPage - 1)) ?>">Anterior</a><?php endif; ?>
            <?php if ($hasMoreRecords): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL . '/' . $pageUrl . '&pagina=' . ($recordPage + 1)) ?>">Siguiente</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
<?php endif; ?>
</section>
<?php if ($selectedEquipment): ?>
<div class="modal fade" id="cobranzasFilePreviewModal" tabindex="-1" aria-labelledby="cobranzasFilePreviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="cobranzasFilePreviewTitle">Archivo del parte diario</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body cobranzas-preview-body" id="cobranzasFilePreviewBody"></div>
        </div>
    </div>
</div>
<div class="modal fade" id="cobranzasRecordModal" tabindex="-1" aria-labelledby="cobranzasRecordTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="post" enctype="multipart/form-data" action="<?= e(APP_URL . '/' . $pageUrl) ?>" class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="cobranzasRecordTitle">Registrar · <?= e($selectedEquipment['nombre']) ?></h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_record"><input type="hidden" name="record_id" id="cobranzasRecordId">
                <?php if ($recordError !== '' && ($_POST['action'] ?? '') === 'save_record'): ?><div class="alert alert-danger" role="alert"><?= e($recordError) ?></div><?php endif; ?>
                <div class="mb-3"><label class="form-label" for="cobranzasRecordDate">Fecha</label><input class="form-control" type="date" name="fecha" id="cobranzasRecordDate" required><div class="form-text" id="cobranzasDatePreview"></div></div>
                <div class="mb-3"><label class="form-label" for="cobranzasRecordNotes">Observaciones</label><textarea class="form-control" name="observaciones" id="cobranzasRecordNotes" rows="4" maxlength="2000" placeholder="Ingrese una observación..."></textarea></div>
                <div><label class="form-label" for="cobranzasRecordFile">Archivo</label><input class="form-control" type="file" name="adjunto" id="cobranzasRecordFile" accept="image/jpeg,image/png,image/webp,application/pdf"><div class="form-text">Formatos: JPG, PNG, WEBP o PDF. Máximo 10 MB. Desde el celular puede elegir la cámara o un archivo.</div><div class="form-text d-none" id="cobranzasCurrentFile">Este registro ya tiene un archivo. Uno nuevo lo reemplazará.</div></div>
            </div>
            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar registro</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<div class="modal fade" id="addCobranzasEquipmentModal" tabindex="-1" aria-labelledby="addCobranzasEquipmentTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="<?= e(APP_URL . '/modulos/cobranzas/parte_diario.php?cliente_id=' . $clientId) ?>" class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h4" id="addCobranzasEquipmentTitle">Agregar maquinaria</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <?php if ($formError !== ''): ?><div class="alert alert-danger py-2" role="alert"><?= e($formError) ?></div><?php endif; ?>
                <label class="form-label" for="newCobranzasEquipment">Nombre de la maquinaria</label>
                <input class="form-control" id="newCobranzasEquipment" name="nombre" maxlength="120" required placeholder="Ejemplo: Excavadora" value="<?= e($formError !== '' ? (string) ($_POST['nombre'] ?? '') : '') ?>">
            </div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-primary" type="submit">Agregar</button>
                <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    <?php if ($client): ?>
    const equipmentModal = document.getElementById('addCobranzasEquipmentModal');
    equipmentModal.addEventListener('shown.bs.modal', () => document.getElementById('newCobranzasEquipment').focus());
    <?php if ($formError !== ''): ?>bootstrap.Modal.getOrCreateInstance(equipmentModal).show();<?php endif; ?>
    <?php if ($selectedEquipment): ?>
    const recordModalElement = document.getElementById('cobranzasRecordModal');
    const recordModal = bootstrap.Modal.getOrCreateInstance(recordModalElement);
    document.querySelectorAll('.deleteCobranzasRecord').forEach(form => form.addEventListener('submit', async event => {
        event.preventDefault();
        const result = await Swal.fire({
            title: '¿Eliminar este registro?',
            text: 'También se eliminará el archivo adjunto. Esta acción no se puede deshacer.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#dc3545',
            reverseButtons: true
        });
        if (result.isConfirmed) form.submit();
    }));
    const filePreviewElement = document.getElementById('cobranzasFilePreviewModal');
    const filePreviewBody = document.getElementById('cobranzasFilePreviewBody');
    filePreviewElement.addEventListener('hidden.bs.modal', () => filePreviewBody.replaceChildren());
    document.querySelectorAll('.previewCobranzasFile').forEach(button => button.addEventListener('click', () => {
        const preview = document.createElement(button.dataset.kind === 'image' ? 'img' : 'iframe');
        preview.src = button.dataset.url;
        if (preview.tagName === 'IMG') preview.alt = 'Archivo del ' + button.dataset.date;
        else preview.title = 'PDF del ' + button.dataset.date;
        filePreviewBody.replaceChildren(preview);
        document.getElementById('cobranzasFilePreviewTitle').textContent = 'Archivo del ' + button.dataset.date;
        bootstrap.Modal.getOrCreateInstance(filePreviewElement).show();
    }));
    const recordTitle = document.getElementById('cobranzasRecordTitle');
    const recordId = document.getElementById('cobranzasRecordId');
    const recordDate = document.getElementById('cobranzasRecordDate');
    const recordNotes = document.getElementById('cobranzasRecordNotes');
    const currentFile = document.getElementById('cobranzasCurrentFile');
    const datePreview = document.getElementById('cobranzasDatePreview');
    const updateDatePreview = () => {
        if (!recordDate.value) { datePreview.textContent = 'Seleccione una fecha.'; return; }
        const date = new Date(recordDate.value + 'T12:00:00');
        datePreview.textContent = Number.isNaN(date.getTime()) ? '' : 'Fecha seleccionada: ' + new Intl.DateTimeFormat('es-PE', {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'}).format(date) + '.';
    };
    recordDate.addEventListener('change', updateDatePreview);
    const machineName = <?= json_encode((string) $selectedEquipment['nombre'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    document.getElementById('newCobranzasRecord').addEventListener('click', () => {
        recordModalElement.querySelector('form').reset();
        recordId.value = '';
        recordDate.value = <?= json_encode(date('Y-m-d')) ?>;
        recordTitle.textContent = 'Registrar · ' + machineName;
        currentFile.classList.add('d-none');
        updateDatePreview();
        recordModal.show();
    });
    document.querySelectorAll('.editCobranzasRecord').forEach(button => button.addEventListener('click', () => {
        recordModalElement.querySelector('form').reset();
        recordId.value = button.dataset.id;
        recordDate.value = button.dataset.date;
        recordNotes.value = button.dataset.notes;
        recordTitle.textContent = 'Editar · ' + machineName;
        currentFile.classList.toggle('d-none', button.dataset.file !== '1');
        updateDatePreview();
        recordModal.show();
    }));
    <?php if ($recordError !== '' && ($_POST['action'] ?? '') === 'save_record'): ?>
    recordId.value = <?= json_encode((string) ($_POST['record_id'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    recordDate.value = <?= json_encode((string) ($_POST['fecha'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    recordNotes.value = <?= json_encode((string) ($_POST['observaciones'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    updateDatePreview();
    recordModal.show();
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
    const search = jQuery('#cobranzasClientSearch');
    search.select2({
        theme: 'bootstrap4',
        width: '100%',
        placeholder: 'Escriba la razón social o el RUC',
        ajax: {
            url: <?= json_encode(APP_URL . '/servicios/cobranzas/buscar_clientes.php') ?>,
            dataType: 'json',
            delay: 250,
            data: params => ({q: params.term || ''}),
            processResults: data => ({results: data.results || []})
        }
    });
    search.on('select2:select', event => {
        window.location.href = <?= json_encode(APP_URL . '/modulos/cobranzas/parte_diario.php?cliente_id=') ?> + encodeURIComponent(event.params.data.id);
    });
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
