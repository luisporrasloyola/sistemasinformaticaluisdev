<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/cobranzas_valorizacion_periodo.php';
require_module_access('compras');
require_module_access('compras.registro');
if (is_personal_role()) { http_response_code(403); exit('Acceso no permitido.'); }
$depositWorkers = db()->query('SELECT id, full_name, document_number FROM workers ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);
$depositRecords = [];
$depositDbError = false;
$depositPeriodError = '';
$depositSearch = mb_substr(trim(is_string($_GET['buscar'] ?? null) ? $_GET['buscar'] : ''), 0, 100);
$depositPage = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$depositHasMore = false;
$depositTotalCents = 0;
try {
    $latestDepositDate = db()->query('SELECT MAX(purchase_date) FROM purchase_records')->fetchColumn();
    $defaultMonth = $latestDepositDate ? substr((string) $latestDepositDate, 0, 7) : date('Y-m');
} catch (PDOException $error) {
    $depositDbError = true;
    $defaultMonth = date('Y-m');
    error_log('Error al listar compras: ' . $error->getMessage());
}
try {
    $depositPeriod = valorizacion_periodo($_GET, $defaultMonth);
} catch (InvalidArgumentException $error) {
    $depositPeriodError = $error->getMessage();
    $depositPeriod = valorizacion_periodo([], $defaultMonth);
}
if (!$depositDbError) {
    try {
        $searchWhere = $depositSearch !== '' ? " AND (creditor LIKE ? ESCAPE '!' OR depositor LIKE ? ESCAPE '!')" : '';
        $searchParams = [$depositPeriod['start'], $depositPeriod['end']];
        if ($depositSearch !== '') {
            $searchTerm = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $depositSearch) . '%';
            $searchParams[] = $searchTerm;
            $searchParams[] = $searchTerm;
        }
        $totalStmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM purchase_records WHERE purchase_date BETWEEN ? AND ?' . $searchWhere);
        $totalStmt->execute($searchParams);
        $depositTotalCents = (int) round((float) $totalStmt->fetchColumn() * 100);
        $depositStmt = db()->prepare('SELECT id, purchase_date, registration_source, creditor, depositor, amount, payment_method, image_path, batch_id, registered_by_user_id FROM purchase_records WHERE purchase_date BETWEEN ? AND ?' . $searchWhere . ' ORDER BY purchase_date DESC, id DESC LIMIT 26 OFFSET ' . (($depositPage - 1) * 25));
        $depositStmt->execute($searchParams);
        $depositRecords = $depositStmt->fetchAll();
        $depositHasMore = count($depositRecords) > 25;
        if ($depositHasMore) array_pop($depositRecords);
    } catch (PDOException $error) {
        $depositDbError = true;
        error_log('Error al listar compras: ' . $error->getMessage());
    }
}
$depositPeriodQuery = $depositPeriod['mode'] === 'mes'
    ? ['periodo' => 'mes', 'mes' => $depositPeriod['month']]
    : ['periodo' => 'fechas', 'desde' => $depositPeriod['from'], 'hasta' => $depositPeriod['to']];
