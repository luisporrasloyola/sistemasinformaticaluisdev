<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../includes/cobranzas_valorizacion_tarifa.php';
require_module_access('cobranzas.valorizacion');
$search = trim((string) ($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 100, 'UTF-8');
$listPage = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$perPage = 25;
$listRows = [];
$listTotal = 0;
$listError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unapprove_period') {
    if (is_personal_role()) { http_response_code(403); exit('No tiene permiso para desaprobar valorizaciones.'); }
    verify_csrf($_POST['csrf_token'] ?? null);
    $unapproveValuationId = filter_var($_POST['valorizacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $unapproveMode = ($_POST['periodo'] ?? '') === 'fechas' ? 'fechas' : 'mes';
    $unapproveFrom = (string) ($_POST['fecha_inicio'] ?? '');
    $unapproveTo = (string) ($_POST['fecha_fin'] ?? '');
    if (!$unapproveValuationId || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $unapproveFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $unapproveTo)) {
        $listError = 'La aprobación seleccionada no es válida.';
    } else {
        db()->prepare('DELETE FROM cobranzas_valorizacion_aprobaciones WHERE valorizacion_id = ? AND periodo_tipo = ? AND fecha_inicio = ? AND fecha_fin = ?')
            ->execute([$unapproveValuationId, $unapproveMode, $unapproveFrom, $unapproveTo]);
        redirect('modulos/cobranzas/valorizacion.php');
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_valuation') {
    if (is_personal_role()) { http_response_code(403); exit('El rol Personal no puede eliminar valorizaciones.'); }
    verify_csrf($_POST['csrf_token'] ?? null);
    $deleteId = filter_var($_POST['valorizacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$deleteId) {
        $listError = 'Seleccione una valorización válida para eliminar.';
    } else {
        $connection = db();
        try {
            $connection->beginTransaction();
            $checkStmt = $connection->prepare('SELECT id FROM cobranzas_valorizaciones WHERE id = ? FOR UPDATE');
            $checkStmt->execute([$deleteId]);
            if (!$checkStmt->fetchColumn()) {
                $connection->rollBack();
                $listError = 'La valorización ya no existe.';
            } else {
                $filesStmt = $connection->prepare('SELECT archivo_path FROM cobranzas_valorizacion_registros WHERE valorizacion_id = ?');
                $filesStmt->execute([$deleteId]);
                $paths = $filesStmt->fetchAll(PDO::FETCH_COLUMN);
                $connection->prepare('DELETE FROM cobranzas_valorizaciones WHERE id = ?')->execute([$deleteId]);
                $connection->commit();
                foreach ($paths as $path) delete_uploaded_file((string) $path);
                redirect('modulos/cobranzas/valorizacion.php');
            }
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) $connection->rollBack();
            error_log('Error al eliminar valorización: ' . $exception->getMessage());
            $listError = 'No se pudo eliminar la valorización. Intente nuevamente.';
        }
    }
}

try {
    // Los borradores sin cabecera ni partes diarios no representan una valorización utilizable.
    $where = " WHERE (TRIM(v.equipo) <> '' OR TRIM(v.capacidad) <> '' OR TRIM(v.periodo) <> ''
        OR EXISTS (SELECT 1 FROM cobranzas_valorizacion_registros r WHERE r.valorizacion_id = v.id))";
    if ($search !== '') $where .= ' AND (c.business_name LIKE ? OR c.ruc LIKE ?)';
    $params = $search === '' ? [] : ['%' . $search . '%', '%' . $search . '%'];
    $countStmt = db()->prepare('SELECT COUNT(*) FROM cobranzas_valorizaciones v JOIN clients c ON c.id = v.client_id' . $where);
    $countStmt->execute($params);
    $listTotal = (int) $countStmt->fetchColumn();
    $lastPage = max(1, (int) ceil($listTotal / $perPage));
    $listPage = min($listPage, $lastPage);
    $offset = ($listPage - 1) * $perPage;
    $listStmt = db()->prepare('SELECT v.id AS valorizacion_id, c.business_name, c.ruc, v.equipo, v.capacidad, v.periodo, v.movilizacion_activa,
        COALESCE(u.name, (
            SELECT ru.name FROM cobranzas_valorizacion_registros rr
            JOIN users ru ON ru.id = rr.registered_by_user_id
            WHERE rr.valorizacion_id = v.id ORDER BY rr.id LIMIT 1
        ), \'\') AS registrado_por,
        (SELECT COUNT(*) FROM cobranzas_valorizacion_registros r WHERE r.valorizacion_id = v.id) AS partes_total,
        (SELECT MIN(r.fecha_servicio) FROM cobranzas_valorizacion_registros r WHERE r.valorizacion_id = v.id) AS primera_fecha_servicio,
        (SELECT MAX(r.fecha_servicio) FROM cobranzas_valorizacion_registros r WHERE r.valorizacion_id = v.id) AS ultima_fecha_servicio
        ,(SELECT a.id FROM cobranzas_valorizacion_aprobaciones a WHERE a.valorizacion_id = v.id ORDER BY a.approved_at DESC, a.id DESC LIMIT 1) AS aprobacion_id
        ,(SELECT a.periodo_tipo FROM cobranzas_valorizacion_aprobaciones a WHERE a.valorizacion_id = v.id ORDER BY a.approved_at DESC, a.id DESC LIMIT 1) AS aprobacion_periodo_tipo
        ,(SELECT a.fecha_inicio FROM cobranzas_valorizacion_aprobaciones a WHERE a.valorizacion_id = v.id ORDER BY a.approved_at DESC, a.id DESC LIMIT 1) AS aprobacion_fecha_inicio
        ,(SELECT a.fecha_fin FROM cobranzas_valorizacion_aprobaciones a WHERE a.valorizacion_id = v.id ORDER BY a.approved_at DESC, a.id DESC LIMIT 1) AS aprobacion_fecha_fin
        FROM cobranzas_valorizaciones v
        JOIN clients c ON c.id = v.client_id'
        . ' LEFT JOIN users u ON u.id = v.registered_by_user_id'
        . $where . ' ORDER BY v.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $listStmt->execute($params);
    $listRows = $listStmt->fetchAll();
    $approvalHistoryByValuation = [];
    if ($listRows) {
        $approvalIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['valorizacion_id'], $listRows)));
        $approvalMarks = implode(',', array_fill(0, count($approvalIds), '?'));
        $approvalStmt = db()->prepare('SELECT id, valorizacion_id, periodo_tipo, fecha_inicio, fecha_fin FROM cobranzas_valorizacion_aprobaciones WHERE valorizacion_id IN (' . $approvalMarks . ') ORDER BY fecha_inicio DESC, id DESC');
        $approvalStmt->execute($approvalIds);
        $priceStmt = db()->prepare('SELECT precio_hora FROM cobranzas_valorizacion_precios_valorizacion WHERE valorizacion_id = ? AND equipo_clave = ?');
        $totalsStmt = db()->prepare('SELECT COALESCE(SUM(horas_total_minutos), 0) AS minutos, COALESCE(SUM(movilizacion), 0) AS movilizacion, COALESCE(SUM(desmovilizacion), 0) AS desmovilizacion FROM cobranzas_valorizacion_registros WHERE valorizacion_id = ? AND fecha_servicio BETWEEN ? AND ?');
        $rowsById = [];
        foreach ($listRows as $row) $rowsById[(int) $row['valorizacion_id']] = $row;
        $rateCache = [];
        foreach ($approvalStmt as $approval) {
            $approvalValuationId = (int) $approval['valorizacion_id'];
            $valuationRow = $rowsById[$approvalValuationId] ?? null;
            if (!$valuationRow) continue;
            if (!array_key_exists($approvalValuationId, $rateCache)) {
                $rate = 37.50;
                $rateKey = valorizacion_clave_tarifa((string) $valuationRow['equipo']);
                if ($rateKey !== '') {
                    $priceStmt->execute([$approvalValuationId, $rateKey]);
                    $savedRate = $priceStmt->fetchColumn();
                    if ($savedRate !== false) $rate = (float) $savedRate;
                }
                $rateCache[$approvalValuationId] = $rate;
            }
            $totalsStmt->execute([$approvalValuationId, $approval['fecha_inicio'], $approval['fecha_fin']]);
            $totals = $totalsStmt->fetch() ?: [];
            $priceCents = (int) round($rateCache[$approvalValuationId] * 100 * (int) ($totals['minutos'] ?? 0) / 60);
            $mobilityCents = (bool) ($valuationRow['movilizacion_activa'] ?? false) ? (int) round(((float) ($totals['movilizacion'] ?? 0) + (float) ($totals['desmovilizacion'] ?? 0)) * 100) : 0;
            $subtotalCents = $priceCents + $mobilityCents;
            $approvalHistoryByValuation[$approvalValuationId][] = [
                'valuation_id' => $approvalValuationId,
                'mode' => (string) $approval['periodo_tipo'],
                'from' => (string) $approval['fecha_inicio'],
                'to' => (string) $approval['fecha_fin'],
                'total_cents' => $subtotalCents + (int) round($subtotalCents * 18 / 100),
                'url' => APP_URL . '/servicios/cobranzas/valorizacion_reporte_datos.php?' . http_build_query([
                    'valorizacion_id' => $approvalValuationId,
                    'periodo' => (string) $approval['periodo_tipo'] === 'fechas' ? 'fechas' : 'mes',
                    ((string) $approval['periodo_tipo'] === 'fechas' ? 'desde' : 'mes') => (string) $approval['periodo_tipo'] === 'fechas' ? (string) $approval['fecha_inicio'] : substr((string) $approval['fecha_inicio'], 0, 7),
                    ...((string) $approval['periodo_tipo'] === 'fechas' ? ['hasta' => (string) $approval['fecha_fin']] : []),
                ]),
            ];
        }
    }
} catch (PDOException $exception) {
    error_log('Error al listar clientes de valorización: ' . $exception->getMessage());
    $listError = 'No se pudo cargar el listado. Ejecute las migraciones de valorizaciones múltiples y registrado por en la base de datos.';
}

