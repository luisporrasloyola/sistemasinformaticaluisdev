<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/cobranzas_valorizacion_periodo.php';
require_module_access('finanzas.depositos_personal');
$depositRecords = [];
$depositDbError = false;
$depositPeriodError = '';
$depositPage = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$depositHasMore = false;
try {
    $latestDepositDate = db()->query('SELECT MAX(deposit_date) FROM finance_personal_deposits')->fetchColumn();
    $defaultMonth = $latestDepositDate ? substr((string) $latestDepositDate, 0, 7) : date('Y-m');
} catch (PDOException $error) {
    $depositDbError = true;
    $defaultMonth = date('Y-m');
    error_log('Error al listar depósitos personales: ' . $error->getMessage());
}
try {
    $depositPeriod = valorizacion_periodo($_GET, $defaultMonth);
} catch (InvalidArgumentException $error) {
    $depositPeriodError = $error->getMessage();
    $depositPeriod = valorizacion_periodo([], $defaultMonth);
}
if (!$depositDbError) {
    try {
        $depositStmt = db()->prepare('SELECT id, deposit_date, creditor, amount, payment_method FROM finance_personal_deposits WHERE deposit_date BETWEEN ? AND ? ORDER BY deposit_date DESC, id DESC LIMIT 26 OFFSET ' . (($depositPage - 1) * 25));
        $depositStmt->execute([$depositPeriod['start'], $depositPeriod['end']]);
        $depositRecords = $depositStmt->fetchAll();
        $depositHasMore = count($depositRecords) > 25;
        if ($depositHasMore) array_pop($depositRecords);
    } catch (PDOException $error) {
        $depositDbError = true;
        error_log('Error al listar depósitos personales: ' . $error->getMessage());
    }
}
$depositPeriodQuery = $depositPeriod['mode'] === 'mes'
    ? ['periodo' => 'mes', 'mes' => $depositPeriod['month']]
    : ['periodo' => 'fechas', 'desde' => $depositPeriod['from'], 'hasta' => $depositPeriod['to']];
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Depósitos al personal</h1>
        <p>Finanzas</p>
    </div>
