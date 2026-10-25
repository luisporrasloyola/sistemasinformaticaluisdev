<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('cobranzas.valorizacion');

$newClientId = filter_var($_POST['cliente_id'] ?? $_GET['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$newLocation = trim((string) ($_POST['lugar_servicio'] ?? ''));
$newEquipment = trim((string) ($_POST['equipo'] ?? ''));
$newCapacity = trim((string) ($_POST['capacidad'] ?? ''));
$newFrom = (string) ($_POST['desde'] ?? '');
$newTo = (string) ($_POST['hasta'] ?? '');
$newError = '';
$selectedClient = null;
$selectedContact = null;
if ($newClientId) {
    $clientStmt = db()->prepare('SELECT id, business_name, ruc FROM clients WHERE id = ?');
    $clientStmt->execute([$newClientId]);
    $selectedClient = $clientStmt->fetch() ?: null;
    if ($selectedClient) {
        $contactStmt = db()->prepare("SELECT name, phone FROM client_contacts WHERE client_id = ? AND LOWER(position) LIKE '%comercial%' ORDER BY sort_order, id LIMIT 1");
        $contactStmt->execute([$newClientId]);
        $selectedContact = $contactStmt->fetch() ?: null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? null);
    if (!$selectedClient) $newError = 'Seleccione un cliente válido.';
    elseif (mb_strlen($newLocation, 'UTF-8') > 200) $newError = 'El lugar de servicio no puede superar 200 caracteres.';
    elseif ($newEquipment === '') $newError = 'Ingrese el equipo.';
    elseif (mb_strlen($newEquipment, 'UTF-8') > 150) $newError = 'El equipo no puede superar 150 caracteres.';
    elseif ($newCapacity === '') $newError = 'Ingrese la capacidad.';
    elseif (mb_strlen($newCapacity, 'UTF-8') > 100) $newError = 'La capacidad no puede superar 100 caracteres.';
    elseif ($newFrom === '' || $newTo === '') $newError = 'Seleccione ambas fechas del periodo.';
    else {
        foreach ([$newFrom, $newTo] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                $newError = 'Seleccione fechas válidas.';
                break;
            }
        }
        if ($newError === '' && $newFrom > $newTo) $newError = 'La fecha Desde debe ser anterior o igual a Hasta.';
    }
    if ($newError === '') {
        try {
            $stmt = db()->prepare('INSERT INTO cobranzas_valorizaciones (client_id, lugar_servicio, equipo, capacidad, periodo, registered_by_user_id) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$newClientId, $newLocation, $newEquipment, $newCapacity, $newFrom . '|' . $newTo, (int) (current_user()['id'] ?? 0) ?: null]);
            redirect('modulos/cobranzas/valorizacion.php?valorizacion_id=' . (int) db()->lastInsertId());
        } catch (PDOException $exception) {
            error_log('Error al crear valorización: ' . $exception->getMessage());
            $newError = 'No se pudo crear la valorización. Verifique que la migración de múltiples valorizaciones esté aplicada.';
        }
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<style>
.cv-new-panel{padding:18px!important;background:linear-gradient(115deg,#fbfaf7 0%,#f4f2ed 100%)!important}
.cv-new-layout{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(255px,.95fr);gap:18px}
.cv-new-fields{display:grid;grid-template-columns:96px minmax(0,1fr);column-gap:10px;row-gap:5px;align-items:center;min-width:0}
.cv-new-fields>label,.cv-new-fields>.cv-new-label{color:#455b79;font-size:.83rem;font-weight:700}
.cv-new-fields .form-control,.cv-new-fields .form-select{height:36px;border-color:#cbd9eb;border-radius:9px}
.cv-new-dual{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;min-width:0}
.cv-new-dual>div{min-width:0}
.cv-new-dual label{display:block;margin:0 0 3px;color:#455b79;font-size:.75rem;font-weight:700;line-height:1.2}
.cv-new-dual .cv-new-spacer{visibility:hidden}
.cv-new-dual .form-control{width:100%}
.cv-new-supplier{display:flex;flex-direction:column;min-width:0;padding-left:16px;border-left:1px solid #dedbd4}
.cv-new-supplier>div{margin-bottom:12px;font-size:.83rem}.cv-new-supplier strong{display:inline-block;width:90px;color:#455b79}
.cv-new-followup{margin-top:12px;padding:16px 18px!important}
.cv-new-followup h2{margin:0 0 4px;font-size:1rem;font-weight:700}
.cv-new-followup p{margin:0;color:#586d8a;font-size:.83rem}
.cv-new-followup .cv-new-file-hint{display:inline-block;margin-top:7px;padding:4px 9px;border:1px solid #e4dfd6;border-radius:7px;background:#fff;font-size:.75rem}
.cv-new-followup .cv-new-upload-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.cv-new-followup .cv-new-toolbar{display:flex;align-items:end;gap:8px;flex-wrap:wrap;margin:15px 0 12px}
.cv-new-followup .cv-new-toolbar label{display:block;margin:0 0 3px;color:#526b91;font-size:.7rem;font-weight:800;text-transform:uppercase}
.cv-new-followup .cv-new-toolbar .cv-new-currency{margin-left:auto}
.cv-new-followup .cv-new-toolbar select,.cv-new-followup .cv-new-toolbar input{height:38px;min-width:110px}
.cv-new-followup #cvNewMonth{width:190px;min-width:190px;color:#24364f;opacity:1}
.cv-new-table-wrap{max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
.cv-new-table{min-width:1000px;margin:0}
.cv-new-table th{background:#f4f7fb;color:#415474;font-size:.68rem;text-transform:uppercase;white-space:nowrap}
.cv-new-table th,.cv-new-table td{padding:.55rem .5rem}
.cv-new-pending{margin-top:8px!important}
.cv-new-save-action{display:flex;justify-content:flex-end;margin-top:auto!important;margin-bottom:0!important}
.cv-new-save-action .btn{min-width:120px}
@media(max-width:900px){.cv-new-layout{grid-template-columns:1fr}.cv-new-supplier{padding:12px 0 0;border:0;border-top:1px solid #dedbd4}}
@media(max-width:600px){.cv-new-fields{grid-template-columns:1fr;gap:5px}.cv-new-dual{grid-template-columns:1fr}.cv-new-followup .cv-new-toolbar .cv-new-currency{margin-left:0}}
</style>
<div class="page-title"><div><h1>Nueva valorización</h1><p>Complete los datos para crear una valorización independiente.</p></div><a class="btn btn-outline-secondary" href="<?= e(APP_URL . '/modulos/cobranzas/valorizacion.php') ?>"><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Volver al listado</a></div>
<section class="work-panel cv-new-panel">
    <?php if ($newError !== ''): ?><div class="alert alert-danger" role="alert"><?= e($newError) ?></div><?php endif; ?>
    <form id="cvNewForm" method="post" action="<?= e(APP_URL . '/modulos/cobranzas/valorizacion.php?nuevo=1') ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="cv-new-layout">
            <div class="cv-new-fields">
                <label for="cvNewClient">Cliente</label><select class="form-select" id="cvNewClient" name="cliente_id" required><option value=""></option><?php if ($selectedClient): ?><option value="<?= (int) $selectedClient['id'] ?>" selected><?= e($selectedClient['business_name'] . ' - ' . $selectedClient['ruc']) ?></option><?php endif; ?></select>
                <label for="cvNewLocation">Lugar de servicio</label><input class="form-control" id="cvNewLocation" name="lugar_servicio" value="<?= e($newLocation) ?>" maxlength="200" placeholder="Ingrese el lugar de servicio">
                <span class="cv-new-label">RUC - Cliente</span><span id="cvNewRuc"><?= $selectedClient ? e((string) $selectedClient['ruc']) : '—' ?></span>
                <span class="cv-new-label">Contacto</span><span id="cvNewContact"><?= $selectedContact ? e(trim((string) $selectedContact['name'] . ' · ' . (string) $selectedContact['phone'])) : '—' ?></span>
                <label for="cvNewEquipment">Equipo</label><div class="cv-new-dual"><div><label class="cv-new-spacer" aria-hidden="true">Equipo</label><input class="form-control" id="cvNewEquipment" name="equipo" value="<?= e($newEquipment) ?>" maxlength="150" placeholder="Ingrese el equipo" required></div><div><label for="cvNewCapacity">Capacidad</label><input class="form-control" id="cvNewCapacity" name="capacidad" value="<?= e($newCapacity) ?>" maxlength="100" placeholder="Ingrese la capacidad" required></div></div>
                <span class="cv-new-label">Periodo</span><div class="cv-new-dual"><div><label for="cvNewFrom">Desde</label><input class="form-control" id="cvNewFrom" name="desde" type="date" value="<?= e($newFrom) ?>" required></div><div><label for="cvNewTo">Hasta</label><input class="form-control" id="cvNewTo" name="hasta" type="date" value="<?= e($newTo) ?>" required></div></div>
            </div>
            <div class="cv-new-supplier"><div><strong>Proveedor</strong>LIFE EQUIPOS INDUSTRIALES SAC</div><div><strong>RUC</strong>20603633441</div><div class="cv-new-save-action"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i>Guardar</button></div></div>
        </div>
    </form>
</section>
<section class="work-panel cv-new-followup" aria-labelledby="cvNewUploadTitle">
    <div class="cv-new-upload-row"><div><h2 id="cvNewUploadTitle">Nuevo parte diario</h2><p>Adjunte una foto o PDF; si se leen todos los datos, el registro aparecerá en la tabla automáticamente.</p><span class="cv-new-file-hint">JPG, PNG, WEBP o PDF. Máximo 5 MB por archivo.</span></div><button class="btn btn-primary" type="button" disabled><i class="fa-solid fa-file-arrow-up me-1" aria-hidden="true"></i>Seleccionar archivo</button></div>
    <p class="cv-new-pending">Guarde la valorización para habilitar la carga de partes diarios.</p>
</section>
<section class="work-panel cv-new-followup" aria-labelledby="cvNewRecordsTitle">
    <h2 id="cvNewRecordsTitle">Registros de valorización</h2>
    <div class="cv-new-toolbar"><div><label for="cvNewPeriodMode">Periodo</label><select class="form-select form-select-sm" id="cvNewPeriodMode" disabled><option>Por mes</option></select></div><div><label for="cvNewMonth">Mes</label><input class="form-control form-control-sm" id="cvNewMonth" type="month" value="<?= e($newFrom !== '' ? substr($newFrom, 0, 7) : date('Y-m')) ?>" disabled></div><button class="btn btn-outline-primary" type="button" disabled>Ver</button><?php if (!is_personal_role()): ?><button class="btn btn-primary" type="button" disabled><i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>PDF Interno</button><button class="btn btn-danger" type="button" disabled><i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>PDF Cobranzas</button><?php endif; ?><div class="cv-new-currency"><label for="cvNewCurrency">Moneda</label><select class="form-select form-select-sm" id="cvNewCurrency" disabled><option>Soles (S/.)</option></select></div></div>
    <div class="cv-new-table-wrap border rounded"><table class="table table-hover align-middle cv-new-table"><thead><tr><th>Equipo</th><th>Operador</th><th>Fecha del servicio</th><th>Hora de inicio</th><th>Hora final</th><th>Horas en total</th><th>Observaciones</th><th>Registrado por</th><th>Acciones</th></tr></thead><tbody><tr><td colspan="9" class="text-center text-muted py-4">Guarde la valorización para agregar registros.</td></tr></tbody></table></div>
</section>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const from = document.getElementById('cvNewFrom');
    const month = document.getElementById('cvNewMonth');
    from.addEventListener('change', () => {
        month.value = from.value ? from.value.slice(0, 7) : <?= json_encode(date('Y-m')) ?>;
    });
    if (!window.jQuery || !jQuery.fn.select2) return;
    const client = jQuery('#cvNewClient');
    client.select2({theme:'bootstrap4',width:'100%',placeholder:'Seleccione o busque un cliente',ajax:{url:<?= json_encode(APP_URL . '/servicios/cobranzas/buscar_clientes_valorizacion.php') ?>,dataType:'json',delay:250,data:params=>({q:params.term||'',page:params.page||1}),processResults:data=>({results:data.results||[],pagination:data.pagination||{more:false}})}});
    client.on('select2:select', event => {
        document.getElementById('cvNewRuc').textContent = event.params.data.ruc || '—';
        document.getElementById('cvNewContact').textContent = event.params.data.contacto || 'Sin contacto comercial';
    });
    client.on('select2:clear', () => {
        document.getElementById('cvNewRuc').textContent = '—';
        document.getElementById('cvNewContact').textContent = '—';
    });
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
