<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('modulos/clientes/pmi.php'); }
verify_csrf($_POST['csrf_token'] ?? null);
$clientId = (int) ($_POST['client_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$newFile = null;
$oldFile = null;
$target = 'modulos/clientes/pmi.php?cliente_id=' . $clientId;
try {
    $check = db()->prepare('SELECT 1 FROM clients WHERE id = :id');
    $check->execute(['id' => $clientId]);
    if ($clientId < 1 || !$check->fetchColumn()) { throw new DomainException('Cliente no encontrado.'); }
    if ($action === 'save_general') {
        $category = (string) ($_POST['category'] ?? '');
        if (!in_array($category, ['personal', 'equipos'], true)) { throw new DomainException('Categoría inválida.'); }
        if ($category === 'equipos') { $target .= '&categoria=equipos'; }
        $ids = $_POST['source_ids'] ?? [];
        if (!is_array($ids)) { throw new DomainException('Selección inválida.'); }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        $sourceTable = $category === 'personal' ? 'requirements_catalog' : 'maquinaria_documentos_catalogo';
        $statusColumn = $category === 'personal' ? 'status' : 'estado';
        db()->beginTransaction();
        db()->prepare('DELETE FROM client_pmi_general_items WHERE client_id = :client_id AND category = :category')->execute(['client_id' => $clientId, 'category' => $category]);
        $exists = db()->prepare("SELECT 1 FROM {$sourceTable} WHERE id = :id AND {$statusColumn} = 1");
        $insert = db()->prepare('INSERT INTO client_pmi_general_items (client_id, category, source_id) VALUES (:client_id, :category, :source_id)');
        foreach ($ids as $id) {
            $exists->execute(['id' => $id]);
            if (!$exists->fetchColumn()) { throw new DomainException('Hay elementos que ya no están disponibles. Actualice la lista.'); }
            $insert->execute(['client_id' => $clientId, 'category' => $category, 'source_id' => $id]);
        }
        db()->commit();
    } elseif ($action === 'save_base') {
        $id = max(0, (int) ($_POST['document_id'] ?? 0));
        $catalogId = (int) ($_POST['catalog_id'] ?? 0);
        $catalogStmt = db()->prepare('SELECT name FROM client_pmi_base_catalog WHERE id = :id AND client_id = :client_id');
        $catalogStmt->execute(['id' => $catalogId, 'client_id' => $clientId]);
        $name = (string) $catalogStmt->fetchColumn();
        $observation = trim((string) ($_POST['observation'] ?? ''));
        $registration = trim((string) ($_POST['registration_date'] ?? ''));
        $start = trim((string) ($_POST['start_date'] ?? ''));
        $end = trim((string) ($_POST['end_date'] ?? ''));
        if ($name === '') { throw new DomainException('Seleccione un documento del catálogo de este cliente.'); }
        if (mb_strlen($observation) > 3000) { throw new DomainException('La observación es demasiado extensa.'); }
        if ($registration === '') { throw new DomainException('Ingrese la fecha de registro.'); }
        foreach ([$registration, $start, $end] as $date) {
            if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || DateTime::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date)) { throw new DomainException('Ingrese fechas válidas.'); }
        }
        if ($start && $end && $end < $start) { throw new DomainException('La fecha de fin debe ser posterior al inicio.'); }
        if ($id) {
            $current = db()->prepare('SELECT file_path FROM client_pmi_base_documents WHERE id = :id AND client_id = :client_id');
            $current->execute(['id' => $id, 'client_id' => $clientId]);
            $row = $current->fetch();
            if (!$row) { throw new DomainException('Documento no encontrado.'); }
            $oldFile = $row['file_path'];
        }
        $newFile = upload_file($_FILES['file'] ?? [], 'clientes_pmi', document_attachment_mimes())['path'];
        db()->beginTransaction();
        if ($id) {
            db()->prepare('UPDATE client_pmi_base_documents SET name = :name, registration_date = :registration_date, start_date = :start_date, end_date = :end_date, file_path = :file_path WHERE id = :id AND client_id = :client_id')
                ->execute(['name' => $name, 'registration_date' => $registration, 'start_date' => $start ?: null, 'end_date' => $end ?: null, 'file_path' => $newFile ?: $oldFile, 'id' => $id, 'client_id' => $clientId]);
        } else {
            db()->prepare('INSERT INTO client_pmi_base_documents (client_id, name, registration_date, start_date, end_date, file_path, registered_by_user_id) VALUES (:client_id, :name, :registration_date, :start_date, :end_date, :file_path, :user_id)')
                ->execute(['client_id' => $clientId, 'name' => $name, 'registration_date' => $registration, 'start_date' => $start ?: null, 'end_date' => $end ?: null, 'file_path' => $newFile, 'user_id' => (int) ($_SESSION['user']['id'] ?? 0) ?: null]);
            $id = (int) db()->lastInsertId();
        }
        if ($observation !== '') {
            db()->prepare('INSERT INTO client_pmi_base_observations (document_id, note, created_by_user_id) VALUES (:document_id, :note, :user_id)')
                ->execute(['document_id' => $id, 'note' => $observation, 'user_id' => (int) ($_SESSION['user']['id'] ?? 0) ?: null]);
        }
        db()->commit();
        if ($newFile && $oldFile) { delete_uploaded_file($oldFile); }
    } elseif ($action === 'delete_base') {
        $id = (int) ($_POST['document_id'] ?? 0);
        db()->beginTransaction();
        $current = db()->prepare('SELECT file_path FROM client_pmi_base_documents WHERE id = :id AND client_id = :client_id FOR UPDATE');
        $current->execute(['id' => $id, 'client_id' => $clientId]);
        $row = $current->fetch();
        if (!$row) { throw new DomainException('Documento no encontrado.'); }
        $oldFile = $row['file_path'];
        db()->prepare('DELETE FROM client_pmi_base_documents WHERE id = :id AND client_id = :client_id')->execute(['id' => $id, 'client_id' => $clientId]);
        db()->commit();
        if ($oldFile) { delete_uploaded_file($oldFile); }
    } elseif ($action === 'create_folder') {
        $name = trim((string) ($_POST['folder_name'] ?? ''));
        $ids = $_POST['document_ids'] ?? [];
        if ($name === '' || mb_strlen($name) > 120 || !is_array($ids) || !$ids) { throw new DomainException('Ingrese el nombre de la carpeta y seleccione al menos un requisito.'); }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        db()->beginTransaction();
        db()->prepare('INSERT INTO client_pmi_base_folders (client_id, name) VALUES (:client_id, :name)')->execute(['client_id' => $clientId, 'name' => $name]);
        $folderId = (int) db()->lastInsertId();
        $checkDoc = db()->prepare('SELECT 1 FROM client_pmi_base_documents d LEFT JOIN client_pmi_base_folder_items fi ON fi.document_id = d.id WHERE d.id = :id AND d.client_id = :client_id AND fi.document_id IS NULL');
        $insertItem = db()->prepare('INSERT INTO client_pmi_base_folder_items (document_id, folder_id) VALUES (:document_id, :folder_id)');
        foreach ($ids as $docId) {
            $checkDoc->execute(['id' => $docId, 'client_id' => $clientId]);
            if (!$checkDoc->fetchColumn()) { throw new DomainException('Un requisito ya pertenece a otra carpeta o no existe.'); }
            $insertItem->execute(['document_id' => $docId, 'folder_id' => $folderId]);
        }
        db()->commit();
    } elseif ($action === 'update_folder') {
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $name = trim((string) ($_POST['folder_name'] ?? ''));
        $ids = $_POST['document_ids'] ?? [];
        if ($folderId < 1 || $name === '' || mb_strlen($name) > 120 || !is_array($ids) || !$ids) { throw new DomainException('Ingrese el nombre de la carpeta y seleccione al menos un documento.'); }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (!$ids) { throw new DomainException('Seleccione al menos un documento.'); }
        db()->beginTransaction();
        $folderCheck = db()->prepare('SELECT 1 FROM client_pmi_base_folders WHERE id = :id AND client_id = :client_id FOR UPDATE');
        $folderCheck->execute(['id' => $folderId, 'client_id' => $clientId]);
        if (!$folderCheck->fetchColumn()) { throw new DomainException('Carpeta no encontrada.'); }
        $checkDoc = db()->prepare('SELECT fi.folder_id FROM client_pmi_base_documents d LEFT JOIN client_pmi_base_folder_items fi ON fi.document_id = d.id WHERE d.id = :id AND d.client_id = :client_id');
        foreach ($ids as $docId) {
            $checkDoc->execute(['id' => $docId, 'client_id' => $clientId]);
            $row = $checkDoc->fetch();
            if (!$row || ($row['folder_id'] !== null && (int) $row['folder_id'] !== $folderId)) { throw new DomainException('Un documento pertenece a otra carpeta o no existe.'); }
        }
        db()->prepare('UPDATE client_pmi_base_folders SET name = :name WHERE id = :id AND client_id = :client_id')->execute(['name' => $name, 'id' => $folderId, 'client_id' => $clientId]);
        db()->prepare('DELETE FROM client_pmi_base_folder_items WHERE folder_id = :folder_id')->execute(['folder_id' => $folderId]);
        $insertItem = db()->prepare('INSERT INTO client_pmi_base_folder_items (document_id, folder_id) VALUES (:document_id, :folder_id)');
        foreach ($ids as $docId) { $insertItem->execute(['document_id' => $docId, 'folder_id' => $folderId]); }
        db()->commit();
    } elseif ($action === 'delete_folder') {
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $delete = db()->prepare('DELETE FROM client_pmi_base_folders WHERE id = :id AND client_id = :client_id');
        $delete->execute(['id' => $folderId, 'client_id' => $clientId]);
        if (!$delete->rowCount()) { throw new DomainException('Carpeta no encontrada.'); }
    } else {
        throw new DomainException('Acción inválida.');
    }
    redirect($target);
} catch (Throwable $error) {
    if (db()->inTransaction()) { db()->rollBack(); }
    if ($newFile) { delete_uploaded_file($newFile); }
    $message = $error instanceof DomainException ? $error->getMessage() : 'No se pudo guardar. Revise que el esquema PMI Clientes esté instalado.';
    redirect($target . '&error=' . urlencode($message));
}
