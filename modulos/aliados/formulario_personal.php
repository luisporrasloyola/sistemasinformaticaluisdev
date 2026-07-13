<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$worker = [
    'id' => 0, 'company_id' => '', 'full_name' => '', 'document_type' => 'DNI', 'document_number' => '',
    'blood_type' => '', 'address' => '', 'phone' => '', 'email' => '', 'birth_date' => '', 'status' => 1,
    'photo_path' => '', 'signature_path' => '',
];
$selectedPositions = [];

if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM workers WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $worker = $stmt->fetch() ?: $worker;
    $stmt = db()->prepare('SELECT position_id FROM worker_positions WHERE worker_id = :id');
    $stmt->execute(['id' => $id]);
    $selectedPositions = array_map('intval', array_column($stmt->fetchAll(), 'position_id'));
}

function progreso_personal_formulario(array $worker, array $positions): int
{
    if (empty($worker['id'])) {
        $worker['document_type'] = '';
    }

    $fields = ['company_id','full_name','document_type','document_number','blood_type','address','phone','email','birth_date','photo_path','signature_path'];
    $done = 0;
    foreach ($fields as $field) {
        if (!empty($worker[$field])) {
            $done++;
        }
    }
    if (!empty($positions)) {
        $done++;
    }
    return (int) round(($done / (count($fields) + 1)) * 100);
}

$companies = db()->query('SELECT * FROM companies WHERE status = 1 ORDER BY name')->fetchAll();
$positions = db()->query('SELECT * FROM positions WHERE status = 1 ORDER BY name')->fetchAll();
$progress = progreso_personal_formulario($worker, $selectedPositions);
$calculatedStatus = $progress === 100;

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1><?= $id ? 'Editar personal' : 'Nuevo personal' ?></h1>
        <p>Información del trabajador, puestos, foto y firma.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/modulos/aliados/personal.php"><i class="fa-solid fa-arrow-left me-2"></i>Volver</a>
</div>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
        <i class="fa-solid fa-circle-exclamation"></i>
        <div><?= e((string) $_GET['error']) ?></div>
    </div>
