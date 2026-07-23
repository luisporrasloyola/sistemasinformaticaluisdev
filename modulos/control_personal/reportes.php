<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/marking_report_data.php';
require_module_access('control_personal.reportes');

$today = date('Y-m-d'); $defaultFrom = date('Y-m-01');
$dateFrom = trim((string) ($_GET['desde'] ?? $defaultFrom));
$dateTo = trim((string) ($_GET['hasta'] ?? $today));
$workerId = (int) ($_GET['trabajador_id'] ?? 0); $companyId = (int) ($_GET['empresa_id'] ?? 0);
$locationId = (int) ($_GET['punto_id'] ?? 0); $status = trim((string) ($_GET['estado'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = $today;
if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
if (!in_array($status, marking_report_allowed_statuses(), true)) $status = '';
$rows = marking_report_build($dateFrom, $dateTo, $workerId, $companyId, $locationId, $status);
$catalogs = marking_report_catalogs();
$exportQuery = http_build_query(['desde' => $dateFrom, 'hasta' => $dateTo, 'trabajador_id' => $workerId,
    'empresa_id' => $companyId, 'punto_id' => $locationId, 'estado' => $status]);

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title attendance-report-title marking-report-title">
    <div><h1>Reporte de marcaciones</h1><p>Consulta y descarga el detalle de las marcaciones registradas.</p></div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-success" href="<?= APP_URL ?>/servicios/control_personal/descargar_reporte_marcaciones_excel.php?<?= e($exportQuery) ?>"><i class="fa-solid fa-file-excel me-2"></i>Descargar Excel</a>
        <a class="btn btn-danger" href="<?= APP_URL ?>/servicios/control_personal/descargar_reporte_marcaciones_pdf.php?<?= e($exportQuery) ?>"><i class="fa-solid fa-file-pdf me-2"></i>Descargar PDF</a>
    </div>
</div>

<form class="dashboard-filters attendance-report-filters" method="get">
    <div class="attendance-report-grid">
        <div><label class="form-label">Desde</label><input class="form-control" type="date" name="desde" value="<?= e($dateFrom) ?>"></div>
        <div><label class="form-label">Hasta</label><input class="form-control" type="date" name="hasta" value="<?= e($dateTo) ?>"></div>
        <div><label class="form-label">Trabajador</label><select class="form-select" name="trabajador_id"><option value="0">Todos</option><?php foreach ($catalogs['workers'] as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $workerId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['full_name'] . ' - ' . $item['document_number']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Empresa</label><select class="form-select" name="empresa_id"><option value="0">Todas</option><?php foreach ($catalogs['companies'] as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $companyId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Lugar de marcación</label><select class="form-select" name="punto_id"><option value="0">Todos</option><?php foreach ($catalogs['locations'] as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $locationId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Estado de marcación</label><select class="form-select" name="estado"><option value="">Todos</option><?php foreach (marking_report_allowed_statuses() as $value): ?><option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e(marking_report_status_label($value)) ?></option><?php endforeach; ?></select></div>
        <div class="attendance-report-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter me-2"></i>Aplicar filtros</button><a class="btn btn-outline-secondary" href="<?= APP_URL ?>/modulos/control_personal/reportes.php">Limpiar</a></div>
    </div>
</form>

<div class="work-panel marking-report-panel">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h2 class="mb-1">Detalle de marcaciones</h2><p class="text-muted mb-0">Resultados del <?= e(date('d/m/Y', strtotime($dateFrom))) ?> al <?= e(date('d/m/Y', strtotime($dateTo))) ?>.</p></div>
        <span class="attendance-summary-count"><?= count($rows) ?> <?= count($rows) === 1 ? 'marcación' : 'marcaciones' ?></span>
    </div>
    <div class="table-responsive marking-report-table-wrap">
        <table class="table table-hover align-middle dashboard-table marking-report-table">
            <thead><tr><th>Fecha</th><th>Hora</th><th>Tipo</th><th>Personal</th><th>Empresa</th><th>Lugar</th><th>Distancia</th><th>Estado de marcación</th><th>Foto</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): $displayStatus = $row['display_status'] ?? $row['final_status'] ?? ''; ?>
                <tr><td class="text-nowrap"><?= e(date('d/m/Y', strtotime($row['mark_date']))) ?></td><td><?= e(marking_report_time($row['mark_time'] ?? null)) ?></td><td><?= e(ucfirst($row['mark_type'])) ?></td>
                    <td><strong><?= e($row['full_name']) ?></strong><span class="text-muted small d-block"><?= e($row['document_number']) ?></span></td><td><?= e($row['company'] ?? '') ?></td><td><?= e($row['location_name']) ?></td>
                    <td class="text-nowrap"><?= e(number_format((float) $row['distance_meters'], 2)) ?> m</td><td><span class="badge <?= marking_report_badge_class($displayStatus) ?>"><?= e(marking_report_status_label($displayStatus)) ?></span></td>
                    <td><?php if ($row['photo_path']): ?><a class="btn btn-sm btn-outline-secondary marking-photo-button" target="_blank" href="<?= APP_URL . '/' . e($row['photo_path']) ?>" title="Ver fotografía"><i class="fa-solid fa-image"></i></a><?php else: ?><span class="text-muted">-</span><?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No hay marcaciones para los filtros seleccionados.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
