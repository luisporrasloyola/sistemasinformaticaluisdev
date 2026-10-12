<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_module_access('requisitos.pmi_individual');
if (is_personal_role()) json_response(['ok' => false, 'message' => 'No tiene permiso para modificar carpetas.'], 403);
verify_csrf($_POST['csrf_token'] ?? null);

$action = (string) ($_POST['action'] ?? '');
$workerId = (int) ($_POST['worker_id'] ?? 0);
$positionId = (int) ($_POST['position_id'] ?? 0);
$folderId = (int) ($_POST['folder_id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
if ($workerId < 1 || $positionId < 1 || !in_array($action, ['create', 'replace', 'rename', 'delete', 'move'], true)) {
    json_response(['ok' => false, 'message' => 'Solicitud inválida.'], 422);
}
$pdo = db();
$position = $pdo->prepare('SELECT 1 FROM worker_positions WHERE worker_id = ? AND position_id = ? LIMIT 1');
$position->execute([$workerId, $positionId]);
if (!$position->fetchColumn()) json_response(['ok' => false, 'message' => 'El puesto no pertenece al trabajador.'], 422);

if (in_array($action, ['create', 'replace', 'rename'], true) && ($name === '' || mb_strlen($name) > 120)) {
    json_response(['ok' => false, 'message' => 'El nombre debe tener entre 1 y 120 caracteres.'], 422);
}
if ($action !== 'create' && $folderId > 0) {
    $folder = $pdo->prepare('SELECT id FROM pmi_requirement_folders WHERE id = ? AND worker_id = ? AND position_id = ?');
    $folder->execute([$folderId, $workerId, $positionId]);
    if (!$folder->fetchColumn()) json_response(['ok' => false, 'message' => 'La carpeta no pertenece a este puesto.'], 404);
} elseif (in_array($action, ['replace', 'rename', 'delete'], true)) {
    json_response(['ok' => false, 'message' => 'Seleccione una carpeta.'], 422);
}

try {
    if ($action === 'rename') {
        $stmt = $pdo->prepare('UPDATE pmi_requirement_folders SET name = ? WHERE id = ?');
        $stmt->execute([$name, $folderId]);
        json_response(['ok' => true]);
    }
    if ($action === 'delete') {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM pmi_requirement_folders WHERE id = ? AND worker_id = ? AND position_id = ? FOR UPDATE');
        $lock->execute([$folderId, $workerId, $positionId]);
        if (!$lock->fetchColumn()) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => 'La carpeta ya no existe.'], 404);
        }
        $members = $pdo->prepare('SELECT wr.requirement_id FROM pmi_requirement_folder_items fi JOIN worker_requirements wr ON wr.id = fi.requirement_record_id WHERE fi.folder_id = ?');
        $members->execute([$folderId]);
        foreach ($members->fetchAll() as $row) {
            if (!current_user_can_document('requisitos.pmi_individual', (int) $row['requirement_id'], 'upload')) {
                $pdo->rollBack();
                json_response(['ok' => false, 'message' => 'No tiene permiso para quitar esta carpeta.'], 403);
            }
        }
        $stmt = $pdo->prepare('DELETE FROM pmi_requirement_folders WHERE id = ?');
        $stmt->execute([$folderId]);
        $pdo->commit();
        json_response(['ok' => true]);
    }

    $idsRaw = $_POST['record_ids'] ?? [];
    if (!is_array($idsRaw) || !$idsRaw || count($idsRaw) > 200) json_response(['ok' => false, 'message' => 'Seleccione entre 1 y 200 registros.'], 422);
    $ids = array_values(array_unique(array_map('intval', $idsRaw)));
    if (count($ids) !== count($idsRaw) || min($ids) < 1) json_response(['ok' => false, 'message' => 'Selección inválida.'], 422);
    $pdo->beginTransaction();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $records = $pdo->prepare("SELECT id, requirement_id FROM worker_requirements WHERE id IN ($placeholders) AND worker_id = ? AND position_id = ? FOR UPDATE");
    $records->execute([...$ids, $workerId, $positionId]);
    $found = $records->fetchAll();
    if (count($found) !== count($ids)) {
        $pdo->rollBack();
        json_response(['ok' => false, 'message' => 'Algún registro ya no pertenece al puesto seleccionado.'], 409);
    }
    foreach ($found as $row) {
        if (!current_user_can_document('requisitos.pmi_individual', (int) $row['requirement_id'], 'upload')) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => 'No tiene permiso para organizar uno de los requisitos.'], 403);
        }
    }
    if (in_array($action, ['create', 'replace'], true)) {
        $memberships = $pdo->prepare("SELECT folder_id FROM pmi_requirement_folder_items WHERE requirement_record_id IN ($placeholders)");
        $memberships->execute($ids);
        foreach ($memberships->fetchAll(PDO::FETCH_COLUMN) as $currentFolderId) {
            if ($action === 'create' || (int) $currentFolderId !== $folderId) {
                $pdo->rollBack();
                json_response(['ok' => false, 'message' => 'Uno de los requisitos ya pertenece a otra carpeta. Actualice la página.'], 409);
            }
        }
    }
    if ($action === 'create') {
        $stmt = $pdo->prepare('INSERT INTO pmi_requirement_folders (worker_id, position_id, name, created_by_user_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$workerId, $positionId, $name, (int) (current_user()['id'] ?? 0) ?: null]);
        $folderId = (int) $pdo->lastInsertId();
    }
    if ($action === 'replace') {
        $current = $pdo->prepare('SELECT wr.requirement_id FROM pmi_requirement_folder_items fi JOIN worker_requirements wr ON wr.id = fi.requirement_record_id WHERE fi.folder_id = ? FOR UPDATE');
        $current->execute([$folderId]);
        foreach ($current->fetchAll() as $row) {
            if (!current_user_can_document('requisitos.pmi_individual', (int) $row['requirement_id'], 'upload')) {
                $pdo->rollBack();
                json_response(['ok' => false, 'message' => 'No tiene permiso para reorganizar todos los requisitos de esta carpeta.'], 403);
            }
        }
        $rename = $pdo->prepare('UPDATE pmi_requirement_folders SET name = ? WHERE id = ? AND worker_id = ? AND position_id = ?');
        $rename->execute([$name, $folderId, $workerId, $positionId]);
        $clear = $pdo->prepare('DELETE FROM pmi_requirement_folder_items WHERE folder_id = ?');
        $clear->execute([$folderId]);
    }
    $previousFolders = [];
    if (in_array($action, ['create', 'replace', 'move'], true)) {
        $previous = $pdo->prepare("SELECT DISTINCT folder_id FROM pmi_requirement_folder_items WHERE requirement_record_id IN ($placeholders)");
        $previous->execute($ids);
        $previousFolders = array_map('intval', $previous->fetchAll(PDO::FETCH_COLUMN));
    }
    $remove = $pdo->prepare("DELETE FROM pmi_requirement_folder_items WHERE requirement_record_id IN ($placeholders)");
    $remove->execute($ids);
    if ($folderId > 0) {
        $insert = $pdo->prepare('INSERT INTO pmi_requirement_folder_items (requirement_record_id, folder_id) VALUES (?, ?)');
        foreach ($ids as $id) $insert->execute([$id, $folderId]);
    }
    if ($previousFolders) {
        $emptyFolder = $pdo->prepare('SELECT COUNT(*) FROM pmi_requirement_folder_items WHERE folder_id = ?');
        $deleteEmpty = $pdo->prepare('DELETE FROM pmi_requirement_folders WHERE id = ? AND worker_id = ? AND position_id = ?');
        foreach ($previousFolders as $previousFolderId) {
            if ($previousFolderId === $folderId) continue;
            $emptyFolder->execute([$previousFolderId]);
            if ((int) $emptyFolder->fetchColumn() === 0) $deleteEmpty->execute([$previousFolderId, $workerId, $positionId]);
        }
    }
    $pdo->commit();
    json_response(['ok' => true, 'folder_id' => $folderId]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') json_response(['ok' => false, 'message' => 'Ya existe una carpeta con ese nombre para este puesto.'], 409);
    error_log('PMI folders: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudo guardar la carpeta. Revise la migración SQL.'], 500);
}