$listUrl = static function (int $page) use ($search): string {
    $query = ['pagina' => $page];
    if ($search !== '') $query['q'] = $search;
    return APP_URL . '/modulos/cobranzas/valorizacion.php?' . http_build_query($query);
};

require __DIR__ . '/../../includes/header.php';
?>
<style>
.cv-list-panel{min-width:0;padding:18px!important}
.cv-list-toolbar{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:16px}
.cv-list-toolbar>div{flex:0 1 340px;min-width:0}
.cv-list-toolbar .form-control{width:100%;max-width:340px}
.cv-list-table{min-width:900px;width:100%}
.cv-list-table th{background:#f4f7fb;color:#415474;font-size:.75rem;text-transform:uppercase;white-space:nowrap}
.cv-list-table td,.cv-list-table th{padding:.7rem .65rem;vertical-align:middle}
.cv-list-table td:nth-child(2),.cv-list-table td:nth-child(3),.cv-list-table td:nth-child(6){overflow-wrap:anywhere}
.cv-list-table td:last-child{white-space:nowrap}
.cv-list-actions{display:flex;align-items:center;gap:5px;white-space:nowrap}
.cv-list-icon{display:inline-grid;place-items:center;flex:0 0 34px;width:34px;height:34px;padding:0;border-radius:7px}
.cv-list-scroll{display:block;width:100%;max-width:100%;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch;scrollbar-width:thin}
.cv-list-scroll:focus-visible{outline:3px solid #93b4ff;outline-offset:2px}
.cv-list-scroll-hint{display:none;margin:0 0 8px;color:#526986;font-size:.8rem}
@media(max-width:767px){.cv-list-panel{padding:14px!important}.cv-list-scroll-hint{display:block}.cv-list-table{width:935px;table-layout:fixed}.cv-list-table th:nth-child(1){width:55px}.cv-list-table th:nth-child(2){width:190px}.cv-list-table th:nth-child(3){width:120px}.cv-list-table th:nth-child(4){width:100px}.cv-list-table th:nth-child(5){width:150px}.cv-list-table th:nth-child(6){width:140px}.cv-list-table th:nth-child(7){width:180px}.cv-list-table td:last-child{white-space:normal}}
.cv-list-pending{display:inline-block;margin-top:4px;padding:2px 7px;border-radius:6px;background:#fff3d6;color:#865400;font-size:.72rem;font-weight:700}
.cv-list-missing{color:#64748b;font-style:italic}
.cv-list-new{margin-left:auto}
.cv-approval-period-fields[hidden]{display:none!important}
</style>
<div class="page-title"><div><h1>Valorización</h1><p>Clientes y datos de valorización.</p></div></div>
<section class="work-panel cv-list-panel">
    <form class="cv-list-toolbar" method="get" action="<?= e(APP_URL . '/modulos/cobranzas/valorizacion.php') ?>">
        <div><label class="form-label small fw-bold" for="cvListSearch">Buscar cliente</label><input class="form-control" id="cvListSearch" name="q" type="search" value="<?= e($search) ?>" placeholder="Nombre o RUC" maxlength="100"></div>
        <button class="btn btn-primary" type="submit">Buscar</button>
        <?php if ($search !== ''): ?><a class="btn btn-outline-secondary" href="<?= e(APP_URL . '/modulos/cobranzas/valorizacion.php') ?>">Limpiar</a><?php endif; ?>
        <a class="btn btn-success cv-list-new" href="<?= e(APP_URL . '/modulos/cobranzas/valorizacion.php?nuevo=1') ?>"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Nuevo</a>
    </form>
    <?php if ($listError !== ''): ?><div class="alert alert-danger" role="alert"><?= e($listError) ?></div><?php endif; ?>
    <p class="cv-list-scroll-hint"><i class="fa-solid fa-arrows-left-right me-1" aria-hidden="true"></i>Desliza la tabla hacia los lados para ver todas las columnas.</p>
    <div class="cv-list-scroll border rounded" role="region" aria-label="Listado de valorizaciones, desplazable horizontalmente" tabindex="0">
        <table class="table table-hover align-middle mb-0 cv-list-table">
            <thead><tr><th scope="col">Ítem</th><th scope="col">Cliente</th><th scope="col">Equipo</th><th scope="col">Capacidad</th><th scope="col">Periodo</th><th scope="col">Registrado por</th><th scope="col">Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($listRows as $index => $row): ?>
                <?php
                $equipment = trim((string) ($row['equipo'] ?? ''));
                $capacity = trim((string) ($row['capacidad'] ?? ''));
                $rawPeriod = trim((string) ($row['periodo'] ?? ''));
                $shownPeriod = '';
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\|(\d{4})-(\d{2})-(\d{2})$/D', $rawPeriod, $dates)) {
                    $shownPeriod = $dates[3] . '/' . $dates[2] . '/' . $dates[1] . ' al ' . $dates[6] . '/' . $dates[5] . '/' . $dates[4];
                } elseif ($rawPeriod !== '') {
                    $shownPeriod = $rawPeriod;
                }
                $hasNoHeader = $equipment === '' && $capacity === '' && $rawPeriod === '';
                $hasMissingData = $equipment === '' || $capacity === '' || $rawPeriod === '';
                $approvalFrom = (string) ($row['primera_fecha_servicio'] ?? '');
                $approvalTo = (string) ($row['ultima_fecha_servicio'] ?? '');
                $approvalMonth = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $approvalTo) ? substr($approvalTo, 0, 7) : date('Y-m');
                $isApproved = (int) ($row['aprobacion_id'] ?? 0) > 0;
                $approvedMode = (string) ($row['aprobacion_periodo_tipo'] ?? 'mes');
                $approvedFrom = (string) ($row['aprobacion_fecha_inicio'] ?? '');
                $approvedTo = (string) ($row['aprobacion_fecha_fin'] ?? '');
                $approvedMonth = $approvedMode === 'mes' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $approvedFrom) ? substr($approvedFrom, 0, 7) : '';
                ?>
                <tr>
                    <td><?= ($listPage - 1) * $perPage + $index + 1 ?></td>
                    <td><strong><?= e((string) $row['business_name']) ?></strong><br><small class="text-muted"><?= e((string) $row['ruc']) ?></small><?php if ($hasMissingData): ?><br><span class="cv-list-pending"><?= $hasNoHeader ? 'Sin datos de valorización' : 'Datos incompletos' ?></span><?php endif; ?></td>
                    <td><?= $equipment !== '' ? e($equipment) : '<span class="cv-list-missing">Pendiente</span>' ?></td>
                    <td><?= $capacity !== '' ? e($capacity) : '<span class="cv-list-missing">Pendiente</span>' ?></td>
                    <td><?= $shownPeriod !== '' ? e($shownPeriod) : '<span class="cv-list-missing">Pendiente</span>' ?></td>
                    <td><?= $row['registrado_por'] !== '' ? e((string) $row['registrado_por']) : '—' ?></td>
                    <td><div class="cv-list-actions">
                        <a class="btn btn-sm btn-outline-primary cv-list-icon" href="<?= e(APP_URL . '/modulos/cobranzas/valorizacion.php?valorizacion_id=' . (int) $row['valorizacion_id']) ?>" title="Ver valorización" aria-label="Ver valorización de <?= e((string) $row['business_name']) ?>"><i class="fa-solid fa-eye" aria-hidden="true"></i></a>
<?php if ($isApproved): ?><button class="btn btn-sm btn-outline-success cv-list-icon" type="button" data-bs-toggle="modal" data-bs-target="#cvApprovalPdfModal" data-client="<?= e((string) $row['business_name']) ?>" data-approvals="<?= e(json_encode($approvalHistoryByValuation[(int) $row['valorizacion_id']] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" title="Aprobaciones" aria-label="Ver registros de aprobación"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></button><?php endif; ?>
                        <?php if (!is_personal_role()): ?><button class="btn btn-sm btn-primary cv-list-icon cv-export-pdf" type="button" data-url="<?= e(APP_URL . '/servicios/cobranzas/valorizacion_reporte_datos.php?valorizacion_id=' . (int) $row['valorizacion_id']) ?>" title="PDF Interno" aria-label="Descargar PDF Interno de <?= e((string) $row['business_name']) ?>"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i></button>
                        <button class="btn btn-sm btn-danger cv-list-icon cv-export-pdf" type="button" data-mode="cobranzas" data-url="<?= e(APP_URL . '/servicios/cobranzas/valorizacion_reporte_datos.php?valorizacion_id=' . (int) $row['valorizacion_id']) ?>" title="PDF Cobranzas" aria-label="Descargar PDF Cobranzas de <?= e((string) $row['business_name']) ?>"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i></button>
                        <?php endif; ?>
                        <?php if (!is_personal_role()): ?><button class="btn btn-sm btn-outline-danger cv-list-icon cv-delete-valuation" type="button" data-bs-toggle="modal" data-bs-target="#cvDeleteValuationModal" data-valuation-id="<?= (int) $row['valorizacion_id'] ?>" data-client="<?= e((string) $row['business_name']) ?>" data-equipment="<?= e($equipment) ?>" data-period="<?= e($shownPeriod) ?>" data-parts="<?= (int) $row['partes_total'] ?>" title="Eliminar valorización" aria-label="Eliminar valorización de <?= e((string) $row['business_name']) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></button><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$listRows && $listError === ''): ?><tr><td colspan="7" class="text-center text-muted py-4">No se encontraron valorizaciones.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($listError === ''): ?>
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mt-3">
            <small class="text-muted">Mostrando <?= $listTotal ? (($listPage - 1) * $perPage + 1) : 0 ?> a <?= min($listPage * $perPage, $listTotal) ?> de <?= $listTotal ?> valorizaciones</small>
            <div class="d-flex gap-2">
                <?php if ($listPage > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e($listUrl($listPage - 1)) ?>">Anterior</a><?php endif; ?>
                <?php if ($listPage * $perPage < $listTotal): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e($listUrl($listPage + 1)) ?>">Siguiente</a><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section><div class="modal fade" id="cvApprovalPdfModal" tabindex="-1" aria-labelledby="cvApprovalPdfTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="cvApprovalPdfTitle"><i class="fa-solid fa-circle-check text-success me-2" aria-hidden="true"></i>Registros de aprobaciones</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><p class="text-muted small mb-3" id="cvApprovedClient"></p><div class="table-responsive border rounded"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Periodo</th><th>Detalle</th><th class="text-end">Monto total</th><th class="text-center">Acción</th></tr></thead><tbody id="cvApprovalRows"></tbody></table></div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button><button class="visually-hidden cv-export-pdf" type="button" id="cvApprovedDownload" tabindex="-1" aria-hidden="true"></button></div></div></div></div><div class="modal fade" id="cvUnapproveConfirmModal" tabindex="-1" aria-labelledby="cvUnapproveConfirmTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="cvUnapproveConfirmForm" method="post" action="<?= e(APP_URL . '/modulos/cobranzas/valorizacion.php') ?>"><div class="modal-header"><h2 class="modal-title h5" id="cvUnapproveConfirmTitle">Desaprobar período</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><p class="mb-2">¿Desea quitar la aprobación de este período?</p><dl class="row mb-0 small"><dt class="col-sm-4">Periodo</dt><dd class="col-sm-8 mb-1" id="cvUnapproveMode"></dd><dt class="col-sm-4" id="cvUnapproveDetailLabel">Mes</dt><dd class="col-sm-8 mb-0" id="cvUnapproveDetail"></dd></dl></div><div class="modal-footer"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="unapprove_period"><input type="hidden" name="valorizacion_id" id="cvUnapproveValuationId"><input type="hidden" name="periodo" id="cvUnapprovePeriod"><input type="hidden" name="fecha_inicio" id="cvUnapproveFrom"><input type="hidden" name="fecha_fin" id="cvUnapproveTo"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger"><i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i>Desaprobar</button></div></form></div></div><div class="modal fade" id="cvDeleteValuationModal" tabindex="-1" aria-labelledby="cvDeleteValuationTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="cvDeleteValuationTitle">Eliminar valorización</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><p>¿Está seguro de eliminar esta valorización?</p><dl class="row mb-2"><dt class="col-4">Cliente</dt><dd class="col-8" id="cvDeleteClient"></dd><dt class="col-4">Equipo</dt><dd class="col-8" id="cvDeleteEquipment"></dd><dt class="col-4">Periodo</dt><dd class="col-8" id="cvDeletePeriod"></dd></dl><p class="alert alert-warning mb-0" id="cvDeleteWarning"></p></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><form method="post" action="<?= e(APP_URL . '/modulos/cobranzas/valorizacion.php') ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_valuation"><input type="hidden" name="valorizacion_id" id="cvDeleteId"><button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Eliminar</button></form></div></div></div></div>
<script>
const cvApprovalPdfModal=document.getElementById('cvApprovalPdfModal'),cvApprovedClient=document.getElementById('cvApprovedClient'),cvApprovalRows=document.getElementById('cvApprovalRows'),cvApprovedDownload=document.getElementById('cvApprovedDownload'),cvUnapproveConfirmModal=document.getElementById('cvUnapproveConfirmModal'),cvUnapproveConfirmForm=document.getElementById('cvUnapproveConfirmForm');
const cvApprovedDateText=value=>/^\d{4}-\d{2}-\d{2}$/.test(value)?`${value.slice(8,10)}/${value.slice(5,7)}/${value.slice(0,4)}`:value;
const cvApprovedMonthText=value=>{const match=/^(\d{4})-(\d{2})$/.exec(value||'');if(!match)return value||'';return new Intl.DateTimeFormat('es-PE',{month:'long',year:'numeric',timeZone:'UTC'}).format(new Date(Date.UTC(Number(match[1]),Number(match[2])-1,1))).replace(/^./,letter=>letter.toUpperCase());};
const cvApprovalMoney=cents=>`S/ ${new Intl.NumberFormat('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(cents||0)/100)}`;
let cvPendingUnapproval=null,cvUnapprovalSubmitting=false;
const cvStartUnapproval=approval=>{cvPendingUnapproval=approval;cvUnapprovalSubmitting=false;const isRange=approval.mode==='fechas';document.getElementById('cvUnapproveMode').textContent=isRange?'Entre fechas':'Por mes';document.getElementById('cvUnapproveDetailLabel').textContent=isRange?'Fechas':'Mes';document.getElementById('cvUnapproveDetail').textContent=isRange?`${cvApprovedDateText(approval.from)} al ${cvApprovedDateText(approval.to)}`:cvApprovedMonthText(String(approval.from||'').slice(0,7));document.getElementById('cvUnapproveValuationId').value=approval.valuation_id;document.getElementById('cvUnapprovePeriod').value=approval.mode;document.getElementById('cvUnapproveFrom').value=approval.from;document.getElementById('cvUnapproveTo').value=approval.to;bootstrap.Modal.getOrCreateInstance(cvApprovalPdfModal).hide()};
cvApprovalPdfModal.addEventListener('hidden.bs.modal',()=>{if(cvPendingUnapproval)bootstrap.Modal.getOrCreateInstance(cvUnapproveConfirmModal).show()});
cvUnapproveConfirmModal.addEventListener('hidden.bs.modal',()=>{if(cvPendingUnapproval&&!cvUnapprovalSubmitting){cvPendingUnapproval=null;bootstrap.Modal.getOrCreateInstance(cvApprovalPdfModal).show()}});
cvUnapproveConfirmForm.addEventListener('submit',()=>{cvUnapprovalSubmitting=true;cvPendingUnapproval=null});
cvApprovalPdfModal.addEventListener('show.bs.modal',event=>{const button=event.relatedTarget;if(!button)return;let approvals=[];try{approvals=JSON.parse(button.dataset.approvals||'[]')}catch(error){approvals=[]}cvApprovedClient.textContent=button.dataset.client||'';cvApprovalRows.replaceChildren();if(!approvals.length){const row=document.createElement('tr'),cell=document.createElement('td');cell.colSpan=4;cell.className='text-center text-muted py-3';cell.textContent='No hay aprobaciones registradas.';row.append(cell);cvApprovalRows.append(row);return}approvals.forEach(approval=>{const row=document.createElement('tr'),period=document.createElement('td'),detail=document.createElement('td'),total=document.createElement('td'),action=document.createElement('td'),download=document.createElement('button'),unapproveAction=document.createElement('span'),unapprove=document.createElement('button');period.textContent=approval.mode==='fechas'?'Entre fechas':'Por mes';detail.textContent=approval.mode==='fechas'?`${cvApprovedDateText(approval.from)} al ${cvApprovedDateText(approval.to)}`:cvApprovedMonthText(String(approval.from||'').slice(0,7));total.className='text-end fw-semibold';total.textContent=cvApprovalMoney(approval.total_cents);action.className='text-center';download.className='btn btn-sm btn-success';download.type='button';download.title='Descargar PDF de este período';download.innerHTML='<i class="fa-solid fa-file-pdf" aria-hidden="true"></i>';download.addEventListener('click',()=>{cvApprovedDownload.dataset.url=approval.url;cvApprovedDownload.dataset.mode='cobranzas';cvApprovedDownload.click()});action.append(download);<?php if (!is_personal_role()): ?>unapprove.className='btn btn-sm btn-outline-danger';unapprove.type='button';unapprove.title='Desaprobar este período';unapprove.innerHTML='<i class="fa-solid fa-circle-xmark" aria-hidden="true"></i>';unapprove.addEventListener('click',()=>cvStartUnapproval(approval));unapproveAction.className='ms-1';unapproveAction.append(unapprove);action.append(unapproveAction);<?php endif; ?>row.append(period,detail,total,action);cvApprovalRows.append(row)})});document.getElementById('cvDeleteValuationModal').addEventListener('show.bs.modal', event => {
    const button = event.relatedTarget;
    if (!button) return;
    document.getElementById('cvDeleteId').value = button.dataset.valuationId;
    document.getElementById('cvDeleteClient').textContent = button.dataset.client || '—';
    document.getElementById('cvDeleteEquipment').textContent = button.dataset.equipment || '—';
    document.getElementById('cvDeletePeriod').textContent = button.dataset.period || '—';
    const parts = Number(button.dataset.parts || 0);
    document.getElementById('cvDeleteWarning').textContent = parts > 0
        ? `También se eliminarán ${parts} ${parts === 1 ? 'parte diario' : 'partes diarios'}, sus archivos adjuntos y los precios de esta valorización. Esta acción no se puede deshacer.`
        : 'También se eliminarán los precios de esta valorización. Esta acción no se puede deshacer.';
});
</script>
<script type="module" src="<?= e(APP_URL . '/recursos/js/cobranzas_valorizacion_reporte.js?v=36') ?>"></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
