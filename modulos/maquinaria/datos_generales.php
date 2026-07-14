<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('maquinaria.datos_generales');

$stmt = db()->query('SELECT m.*, c.name AS empresa
    FROM maquinarias m
    LEFT JOIN companies c ON c.id = m.company_id
    ORDER BY m.equipo, m.serie_placa');
$maquinarias = $stmt->fetchAll();
$companies = db()->query('SELECT * FROM companies WHERE status = 1 ORDER BY name')->fetchAll();
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Datos generales</h1>
        <p>Registro general de equipos y maquinaria.</p>
    </div>
    <button class="btn btn-primary" type="button" id="nuevoMaquinariaBtn"><i class="fa-solid fa-plus me-2"></i>Nuevo</button>
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
                        <button class="btn btn-sm btn-outline-primary js-editar-maquinaria" type="button"
                            data-id="<?= (int) $item['id'] ?>"
                            data-company-id="<?= (int) ($item['company_id'] ?? 0) ?>"
                            data-equipo="<?= e($item['equipo']) ?>"
                            data-serie="<?= e($item['serie_placa']) ?>"
                            data-anio="<?= e((string) $item['anio_equipo']) ?>"
                            data-foto="<?= e($item['foto_path'] ?? '') ?>"
                            title="Editar"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-sm btn-outline-danger js-eliminar-maquinaria" type="button" data-id="<?= (int) $item['id'] ?>" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="maquinariaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content needs-validation" id="maquinariaForm" novalidate enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title" id="maquinariaModalTitle">Nueva maquinaria</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="maquinariaId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Equipo</label>
                        <input class="form-control" name="equipo" id="maquinariaEquipo" required maxlength="150">
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                            <label class="form-label mb-0" for="maquinariaEmpresa">Empresa</label>
                            <div class="d-flex gap-1">
                                <button class="btn btn-sm btn-outline-primary" type="button" id="openMachineCompanyModalBtn" title="Agregar empresa">
                                    <i class="fa-solid fa-plus"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" type="button" id="deleteMachineCompanyBtn" title="Eliminar empresa seleccionada">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <select class="form-select select2-tags" name="company_id" id="maquinariaEmpresa" required data-placeholder="Seleccione o agregue">
                            <option></option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= (int) $company['id'] ?>"><?= e($company['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Seleccione una empresa.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Serie o Placa</label>
                        <input class="form-control" name="serie_placa" id="maquinariaSerie" required maxlength="80">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">A&ntilde;o del equipo</label>
                        <input class="form-control" type="number" name="anio_equipo" id="maquinariaAnio" min="1950" max="2100" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Foto</label>
                        <input class="form-control" type="file" name="foto" id="maquinariaFoto" accept="image/png,image/jpeg,image/webp">
                        <div class="file-current mt-2 d-none" id="maquinariaFotoActual"></div>
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

<div class="modal fade" id="machineCompanyQuickModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content needs-validation" id="machineCompanyQuickForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title">Nueva empresa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label class="form-label" for="machineCompanyQuickName">Nombre de empresa</label>
                <input class="form-control" id="machineCompanyQuickName" name="name" maxlength="160" required autocomplete="off">
                <div class="invalid-feedback">Ingrese el nombre de la empresa.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
