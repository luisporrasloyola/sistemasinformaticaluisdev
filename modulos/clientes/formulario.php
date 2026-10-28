<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.informacion');
$id = max(0, (int) ($_GET['id'] ?? 0));
$client = ['id' => 0, 'business_name' => '', 'ruc' => '', 'photo_path' => ''];
$contacts = [];
$selectedProjectIds = [];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM clients WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $client = $stmt->fetch();
    if (!$client) { http_response_code(404); exit('Cliente no encontrado.'); }
    $stmt = db()->prepare('SELECT name, position, phone, email FROM client_contacts WHERE client_id = :id ORDER BY sort_order, id');
    $stmt->execute(['id' => $id]);
    $contacts = $stmt->fetchAll();
    $projectStmt = db()->prepare('SELECT project_id FROM client_project_assignments WHERE client_id = ?');
    $projectStmt->execute([$id]);
    $selectedProjectIds = array_map('intval', $projectStmt->fetchAll(PDO::FETCH_COLUMN));
}
$projects = db()->query('SELECT id, name FROM client_project_catalog ORDER BY name, id')->fetchAll();
if (!$contacts) { $contacts[] = ['name' => '', 'position' => '', 'phone' => '', 'email' => '']; }
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div><h1><?= $id ? 'Editar cliente' : 'Nuevo cliente' ?></h1><p>Datos de la empresa, foto y personas de contacto.</p></div>
    <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/modulos/clientes/informacion.php"><i class="fa-solid fa-arrow-left me-2"></i>Volver</a>
