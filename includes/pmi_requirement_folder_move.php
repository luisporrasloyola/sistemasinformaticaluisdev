<?php
declare(strict_types=1);

function pmi_requirement_root_items(PDO $pdo, int $workerId, int $positionId): array
{
    $query = $pdo->prepare("SELECT 'record' AS kind, wr.id, wr.sort_order, rc.name
        FROM worker_requirements wr JOIN requirements_catalog rc ON rc.id = wr.requirement_id
        LEFT JOIN pmi_requirement_folder_items fi ON fi.requirement_record_id = wr.id
        WHERE wr.worker_id = ? AND wr.position_id = ? AND fi.folder_id IS NULL
        UNION ALL SELECT 'folder' AS kind, f.id, f.sort_order, f.name
        FROM pmi_requirement_folders f WHERE f.worker_id = ? AND f.position_id = ?
        AND EXISTS (SELECT 1 FROM pmi_requirement_folder_items fi WHERE fi.folder_id = f.id)
        ORDER BY CASE WHEN sort_order = 0 THEN 2147483647 ELSE sort_order END, kind, name, id");
    $query->execute([$workerId, $positionId, $workerId, $positionId]);
    return $query->fetchAll();
}

/** Extrae un registro y conserva el orden de los miembros restantes y sus separadores. */
function pmi_requirement_remove_from_folder(PDO $pdo, int $workerId, int $positionId, int $recordId, string $targetKind, int $targetId, string $placement): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Se requiere una transaccion activa.');
    if (!in_array($placement, ['before', 'after', 'end'], true)) throw new DomainException('Destino no valido.');
    $source = $pdo->prepare('SELECT fi.folder_id FROM pmi_requirement_folder_items fi
        JOIN worker_requirements wr ON wr.id = fi.requirement_record_id
        JOIN pmi_requirement_folders f ON f.id = fi.folder_id
        WHERE wr.id = ? AND wr.worker_id = ? AND wr.position_id = ? AND f.worker_id = ? AND f.position_id = ? FOR UPDATE');
    $source->execute([$recordId, $workerId, $positionId, $workerId, $positionId]);
    $folderId = (int) $source->fetchColumn();
    if (!$folderId) throw new DomainException('El requisito ya no pertenece a una carpeta de este puesto. Actualice la lista.');
    $root = pmi_requirement_root_items($pdo, $workerId, $positionId);
    $destination = count($root);
    if ($placement !== 'end') {
        $destination = null;
        foreach ($root as $index => $item) {
            if ($item['kind'] === $targetKind && (int) $item['id'] === $targetId) {
                $destination = $index + ($placement === 'after' ? 1 : 0);
                break;
            }
        }
        if ($destination === null) throw new DomainException('El destino no pertenece a la lista externa de este puesto.');
    }
    $members = $pdo->prepare('SELECT fi.requirement_record_id FROM pmi_requirement_folder_items fi
        JOIN worker_requirements wr ON wr.id = fi.requirement_record_id JOIN requirements_catalog rc ON rc.id = wr.requirement_id
        WHERE fi.folder_id = ? ORDER BY CASE WHEN fi.sort_order = 0 THEN 2147483647 ELSE fi.sort_order END, rc.name, wr.id FOR UPDATE');
    $members->execute([$folderId]);
    $ids = array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN));
    $index = array_search($recordId, $ids, true);
    if ($index === false) throw new DomainException('El requisito ya no pertenece a la carpeta.');
    $separator = $pdo->prepare("SELECT id FROM pmi_requirement_separators WHERE worker_id = ? AND position_id = ? AND scope_type = 'folder' AND scope_id = ? AND after_item_id = ? FOR UPDATE");
    $separator->execute([$workerId, $positionId, $folderId, $recordId]);
    if ($separatorId = $separator->fetchColumn()) {
        $anchor = $index > 0 ? $ids[$index - 1] : 0;
        $existing = $pdo->prepare("SELECT id FROM pmi_requirement_separators WHERE worker_id = ? AND position_id = ? AND scope_type = 'folder' AND scope_id = ? AND after_item_id = ? FOR UPDATE");
        $existing->execute([$workerId, $positionId, $folderId, $anchor]);
        if ($existing->fetchColumn()) $pdo->prepare('DELETE FROM pmi_requirement_separators WHERE id = ?')->execute([$separatorId]);
        else $pdo->prepare('UPDATE pmi_requirement_separators SET after_item_id = ? WHERE id = ?')->execute([$anchor, $separatorId]);
    }
    $pdo->prepare('DELETE FROM pmi_requirement_folder_items WHERE requirement_record_id = ? AND folder_id = ?')->execute([$recordId, $folderId]);
    array_splice($root, $destination, 0, [['kind' => 'record', 'id' => $recordId]]);
    if (count($ids) === 1) {
        // Sigue el comportamiento existente: una carpeta sin miembros se elimina.
        $pdo->prepare("UPDATE pmi_requirement_separators SET scope_type = 'ungrouped', after_item_id = ? WHERE worker_id = ? AND position_id = ? AND scope_type = 'folders' AND scope_id = 0 AND after_item_id = ?")
            ->execute([$recordId, $workerId, $positionId, $folderId]);
        $pdo->prepare('DELETE FROM pmi_requirement_folders WHERE id = ? AND worker_id = ? AND position_id = ?')->execute([$folderId, $workerId, $positionId]);
        $root = array_values(array_filter($root, static fn(array $item): bool => !($item['kind'] === 'folder' && (int) $item['id'] === $folderId)));
    }
    $updateFolder = $pdo->prepare('UPDATE pmi_requirement_folders SET sort_order = ? WHERE id = ? AND worker_id = ? AND position_id = ?');
    $updateRecord = $pdo->prepare('UPDATE worker_requirements SET sort_order = ? WHERE id = ? AND worker_id = ? AND position_id = ?');
    foreach ($root as $index => $item) {
        ($item['kind'] === 'folder' ? $updateFolder : $updateRecord)->execute([$index + 1, $item['id'], $workerId, $positionId]);
    }
}

