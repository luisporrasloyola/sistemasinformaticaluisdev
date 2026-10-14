<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.informacion');

$page = max(1, (int) ($_GET['pagina'] ?? 1));
$perPage = 25;
$total = (int) db()->query('SELECT COUNT(*) FROM clients')->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$stmt = db()->prepare('SELECT id, business_name, ruc FROM clients ORDER BY business_name, id LIMIT :limit OFFSET :offset');
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$clients = $stmt->fetchAll();
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div><h1>Información de clientes</h1><p>Registro de clientes y sus contactos.</p></div>
    <a class="btn btn-primary" href="<?= APP_URL ?>/modulos/clientes/formulario.php"><i class="fa-solid fa-plus me-2"></i>Nuevo</a>
</div>
<?php if (isset($_GET['error'])): ?><div class="alert alert-danger"><?= e((string) $_GET['error']) ?></div><?php endif; ?>
<div class="work-panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Razón social</th><th>RUC</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($clients as $client): ?>
                <tr>
                    <td><?= e($client['business_name']) ?></td>
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
            <?php if (!$clients): ?><tr><td colspan="3" class="text-center text-muted py-4">Todavía no hay clientes registrados.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <nav class="d-flex justify-content-end align-items-center gap-2 mt-3" aria-label="Páginas de clientes">
        <span class="small text-muted">Página <?= $page ?> de <?= $pages ?> · <?= $total ?> clientes</span>
        <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?pagina=<?= $page - 1 ?>">Anterior</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn btn-sm btn-outline-secondary" href="?pagina=<?= $page + 1 ?>">Siguiente</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
