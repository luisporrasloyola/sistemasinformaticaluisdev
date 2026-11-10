<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.informacion');

$page = max(1, (int) ($_GET['pagina'] ?? 1));
$perPage = 25;
$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$search = mb_substr($search, 0, 100, 'UTF-8');
$where = '';
$params = [];
if ($search !== '') {
    $term = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $where = " WHERE (c.business_name LIKE :business ESCAPE '!' OR c.ruc LIKE :ruc ESCAPE '!'
        OR EXISTS (SELECT 1 FROM client_project_assignments a
                   JOIN client_project_catalog p ON p.id = a.project_id
                   WHERE a.client_id = c.id AND p.name LIKE :project ESCAPE '!'))";
    $params = [':business' => $term, ':ruc' => $term, ':project' => $term];
}
$countStmt = db()->prepare('SELECT COUNT(*) FROM clients c' . $where);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$stmt = db()->prepare('SELECT c.id, c.business_name, c.ruc FROM clients c' . $where . ' ORDER BY c.business_name, c.id LIMIT :limit OFFSET :offset');
foreach ($params as $name => $value) $stmt->bindValue($name, $value, PDO::PARAM_STR);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$clients = $stmt->fetchAll();
$clientProjects = [];
if ($clients) {
    $ids = array_map('intval', array_column($clients, 'id'));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $projectsStmt = db()->prepare("SELECT a.client_id, p.name FROM client_project_assignments a JOIN client_project_catalog p ON p.id = a.project_id WHERE a.client_id IN ($placeholders) ORDER BY p.name, p.id");
    $projectsStmt->execute($ids);
    foreach ($projectsStmt as $project) { $clientProjects[(int) $project['client_id']][] = $project['name']; }
}
require __DIR__ . '/../../includes/header.php';
?>
<style>.client-list-panel.work-panel{padding:14px 20px!important}@media(max-width:575px){.client-list-panel.work-panel{padding:12px!important}}</style>
<div class="page-title">
    <div><h1>Información de clientes</h1><p>Registro de clientes y sus contactos.</p></div>
    <a class="btn btn-primary" href="<?= e(APP_URL . '/modulos/clientes/formulario.php') ?>"><i class="fa-solid fa-plus me-2"></i>Nuevo</a>
</div>
<?php if (isset($_GET['error'])): ?><div class="alert alert-danger"><?= e((string) $_GET['error']) ?></div><?php endif; ?>
<div class="work-panel client-list-panel">
    <form class="d-flex justify-content-end align-items-center gap-2 flex-wrap mb-2" method="get" action="<?= e(APP_URL . '/modulos/clientes/informacion.php') ?>" role="search">
        <label class="visually-hidden" for="clientSearch">Buscar cliente</label>
        <input class="form-control form-control-sm" id="clientSearch" type="search" name="q" value="<?= e($search) ?>" placeholder="Razón social, proyecto o RUC" maxlength="100" aria-label="Buscar por razón social, proyecto o RUC" style="width:min(320px, 100%)">
        <button class="btn btn-success btn-sm" type="submit">Buscar</button>
        <?php if ($search !== ''): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(APP_URL . '/modulos/clientes/informacion.php') ?>" aria-label="Limpiar búsqueda" title="Limpiar búsqueda"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
    </form>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Razón social</th><th>Proyectos</th><th>RUC</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($clients as $client): ?>
                <tr>
                    <td><?= e($client['business_name']) ?></td>
                    <td><?= e(implode(', ', $clientProjects[(int) $client['id']] ?? [])) ?: '—' ?></td>
                    <td><?= e($client['ruc']) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/modulos/clientes/formulario.php?id=<?= (int) $client['id'] ?>" title="Editar <?= e($client['business_name']) ?>" aria-label="Editar <?= e($client['business_name']) ?>"><i class="fa-solid fa-pen-to-square"></i></a>
                        <form action="<?= APP_URL ?>/modulos/clientes/eliminar.php" method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este cliente y sus contactos?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $client['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Eliminar <?= e($client['business_name']) ?>" aria-label="Eliminar <?= e($client['business_name']) ?>"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$clients): ?><tr><td colspan="4" class="text-center text-muted py-4"><?= $search !== '' ? 'No se encontraron clientes para esta búsqueda.' : 'Todavía no hay clientes registrados.' ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <nav class="d-flex justify-content-end align-items-center gap-2 mt-3" aria-label="Páginas de clientes">
        <span class="small text-muted">Página <?= $page ?> de <?= $pages ?> · <?= $total ?> clientes</span>
        <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(['q' => $search, 'pagina' => $page - 1])) ?>">Anterior</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(['q' => $search, 'pagina' => $page + 1])) ?>">Siguiente</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
