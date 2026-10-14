<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.informacion');
$id = max(0, (int) ($_GET['id'] ?? 0));
$client = ['id' => 0, 'business_name' => '', 'ruc' => '', 'photo_path' => ''];
$contacts = [];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM clients WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $client = $stmt->fetch();
    if (!$client) { http_response_code(404); exit('Cliente no encontrado.'); }
    $stmt = db()->prepare('SELECT name, position, phone, email FROM client_contacts WHERE client_id = :id ORDER BY sort_order, id');
    $stmt->execute(['id' => $id]);
    $contacts = $stmt->fetchAll();
}
if (!$contacts) { $contacts[] = ['name' => '', 'position' => '', 'phone' => '', 'email' => '']; }
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div><h1><?= $id ? 'Editar cliente' : 'Nuevo cliente' ?></h1><p>Datos de la empresa, foto y personas de contacto.</p></div>
    <a class="btn btn-outline-secondary" href="<?= APP_URL ?>/modulos/clientes/informacion.php"><i class="fa-solid fa-arrow-left me-2"></i>Volver</a>
</div>
<?php if (isset($_GET['error'])): ?><div class="alert alert-danger"><?= e((string) $_GET['error']) ?></div><?php endif; ?>
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
