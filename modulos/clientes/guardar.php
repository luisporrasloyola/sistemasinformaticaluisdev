<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.informacion');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('modulos/clientes/informacion.php'); }
verify_csrf($_POST['csrf_token'] ?? null);

$id = max(0, (int) ($_POST['id'] ?? 0));
$name = trim((string) ($_POST['business_name'] ?? ''));
$ruc = trim((string) ($_POST['ruc'] ?? ''));
$names = $_POST['contact_name'] ?? [];
$positions = $_POST['contact_position'] ?? [];
$phones = $_POST['contact_phone'] ?? [];
$emails = $_POST['contact_email'] ?? [];
$newPhoto = null;
$oldPhoto = null;
$target = 'modulos/clientes/formulario.php' . ($id ? '?id=' . $id . '&error=' : '?error=');

try {
    if ($name === '' || mb_strlen($name) > 200) { throw new DomainException('Ingrese una razón social válida.'); }
    if (!preg_match('/^[0-9]{11}$/D', $ruc)) { throw new DomainException('El RUC debe tener exactamente 11 dígitos.'); }
    if (!is_array($names) || !is_array($positions) || !is_array($phones) || !is_array($emails) || count($names) < 1 || count($names) > 100) {
        throw new DomainException('Registre al menos un contacto válido.');
    }
    $contacts = [];
    foreach ($names as $index => $contactName) {
        $contact = [
            'name' => trim((string) $contactName),
            'position' => trim((string) ($positions[$index] ?? '')),
            'phone' => trim((string) ($phones[$index] ?? '')),
            'email' => trim((string) ($emails[$index] ?? '')),
        ];
        if ($contact['name'] === '' || mb_strlen($contact['name']) > 150 || $contact['position'] === '' || mb_strlen($contact['position']) > 120 || $contact['phone'] === '' || mb_strlen($contact['phone']) > 40 || !filter_var($contact['email'], FILTER_VALIDATE_EMAIL) || strlen($contact['email']) > 190) {
            throw new DomainException('Complete nombre, cargo, celular y correo válido en cada contacto.');
        }
        $contacts[] = $contact;
    }
    $photo = $_FILES['photo'] ?? [];
    if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $newPhoto = upload_file($photo, 'clientes', ['image/jpeg', 'image/png', 'image/webp'])['path'];
    }

    db()->beginTransaction();
    $dup = db()->prepare('SELECT id FROM clients WHERE ruc = :ruc AND id <> :id LIMIT 1');
    $dup->execute(['ruc' => $ruc, 'id' => $id]);
    if ($dup->fetchColumn()) { throw new DomainException('Ya existe un cliente registrado con ese RUC.'); }
    if ($id) {
        $current = db()->prepare('SELECT photo_path FROM clients WHERE id = :id FOR UPDATE');
        $current->execute(['id' => $id]);
        $row = $current->fetch();
        if (!$row) { throw new DomainException('El cliente ya no existe.'); }
        $oldPhoto = $row['photo_path'];
        db()->prepare('UPDATE clients SET business_name = :name, ruc = :ruc, photo_path = :photo WHERE id = :id')
            ->execute(['name' => $name, 'ruc' => $ruc, 'photo' => $newPhoto ?: $oldPhoto, 'id' => $id]);
        db()->prepare('DELETE FROM client_contacts WHERE client_id = :id')->execute(['id' => $id]);
    } else {
        db()->prepare('INSERT INTO clients (business_name, ruc, photo_path) VALUES (:name, :ruc, :photo)')
            ->execute(['name' => $name, 'ruc' => $ruc, 'photo' => $newPhoto]);
        $id = (int) db()->lastInsertId();
    }
    $insert = db()->prepare('INSERT INTO client_contacts (client_id, name, position, phone, email, sort_order) VALUES (:client_id, :name, :position, :phone, :email, :sort_order)');
    foreach ($contacts as $index => $contact) {
        $insert->execute($contact + ['client_id' => $id, 'sort_order' => $index]);
    }
    db()->commit();
    if ($newPhoto && $oldPhoto) { delete_uploaded_file($oldPhoto); }
    redirect('modulos/clientes/informacion.php');
} catch (Throwable $error) {
    if (db()->inTransaction()) { db()->rollBack(); }
    if ($newPhoto) { delete_uploaded_file($newPhoto); }
    $message = $error instanceof DomainException ? $error->getMessage() : 'No se pudo guardar el cliente. Revise que la base de datos de clientes esté instalada.';
    redirect($target . urlencode($message));
}
