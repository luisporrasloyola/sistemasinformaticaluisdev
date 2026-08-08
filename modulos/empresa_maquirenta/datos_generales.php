<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa_maquirenta.datos_generales');

$empresas = db()->query('SELECT * FROM empresas_maquirenta WHERE status = 1 ORDER BY razon_social')->fetchAll();
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Empresa Maquirenta</h1>
        <p>Datos generales de las empresas Maquirenta.</p>
    </div>
    <button class="btn btn-primary" type="button" id="nuevaEmpresaMaquirentaBtn"><i class="fa-solid fa-plus me-2"></i>Nuevo</button>
</div>

<div class="work-panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle data-table" id="empresaMaquirentaTable">
            <thead>
            <tr>
                <th>Razón social</th>
                <th>RUC</th>
                <th>Dirección</th>
                <th>Foto</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($empresas as $empresa): ?>
                <tr>
                    <td><?= e($empresa['razon_social']) ?></td>
                    <td><?= e($empresa['ruc']) ?></td>
                    <td><?= e($empresa['direccion'] ?? '') ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-secondary js-ver-foto-empresa-maquirenta" type="button" data-foto="<?= e($empresa['foto_path'] ?? '') ?>" <?= empty($empresa['foto_path']) ? 'disabled' : '' ?> title="Ver foto">
                            <i class="fa-solid fa-image"></i>
                        </button>
                    </td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-primary js-editar-empresa-maquirenta" type="button"
                            data-id="<?= (int) $empresa['id'] ?>"
                            data-razon-social="<?= e($empresa['razon_social']) ?>"
                            data-ruc="<?= e($empresa['ruc']) ?>"
                            data-direccion="<?= e($empresa['direccion'] ?? '') ?>"
                            data-foto="<?= e($empresa['foto_path'] ?? '') ?>"
                            title="Editar"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-sm btn-outline-danger js-eliminar-empresa-maquirenta" type="button" data-id="<?= (int) $empresa['id'] ?>" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="empresaMaquirentaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content needs-validation" id="empresaMaquirentaForm" novalidate enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title" id="empresaMaquirentaModalTitle">Nueva empresa Maquirenta</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="empresaMaquirentaId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Razón social</label>
                        <input class="form-control" name="razon_social" id="empresaMaquirentaRazonSocial" maxlength="180" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">RUC</label>
                        <input class="form-control" name="ruc" id="empresaMaquirentaRuc" maxlength="20" required>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Dirección</label>
                        <input class="form-control" name="direccion" id="empresaMaquirentaDireccion" maxlength="255">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Foto</label>
                        <input class="form-control" type="file" name="foto" id="empresaMaquirentaFoto" accept="image/png,image/jpeg,image/webp">
                        <div class="file-current mt-2 d-none" id="empresaMaquirentaFotoActual"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="empresaMaquirentaFotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Foto</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body text-center">
                <img class="maquinaria-foto-modal" id="empresaMaquirentaFotoModalImg" src="" alt="Foto empresa Maquirenta">
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
