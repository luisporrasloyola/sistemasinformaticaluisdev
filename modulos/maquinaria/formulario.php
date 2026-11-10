<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('maquinaria.datos_generales');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$machine = null;
$contacts = [];
if ($id > 0) {
    $stmt = db()->prepare('SELECT id, company_id, equipo, serie_placa, anio_equipo, foto_path FROM maquinarias WHERE id = ?');
    $stmt->execute([$id]);
    $machine = $stmt->fetch();
    if (!$machine) { http_response_code(404); exit('No se encontró la maquinaria.'); }
    $stmt = db()->prepare('SELECT name, phone FROM maquinaria_contactos WHERE maquinaria_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $contacts = $stmt->fetchAll();
}
$companies = db()->query('SELECT id, name FROM companies WHERE status = 1 ORDER BY name')->fetchAll();
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div><h1><?= $machine ? 'Editar maquinaria' : 'Nueva maquinaria' ?></h1><p>Datos del equipo, foto y personas de contacto.</p></div>
    <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/modulos/maquinaria/datos_generales.php"><i class="fa-solid fa-arrow-left me-2"></i>Volver</a>
</div>
<form class="work-panel needs-validation" id="maquinariaForm" novalidate enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" id="maquinariaId" value="<?= (int) $id ?>">
    <div class="row g-4">
        <div class="col-lg-8">
            <h2 class="h5 mb-3">Información de la maquinaria</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="maquinariaEquipo">Equipo</label>
                    <input class="form-control" name="equipo" id="maquinariaEquipo" value="<?= e($machine['equipo'] ?? '') ?>" required maxlength="150">
                </div>
                <div class="col-md-6 position-relative">
                    <label class="form-label" for="maquinariaEmpresa">Empresa</label>
                    <div class="position-absolute d-flex gap-2" style="right: 12px; top: -16px;">
                            <button class="btn btn-sm btn-outline-primary" type="button" id="openMachineCompanyModalBtn" title="Agregar empresa"><i class="fa-solid fa-plus"></i></button>
                            <button class="btn btn-sm btn-outline-danger" type="button" id="deleteMachineCompanyBtn" title="Eliminar empresa seleccionada"><i class="fa-solid fa-trash"></i></button>
                    </div>
                    <select class="form-select select2-tags" name="company_id" id="maquinariaEmpresa" required data-placeholder="Seleccione o agregue">
                        <option></option>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= (int) $company['id'] ?>" <?= (int) ($machine['company_id'] ?? 0) === (int) $company['id'] ? 'selected' : '' ?>><?= e($company['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Seleccione una empresa.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="maquinariaSerie">Serie o Placa</label>
                    <input class="form-control" name="serie_placa" id="maquinariaSerie" value="<?= e($machine['serie_placa'] ?? '') ?>" required maxlength="80">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="maquinariaAnio">Año del equipo</label>
                    <input class="form-control" type="number" name="anio_equipo" id="maquinariaAnio" value="<?= e((string) ($machine['anio_equipo'] ?? '')) ?>" min="1950" max="2100" required>
                </div>
            </div>
            <div class="d-flex align-items-center justify-content-between mt-4 mb-3">
                <h2 class="h5 mb-0">Contactos</h2>
                <button class="btn btn-sm btn-outline-primary" type="button" id="addMachineContact"><i class="fa-solid fa-plus me-1"></i>Agregar contacto</button>
            </div>
            <div id="machineContacts"></div>
            <div class="form-text">Complete el nombre y celular de cada contacto que agregue.</div>
        </div>
        <div class="col-lg-4">
            <h2 class="h5 mb-3">Foto</h2>
            <div class="file-box">
                <div class="preview-box mb-3" id="maquinariaFotoPreview">
                    <?php if (!empty($machine['foto_path'])): ?>
                        <img src="<?= APP_URL . '/' . e($machine['foto_path']) ?>" alt="Foto de la maquinaria">
                    <?php else: ?>
                        <span><i class="fa-regular fa-image"></i></span>
                    <?php endif; ?>
                </div>
                <input class="form-control" type="file" name="foto" id="maquinariaFoto" accept="image/png,image/jpeg,image/webp">
                <div class="form-text">JPG, PNG o WebP.</div>
                <div class="file-current mt-2 d-none" id="maquinariaFotoActual"></div>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/modulos/maquinaria/datos_generales.php">Cancelar</a>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar maquinaria</button>
    </div>
</form>
<script>
window.machineInitialContacts = <?= json_encode($contacts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.machineCurrentPhoto = <?= json_encode($machine['foto_path'] ?? null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
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
