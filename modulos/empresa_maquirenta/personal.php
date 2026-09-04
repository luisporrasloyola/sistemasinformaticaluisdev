<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa_maquirenta.personal');

$personalView = is_personal_role();
$sql = "SELECT w.*, c.name AS company,
        GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') AS positions
        FROM empresa_maquirenta_formato_personal w
        LEFT JOIN empresa_maquirenta_formato_empresas c ON c.id = w.company_id
        LEFT JOIN empresa_maquirenta_formato_personal_puestos wp ON wp.worker_id = w.id
        LEFT JOIN empresa_maquirenta_formato_puestos p ON p.id = wp.position_id
        LEFT JOIN workers source_worker ON source_worker.document_number = w.document_number
        WHERE (:personal_view = 0 OR source_worker.id = :worker_id)
        GROUP BY w.id
        ORDER BY w.full_name";
$stmt = db()->prepare($sql);
$stmt->execute(['personal_view' => $personalView ? 1 : 0, 'worker_id' => current_user_worker_id() ?? 0]);
$workers = $stmt->fetchAll();

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
    <a class="btn btn-primary <?= $personalView ? 'd-none' : '' ?>" href="<?= APP_URL ?>/modulos/empresa_maquirenta/formulario_personal.php"><i class="fa-solid fa-plus me-2"></i>Nuevo</a>
</div>
<div class="work-panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle data-table" id="personalTable">
            <thead>
            <tr>
                <th>Empresa</th>
                <th>Personal</th>
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
                    <td class="personal-identity">
                        <strong><?= e($worker['full_name']) ?></strong>
                        <small><?= e($worker['document_type']) ?>: <?= e($worker['document_number']) ?></small>
                    </td>
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
                        <?php if ($personalView): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/modulos/empresa_maquirenta/pmi_individual.php" title="Ver mis requisitos"><i class="fa-solid fa-eye"></i></a>
                        <?php else: ?>
                        <div class="d-flex gap-1">
                            <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/modulos/empresa_maquirenta/formulario_personal.php?id=<?= (int) $worker['id'] ?>" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                            <button class="btn btn-sm btn-outline-danger js-eliminar-personal" type="button" data-id="<?= (int) $worker['id'] ?>" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<style>.personal-identity strong{display:block;color:#172033;font-weight:650;line-height:1.25}.personal-identity small{display:block;margin-top:.15rem;color:#7a8799;font-size:.72rem;line-height:1.2}#personalTable th{white-space:nowrap}</style>
<script>window.personalServiceBase = <?= json_encode(APP_URL . '/servicios/empresa_maquirenta/formatos') ?>;</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

