<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload.php';
require_module_access('cobranzas.valorizacion');
$search = trim((string) ($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 100, 'UTF-8');
$listPage = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$perPage = 25;
$listRows = [];
$listTotal = 0;
$listError = '';
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
    $listStmt = db()->prepare('SELECT v.id AS valorizacion_id, c.business_name, c.ruc, v.equipo, v.capacidad, v.periodo,
        COALESCE(u.name, (
            SELECT ru.name FROM cobranzas_valorizacion_registros rr
            JOIN users ru ON ru.id = rr.registered_by_user_id
            WHERE rr.valorizacion_id = v.id ORDER BY rr.id LIMIT 1
        ), \'\') AS registrado_por,
        (SELECT COUNT(*) FROM cobranzas_valorizacion_registros r WHERE r.valorizacion_id = v.id) AS partes_total
        FROM cobranzas_valorizaciones v
        JOIN clients c ON c.id = v.client_id'
        . ' LEFT JOIN users u ON u.id = v.registered_by_user_id'
        . $where . ' ORDER BY v.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $listStmt->execute($params);
    $listRows = $listStmt->fetchAll();
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
                        <?php if (!is_personal_role()): ?>
                        <button class="btn btn-sm btn-primary cv-list-icon cv-export-pdf" type="button" data-url="<?= e(APP_URL . '/servicios/cobranzas/valorizacion_reporte_datos.php?valorizacion_id=' . (int) $row['valorizacion_id']) ?>" title="PDF Interno" aria-label="Descargar PDF Interno de <?= e((string) $row['business_name']) ?>"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i></button>
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
</section>
<div class="modal fade" id="cvDeleteValuationModal" tabindex="-1" aria-labelledby="cvDeleteValuationTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="cvDeleteValuationTitle">Eliminar valorización</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><p>¿Está seguro de eliminar esta valorización?</p><dl class="row mb-2"><dt class="col-4">Cliente</dt><dd class="col-8" id="cvDeleteClient"></dd><dt class="col-4">Equipo</dt><dd class="col-8" id="cvDeleteEquipment"></dd><dt class="col-4">Periodo</dt><dd class="col-8" id="cvDeletePeriod"></dd></dl><p class="alert alert-warning mb-0" id="cvDeleteWarning"></p></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><form method="post" action="<?= e(APP_URL . '/modulos/cobranzas/valorizacion.php') ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_valuation"><input type="hidden" name="valorizacion_id" id="cvDeleteId"><button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Eliminar</button></form></div></div></div></div>
<script>
document.getElementById('cvDeleteValuationModal').addEventListener('show.bs.modal', event => {
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
<script type="module" src="<?= e(APP_URL . '/recursos/js/cobranzas_valorizacion_reporte.js?v=33') ?>"></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