<?php endif; ?>
<form class="work-panel needs-validation" id="personalForm" action="<?= APP_URL ?>/modulos/aliados/guardar_personal.php" method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int) $worker['id'] ?>">
    <div class="row g-4">
        <div class="col-lg-8">
            <h2>Información del personal</h2>
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label">Apellidos y Nombres</label>
                    <input class="form-control" name="full_name" value="<?= e($worker['full_name']) ?>" required>
                    <div class="invalid-feedback">Campo obligatorio.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipo de documento</label>
                    <select class="form-select" name="document_type" required>
                        <?php foreach (['DNI','Carnet de Extranjería','Pasaporte'] as $type): ?>
                            <option value="<?= e($type) ?>" <?= $worker['document_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nro. Documento</label>
                    <input class="form-control" name="document_number" value="<?= e($worker['document_number']) ?>" required maxlength="30">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipo de sangre</label>
                    <input class="form-control" name="blood_type" value="<?= e($worker['blood_type']) ?>" maxlength="15">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Celular</label>
                    <input class="form-control" name="phone" value="<?= e($worker['phone']) ?>" maxlength="40">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Dirección</label>
                    <input class="form-control" name="address" value="<?= e($worker['address']) ?>" maxlength="220">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Correo</label>
                    <input class="form-control" type="email" name="email" value="<?= e($worker['email']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Fecha de nacimiento</label>
                    <input class="form-control" type="date" name="birth_date" value="<?= e($worker['birth_date']) ?>">
                </div>
                <div class="col-md-6">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                        <label class="form-label mb-0" for="companySelect">Empresa</label>
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-primary" type="button" id="openCompanyModalBtn" title="Agregar empresa">
                                <i class="fa-solid fa-plus"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" type="button" id="deleteCompanyBtn" title="Eliminar empresa seleccionada">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <select class="form-select select2-tags" id="companySelect" name="company_id" required data-placeholder="Seleccione o agregue">
                        <option></option>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= (int) $company['id'] ?>" <?= (int) $worker['company_id'] === (int) $company['id'] ? 'selected' : '' ?>><?= e($company['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Estado</label>
                    <div class="estado-calculado-box <?= $calculatedStatus ? 'estado-activo' : 'estado-inactivo' ?>">
                        <span class="badge <?= $calculatedStatus ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $calculatedStatus ? 'Activo' : 'Inactivo' ?></span>
                        <small>Progreso: <?= $progress ?>%</small>
                    </div>
                    <input type="hidden" name="status" value="<?= $calculatedStatus ? 1 : 0 ?>">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Puestos de trabajo</label>
                    <div class="puestos-control" data-required-message="Seleccione al menos un puesto.">
                        <div class="puestos-grid">
                            <?php foreach ($positions as $position): ?>
                                <?php $inputId = 'puesto_' . (int) $position['id']; ?>
                                <div class="puesto-item" data-position-id="<?= (int) $position['id'] ?>">
                                    <input class="btn-check puesto-check" type="checkbox" name="positions[]" id="<?= e($inputId) ?>" value="<?= (int) $position['id'] ?>" <?= in_array((int) $position['id'], $selectedPositions, true) ? 'checked' : '' ?>>
                                    <label class="puesto-chip" for="<?= e($inputId) ?>">
                                        <i class="fa-solid fa-check"></i>
                                        <span><?= e($position['name']) ?></span>
                                    </label>
                                    <button class="btn btn-sm btn-outline-danger puesto-delete-btn js-eliminar-puesto" type="button" data-id="<?= (int) $position['id'] ?>" data-name="<?= e($position['name']) ?>" title="Eliminar puesto">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="input-group mt-2">
                            <input class="form-control" type="text" id="nuevoPuestoInput" placeholder="Agregar nuevo puesto">
                            <button class="btn btn-outline-primary" type="button" id="agregarPuestoBtn"><i class="fa-solid fa-plus me-1"></i>Agregar</button>
                        </div>
                        <div class="invalid-feedback d-block puestos-error d-none">Seleccione al menos un puesto.</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <h2>Foto y firma</h2>
            <?php foreach ([['photo','Foto','fotos',$worker['photo_path']], ['signature','Firma digital','firmas',$worker['signature_path']]] as $file): ?>
                <div class="file-box mb-3">
                    <label class="form-label"><?= e($file[1]) ?></label>
                    <div class="preview-box">
                        <?php if ($file[3]): ?>
                            <img src="<?= APP_URL . '/' . e($file[3]) ?>" alt="<?= e($file[1]) ?>">
                        <?php else: ?>
                            <span><i class="fa-regular fa-image"></i></span>
                        <?php endif; ?>
                    </div>
                    <input class="form-control mt-2 personal-file" type="file" name="<?= e($file[0]) ?>" accept="image/png,image/jpeg,image/webp" data-existing="<?= $file[3] ? '1' : '0' ?>">
                    <?php if ($file[3]): ?>
                        <div class="d-flex gap-2 mt-2">
                            <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/descargar.php?tipo=<?= e($file[0]) ?>&id=<?= (int) $worker['id'] ?>"><i class="fa-solid fa-download"></i></a>
                            <button class="btn btn-sm btn-outline-danger js-delete-worker-file" type="button" data-type="<?= e($file[0]) ?>" data-id="<?= (int) $worker['id'] ?>"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="d-flex justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/modulos/aliados/personal.php">Cancelar</a>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar</button>
    </div>
</form>

<div class="modal fade" id="companyQuickModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content needs-validation" id="companyQuickForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title">Nueva empresa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label class="form-label" for="companyQuickName">Nombre de empresa</label>
                <input class="form-control" id="companyQuickName" name="name" maxlength="160" required autocomplete="off">
                <div class="invalid-feedback">Ingrese el nombre de la empresa.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('personalForm');
    if (!form) return;

    function valueOf(name) {
        const field = form.querySelector('[name="' + name + '"]');
        if (!field) return '';
        if (name === 'company_id' && window.jQuery) {
            return String(jQuery(field).val() || '').trim();
        }
        return String(field.value || '').trim();
    }

    function hasFile(name) {
        const field = form.querySelector('input[type="file"][name="' + name + '"]');
        return !!field && (field.files.length > 0 || field.dataset.existing === '1');
    }

    function updateProgress() {
        const simpleFields = ['company_id', 'full_name', 'document_number', 'blood_type', 'address', 'phone', 'email', 'birth_date'];
        let done = 0;

        simpleFields.forEach(function (name) {
            if (valueOf(name) !== '') done++;
        });

        const id = valueOf('id');
        if (valueOf('document_type') !== '' && (id !== '0' || valueOf('document_number') !== '')) {
            done++;
        }

        if (hasFile('photo')) done++;
        if (hasFile('signature')) done++;
        if (form.querySelectorAll('.puesto-check:checked').length > 0) done++;

        const progress = Math.round((done / 12) * 100);
        const active = progress === 100;
        const box = form.querySelector('.estado-calculado-box');
        const badge = box ? box.querySelector('.badge') : null;
        const small = box ? box.querySelector('small') : null;
        const status = form.querySelector('input[name="status"]');

        if (box) {
            box.classList.toggle('estado-activo', active);
            box.classList.toggle('estado-inactivo', !active);
        }
        if (badge) {
            badge.classList.toggle('text-bg-success', active);
            badge.classList.toggle('text-bg-danger', !active);
            badge.textContent = active ? 'Activo' : 'Inactivo';
        }
        if (small) small.textContent = 'Progreso: ' + progress + '%';
        if (status) status.value = active ? '1' : '0';
    }

    function initCompanyQuickCreate() {
        const companySelect = form.querySelector('select[name="company_id"]');
        const companyModalElement = document.getElementById('companyQuickModal');
        const companyQuickForm = document.getElementById('companyQuickForm');
        const companyQuickName = document.getElementById('companyQuickName');
        const openCompanyModalBtn = document.getElementById('openCompanyModalBtn');
        const deleteCompanyBtn = document.getElementById('deleteCompanyBtn');
        const csrfToken = form.querySelector('input[name="csrf_token"]')?.value || '';
        let companyModal = null;

        function getCompanyModal() {
            if (!companyModalElement || !window.bootstrap) return null;
            companyModal = companyModal || bootstrap.Modal.getOrCreateInstance(companyModalElement);
            return companyModal;
        }

        openCompanyModalBtn?.addEventListener('click', function () {
            companyQuickForm?.reset();
            companyQuickForm?.classList.remove('was-validated');
            const modal = getCompanyModal();
            if (!modal) {
                if (window.Swal) {
                    Swal.fire('Atención', 'No se pudo abrir el modal de empresa.', 'warning');
                } else {
                    alert('No se pudo abrir el modal de empresa.');
                }
                return;
            }
            modal.show();
            setTimeout(function () { companyQuickName?.focus(); }, 180);
        });
        deleteCompanyBtn?.addEventListener('click', async function () {
            const value = window.jQuery ? String(jQuery(companySelect).val() || '') : String(companySelect?.value || '');
            if (!value || !/^\d+$/.test(value)) {
                Swal.fire('Atención', 'Seleccione una empresa guardada para eliminar.', 'warning');
                return;
            }

            const result = await Swal.fire({
                title: '¿Eliminar empresa?',
                text: 'Solo se eliminará si no está asignada a ningún personal.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            });
            if (!result.isConfirmed) return;

            const body = new FormData();
            body.append('csrf_token', csrfToken);
            body.append('id', value);

            const baseUrl = window.APP_URL || window.location.origin;
            const response = await fetch(`${baseUrl}/servicios/eliminar_empresa.php`, { method: 'POST', body });
            const data = await response.json();
            if (!data.ok) {
                Swal.fire('Atención', data.message || 'No se pudo eliminar la empresa.', 'warning');
                return;
            }

            Array.from(companySelect?.options || []).find(function (option) {
                return option.value === value;
            })?.remove();
            if (window.jQuery) {
                jQuery(companySelect).val(null).trigger('change');
            } else if (companySelect) {
                companySelect.value = '';
                companySelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
            updateProgress();
            Swal.fire({ icon: 'success', title: 'Empresa eliminada', timer: 1200, showConfirmButton: false });
        });

        companyQuickForm?.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!companyQuickForm.checkValidity()) {
                companyQuickForm.classList.add('was-validated');
                return;
            }

            const submitButton = companyQuickForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            try {
                const baseUrl = window.APP_URL || (typeof BASE_URL !== 'undefined' ? BASE_URL : window.location.origin);
                const response = await fetch(`${baseUrl}/servicios/guardar_empresa.php`, {
                    method: 'POST',
                    body: new FormData(companyQuickForm)
                });
                const data = await response.json();
                if (!data.ok) {
                    Swal.fire('Atención', data.message || 'No se pudo guardar la empresa.', 'warning');
                    return;
                }

                if (companySelect) {
                    const existing = Array.from(companySelect.options).find(function (option) {
                        return option.value === String(data.id);
                    });
                    if (!existing) {
                        companySelect.append(new Option(data.text, data.id, true, true));
                    } else {
                        existing.textContent = data.text;
                        existing.selected = true;
                    }

                    if (window.jQuery) {
                        jQuery(companySelect).val(String(data.id)).trigger('change');
                    } else {
                        companySelect.value = String(data.id);
                        companySelect.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }

                getCompanyModal()?.hide();
                updateProgress();
                Swal.fire({ icon: 'success', title: 'Empresa agregada', timer: 1200, showConfirmButton: false });
            } catch (error) {
                Swal.fire('Atención', 'No se pudo guardar la empresa.', 'warning');
            } finally {
                submitButton.disabled = false;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCompanyQuickCreate, { once: true });
    } else {
        setTimeout(initCompanyQuickCreate, 0);
    }
    form.addEventListener('input', updateProgress, true);
    form.addEventListener('change', updateProgress, true);
    document.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('.puesto-chip, #agregarPuestoBtn')) {
            setTimeout(updateProgress, 0);
        }
    });

    function bindCompanyProgress() {
        if (!window.jQuery) return;
        jQuery(form).find('select[name="company_id"]').off('.progressPersonal').on('change.progressPersonal select2:select.progressPersonal select2:clear.progressPersonal', updateProgress);
    }

    bindCompanyProgress();
    setTimeout(bindCompanyProgress, 300);
    setTimeout(updateProgress, 350);

    updateProgress();
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>