</div>
<style>
.personal-deposit-upload.work-panel{margin-bottom:12px;border:1px solid #dedbd4!important;border-radius:15px!important;background:linear-gradient(115deg,#fbfaf7 0%,#f4f2ed 100%)!important;box-shadow:0 5px 18px rgba(60,55,45,.055)!important}
.personal-deposit-upload-title{display:flex;align-items:flex-start;gap:8px}
.personal-deposit-upload-title h2{line-height:26px}
.personal-deposit-info{display:inline-grid;place-items:center;flex:none;width:26px;height:26px;padding:0;border:1px solid #bdd2f4;border-radius:50%;background:#eef5ff;color:#2563eb;line-height:1;cursor:pointer}
.personal-deposit-info:hover{background:#dfeeff;border-color:#8fb3ed}
.personal-deposit-info:focus-visible{outline:3px solid #93b4ff;outline-offset:2px}
.personal-deposit-model-modal .modal-content{border:1px solid #cbd8eb;border-radius:15px;overflow:hidden}
.personal-deposit-model-modal .modal-header{background:#f4f7fb}
.personal-deposit-model-carousel{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;overscroll-behavior-inline:contain;padding:4px 4px 12px}
.personal-deposit-model-slide{flex:0 0 100%;min-width:0;scroll-snap-align:start;text-align:center}
.personal-deposit-model-slide img{display:block;max-width:100%;max-height:min(55vh,450px);width:auto;height:auto;margin:auto;border:1px solid #d9e3ef;border-radius:8px;object-fit:contain}
.personal-deposit-model-slide figcaption{margin-top:8px;color:#344761;font-size:.85rem;font-weight:700}
.personal-deposit-model-nav{display:flex;justify-content:center;gap:7px;flex-wrap:wrap;margin-top:4px}
.personal-deposit-model-nav a{display:inline-grid;place-items:center;width:29px;height:29px;border:1px solid #cbd8eb;border-radius:50%;color:#2563eb;text-decoration:none;font-size:.8rem;font-weight:700}
.personal-deposit-model-nav a:hover,.personal-deposit-model-nav a:focus-visible{background:#eef5ff;border-color:#8fb3ed}
.personal-deposit-records.work-panel{border:1px solid #cbd8eb;border-radius:15px}
.personal-deposit-upload-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.personal-deposit-fields{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.personal-deposit-fields label{display:block;margin-bottom:5px;color:#526b91;font-size:.75rem;font-weight:800}
.personal-deposit-table{width:100%;border-collapse:collapse;font-size:.85rem}
.personal-deposit-table th,.personal-deposit-table td{padding:12px;border-bottom:1px solid #d6e1ef}
.personal-deposit-table th{background:#f5f8fc;color:#0b1932;font-size:.72rem;text-transform:uppercase}
.personal-deposit-table td{text-align:center;color:#63738a}
.personal-deposit-table td:not([colspan]){text-align:left;color:#0b1932}
.personal-deposit-card{padding:15px;margin-top:12px;border:1px solid #cbd8eb;border-radius:10px;background:#fff}
.personal-deposit-card-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.personal-deposit-card-header strong{overflow-wrap:anywhere}
.personal-deposit-status{font-size:.8rem;color:#526b91}
.personal-deposit-status.is-error{color:#b42318}
.personal-deposit-preview{display:block;width:auto;max-width:100%;max-height:220px;margin:0 0 12px;border:1px solid #d9e3ef;border-radius:7px;object-fit:contain}
.personal-deposit-preview[hidden]{display:none}
.personal-deposit-actions{display:flex;align-items:center;gap:5px;white-space:nowrap}
.personal-deposit-action{display:inline-grid;place-items:center;width:30px;height:30px;padding:0;border:1px solid #cbd8eb;border-radius:6px;background:#fff;color:#12213a}
.personal-deposit-action:hover{background:#f0f5ff}
.personal-deposit-action.is-file{color:#2563eb}.personal-deposit-action.is-delete{color:#dc3545;border-color:#f4a1aa}
.personal-deposit-table tr.is-editing{background:#f1f6fd}
.personal-deposit-table .form-control{min-width:100px}
.personal-deposit-period{display:flex;align-items:end;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.personal-deposit-period-field{min-width:125px}
.personal-deposit-period-field label{display:block;margin-bottom:4px;color:#526b91;font-size:.7rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
.personal-deposit-period-field .form-control,.personal-deposit-period-field .form-select,.personal-deposit-period .btn{height:38px}
.personal-deposit-period-field[hidden]{display:none}
@media(max-width:850px){.personal-deposit-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:550px){.personal-deposit-fields{grid-template-columns:1fr}}
</style>
<section class="work-panel personal-deposit-upload" aria-labelledby="personalDepositUploadTitle">
    <div class="personal-deposit-upload-header">
        <div><div class="personal-deposit-upload-title mb-1"><h2 class="h5 mb-0" id="personalDepositUploadTitle">Nuevo depósito</h2><button class="personal-deposit-info" type="button" data-bs-toggle="modal" data-bs-target="#personalDepositModelsModal" title="Ver comprobantes compatibles" aria-label="Ver comprobantes compatibles"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button></div><p class="small text-muted mb-0">Seleccione hasta 5 fotos (JPG, PNG o WEBP; máximo 5 MB por foto). Cada foto corresponde a un depósito.</p></div>
        <label class="btn btn-primary mb-0" for="personalDepositFile"><i class="fa-solid fa-file-arrow-up me-1" aria-hidden="true"></i>Seleccionar fotos</label>
        <input class="visually-hidden" type="file" id="personalDepositFile" accept="image/jpeg,image/png,image/webp" multiple aria-label="Seleccionar hasta cinco fotos del depósito">
    </div>
    <div class="small text-danger mt-2" id="personalDepositFileError" role="alert" hidden></div>
    <div class="mt-3" id="personalDepositProgress" hidden><div class="small fw-semibold mb-1" id="personalDepositProgressText" role="status"></div><div class="progress" role="progressbar" aria-label="Lectura y guardado de depósitos" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="progress-bar progress-bar-striped progress-bar-animated" id="personalDepositProgressBar" style="width:0%"></div></div></div>
    <div class="mt-3" id="personalDepositSelection" hidden><div class="small text-muted mb-2" id="personalDepositFileCount"></div><div id="personalDepositCards"></div></div>
</section>
<div class="modal fade personal-deposit-model-modal" id="personalDepositModelsModal" tabindex="-1" aria-labelledby="personalDepositModelsTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title h5 mb-0" id="personalDepositModelsTitle">Comprobantes compatibles</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body"><p class="text-muted small mb-3">La lectura automática reconoce estos formatos de Yape, Plin y transferencias bancarias.</p>
            <div class="personal-deposit-model-carousel" aria-label="Ejemplos de comprobantes">
                <?php foreach ([['YAPE.png', 'Yape'], ['PLIN.png', 'Plin'], ['PLIN otro modelo.png', 'Plin'], ['tranferencia interbank.png', 'Transferencia Interbank'], ['tranferencia interbank otro modelo.png', 'Transferencia Interbank'], ['Transferencia BCP.png', 'Transferencia BCP']] as $index => [$file, $label]): ?>
                    <figure class="personal-deposit-model-slide mb-0" id="depositModel<?= $index + 1 ?>"><img src="<?= e(APP_URL . '/img/depositosgemini/' . rawurlencode($file)) ?>" alt="Modelo de comprobante <?= e($label) ?>" loading="lazy" decoding="async"><figcaption><?= e($label) ?></figcaption></figure>
                <?php endforeach; ?>
            </div>
            <nav class="personal-deposit-model-nav" aria-label="Ir a un modelo de comprobante"><?php for ($index = 1; $index <= 6; $index++): ?><a href="#depositModel<?= $index ?>" aria-label="Ver modelo <?= $index ?>"><?= $index ?></a><?php endfor; ?></nav>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>
<section class="work-panel personal-deposit-records" aria-labelledby="personalDepositRecordsTitle">
    <h2 class="h5 mb-3" id="personalDepositRecordsTitle">Registros de depósitos</h2>
    <?php if ($depositDbError): ?><div class="alert alert-warning mb-3">Para guardar y ver depósitos, ejecute <code>database/finanzas_depositos_personal.sql</code> en la base de datos.</div><?php endif; ?>
    <?php if ($depositPeriodError !== ''): ?><div class="alert alert-warning mb-3" role="alert"><?= e($depositPeriodError) ?></div><?php endif; ?>
    <form class="personal-deposit-period" id="personalDepositPeriodForm" method="get" action="<?= e(APP_URL . '/modulos/finanzas/depositos_personal.php') ?>">
        <div class="personal-deposit-period-field"><label for="personalDepositPeriodMode">Periodo</label><select class="form-select form-select-sm" id="personalDepositPeriodMode" name="periodo"><option value="mes" <?= $depositPeriod['mode'] === 'mes' ? 'selected' : '' ?>>Por mes</option><option value="fechas" <?= $depositPeriod['mode'] === 'fechas' ? 'selected' : '' ?>>Entre fechas</option></select></div>
        <div class="personal-deposit-period-field" id="personalDepositMonthField" <?= $depositPeriod['mode'] !== 'mes' ? 'hidden' : '' ?>><label for="personalDepositMonth">Mes</label><input class="form-control form-control-sm" id="personalDepositMonth" name="mes" type="month" value="<?= e($depositPeriod['month']) ?>" <?= $depositPeriod['mode'] === 'mes' ? 'required' : 'disabled' ?>></div>
        <div class="personal-deposit-period-field" id="personalDepositFromField" <?= $depositPeriod['mode'] !== 'fechas' ? 'hidden' : '' ?>><label for="personalDepositFrom">Desde</label><input class="form-control form-control-sm" id="personalDepositFrom" name="desde" type="date" value="<?= e($depositPeriod['from']) ?>" <?= $depositPeriod['mode'] === 'fechas' ? 'required' : 'disabled' ?>></div>
        <div class="personal-deposit-period-field" id="personalDepositToField" <?= $depositPeriod['mode'] !== 'fechas' ? 'hidden' : '' ?>><label for="personalDepositTo">Hasta</label><input class="form-control form-control-sm" id="personalDepositTo" name="hasta" type="date" value="<?= e($depositPeriod['to']) ?>" <?= $depositPeriod['mode'] === 'fechas' ? 'required' : 'disabled' ?>></div>
        <button class="btn btn-outline-primary" type="submit">Ver</button>
    </form>
    <div class="table-responsive"><table class="personal-deposit-table"><thead><tr><th>Fecha</th><th>Acreedor de depósito</th><th>Monto depositado</th><th>Modo de pago</th><th>Acciones</th></tr></thead><tbody id="personalDepositRows"><?php foreach ($depositRecords as $record): ?><tr data-id="<?= (int) $record['id'] ?>" data-date="<?= e((string) $record['deposit_date']) ?>" data-creditor="<?= e((string) $record['creditor']) ?>" data-amount="<?= e((string) $record['amount']) ?>" data-method="<?= e((string) $record['payment_method']) ?>"><td><?= e(date('d/m/Y', strtotime((string) $record['deposit_date']))) ?></td><td><?= e((string) $record['creditor']) ?></td><td>S/ <?= e(number_format((float) $record['amount'], 2)) ?></td><td><?= e((string) $record['payment_method']) ?></td><td><div class="personal-deposit-actions"><button class="personal-deposit-action" type="button" data-action="edit" title="Editar depósito" aria-label="Editar depósito"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><a class="personal-deposit-action is-file" href="<?= e(APP_URL . '/servicios/finanzas/depositos_personal_archivo.php?id=' . (int) $record['id']) ?>" target="_blank" rel="noopener noreferrer" title="Ver foto" aria-label="Ver foto"><i class="fa-solid fa-file-image" aria-hidden="true"></i></a><button class="personal-deposit-action is-delete" type="button" data-action="delete" title="Eliminar depósito" aria-label="Eliminar depósito"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></div></td></tr><?php endforeach; ?><?php if (!$depositRecords && !$depositDbError): ?><tr id="personalDepositEmpty"><td colspan="5">Aún no hay depósitos registrados.</td></tr><?php endif; ?></tbody></table></div>
    <?php if ($depositPage > 1 || $depositHasMore): ?><nav class="d-flex justify-content-end gap-2 mt-3" aria-label="Páginas de depósitos"><?php if ($depositPage > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query($depositPeriodQuery + ['pagina' => $depositPage - 1])) ?>">Anterior</a><?php endif; ?><?php if ($depositHasMore): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query($depositPeriodQuery + ['pagina' => $depositPage + 1])) ?>">Siguiente</a><?php endif; ?></nav><?php endif; ?>
</section>
<script>
window.personalDepositGemini = {
    endpoint: <?= json_encode(APP_URL . '/servicios/finanzas/depositos_personal_gemini.php') ?>,
    saveEndpoint: <?= json_encode(APP_URL . '/servicios/finanzas/depositos_personal_guardar.php') ?>,
    fileEndpoint: <?= json_encode(APP_URL . '/servicios/finanzas/depositos_personal_archivo.php') ?>,
    actionsEndpoint: <?= json_encode(APP_URL . '/servicios/finanzas/depositos_personal_acciones.php') ?>,
    periodMode: <?= json_encode($depositPeriod['mode']) ?>,
    periodMonth: <?= json_encode($depositPeriod['month']) ?>,
    periodStart: <?= json_encode($depositPeriod['start']) ?>,
    periodEnd: <?= json_encode($depositPeriod['end']) ?>,
    page: <?= $depositPage ?>,
    csrf: <?= json_encode(csrf_token()) ?>
};
</script>
<script src="<?= e(APP_URL . '/recursos/js/finanzas_depositos_personal.js?v=12') ?>" defer></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