/** Agrega un requisito externo al final de una carpeta sin modificar sus archivos. */
function pmi_requirement_add_to_folder(PDO $pdo, int $workerId, int $positionId, int $recordId, int $folderId): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Se requiere una transaccion activa.');
    $folder = $pdo->prepare('SELECT id FROM pmi_requirement_folders WHERE id = ? AND worker_id = ? AND position_id = ? FOR UPDATE');
    $folder->execute([$folderId, $workerId, $positionId]);
    if (!$folder->fetchColumn()) throw new DomainException('La carpeta no pertenece al trabajador y puesto seleccionados.');
    $record = $pdo->prepare('SELECT id FROM worker_requirements WHERE id = ? AND worker_id = ? AND position_id = ? FOR UPDATE');
    $record->execute([$recordId, $workerId, $positionId]);
    if (!$record->fetchColumn()) throw new DomainException('El requisito no pertenece al trabajador y puesto seleccionados.');
    $membership = $pdo->prepare('SELECT folder_id FROM pmi_requirement_folder_items WHERE requirement_record_id = ? FOR UPDATE');
    $membership->execute([$recordId]);
    if ($membership->fetchColumn() !== false) throw new DomainException('El requisito ya pertenece a una carpeta. Actualice la lista.');

    // Los separadores externos permanecen en la lista, en el espacio del registro retirado.
    $root = $pdo->prepare("SELECT 'record' AS kind, wr.id, wr.sort_order, rc.name
        FROM worker_requirements wr JOIN requirements_catalog rc ON rc.id = wr.requirement_id
        LEFT JOIN pmi_requirement_folder_items fi ON fi.requirement_record_id = wr.id
        WHERE wr.worker_id = ? AND wr.position_id = ? AND fi.folder_id IS NULL
        UNION ALL SELECT 'folder' AS kind, f.id, f.sort_order, f.name
        FROM pmi_requirement_folders f WHERE f.worker_id = ? AND f.position_id = ?
        AND EXISTS (SELECT 1 FROM pmi_requirement_folder_items fi WHERE fi.folder_id = f.id)
        ORDER BY CASE WHEN sort_order = 0 THEN 2147483647 ELSE sort_order END, kind, name, id");
    $root->execute([$workerId, $positionId, $workerId, $positionId]);
    $previous = null;
    foreach ($root->fetchAll() as $item) {
        if ($item['kind'] === 'record' && (int) $item['id'] === $recordId) break;
        $previous = $item;
    }
    $scope = $previous && $previous['kind'] === 'folder' ? 'folders' : 'ungrouped';
    $anchor = $previous ? (int) $previous['id'] : 0;
    $separator = $pdo->prepare("SELECT id FROM pmi_requirement_separators WHERE worker_id = ? AND position_id = ? AND scope_type = 'ungrouped' AND scope_id = 0 AND after_item_id = ? FOR UPDATE");
    $separator->execute([$workerId, $positionId, $recordId]);
    if ($separatorId = $separator->fetchColumn()) {
        $existing = $pdo->prepare('SELECT id FROM pmi_requirement_separators WHERE worker_id = ? AND position_id = ? AND scope_type = ? AND scope_id = 0 AND after_item_id = ? FOR UPDATE');
        $existing->execute([$workerId, $positionId, $scope, $anchor]);
        if ($existing->fetchColumn()) {
            $pdo->prepare('DELETE FROM pmi_requirement_separators WHERE id = ?')->execute([$separatorId]);
        } else {
            $pdo->prepare('UPDATE pmi_requirement_separators SET scope_type = ?, after_item_id = ? WHERE id = ?')->execute([$scope, $anchor, $separatorId]);
        }
    }

    $members = $pdo->prepare('SELECT fi.requirement_record_id FROM pmi_requirement_folder_items fi
        JOIN worker_requirements wr ON wr.id = fi.requirement_record_id
        JOIN requirements_catalog rc ON rc.id = wr.requirement_id
        WHERE fi.folder_id = ? ORDER BY CASE WHEN fi.sort_order = 0 THEN 2147483647 ELSE fi.sort_order END, rc.name, wr.id FOR UPDATE');
    $members->execute([$folderId]);
    $ids = array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN));
    $update = $pdo->prepare('UPDATE pmi_requirement_folder_items SET sort_order = ? WHERE folder_id = ? AND requirement_record_id = ?');
    foreach ($ids as $index => $id) $update->execute([$index + 1, $folderId, $id]);
    $pdo->prepare('INSERT INTO pmi_requirement_folder_items (requirement_record_id, folder_id, sort_order) VALUES (?, ?, ?)')
        ->execute([$recordId, $folderId, count($ids) + 1]);
}