if ($depositSearch !== '') $depositPeriodQuery['buscar'] = $depositSearch;
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Registro de compras</h1>
        <p>Compras</p>
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
.personal-deposit-model-slide iframe{display:block;width:100%;height:min(55vh,450px);margin:auto;border:1px solid #d9e3ef;border-radius:8px;background:#fff}
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
.personal-deposit-table tfoot th{padding:12px;border-top:2px solid #9db5ce;background:#f1f7f4;color:#12543f;font-weight:800;text-align:left}
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
.personal-deposit-search-field{min-width:220px;flex:1 1 220px;max-width:320px}
.personal-deposit-period-field label{display:block;margin-bottom:4px;color:#526b91;font-size:.7rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
.personal-deposit-period-field .form-control,.personal-deposit-period-field .form-select,.personal-deposit-period .btn{height:38px}
.personal-deposit-period-field[hidden]{display:none}
.personal-deposit-manual-modal .modal-content{border:1px solid #cbd8eb;border-radius:15px}
.personal-deposit-manual-modal .modal-header{background:#f4f7fb}
@media(max-width:850px){.personal-deposit-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:550px){.personal-deposit-fields{grid-template-columns:1fr}}
</style>
<section class="work-panel personal-deposit-upload" aria-labelledby="personalDepositUploadTitle">
    <div class="personal-deposit-upload-header">
        <div><div class="personal-deposit-upload-title mb-1"><h2 class="h5 mb-0" id="personalDepositUploadTitle">Nueva compra</h2><button class="personal-deposit-info" type="button" data-bs-toggle="modal" data-bs-target="#purchaseModelsModal" title="Ver comprobantes compatibles" aria-label="Ver comprobantes compatibles"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button></div><p class="small text-muted mb-0">Seleccione hasta 5 fotos o PDF (máximo 5 MB por foto y 1,5 MB por PDF). Los registros reconocidos se guardan automáticamente, incluso si un archivo contiene varios beneficiarios.</p></div>
        <label class="btn btn-primary mb-0" for="personalDepositFile"><i class="fa-solid fa-file-arrow-up me-1" aria-hidden="true"></i>Seleccionar fotos o PDF</label>
        <input class="visually-hidden" type="file" id="personalDepositFile" accept="image/jpeg,image/png,image/webp,application/pdf" multiple aria-label="Seleccionar hasta cinco fotos o PDF de compras">
    </div>
    <div class="small text-danger mt-2" id="personalDepositFileError" role="alert" hidden></div>
    <div class="mt-3" id="personalDepositProgress" hidden><div class="small fw-semibold mb-1" id="personalDepositProgressText" role="status"></div><div class="progress" role="progressbar" aria-label="Lectura y guardado de compras" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="progress-bar progress-bar-striped progress-bar-animated" id="personalDepositProgressBar" style="width:0%"></div></div></div>
    <div class="mt-3" id="personalDepositSelection" hidden><div class="small text-muted mb-2" id="personalDepositFileCount"></div><div id="personalDepositCards"></div></div>
</section>
<div class="modal fade personal-deposit-model-modal" id="purchaseModelsModal" tabindex="-1" aria-labelledby="purchaseModelsTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title h5 mb-0" id="purchaseModelsTitle">Comprobantes compatibles</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body"><p class="text-muted small mb-3">La lectura automática reconoce estos formatos de Yape, Plin y transferencias bancarias.</p>
            <div class="personal-deposit-model-carousel" aria-label="Ejemplos de comprobantes">
                <?php
                $purchaseModels = [
                    ['YAPE.png', 'Yape'],
                    ['PLIN.png', 'Plin'],
                    ['PLIN otro modelo.png', 'Plin'],
                    ['tranferencia interbank.png', 'Transferencia Interbank'],
                    ['tranferencia interbank otro modelo.png', 'Transferencia Interbank'],
                    ['Tranferencia Interbank-otros.png', 'Transferencia Interbank'],
                    ['Transferencia BCP.png', 'Transferencia BCP'],
                    ['Transferencia.png', 'Transferencia'],
                    ['Transferencia Interbank otros.png', 'Transferencia Interbank por lote', 'lote'],
                    ['Depositos.pdf', 'Transferencia Interbank por lote (PDF)', 'pdf'],
                    ['Depositos.png', 'Transferencia Interbank por lote (foto)', 'imagen'],
                    ['Plin_otros.jpeg', 'Constancia de Pago Plin', 'plin'],
                    ['transferencia Interbank-Otros.jpg', 'Constancia de transferencia Interbank', 'interbank_constancia'],
                    ['Tranferencia Interbank 2.jpeg', 'Transferencia Interbank con destinatario oculto', 'interbank_oculto'],
                    ['Pago efectivo.jpeg', 'Pago efectivo Interbank', 'pago_efectivo'],
                    ['TransferenciaOtros.jpeg', 'Transferencia masiva con un beneficiario', 'transferencia_masiva'],
                ];
                foreach ($purchaseModels as $index => $model):
                    [$file, $label] = $model;
                    $modelUrl = isset($model[2])
                        ? APP_URL . '/servicios/compras/modelo_comprobante.php?formato=' . rawurlencode($model[2])
                        : APP_URL . '/img/depositosgemini/' . rawurlencode($file);
                ?>
                    <figure class="personal-deposit-model-slide mb-0" id="purchaseModel<?= $index + 1 ?>"><?php if ($file === 'Depositos.pdf'): ?><iframe src="<?= e($modelUrl) ?>#toolbar=0" title="Modelo de comprobante <?= e($label) ?>" loading="lazy"></iframe><?php else: ?><img src="<?= e($modelUrl) ?>" alt="Modelo de comprobante <?= e($label) ?>" loading="lazy" decoding="async"><?php endif; ?><figcaption><?= e($label) ?></figcaption></figure>
                <?php endforeach; ?>
            </div>
            <nav class="personal-deposit-model-nav" aria-label="Ir a un modelo de comprobante"><?php for ($index = 1; $index <= count($purchaseModels); $index++): ?><a href="#purchaseModel<?= $index ?>" aria-label="Ver modelo <?= $index ?>"><?= $index ?></a><?php endfor; ?></nav>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>
<div class="modal fade personal-deposit-manual-modal" id="personalDepositManualModal" tabindex="-1" aria-labelledby="personalDepositManualTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form id="personalDepositManualForm" enctype="multipart/form-data">
            <div class="modal-header"><h2 class="modal-title h5 mb-0" id="personalDepositManualTitle">Nueva compra manual</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-sm-6"><label class="form-label" for="manualDepositDate">Fecha</label><input class="form-control" id="manualDepositDate" name="fecha" type="date" value="<?= e(date('Y-m-d')) ?>" required></div>
                    <div class="col-sm-6"><label class="form-label" for="manualDepositAmount">Monto depositado (S/)</label><input class="form-control" id="manualDepositAmount" name="monto" type="number" min="0.01" max="999999999.99" step="0.01" required></div>
                    <div class="col-12"><label class="form-label" for="manualDepositCreditor">Acreedor de depósito</label><select class="form-select select2-searchable" id="manualDepositCreditor" name="trabajador_id" data-placeholder="Buscar personal" data-no-results="No se encontró personal"><option value="">Busque y seleccione al trabajador</option><?php foreach ($depositWorkers as $worker): ?><option value="<?= (int) $worker['id'] ?>"><?= e($worker['full_name'] . ((string) $worker['document_number'] !== '' ? ' · ' . $worker['document_number'] : '')) ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-6"><label class="form-label" for="manualDepositMethod">Modo de pago</label><input class="form-control" id="manualDepositMethod" name="modo_pago" type="text" maxlength="100" placeholder="Ej.: transferencia bancaria" required></div>
                    <div class="col-sm-6"><label class="form-label" for="manualDepositDepositor">Depositante</label><input class="form-control" id="manualDepositDepositor" name="depositante" type="text" maxlength="150" required></div>
                    <div class="col-12"><label class="form-label" for="manualDepositPhoto">Foto del comprobante <span class="text-muted fw-normal">(opcional)</span></label><input class="form-control" id="manualDepositPhoto" name="foto" type="file" accept="image/jpeg,image/png,image/webp"><div class="form-text">JPG, PNG o WEBP; máximo 5 MB.</div></div>
                </div>
                <div class="alert alert-danger mt-3 mb-0" id="personalDepositManualError" role="alert" hidden></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit" id="personalDepositManualSave">Guardar compra</button></div>
        </form>
    </div></div>
</div>
<section class="work-panel personal-deposit-records" aria-labelledby="personalDepositRecordsTitle">
    <h2 class="h5 mb-3" id="personalDepositRecordsTitle">Registros de compras</h2>
    <?php if ($depositDbError): ?><div class="alert alert-warning mb-3">Para guardar y ver compras, ejecute <code>database/purchase_records.sql</code> en la base de datos.</div><?php endif; ?>
    <?php if ($depositPeriodError !== ''): ?><div class="alert alert-warning mb-3" role="alert"><?= e($depositPeriodError) ?></div><?php endif; ?>
    <form class="personal-deposit-period" id="personalDepositPeriodForm" method="get" action="<?= e(APP_URL . '/modulos/compras/registro.php') ?>">
        <div class="personal-deposit-period-field"><label for="personalDepositPeriodMode">Periodo</label><select class="form-select form-select-sm" id="personalDepositPeriodMode" name="periodo"><option value="mes" <?= $depositPeriod['mode'] === 'mes' ? 'selected' : '' ?>>Por mes</option><option value="fechas" <?= $depositPeriod['mode'] === 'fechas' ? 'selected' : '' ?>>Entre fechas</option></select></div>
        <div class="personal-deposit-period-field" id="personalDepositMonthField" <?= $depositPeriod['mode'] !== 'mes' ? 'hidden' : '' ?>><label for="personalDepositMonth">Mes</label><input class="form-control form-control-sm" id="personalDepositMonth" name="mes" type="month" value="<?= e($depositPeriod['month']) ?>" <?= $depositPeriod['mode'] === 'mes' ? 'required' : 'disabled' ?>></div>
        <div class="personal-deposit-period-field" id="personalDepositFromField" <?= $depositPeriod['mode'] !== 'fechas' ? 'hidden' : '' ?>><label for="personalDepositFrom">Desde</label><input class="form-control form-control-sm" id="personalDepositFrom" name="desde" type="date" value="<?= e($depositPeriod['from']) ?>" <?= $depositPeriod['mode'] === 'fechas' ? 'required' : 'disabled' ?>></div>
        <div class="personal-deposit-period-field" id="personalDepositToField" <?= $depositPeriod['mode'] !== 'fechas' ? 'hidden' : '' ?>><label for="personalDepositTo">Hasta</label><input class="form-control form-control-sm" id="personalDepositTo" name="hasta" type="date" value="<?= e($depositPeriod['to']) ?>" <?= $depositPeriod['mode'] === 'fechas' ? 'required' : 'disabled' ?>></div>
        <div class="personal-deposit-period-field personal-deposit-search-field"><label for="personalDepositSearch">Acreedor o depositante</label><input class="form-control form-control-sm" id="personalDepositSearch" name="buscar" type="search" maxlength="100" value="<?= e($depositSearch) ?>" placeholder="Buscar por acreedor o depositante" autocomplete="off"></div>
        <button class="btn btn-outline-primary" type="submit">Ver</button>
        <button class="btn btn-success ms-auto" type="button" data-bs-toggle="modal" data-bs-target="#personalDepositManualModal"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Nuevo Manual</button>
    </form>
    <div class="table-responsive"><table class="personal-deposit-table"><thead><tr><th>Fecha</th><th>Registro</th><th>Acreedor de depósito</th><th>Monto depositado</th><th>Modo de pago</th><th>Depositante</th><th>Acciones</th></tr></thead><tbody id="personalDepositRows"><?php foreach ($depositRecords as $record): ?><tr data-id="<?= (int) $record['id'] ?>" data-date="<?= e((string) $record['purchase_date']) ?>" data-registration="<?= e((string) $record['registration_source']) ?>" data-creditor="<?= e((string) $record['creditor']) ?>" data-amount="<?= e((string) $record['amount']) ?>" data-method="<?= e((string) $record['payment_method']) ?>" data-depositor="<?= e((string) ($record['depositor'] ?? '')) ?>" data-has-photo="<?= (!empty($record['image_path']) || $record['batch_id'] !== null) ? '1' : '0' ?>" data-is-batch="<?= $record['batch_id'] !== null ? '1' : '0' ?>" data-can-manage="1"><td><?= e(date('d/m/Y', strtotime((string) $record['purchase_date']))) ?></td><td><?= $record['registration_source'] === 'manual' ? 'Manual' : 'Gemini' ?></td><td><?= e((string) $record['creditor']) ?></td><td>S/ <?= e(number_format((float) $record['amount'], 2)) ?></td><td><?= e((string) $record['payment_method']) ?></td><td><?= e((string) ($record['depositor'] ?: '—')) ?></td><td><div class="personal-deposit-actions"><button class="personal-deposit-action" type="button" data-action="edit" title="Editar compra" aria-label="Editar compra"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><?php if (!empty($record['image_path']) || $record['batch_id'] !== null): ?><a class="personal-deposit-action is-file" href="<?= e(APP_URL . '/servicios/compras/archivo.php?id=' . (int) $record['id']) ?>" target="_blank" rel="noopener noreferrer" title="Ver foto" aria-label="Ver foto"><i class="fa-solid fa-file-image" aria-hidden="true"></i></a><?php endif; ?><button class="personal-deposit-action is-delete" type="button" data-action="delete" title="Eliminar compra" aria-label="Eliminar compra"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></div></td></tr><?php endforeach; ?><?php if (!$depositRecords && !$depositDbError): ?><tr id="personalDepositEmpty"><td colspan="7"><?= $depositSearch !== '' ? 'No se encontraron compras para ese acreedor o depositante en el período seleccionado.' : 'Aún no hay compras registradas.' ?></td></tr><?php endif; ?></tbody><tfoot><tr><th colspan="3"></th><th id="personalDepositTotal" data-cents="<?= $depositTotalCents ?>">S/ <?= e(number_format($depositTotalCents / 100, 2)) ?></th><th colspan="3"></th></tr></tfoot></table></div>
    <?php if ($depositPage > 1 || $depositHasMore): ?><nav class="d-flex justify-content-end gap-2 mt-3" aria-label="Páginas de compras"><?php if ($depositPage > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query($depositPeriodQuery + ['pagina' => $depositPage - 1])) ?>">Anterior</a><?php endif; ?><?php if ($depositHasMore): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query($depositPeriodQuery + ['pagina' => $depositPage + 1])) ?>">Siguiente</a><?php endif; ?></nav><?php endif; ?>
</section>
<script>
window.purchaseRegistration = {
    endpoint: <?= json_encode(APP_URL . '/servicios/compras/gemini.php') ?>,
    manualSaveEndpoint: <?= json_encode(APP_URL . '/servicios/compras/guardar_manual.php') ?>,
    batchSaveEndpoint: <?= json_encode(APP_URL . '/servicios/compras/guardar_lote.php') ?>,
    saveEndpoint: <?= json_encode(APP_URL . '/servicios/compras/guardar.php') ?>,
    fileEndpoint: <?= json_encode(APP_URL . '/servicios/compras/archivo.php') ?>,
    actionsEndpoint: <?= json_encode(APP_URL . '/servicios/compras/acciones.php') ?>,
    periodMode: <?= json_encode($depositPeriod['mode']) ?>,
    periodMonth: <?= json_encode($depositPeriod['month']) ?>,
    periodStart: <?= json_encode($depositPeriod['start']) ?>,
    periodEnd: <?= json_encode($depositPeriod['end']) ?>,
    search: <?= json_encode($depositSearch, JSON_UNESCAPED_UNICODE) ?>,
    page: <?= $depositPage ?>,
    workers: <?= json_encode(array_map(static fn(array $worker): array => ['id' => (int) $worker['id'], 'name' => (string) $worker['full_name']], $depositWorkers), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    canManageNew: <?= (is_admin() || is_gestor_role()) ? 'true' : 'false' ?>,
    csrf: <?= json_encode(csrf_token()) ?>
};
</script>

<script src="<?= e(APP_URL . '/recursos/js/compras_registro_lote.js?v=1') ?>" defer></script>
<script src="<?= e(APP_URL . '/recursos/js/compras_registro.js?v=2') ?>" defer></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
