<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.informacion');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('modulos/clientes/informacion.php'); }
verify_csrf($_POST['csrf_token'] ?? null);
$id = (int) ($_POST['id'] ?? 0);
if ($id < 1) { redirect('modulos/clientes/informacion.php?error=' . urlencode('Cliente inválido.')); }
try {
    db()->beginTransaction();
    $stmt = db()->prepare('SELECT photo_path FROM clients WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $photo = $stmt->fetchColumn();
    if ($photo === false) { throw new DomainException('El cliente ya no existe.'); }
    db()->prepare('DELETE FROM clients WHERE id = :id')->execute(['id' => $id]);
    db()->commit();
    if ($photo) { delete_uploaded_file((string) $photo); }
    redirect('modulos/clientes/informacion.php');
} catch (Throwable $error) {
    if (db()->inTransaction()) { db()->rollBack(); }
    redirect('modulos/clientes/informacion.php?error=' . urlencode($error instanceof DomainException ? $error->getMessage() : 'No se pudo eliminar el cliente.'));
}
