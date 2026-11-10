<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('maquinaria.datos_generales');

$stmt = db()->query('SELECT m.*, c.name AS empresa
    FROM maquinarias m
    LEFT JOIN companies c ON c.id = m.company_id
    ORDER BY m.equipo, m.serie_placa');
$maquinarias = $stmt->fetchAll();
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Datos generales</h1>
        <p>Registro general de equipos y maquinaria.</p>
    </div>
    <a class="btn btn-primary" href="<?= APP_URL ?>/modulos/maquinaria/formulario.php"><i class="fa-solid fa-plus me-2"></i>Nuevo</a>
</div>

<div class="work-panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle data-table" id="maquinariaTable">
            <thead>
            <tr>
                <th>Equipo</th>
                <th>Empresa</th>
                <th>Serie o Placa</th>
                <th>A&ntilde;o del equipo</th>
                <th>Foto</th>
                <th>Acci&oacute;n</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($maquinarias as $item): ?>
                <tr>
                    <td><?= e($item['equipo']) ?></td>
                    <td><?= e($item['empresa'] ?? '') ?></td>
                    <td><?= e($item['serie_placa']) ?></td>
                    <td><?= e((string) $item['anio_equipo']) ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-secondary js-ver-foto-maquinaria" type="button" data-foto="<?= e($item['foto_path'] ?? '') ?>" <?= empty($item['foto_path']) ? 'disabled' : '' ?> title="Ver foto">
                            <i class="fa-solid fa-image"></i>
                        </button>
                    </td>
                    <td class="text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/modulos/maquinaria/formulario.php?id=<?= (int) $item['id'] ?>" title="Editar"><i class="fa-solid fa-pen"></i></a>
                        <button class="btn btn-sm btn-outline-danger js-eliminar-maquinaria" type="button" data-id="<?= (int) $item['id'] ?>" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="maquinariaFotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Foto</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body text-center">
                <img class="maquinaria-foto-modal" id="maquinariaFotoModalImg" src="" alt="Foto maquinaria">
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
