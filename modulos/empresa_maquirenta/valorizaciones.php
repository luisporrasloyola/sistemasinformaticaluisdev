<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa_maquirenta.valorizaciones');

$month = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$site = (string) ($_GET['sede'] ?? 'ventanilla');
if (!in_array($site, ['ventanilla', 'santa_rosa', 'consolidado'], true)) $site = 'ventanilla';
$siteName = ['ventanilla' => 'Ventanilla', 'santa_rosa' => 'Santa Rosa', 'consolidado' => 'ambas centrales'][$site];
$sources = [
    'ventanilla' => ['charla' => 'empresa_maquirenta_charla_preoperacional', 'equipment' => 'empresa_maquirenta_charla_preoperacional_maquinarias', 'name' => 'Ventanilla'],
    'santa_rosa' => ['charla' => 'empresa_maquirenta_santa_rosa_charla_preoperacional', 'equipment' => 'empresa_maquirenta_santa_rosa_charla_preoperacional_maquinarias', 'name' => 'Santa Rosa'],
];
$start = $month . '-01';
$end = (new DateTimeImmutable($start))->format('Y-m-t');
$dateFrom = trim((string) ($_GET['desde'] ?? ''));
$dateTo = trim((string) ($_GET['hasta'] ?? ''));
$periodMode = ($dateFrom !== '' || $dateTo !== '') ? 'rango' : 'mes';
$filterError = '';
$rangeActive = false;
if ($dateFrom !== '' || $dateTo !== '') {
    $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateFrom);
    $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateTo);
    if (!$fromDate || !$toDate || $fromDate->format('Y-m-d') !== $dateFrom || $toDate->format('Y-m-d') !== $dateTo) {
        $filterError = 'Seleccione ambas fechas válidas para filtrar.';
    } elseif ($fromDate > $toDate) {
        $filterError = 'La fecha «Desde» no puede ser posterior a «Hasta».';
    } elseif ($fromDate->diff($toDate)->days > 366) {
        $filterError = 'Seleccione un período máximo de 366 días.';
    } else {
        $start = $dateFrom;
        $end = $dateTo;
        $rangeActive = true;
    }
}
$chipFilter = ['mes' => $month];
if ($rangeActive) {
    $chipFilter['desde'] = $dateFrom;
    $chipFilter['hasta'] = $dateTo;
}
$selectedSources = $site === 'consolidado' ? $sources : [$site => $sources[$site]];
$queries = [];
$parameters = [];
foreach ($selectedSources as $sourceKey => $source) {
    $queries[] = "SELECT c.id, c.fecha, m.nombre AS equipo, COALESCE(u.name, 'Sin usuario') AS registrado_por,
        v.horas_trabajo, v.tarifa_equipo, s.tarifa_supervisor, d.descuento, d.observaciones,
        COALESCE(b.bloqueado, 0) AS bloqueado,
        '{$source['name']}' AS central
        FROM {$source['charla']} c
        JOIN {$source['equipment']} m ON m.id = c.maquinaria_tipo_id
        LEFT JOIN users u ON u.id = c.registered_by_user_id
        LEFT JOIN empresa_maquirenta_valorizacion_equipos v ON v.central = '{$sourceKey}' AND v.charla_id = c.id
        LEFT JOIN empresa_maquirenta_valorizacion_supervisor s ON s.central = '{$sourceKey}' AND s.fecha = c.fecha
        LEFT JOIN empresa_maquirenta_valorizacion_detalles d ON d.central = '{$sourceKey}' AND d.fecha = c.fecha
        LEFT JOIN empresa_maquirenta_valorizacion_bloqueos b ON b.central = '{$sourceKey}' AND b.charla_id = c.id
        WHERE c.fecha BETWEEN ? AND ?";
    array_push($parameters, $start, $end);
}
$stmt = db()->prepare(implode(' UNION ALL ', $queries) . ' ORDER BY fecha DESC, central DESC, equipo, id');
$stmt->execute($parameters);
$groups = [];
foreach ($stmt->fetchAll() as $record) $groups[$record['fecha'] . '|' . $record['central']][] = $record;
function valuation_input_number(int|float|string $value): string
{
    $number = (string) $value;
    return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
}
require __DIR__ . '/../../includes/header.php';
?>
<style>
.valuation-toolbar{display:flex;align-items:end;justify-content:space-between;gap:1rem;flex-wrap:wrap;padding:1rem 1.1rem;border:1px solid #dbe6f5;border-radius:12px;background:linear-gradient(120deg,#f5f9ff,#fbfdff)}
.valuation-toolbar-sites{display:grid;gap:.45rem}
.valuation-toolbar-label{font-size:.69rem;font-weight:800;letter-spacing:.075em;text-transform:uppercase;color:#526c91}
.valuation-chip{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;min-height:38px;padding:.5rem .9rem;border:1px solid var(--chip-border);border-radius:9px;background:var(--chip-soft);color:var(--chip-color);font-size:.84rem;font-weight:700;text-decoration:none;transition:background-color .15s,border-color .15s,box-shadow .15s}
.valuation-chip:hover{border-color:var(--chip-color);background:var(--chip-hover);color:var(--chip-color)}
.valuation-chip:focus-visible{outline:3px solid #93c5fd;outline-offset:2px}
.valuation-chip.is-active{border-color:var(--chip-color);background:var(--chip-color);color:#fff;box-shadow:0 3px 9px rgba(15,23,42,.16)}
.valuation-chip--ventanilla{--chip-color:#2563eb;--chip-border:#bfdbfe;--chip-soft:#eff6ff;--chip-hover:#dbeafe}
.valuation-chip--santa-rosa{--chip-color:#078355;--chip-border:#a7e3c8;--chip-soft:#ecfdf5;--chip-hover:#d1fae5}
.valuation-chip--consolidado{--chip-color:#7c3aed;--chip-border:#d8b4fe;--chip-soft:#f7f0ff;--chip-hover:#ede2ff}
.valuation-period-form{width:max-content;max-width:100%;min-width:0}
.valuation-period-form .form-control,.valuation-period-form .form-select,.valuation-period-form .btn{min-height:38px}
.valuation-period-fields{display:flex;align-items:end;gap:.4rem;flex-wrap:nowrap;overflow-x:auto}
.valuation-period-fields [hidden]{display:none!important}
.valuation-period-fields #valuationMonth{width:180px}
.valuation-period-range{display:flex;align-items:end;gap:.4rem;flex-wrap:nowrap}
.valuation-period-range .form-control{width:140px;min-width:140px}
.valuation-period-choice,.valuation-period-fields .valuation-date-field{display:grid;gap:.3rem;margin:0;white-space:nowrap;font-size:.69rem;line-height:1.2;text-transform:uppercase;letter-spacing:.075em;color:#526c91;font-weight:800}
.valuation-period-form .valuation-period-select{width:145px;min-width:145px}
.valuation-period-form .btn{flex:none}
.valuation-table-wrap{border:1px solid #dbe4f0;border-radius:12px;overflow:auto}
.valuation-table{min-width:1480px;margin:0}
.valuation-table th{padding:.8rem .55rem;font-size:.7rem;line-height:1.3;text-transform:uppercase;letter-spacing:.025em;color:#425575;background:#f5f8fc;border-bottom:1px solid #cbd8e8;white-space:normal;vertical-align:middle}
.valuation-table td{padding:.55rem .55rem;vertical-align:middle;border-color:#e0e8f1;font-size:.84rem}
.valuation-table .valuation-number{width:38px;min-width:38px;padding-left:.2rem;padding-right:.2rem;text-align:center;font-variant-numeric:tabular-nums;font-weight:700;color:#52657f}
.valuation-table tbody tr:hover>*{box-shadow:none}
.valuation-table tbody tr:hover td:first-child{box-shadow:none}
.valuation-table .valuation-date-row td{padding:.58rem .7rem;border-top:2px solid #ccdced;border-bottom:1px solid #d5e1ee;font-weight:700}
.valuation-table .valuation-date-row td:nth-child(3){padding-left:.55rem}
.valuation-date-label{display:flex;align-items:center;gap:.55rem}
.valuation-count{display:inline-flex;align-items:center;border-radius:999px;padding:.12rem .55rem;font-size:.72rem;font-weight:650;background:#fff;border:1px solid currentColor}
.valuation-table .valuation-input{width:76px;min-width:76px;height:34px;text-align:right;padding:.35rem .5rem;background:#fff;border-color:#cad7e8;font-variant-numeric:tabular-nums}
.valuation-table .valuation-input:focus{border-color:#2563eb;box-shadow:0 0 0 .2rem rgba(37,99,235,.14)}
.valuation-table .valuation-discount{width:92px;min-width:92px}
.valuation-table .valuation-observations{width:180px;min-width:180px;min-height:54px;resize:vertical;font-size:.79rem;background:#fff;border-color:#cad7e8}
.valuation-table .valuation-counted-cell{width:42px;min-width:42px;padding-left:.3rem;padding-right:.3rem;text-align:center}
.valuation-counted{display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
.valuation-counted input,.valuation-count-all input{width:1.1rem;height:1.1rem;margin:0;accent-color:#078355;cursor:pointer}
.valuation-counted input:focus-visible{outline:3px solid #93c5fd;outline-offset:2px}
.valuation-count-all{display:flex;align-items:center;justify-content:center;gap:.4rem;cursor:pointer}
.valuation-table .valuation-equipment.is-blocked>td:not([rowspan]){background:#f3f5f8;color:#64748b}
.valuation-table .valuation-equipment.is-blocked .valuation-input:disabled{background:#edf0f4;color:#718096;opacity:1}
.valuation-table .valuation-equipment.is-blocked [data-equipment-cost]{color:#9a3652;font-weight:700}
.valuation-money{white-space:nowrap;font-variant-numeric:tabular-nums;font-weight:700}
.valuation-supervisor-cost{text-align:center;border-left:1px solid #d4e3f1;border-right:1px solid #d4e3f1}
.valuation-table [data-day-total]{font-size:.98rem;font-weight:800;text-align:center}
.valuation-group-blue{--group-bg:#eaf2ff;--group-ink:#17427e}
.valuation-group-mint{--group-bg:#e4f5ef;--group-ink:#17634b}
.valuation-group-amber{--group-bg:#fff4df;--group-ink:#805600}
.valuation-group-violet{--group-bg:#f0ebff;--group-ink:#5b3a9d}
.valuation-group-rose{--group-bg:#fff0f1;--group-ink:#9a3652}
.valuation-group-cyan{--group-bg:#e8f7fa;--group-ink:#17677b}
.valuation-table .valuation-date-row td,.valuation-table .valuation-equipment td{background:var(--group-bg)}
.valuation-table .valuation-date-row td{color:var(--group-ink)}
.valuation-table .valuation-equipment .valuation-supervisor-cost{background:var(--group-bg)!important}
.valuation-table .valuation-equipment [data-day-total]{background:var(--group-bg)!important;color:var(--group-ink)}
.valuation-table .valuation-equipment .is-day-blocked{background:#f3f5f8!important;color:#64748b!important}
.valuation-table .valuation-equipment .is-day-blocked .valuation-discount:disabled{background:#edf0f4;color:#718096;opacity:1}
.valuation-total{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 1.2rem;border:1px solid #cbdcf4;border-radius:14px;background:linear-gradient(110deg,#f8fbff,#edf5ff);box-shadow:0 3px 12px rgba(30,64,175,.05);color:#143b6c}
.valuation-total-label{font-size:.96rem;font-weight:750;color:#153d70}
.valuation-total-amount{font-size:1.55rem;line-height:1.15;font-weight:800;font-variant-numeric:tabular-nums;color:#103e7d}
.valuation-save-status{min-height:1.25rem;font-size:.8rem;color:#52708f}
.valuation-save-status.is-error{color:#b42318}
@media(max-width:640px){.valuation-toolbar{align-items:stretch;padding:.85rem}.valuation-period-form{width:100%}.valuation-total{padding:.85rem;gap:.5rem}.valuation-total-amount{font-size:1.15rem}}
</style>
<div class="page-title"><div><h1>Valorizaciones</h1><p>Costos diarios según Charla preoperacional.</p></div></div>
<section class="work-panel" id="valuationPanel">
    <div class="valuation-toolbar mb-3">
        <div class="valuation-toolbar-sites"><span class="valuation-toolbar-label">Central térmica</span><nav class="d-flex gap-2 flex-wrap" aria-label="Central térmica">
            <a class="valuation-chip valuation-chip--ventanilla<?= $site === 'ventanilla' ? ' is-active' : '' ?>" href="?<?= e(http_build_query(['sede' => 'ventanilla'] + $chipFilter)) ?>"<?= $site === 'ventanilla' ? ' aria-current="page"' : '' ?>><i class="fa-solid fa-industry" aria-hidden="true"></i>Ventanilla</a>
            <a class="valuation-chip valuation-chip--santa-rosa<?= $site === 'santa_rosa' ? ' is-active' : '' ?>" href="?<?= e(http_build_query(['sede' => 'santa_rosa'] + $chipFilter)) ?>"<?= $site === 'santa_rosa' ? ' aria-current="page"' : '' ?>><i class="fa-solid fa-industry" aria-hidden="true"></i>Santa Rosa</a>
            <a class="valuation-chip valuation-chip--consolidado<?= $site === 'consolidado' ? ' is-active' : '' ?>" href="?<?= e(http_build_query(['sede' => 'consolidado'] + $chipFilter)) ?>"<?= $site === 'consolidado' ? ' aria-current="page"' : '' ?>><i class="fa-solid fa-layer-group" aria-hidden="true"></i>Consolidado</a>
        </nav></div>
        <form class="valuation-period-form" method="get" id="valuationPeriodForm">
            <input type="hidden" name="sede" value="<?= e($site) ?>">
            <div class="valuation-period-fields">
                <label class="valuation-period-choice" for="valuationPeriodMode">Período <select class="form-select valuation-period-select" id="valuationPeriodMode"><option value="mes"<?= $periodMode === 'mes' ? ' selected' : '' ?>>Por mes</option><option value="rango"<?= $periodMode === 'rango' ? ' selected' : '' ?>>Entre fechas</option></select></label>
                <label class="valuation-date-field" for="valuationMonth" id="valuationMonthField"<?= $periodMode === 'rango' ? ' hidden' : '' ?>>Mes <input class="form-control" type="month" id="valuationMonth" name="mes" value="<?= e($month) ?>"<?= $periodMode === 'rango' ? '' : ' required' ?>></label>
                <div class="valuation-period-range" id="valuationPeriodRange"<?= $periodMode === 'mes' ? ' hidden' : '' ?>>
                    <label class="valuation-date-field" for="valuationFrom">Desde <input class="form-control" type="date" id="valuationFrom" name="desde" value="<?= e($dateFrom) ?>"<?= $periodMode === 'mes' ? ' disabled' : ' required' ?>></label>
                    <label class="valuation-date-field" for="valuationTo">Hasta <input class="form-control" type="date" id="valuationTo" name="hasta" value="<?= e($dateTo) ?>"<?= $periodMode === 'mes' ? ' disabled' : ' required' ?>></label>
                </div>
                <button class="btn btn-outline-primary" type="submit">Ver</button>
                <a class="btn btn-outline-success" href="<?= e(APP_URL . '/servicios/empresa_maquirenta/descargar_valorizaciones_excel.php?' . http_build_query(['sede' => $site] + $chipFilter)) ?>" aria-label="Descargar valorizaciones visibles en Excel" title="Descargar valorizaciones visibles en Excel"><i class="fa-solid fa-file-excel" aria-hidden="true"></i> Excel</a>
            </div>
        </form>
    </div>
    <?php if ($filterError !== ''): ?><div class="alert alert-warning py-2" role="alert"><?= e($filterError) ?> Se muestra el mes seleccionado.</div><?php endif; ?>
    <?php if (!$groups): ?>
        <p class="text-center text-muted py-5 mb-0">No hay charlas preoperacionales de <?= e($siteName) ?> para <?= $rangeActive ? 'las fechas seleccionadas' : 'este mes' ?>.</p>
    <?php else: ?>
    <div class="valuation-table-wrap"><table class="table valuation-table">
        <thead><tr><th class="valuation-number" scope="col">Nro.</th><th class="valuation-counted-cell" scope="col"><label class="valuation-count-all"><input type="checkbox" data-count-all aria-label="Contabilizar todos los registros visibles" title="Incluir o excluir todos los registros visibles"></label></th><th>Fecha</th><th>Equipo</th><th>Registrado por</th><th>Horas de trabajo</th><th>Costo por hora del equipo</th><th>Costo hora supervisor + RT</th><th>Costo por día del equipo</th><th>Costo día supervisor + RT</th><th>Descuento</th><th>Total</th><th>Observaciones</th></tr></thead>
        <tbody>
        <?php
        $groupTones = ['blue', 'mint', 'amber', 'violet', 'rose', 'cyan'];
        $dayIndex = -1;
        $previousDate = null;
        $rowNumber = 0;
        foreach ($groups as $groupKey => $records):
            $date = (string) $records[0]['fecha'];
            if ($date !== $previousDate) {
                $dayIndex++;
                $previousDate = $date;
            }
            $displayDate = (new DateTimeImmutable($date))->format('d/m/Y');
            $groupTone = $groupTones[$dayIndex % count($groupTones)];
        ?>
            <tr class="valuation-date-row valuation-group-<?= $groupTone ?>" data-valuation-date="<?= e($groupKey) ?>"><td class="valuation-number" aria-hidden="true"></td><td class="valuation-counted-cell" aria-hidden="true"></td><td colspan="11"><div class="valuation-date-label"><i class="fa-regular fa-calendar" aria-hidden="true"></i><span><?= e($displayDate) ?></span><?php if ($site === 'consolidado'): ?><span class="valuation-count"><?= e($records[0]['central']) ?></span><?php endif; ?><span class="valuation-count"><?= count($records) ?> <?= count($records) === 1 ? 'equipo' : 'equipos' ?></span></div></td></tr>
            <?php foreach ($records as $index => $record):
                $equipmentName = mb_strtolower(trim((string) $record['equipo']), 'UTF-8');
                $defaultRate = str_contains($equipmentName, 'montacarga') ? 95
                    : (str_contains($equipmentName, 'tijeral') ? 80 : 125);
                $blocked = (int) $record['bloqueado'] === 1;
            ?>
            <tr class="valuation-equipment valuation-group-<?= $groupTone ?><?= $blocked ? ' is-blocked' : '' ?>" data-site="<?= $record['central'] === 'Santa Rosa' ? 'santa_rosa' : 'ventanilla' ?>" data-group="<?= e($groupKey) ?>" data-source-id="<?= (int) $record['id'] ?>">
                <td class="valuation-number"><?= ++$rowNumber ?></td><td class="valuation-counted-cell"><label class="valuation-counted"><input type="checkbox" data-counted <?= $blocked ? '' : 'checked' ?> aria-label="Contabilizar <?= e($record['equipo']) ?> del <?= e($displayDate) ?> en <?= e($record['central']) ?>" title="<?= $blocked ? 'Excluido: marcar para contabilizar' : 'Incluido: desmarcar para excluir' ?>"></label></td><td><?= e($displayDate) ?></td><td class="fw-semibold"><?= e($record['equipo']) ?></td><td><?= e($record['registrado_por']) ?></td>
                <td><input class="form-control form-control-sm valuation-input" type="number" min="0" max="24" step="0.25" value="<?= e(valuation_input_number($record['horas_trabajo'] ?? 8)) ?>" data-hours aria-label="Horas de <?= e($record['equipo']) ?> el <?= e($displayDate) ?>" <?= $blocked ? 'disabled' : '' ?>></td>
                <td><input class="form-control form-control-sm valuation-input" type="number" min="0" step="0.01" value="<?= e(valuation_input_number($record['tarifa_equipo'] ?? $defaultRate)) ?>" data-equipment-rate aria-label="Tarifa del equipo <?= e($record['equipo']) ?>" <?= $blocked ? 'disabled' : '' ?>></td>
                <td><input class="form-control form-control-sm valuation-input" type="number" min="0" step="0.01" value="<?= e(valuation_input_number($record['tarifa_supervisor'] ?? 50)) ?>" data-supervisor-rate aria-label="Tarifa supervisor y RT de <?= e($record['equipo']) ?> el <?= e($displayDate) ?>" <?= $blocked ? 'disabled' : '' ?>></td>
                <td class="valuation-money" data-equipment-cost>—</td><?php if ($index === 0): ?><td class="valuation-money valuation-supervisor-cost" data-supervisor-cost rowspan="<?= count($records) ?>">—</td><td data-day-discount-cell rowspan="<?= count($records) ?>"><input class="form-control form-control-sm valuation-input valuation-discount" type="number" min="0" max="999999.99" step="0.01" value="<?= e(valuation_input_number($record['descuento'] ?? 0)) ?>" data-discount aria-label="Descuento del <?= e($displayDate) ?> en <?= e($record['central']) ?>"></td><td class="valuation-money valuation-supervisor-cost" data-day-total rowspan="<?= count($records) ?>">—</td><td rowspan="<?= count($records) ?>"><textarea class="form-control form-control-sm valuation-observations" maxlength="1000" data-observations aria-label="Observaciones del <?= e($displayDate) ?> en <?= e($record['central']) ?>"><?= e((string) ($record['observaciones'] ?? '')) ?></textarea></td><?php endif; ?>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="valuation-total mt-3"><span class="valuation-total-label"><?= $rangeActive ? 'Total del período' : 'Total del mes' ?></span><strong class="valuation-total-amount" id="valuationMonthTotal">—</strong></div>
    <div class="valuation-save-status mt-2" id="valuationSaveStatus" role="status" aria-live="polite"></div>
    <?php endif; ?>
</section>
<script>
(() => {
    const panel = document.getElementById('valuationPanel');
    const periodMode = document.getElementById('valuationPeriodMode');
    const periodMonth = document.getElementById('valuationMonth');
    const periodMonthField = document.getElementById('valuationMonthField');
    const periodRange = document.getElementById('valuationPeriodRange');
    const periodDates = [document.getElementById('valuationFrom'), document.getElementById('valuationTo')];
    periodMode.addEventListener('change', () => {
        const byRange = periodMode.value === 'rango';
        periodMonthField.hidden = byRange;
        periodMonth.required = !byRange;
        periodRange.hidden = !byRange;
        periodDates.forEach(input => { input.disabled = !byRange; input.required = byRange; });
    });
    const saveStatus = document.getElementById('valuationSaveStatus');
    const saveUrl = <?= json_encode(APP_URL . '/servicios/empresa_maquirenta/valorizaciones.php') ?>;
    const csrfToken = <?= json_encode(csrf_token()) ?>;
    const format = new Intl.NumberFormat('es-PE', {style:'currency',currency:'PEN'});
    const value = input => Math.max(0, Number(input?.value) || 0);
    const countAll = panel.querySelector('[data-count-all]');
    const countedInputs = () => [...panel.querySelectorAll('[data-counted]')];
    const syncCountAll = () => {
        if (!countAll) return;
        const inputs = countedInputs();
        const included = inputs.filter(input => input.checked).length;
        countAll.checked = inputs.length > 0 && included === inputs.length;
        countAll.indeterminate = included > 0 && included < inputs.length;
        countAll.title = included === inputs.length ? 'Excluir todos los registros visibles' : 'Incluir todos los registros visibles';
    };
    const setIncluded = (row, included) => {
        const checkbox = row.querySelector('[data-counted]');
        checkbox.checked = included;
        checkbox.title = included ? 'Incluido: desmarcar para excluir' : 'Excluido: marcar para contabilizar';
        row.classList.toggle('is-blocked', !included);
        row.querySelectorAll('[data-hours], [data-equipment-rate], [data-supervisor-rate]').forEach(input => { input.disabled = !included; });
    };
    const update = () => {
        let monthTotal = 0;
        panel.querySelectorAll('[data-valuation-date]').forEach(header => {
            const date = header.dataset.valuationDate;
            const rows = [...panel.querySelectorAll('.valuation-equipment')].filter(row => row.dataset.group === date);
            const included = rows.filter(row => row.querySelector('[data-counted]').checked);
            let equipmentTotal = 0;
            rows.forEach(row => {
                const cost = row.querySelector('[data-counted]').checked
                    ? value(row.querySelector('[data-hours]')) * value(row.querySelector('[data-equipment-rate]')) : 0;
                row.querySelector('[data-equipment-cost]').textContent = format.format(cost);
                equipmentTotal += cost;
            });
            const supervisorHours = included.length ? Math.max(...included.map(row => value(row.querySelector('[data-hours]')))) : 0;
            const supervisorCost = included.length ? supervisorHours * value(included[0].querySelector('[data-supervisor-rate]')) : 0;
            const dayTotal = included.length ? equipmentTotal + supervisorCost - value(rows[0].querySelector('[data-discount]')) : 0;
            const dayBlocked = included.length === 0;
            const supervisorCell = rows[0].querySelector('[data-supervisor-cost]');
            const discountCell = rows[0].querySelector('[data-day-discount-cell]');
            const totalCell = rows[0].querySelector('[data-day-total]');
            [supervisorCell, discountCell, totalCell].forEach(cell => cell.classList.toggle('is-day-blocked', dayBlocked));
            discountCell.querySelector('[data-discount]').disabled = dayBlocked;
            supervisorCell.textContent = format.format(supervisorCost);
            totalCell.textContent = format.format(dayTotal);
            monthTotal += dayTotal;
        });
        const total = document.getElementById('valuationMonthTotal');
        if (total) total.textContent = format.format(monthTotal);
        syncCountAll();
    };
    panel.addEventListener('input', event => {
        if (event.target.matches('[data-supervisor-rate]')) {
            const source = event.target.closest('.valuation-equipment');
            panel.querySelectorAll('.valuation-equipment').forEach(row => {
                if (row.dataset.group === source.dataset.group && row !== source) row.querySelector('[data-supervisor-rate]').value = event.target.value;
            });
        }
        if (event.target.matches('[data-hours], [data-equipment-rate], [data-supervisor-rate], [data-discount]')) update();
    });
    panel.querySelectorAll('[data-hours], [data-equipment-rate], [data-supervisor-rate], [data-discount], [data-observations]').forEach(input => {
        input.dataset.savedValue = input.value;
    });
    panel.addEventListener('change', async event => {
        if (!event.target.matches('[data-count-all]')) return;
        const included = countAll.checked;
        const inputs = countedInputs();
        const records = {ventanilla: [], santa_rosa: []};
        inputs.forEach(input => {
            const row = input.closest('.valuation-equipment');
            records[row.dataset.site].push(Number(row.dataset.sourceId));
            input.disabled = true;
        });
        countAll.disabled = true;
        saveStatus.classList.remove('is-error');
        saveStatus.textContent = 'Guardando cambios…';
        const body = new FormData();
        body.set('csrf_token', csrfToken);
        body.set('campo', 'bloqueo_masivo');
        body.set('valor', included ? '0' : '1');
        body.set('registros', JSON.stringify(records));
        try {
            const response = await fetch(saveUrl, {method: 'POST', body, credentials: 'same-origin'});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudieron guardar los cambios.');
            inputs.forEach(input => setIncluded(input.closest('.valuation-equipment'), included));
            update();
            saveStatus.textContent = '';
        } catch (error) {
            syncCountAll();
            saveStatus.textContent = error.message || 'No se pudieron guardar los cambios.';
            saveStatus.classList.add('is-error');
        } finally {
            inputs.forEach(input => { input.disabled = false; });
            countAll.disabled = false;
        }
    });
    panel.addEventListener('change', async event => {
        const checkbox = event.target;
        if (!checkbox.matches('[data-counted]')) return;
        const row = checkbox.closest('.valuation-equipment');
        const included = checkbox.checked;
        checkbox.disabled = true;
        if (countAll) countAll.disabled = true;
        saveStatus.classList.remove('is-error');
        saveStatus.textContent = 'Guardando cambio…';
        const body = new FormData();
        body.set('csrf_token', csrfToken);
        body.set('sede', row.dataset.site);
        body.set('charla_id', row.dataset.sourceId);
        body.set('campo', 'bloqueado');
        body.set('valor', included ? '0' : '1');
        try {
            const response = await fetch(saveUrl, {method: 'POST', body, credentials: 'same-origin'});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo guardar el cambio.');
            setIncluded(row, included);
            update();
            saveStatus.textContent = '';
        } catch (error) {
            checkbox.checked = !included;
            saveStatus.textContent = error.message || 'No se pudo guardar el cambio.';
            saveStatus.classList.add('is-error');
        } finally {
            checkbox.disabled = false;
            if (countAll) countAll.disabled = false;
        }
    });
    panel.addEventListener('change', async event => {
        const input = event.target;
        if (!input.matches('[data-hours], [data-equipment-rate], [data-supervisor-rate], [data-discount], [data-observations]')) return;
        const row = input.closest('.valuation-equipment');
        const isSupervisor = input.matches('[data-supervisor-rate]');
        const isObservations = input.matches('[data-observations]');
        const related = isSupervisor
            ? [...panel.querySelectorAll('.valuation-equipment')].filter(item => item.dataset.group === row.dataset.group).map(item => item.querySelector('[data-supervisor-rate]'))
            : [input];
        if (!input.checkValidity() || (!isObservations && input.value.trim() === '')) {
            related.forEach(item => { item.value = item.dataset.savedValue; });
            update();
            saveStatus.textContent = 'Ingrese un valor válido.';
            saveStatus.classList.add('is-error');
            return;
        }
        const body = new FormData();
        body.set('csrf_token', csrfToken);
        body.set('sede', row.dataset.site);
        body.set('charla_id', row.dataset.sourceId);
        body.set('campo', isSupervisor ? 'tarifa_supervisor' : input.matches('[data-hours]') ? 'horas_trabajo' : input.matches('[data-equipment-rate]') ? 'tarifa_equipo' : input.matches('[data-discount]') ? 'descuento' : 'observaciones');
        body.set('valor', input.value);
        related.forEach(item => { item.disabled = true; });
        saveStatus.classList.remove('is-error');
        saveStatus.textContent = 'Guardando cambio…';
        try {
            const response = await fetch(saveUrl, {method: 'POST', body, credentials: 'same-origin', keepalive: true});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo guardar el cambio.');
            const displayValue = isObservations ? result.value : String(Number(result.value));
            related.forEach(item => { item.value = displayValue; item.dataset.savedValue = displayValue; });
            saveStatus.textContent = 'Cambio guardado.';
        } catch (error) {
            related.forEach(item => { item.value = item.dataset.savedValue; });
            update();
            saveStatus.textContent = error.message || 'No se pudo guardar el cambio.';
            saveStatus.classList.add('is-error');
        } finally {
            related.forEach(item => { item.disabled = false; });
        }
    });
    update();
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
