<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('modulos/clientes/pmi.php'); }
verify_csrf($_POST['csrf_token'] ?? null);

$clientId = (int) ($_POST['client_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$target = 'modulos/clientes/pmi.php?cliente_id=' . $clientId . '&tab=formatos';
$newFile = null;
$oldFile = null;

try {
    $check = db()->prepare('SELECT 1 FROM clients WHERE id = :id');
    $check->execute(['id' => $clientId]);
    if ($clientId < 1 || !$check->fetchColumn()) { throw new DomainException('Cliente no encontrado.'); }

    if ($action === 'save_document') {
        $id = max(0, (int) ($_POST['document_id'] ?? 0));
        $name = trim((string) ($_POST['name'] ?? ''));
        $registration = trim((string) ($_POST['registration_date'] ?? ''));
        $start = trim((string) ($_POST['start_date'] ?? ''));
        $end = trim((string) ($_POST['end_date'] ?? ''));
        $linkUrl = trim((string) ($_POST['link_url'] ?? ''));
        if ($name === '' || mb_strlen($name) > 180) { throw new DomainException('Ingrese un nombre de formato válido.'); }
        if ($registration === '') { throw new DomainException('Ingrese la fecha de registro.'); }
        if ($linkUrl !== '') {
            $parts = parse_url($linkUrl);
            if (strlen($linkUrl) > 2048 || !filter_var($linkUrl, FILTER_VALIDATE_URL) || !$parts || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                throw new DomainException('Ingrese un enlace válido que empiece con https:// o http://.');
            }
        }
        foreach ([$registration, $start, $end] as $date) {
            if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || DateTime::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date)) { throw new DomainException('Ingrese fechas válidas.'); }
        }
        if ($start && $end && $end < $start) { throw new DomainException('La fecha de fin debe ser posterior al inicio.'); }

        if ($id) {
            $current = db()->prepare('SELECT file_path FROM client_pmi_format_documents WHERE id = :id AND client_id = :client_id');
            $current->execute(['id' => $id, 'client_id' => $clientId]);
            $row = $current->fetch();
            if (!$row) { throw new DomainException('Formato no encontrado.'); }
            $oldFile = $row['file_path'];
        }
        $newFile = upload_file($_FILES['file'] ?? [], 'clientes_pmi_formatos', format_attachment_mimes(), format_attachment_extensions())['path'];
        db()->beginTransaction();
        $catalog = db()->prepare('INSERT INTO client_pmi_format_catalog (client_id, name) VALUES (:client_id, :name) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
        $catalog->execute(['client_id' => $clientId, 'name' => $name]);
        $catalogId = (int) db()->lastInsertId();
        if ($id) {
            db()->prepare('UPDATE client_pmi_format_documents SET catalog_id = :catalog_id, registration_date = :registration_date, start_date = :start_date, end_date = :end_date, file_path = :file_path, link_url = :link_url WHERE id = :id AND client_id = :client_id')->execute([
                'catalog_id' => $catalogId, 'registration_date' => $registration, 'start_date' => $start ?: null, 'end_date' => $end ?: null,
                'file_path' => $newFile ?: $oldFile, 'link_url' => $linkUrl ?: null, 'id' => $id, 'client_id' => $clientId,
            ]);
        } else {
            db()->prepare('INSERT INTO client_pmi_format_documents (client_id, catalog_id, registration_date, start_date, end_date, file_path, link_url, registered_by_user_id) VALUES (:client_id, :catalog_id, :registration_date, :start_date, :end_date, :file_path, :link_url, :user_id)')->execute([
                'client_id' => $clientId, 'catalog_id' => $catalogId, 'registration_date' => $registration, 'start_date' => $start ?: null,
                'end_date' => $end ?: null, 'file_path' => $newFile, 'link_url' => $linkUrl ?: null, 'user_id' => (int) ($_SESSION['user']['id'] ?? 0) ?: null,
            ]);
            $id = (int) db()->lastInsertId();
        }
        db()->commit();
        if ($newFile && $oldFile) { delete_uploaded_file($oldFile); }
    } elseif ($action === 'delete_document') {
        $id = (int) ($_POST['document_id'] ?? 0);
        db()->beginTransaction();
        $current = db()->prepare('SELECT file_path FROM client_pmi_format_documents WHERE id = :id AND client_id = :client_id FOR UPDATE');
        $current->execute(['id' => $id, 'client_id' => $clientId]);
        $row = $current->fetch();
        if (!$row) { throw new DomainException('Formato no encontrado.'); }
        $oldFile = $row['file_path'];
        db()->prepare('DELETE FROM client_pmi_format_documents WHERE id = :id AND client_id = :client_id')->execute(['id' => $id, 'client_id' => $clientId]);
        db()->commit();
        if ($oldFile) { delete_uploaded_file($oldFile); }
    } elseif ($action === 'create_folder' || $action === 'update_folder') {
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $name = trim((string) ($_POST['folder_name'] ?? ''));
        $ids = $_POST['document_ids'] ?? [];
        if ($name === '' || mb_strlen($name) > 120 || !is_array($ids)) { throw new DomainException('Ingrese un nombre de carpeta válido.'); }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (!$ids) { throw new DomainException('Seleccione al menos un formato.'); }
        db()->beginTransaction();
        if ($action === 'create_folder') {
            db()->prepare('INSERT INTO client_pmi_format_folders (client_id, name) VALUES (:client_id, :name)')->execute(['client_id' => $clientId, 'name' => $name]);
            $folderId = (int) db()->lastInsertId();
        } else {
            $folder = db()->prepare('SELECT 1 FROM client_pmi_format_folders WHERE id = :id AND client_id = :client_id FOR UPDATE');
            $folder->execute(['id' => $folderId, 'client_id' => $clientId]);
            if (!$folder->fetchColumn()) { throw new DomainException('Carpeta no encontrada.'); }
        }
        $checkDoc = db()->prepare('SELECT fi.folder_id FROM client_pmi_format_documents d LEFT JOIN client_pmi_format_folder_items fi ON fi.document_id = d.id WHERE d.id = :id AND d.client_id = :client_id');
        foreach ($ids as $docId) {
            $checkDoc->execute(['id' => $docId, 'client_id' => $clientId]);
            $row = $checkDoc->fetch();
            if (!$row || ($row['folder_id'] !== null && (int) $row['folder_id'] !== $folderId)) { throw new DomainException('Un formato pertenece a otra carpeta o no existe.'); }
        }
        if ($action === 'update_folder') {
            db()->prepare('UPDATE client_pmi_format_folders SET name = :name WHERE id = :id AND client_id = :client_id')->execute(['name' => $name, 'id' => $folderId, 'client_id' => $clientId]);
            db()->prepare('DELETE FROM client_pmi_format_folder_items WHERE folder_id = :folder_id')->execute(['folder_id' => $folderId]);
        }
        $insert = db()->prepare('INSERT INTO client_pmi_format_folder_items (document_id, folder_id) VALUES (:document_id, :folder_id)');
        foreach ($ids as $docId) { $insert->execute(['document_id' => $docId, 'folder_id' => $folderId]); }
        db()->commit();
    } elseif ($action === 'delete_folder') {
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $delete = db()->prepare('DELETE FROM client_pmi_format_folders WHERE id = :id AND client_id = :client_id');
        $delete->execute(['id' => $folderId, 'client_id' => $clientId]);
        if (!$delete->rowCount()) { throw new DomainException('Carpeta no encontrada.'); }
    } else {
        throw new DomainException('Acción inválida.');
    }
    redirect($target);
} catch (Throwable $error) {
    if (db()->inTransaction()) { db()->rollBack(); }
    if ($newFile) { delete_uploaded_file($newFile); }
    $message = $error instanceof DomainException ? $error->getMessage() : 'No se pudo guardar. Revise que la estructura de Formatos editables esté instalada.';
    redirect($target . '&error=' . urlencode($message));
}