</div>
<?php if (isset($_GET['error'])): ?><div class="alert alert-danger"><?= e((string) $_GET['error']) ?></div><?php endif; ?>
<style>
.client-projects-box{min-height:58px;padding:10px;border:1px solid #cbd7e7;border-radius:8px;background:#fbfdff}
.client-projects-grid{display:flex;flex-wrap:wrap;gap:8px}
.client-project-item{display:inline-flex;align-items:stretch}
.client-project-chip{display:inline-flex;align-items:center;gap:7px;max-width:100%;padding:6px 10px;border:1px solid #c4d5ee;border-radius:7px;background:#fff;color:#244a81;font-size:.83rem;cursor:pointer;transition:background .15s,border-color .15s}
.client-project-chip:has(input:checked){border-color:#2e73d5;background:#eaf3ff;color:#164f9e;font-weight:600}
.client-project-chip:hover{border-color:#5a8fda;background:#f1f7ff}
.client-project-chip:focus-within{outline:2px solid #3778dc;outline-offset:2px}
.client-project-chip .form-check-input{margin:0;flex:none}
.client-project-delete{margin-left:3px}
</style>
<form class="work-panel" method="post" action="<?= APP_URL ?>/modulos/clientes/guardar.php" enctype="multipart/form-data" id="clientForm">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int) $client['id'] ?>">
    <div class="row g-4">
        <div class="col-lg-8">
            <h2 class="h5 mb-3">Información del cliente</h2>
            <div class="row g-3">
                <div class="col-md-8"><label class="form-label" for="businessName">Razón social</label><input class="form-control" id="businessName" name="business_name" maxlength="200" value="<?= e($client['business_name']) ?>" required></div>
                <div class="col-md-4"><label class="form-label" for="ruc">RUC</label><input class="form-control" id="ruc" name="ruc" inputmode="numeric" pattern="[0-9]{11}" minlength="11" maxlength="11" value="<?= e($client['ruc']) ?>" required><div class="form-text">11 dígitos.</div></div>
            </div>
            <div class="mt-3">
                <label class="form-label d-block">Proyectos</label>
                <div class="client-projects-box" role="group" aria-label="Proyectos del cliente">
                    <div class="client-projects-grid" id="clientProjectsGrid">
                        <?php foreach ($projects as $project): $projectId = (int) $project['id']; ?>
                        <div class="client-project-item"><label class="client-project-chip"><input class="form-check-input" type="checkbox" name="project_ids[]" value="<?= $projectId ?>" <?= in_array($projectId, $selectedProjectIds, true) ? 'checked' : '' ?>><span><?= e($project['name']) ?></span></label><button class="btn btn-sm btn-outline-danger client-project-delete" type="button" data-id="<?= $projectId ?>" title="Eliminar proyecto del catálogo" aria-label="Eliminar proyecto <?= e($project['name']) ?> del catálogo"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="input-group mt-2"><input class="form-control" id="newClientProject" maxlength="180" placeholder="Agregar nuevo proyecto" aria-label="Nombre del nuevo proyecto"><button class="btn btn-outline-primary" type="button" id="addClientProject"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Agregar</button></div>
                </div>
                <div class="form-text">Marque los proyectos que pertenecen a este cliente.</div>
            </div>
            <div class="d-flex align-items-center justify-content-between mt-4 mb-3">
                <h2 class="h5 mb-0">Contactos</h2>
                <button class="btn btn-sm btn-outline-primary" type="button" id="addContact"><i class="fa-solid fa-plus me-1"></i>Agregar contacto</button>
            </div>
            <div id="contacts">
                <?php foreach ($contacts as $contact): ?>
                <div class="card mb-3 contact-card"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3"><strong class="contact-title">Contacto</strong><button class="btn btn-sm btn-outline-danger remove-contact" type="button" aria-label="Quitar contacto"><i class="fa-solid fa-trash"></i></button></div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Nombre</label><input class="form-control" name="contact_name[]" maxlength="150" value="<?= e($contact['name']) ?>" required></div>
                        <div class="col-md-6"><label class="form-label">Cargo</label><input class="form-control" name="contact_position[]" maxlength="120" value="<?= e($contact['position']) ?>" required></div>
                        <div class="col-md-6"><label class="form-label">Celular</label><input class="form-control" name="contact_phone[]" type="tel" maxlength="40" value="<?= e($contact['phone']) ?>" required></div>
                        <div class="col-md-6"><label class="form-label">Correo</label><input class="form-control" name="contact_email[]" type="email" maxlength="190" value="<?= e($contact['email']) ?>" required></div>
                    </div>
                </div></div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-lg-4">
            <h2 class="h5 mb-3">Foto</h2>
            <div class="file-box">
                <div class="preview-box mb-3" id="photoPreview">
                    <?php if ($client['photo_path']): ?><img src="<?= APP_URL . '/' . e($client['photo_path']) ?>" alt="Foto del cliente"><?php else: ?><span><i class="fa-regular fa-image"></i></span><?php endif; ?>
                </div>
                <input class="form-control" type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">
                <div class="form-text">JPG, PNG o WebP. Máximo 10 MB.</div>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-end gap-2 mt-4"><a class="btn btn-outline-secondary" href="<?= APP_URL ?>/modulos/clientes/informacion.php">Cancelar</a><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar cliente</button></div>
</form>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const projectGrid = document.getElementById('clientProjectsGrid');
    const projectInput = document.getElementById('newClientProject');
    const addProjectButton = document.getElementById('addClientProject');
    const projectUrl = <?= json_encode(APP_URL . '/modulos/clientes/proyectos_catalogo.php') ?>;
    const projectToken = document.querySelector('#clientForm input[name="csrf_token"]').value;
    const projectRequest = async fields => {
        const body = new FormData();
        body.append('csrf_token', projectToken);
        Object.entries(fields).forEach(([key, value]) => body.append(key, value));
        const response = await fetch(projectUrl, {method: 'POST', body, credentials: 'same-origin'});
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo guardar el proyecto.');
        return data;
    };
    const addProject = async () => {
        const name = projectInput.value.trim();
        if (!name || addProjectButton.disabled) return;
        addProjectButton.disabled = true;
        try {
            const data = await projectRequest({action: 'add', name});
            const item = document.createElement('div'); item.className = 'client-project-item';
            const label = document.createElement('label'); label.className = 'client-project-chip';
            const check = document.createElement('input'); check.type = 'checkbox'; check.name = 'project_ids[]'; check.value = data.id; check.checked = true; check.className = 'form-check-input';
            const caption = document.createElement('span'); caption.textContent = data.name;
            label.append(check, caption);
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-sm btn-outline-danger client-project-delete'; remove.dataset.id = data.id; remove.title = 'Eliminar proyecto del catálogo'; remove.setAttribute('aria-label', 'Eliminar proyecto ' + data.name + ' del catálogo');
            const icon = document.createElement('i'); icon.className = 'fa-solid fa-trash'; icon.setAttribute('aria-hidden', 'true'); remove.append(icon);
            item.append(label, remove); projectGrid.append(item);
            projectInput.value = ''; projectInput.focus();
        } catch (error) { alert(error.message); }
        finally { addProjectButton.disabled = false; }
    };
    addProjectButton.addEventListener('click', addProject);
    projectInput.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); addProject(); } });
    projectGrid.addEventListener('click', async event => {
        const button = event.target.closest('.client-project-delete');
        if (!button || button.disabled) return;
        const name = button.closest('.client-project-item').querySelector('.client-project-chip span').textContent;
        if (!confirm('¿Eliminar el proyecto «' + name + '» del catálogo de Clientes?')) return;
        button.disabled = true;
        try {
            await projectRequest({action: 'delete', project_id: button.dataset.id});
            button.closest('.client-project-item').remove();
        } catch (error) { alert(error.message); button.disabled = false; }
    });
    const list = document.getElementById('contacts');
    const renumber = () => {
        list.querySelectorAll('.contact-card').forEach((card, index) => {
            card.querySelector('.contact-title').textContent = `Contacto ${index + 1}`;
            card.querySelector('.remove-contact').disabled = list.children.length === 1;
        });
    };
    document.getElementById('addContact').addEventListener('click', () => {
        const card = list.querySelector('.contact-card').cloneNode(true);
        card.querySelectorAll('input').forEach(input => { input.value = ''; });
        list.appendChild(card);
        renumber();
        card.querySelector('input').focus();
    });
    list.addEventListener('click', event => {
        const button = event.target.closest('.remove-contact');
        if (button && list.children.length > 1) { button.closest('.contact-card').remove(); renumber(); }
    });
    document.getElementById('photo').addEventListener('change', event => {
        const file = event.target.files[0];
        if (!file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return;
        const url = URL.createObjectURL(file);
        const preview = document.getElementById('photoPreview');
        preview.innerHTML = '';
        const img = document.createElement('img'); img.src = url; img.alt = 'Vista previa de foto'; preview.appendChild(img);
        img.onload = () => URL.revokeObjectURL(url);
    });
    renumber();
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
