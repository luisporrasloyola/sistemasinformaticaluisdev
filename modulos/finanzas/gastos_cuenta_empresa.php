<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_module_access('finanzas.gastos_personal');
if (is_personal_role()) { http_response_code(403); exit('No tiene acceso a la cuenta de la empresa.'); }
$mode = ($_GET['periodo'] ?? 'mes') === 'fechas' ? 'fechas' : 'mes';
$month = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) $month = date('Y-m');
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title"><div><h1 class="mb-0">Gastos de personal</h1><p class="mb-0">Ficha de gastos de Cuenta soles empresa.</p></div><a class="btn btn-outline-primary" href="<?= e(APP_URL . '/modulos/finanzas/gastos_personal.php') ?>"><i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Volver a la lista</a></div>
<section class="work-panel mb-3"><div class="row g-3"><div class="col-md-6"><small class="text-uppercase text-muted fw-bold">Personal</small><h2 class="h5 mb-0">Cuenta soles empresa</h2></div><div class="col-md-6"><small class="text-uppercase text-muted fw-bold">Empresa</small><div class="fw-bold">Life Maquinarias</div></div></div></section>
<section class="work-panel"><h2 class="h5 mb-3">Registros de gastos</h2>
<form class="d-flex flex-wrap align-items-end gap-2 mb-3" method="get">
<div><label class="form-label mb-1" for="expensePeriodMode">Periodo</label><select class="form-select form-select-sm" id="expensePeriodMode" name="periodo"><option value="mes" <?= $mode === 'mes' ? 'selected' : '' ?>>Por mes</option><option value="fechas" <?= $mode === 'fechas' ? 'selected' : '' ?>>Entre fechas</option></select></div>
<div id="expensePeriodMonthField" <?= $mode !== 'mes' ? 'hidden' : '' ?>><label class="form-label mb-1" for="expensePeriodMonth">Mes</label><input class="form-control form-control-sm" id="expensePeriodMonth" name="mes" type="month" value="<?= e($month) ?>" <?= $mode === 'mes' ? '' : 'disabled' ?>></div>
<div id="expensePeriodFromField" <?= $mode !== 'fechas' ? 'hidden' : '' ?>><label class="form-label mb-1" for="expensePeriodFrom">Desde</label><input class="form-control form-control-sm" id="expensePeriodFrom" name="desde" type="date" <?= $mode === 'fechas' ? '' : 'disabled' ?>></div>
<div id="expensePeriodToField" <?= $mode !== 'fechas' ? 'hidden' : '' ?>><label class="form-label mb-1" for="expensePeriodTo">Hasta</label><input class="form-control form-control-sm" id="expensePeriodTo" name="hasta" type="date" <?= $mode === 'fechas' ? '' : 'disabled' ?>></div>
<button class="btn btn-sm btn-outline-primary" type="submit">Ver</button></form>
<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Fecha</th><th>Ubicación</th><th>Proyecto</th><th>Viáticos</th><th>Observaciones</th><th>Gastos adicionales</th><th>Motivo</th><th>Total</th><th>Depósitos</th><th>Saldo restante</th><th>Registrado por</th><th>Acción</th></tr></thead><tbody><tr><td colspan="12" class="text-center text-muted py-4">Aún no hay registros de gastos para esta cuenta.</td></tr></tbody></table></div></section>
<script>document.getElementById('expensePeriodMode').addEventListener('change',function(){const monthly=this.value==='mes';for(const [id,show] of [['expensePeriodMonthField',monthly],['expensePeriodFromField',!monthly],['expensePeriodToField',!monthly]]){const field=document.getElementById(id);field.hidden=!show;field.querySelector('input').disabled=!show;}});</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
