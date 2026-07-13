<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

$sql = "SELECT w.*, c.name AS company,
        GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') AS positions
        FROM workers w
        LEFT JOIN companies c ON c.id = w.company_id
        LEFT JOIN worker_positions wp ON wp.worker_id = w.id
        LEFT JOIN positions p ON p.id = wp.position_id
        GROUP BY w.id
        ORDER BY w.full_name";
$workers = db()->query($sql)->fetchAll();

function worker_progress(array $w): int
{
    $fields = ['company_id','full_name','document_type','document_number','blood_type','address','phone','email','birth_date','photo_path','signature_path','positions'];
    $done = 0;
    foreach ($fields as $field) {
        if (!empty($w[$field])) {
            $done++;
        }
    }
    return (int) round(($done / count($fields)) * 100);
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Personal</h1>
        <p>Gestión de trabajadores aliados.</p>
    </div>
    <a class="btn btn-primary" href="<?= APP_URL ?>/modulos/aliados/formulario_personal.php"><i class="fa-solid fa-plus me-2"></i>Nuevo</a>
</div>
<div class="work-panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle data-table" id="personalTable">
            <thead>
            <tr>
                <th>Empresa</th>
                <th>Documento</th>
                <th>Nro. Documento</th>
                <th>Apellidos y Nombres</th>
                <th>Correo</th>
                <th>Progreso</th>
                <th>Estado</th>
                <th>Acción</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($workers as $worker): $progress = worker_progress($worker); ?>
                <tr>
                    <td><?= e($worker['company'] ?? '') ?></td>
                    <td><?= e($worker['document_type']) ?></td>
                    <td><?= e($worker['document_number']) ?></td>
                    <td><?= e($worker['full_name']) ?></td>
                    <td><?= e($worker['email'] ?? '') ?></td>
                    <td>
                        <?php if ($progress === 100): ?>
                            <span class="badge text-bg-success">VALIDADO</span>
                        <?php else: ?>
                            <div class="progress progress-thin"><div class="progress-bar" style="width: <?= $progress ?>%"></div></div>
                            <span class="small"><?= $progress ?>%</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($progress === 100): ?>
                            <span class="badge text-bg-success">Activo</span>
                        <?php else: ?>
                            <span class="badge text-bg-danger">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/modulos/aliados/formulario_personal.php?id=<?= (int) $worker['id'] ?>" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                            <button class="btn btn-sm btn-outline-danger js-eliminar-personal" type="button" data-id="<?= (int) $worker['id'] ?>" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

