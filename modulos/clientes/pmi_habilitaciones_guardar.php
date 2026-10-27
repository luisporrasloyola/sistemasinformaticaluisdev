<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('modulos/clientes/pmi.php'); }
verify_csrf($_POST['csrf_token'] ?? null);
$clientId = max(0, (int) ($_POST['client_id'] ?? 0));
$target = 'modulos/clientes/pmi.php?cliente_id=' . $clientId . '&tab=personal';
$target .= '&habilitacion_area=' . (($_POST['habilitacion_area'] ?? 'personal') === 'equipo' ? 'equipo' : 'personal');
try {
    $check = db()->prepare('SELECT 1 FROM clients WHERE id = ?');
    $check->execute([$clientId]);
    if (!$check->fetchColumn()) { throw new DomainException('Cliente no encontrado.'); }
    $id = max(0, (int) ($_POST['id'] ?? 0));
    if (($_POST['action'] ?? '') === 'delete') {
        $fileStmt = db()->prepare('SELECT pass_file_path FROM client_pmi_habilitations WHERE id = ? AND client_id = ?');
        $fileStmt->execute([$id, $clientId]);
        $passFile = $fileStmt->fetchColumn();
        $stmt = db()->prepare('DELETE FROM client_pmi_habilitations WHERE id = ? AND client_id = ?');
        $stmt->execute([$id, $clientId]);
        if ($stmt->rowCount() && $passFile) { delete_uploaded_file((string) $passFile); }
    } elseif (($_POST['action'] ?? '') === 'save') {
        $area = (string) ($_POST['area'] ?? '');
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $isGroup = $area === 'personal' && ($_POST['habilitation_type'] ?? '') === 'grupal';
        if (!in_array($area, ['personal', 'equipo'], true) || (!$isGroup && $subjectId < 1)) { throw new DomainException('Seleccione personal o equipo.'); }
        $habilitationType = $area === 'personal' ? (string) ($_POST['habilitation_type'] ?? '') : 'individual';
        $hasPass = $area === 'personal' ? (string) ($_POST['has_pass'] ?? '') : null;
        $hasValidity = $area === 'personal' ? (string) ($_POST['has_validity'] ?? '') : null;
        if ($area === 'personal' && (!in_array($habilitationType, ['individual', 'grupal'], true) || !in_array($hasPass, ['0', '1'], true) || !in_array($hasValidity, ['0', '1'], true))) { throw new DomainException('Seleccione el tipo de habilitación y las opciones de pase y vigencia.'); }
        if (!$isGroup) {
            $table = $area === 'personal' ? 'workers' : 'maquinarias';
            $stmt = db()->prepare("SELECT 1 FROM {$table} WHERE id = ?");
            $stmt->execute([$subjectId]);
            if (!$stmt->fetchColumn()) { throw new DomainException('El nombre seleccionado ya no está disponible.'); }
        }
        $memberPositions = [];
        if ($isGroup) {
            $submitted = $_POST['member_ids'] ?? [];
            if (!is_array($submitted) || count($submitted) > 500) { throw new DomainException('Seleccione integrantes válidos.'); }
            $memberIds = array_values(array_unique(array_filter(array_map('intval', $submitted), static fn(int $value): bool => $value > 0)));
            if (!$memberIds || ($id && count($memberIds) !== 1)) { throw new DomainException('Seleccione al menos un integrante; al editar solo puede elegir uno.'); }
            $submittedPositions = $_POST['member_positions'] ?? [];
            if (!is_array($submittedPositions) || count($submittedPositions) > 500) { throw new DomainException('Seleccione puestos válidos para los integrantes.'); }
            $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
            $memberStmt = db()->prepare("SELECT w.id AS worker_id, gp.position_id FROM workers w JOIN worker_positions wp ON wp.worker_id = w.id JOIN client_pmi_general_positions gp ON gp.position_id = wp.position_id AND gp.client_id = ? WHERE w.id IN ($placeholders)");
            $memberStmt->execute(array_merge([$clientId], $memberIds));
            $allowedPositions = [];
            foreach ($memberStmt as $row) { $allowedPositions[(int) $row['worker_id']][(int) $row['position_id']] = true; }
            foreach ($memberIds as $memberId) {
                $chosen = $submittedPositions[$memberId] ?? [];
                if (!is_array($chosen) || !$chosen || count($chosen) > 200) { throw new DomainException('Seleccione al menos un puesto por integrante.'); }
                $positionIdsForMember = array_values(array_unique(array_filter(array_map('intval', $chosen), static fn(int $value): bool => $value > 0)));
                if (!$positionIdsForMember || count(array_diff_key(array_fill_keys($positionIdsForMember, true), $allowedPositions[$memberId] ?? []))) { throw new DomainException('Un puesto seleccionado no corresponde al trabajador o al cliente.'); }
                $memberPositions[$memberId] = $positionIdsForMember;
            }
            if ($id) { $subjectId = $memberIds[0]; }
        }
        $positionIds = [];
        if ($area === 'personal' && !$isGroup) {
            $submitted = $_POST['position_ids'] ?? [];
            if (!is_array($submitted) || count($submitted) > 200) { throw new DomainException('Seleccione puestos válidos.'); }
            $positionIds = array_values(array_unique(array_filter(array_map('intval', $submitted), static fn(int $value): bool => $value > 0)));
            if (!$positionIds) { throw new DomainException('Seleccione al menos un puesto.'); }
            $placeholders = implode(',', array_fill(0, count($positionIds), '?'));
            $validPositions = db()->prepare("SELECT COUNT(*) FROM worker_positions wp JOIN client_pmi_general_positions gp ON gp.position_id = wp.position_id AND gp.client_id = ? WHERE wp.worker_id = ? AND wp.position_id IN ($placeholders)");
            $validPositions->execute(array_merge([$clientId, $subjectId], $positionIds));
            if ((int) $validPositions->fetchColumn() !== count($positionIds)) { throw new DomainException('Seleccione solo puestos del trabajador habilitados para este cliente.'); }
        }
        $dates = ['registration_date' => null, 'start_date' => null, 'end_date' => null];
        if ($area === 'equipo' || $hasValidity === '1') {
            foreach (array_keys($dates) as $field) {
                $value = (string) ($_POST[$field] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || DateTime::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') !== $value) { throw new DomainException('Ingrese fechas válidas.'); }
                $dates[$field] = $value;
            }
            if ($dates['end_date'] < $dates['start_date']) { throw new DomainException('La fecha de fin debe ser posterior al inicio.'); }
        }
        db()->beginTransaction();
        if ($isGroup && !$id) {
            $existingStmt = db()->prepare("SELECT subject_id FROM client_pmi_habilitations WHERE client_id = ? AND area = 'personal' AND subject_id IN ($placeholders)");
            $existingStmt->execute(array_merge([$clientId], $memberIds));
            $existingIds = array_fill_keys(array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN)), true);
            $insert = db()->prepare('INSERT INTO client_pmi_habilitations (client_id, area, subject_id, habilitation_type, has_pass, has_validity, registration_date, start_date, end_date, registered_by_user_id) VALUES (:client_id, :area, :subject_id, :habilitation_type, :has_pass, :has_validity, :registration_date, :start_date, :end_date, :user_id)');
            $insertPosition = db()->prepare('INSERT INTO client_pmi_habilitation_positions (habilitation_id, position_id) VALUES (?, ?)');
            $created = 0;
            foreach ($memberIds as $memberId) {
                if (isset($existingIds[$memberId])) { continue; }
                $insert->execute($dates + ['client_id' => $clientId, 'area' => 'personal', 'subject_id' => $memberId, 'habilitation_type' => 'grupal', 'has_pass' => (int) $hasPass, 'has_validity' => (int) $hasValidity, 'user_id' => (int) ($_SESSION['user']['id'] ?? 0) ?: null]);
                $habilitationId = (int) db()->lastInsertId();
                foreach ($memberPositions[$memberId] as $positionId) { $insertPosition->execute([$habilitationId, $positionId]); }
                ++$created;
            }
            if (!$created) { throw new DomainException('Todos los integrantes seleccionados ya tienen una habilitación para este cliente.'); }
            db()->commit();
            redirect($target);
        }
        if ($id) {
            $existing = db()->prepare('SELECT 1 FROM client_pmi_habilitations WHERE id = ? AND client_id = ? FOR UPDATE');
            $existing->execute([$id, $clientId]);
            if (!$existing->fetchColumn()) { throw new DomainException('Habilitación no encontrada.'); }
            $stmt = db()->prepare('UPDATE client_pmi_habilitations SET area = :area, subject_id = :subject_id, habilitation_type = :habilitation_type, has_pass = :has_pass, has_validity = :has_validity, registration_date = :registration_date, start_date = :start_date, end_date = :end_date WHERE id = :id AND client_id = :client_id');
            $stmt->execute($dates + ['area' => $area, 'subject_id' => $subjectId, 'habilitation_type' => $habilitationType, 'has_pass' => $hasPass === null ? null : (int) $hasPass, 'has_validity' => $hasValidity === null ? null : (int) $hasValidity, 'id' => $id, 'client_id' => $clientId]);
        } else {
            $stmt = db()->prepare('INSERT INTO client_pmi_habilitations (client_id, area, subject_id, habilitation_type, has_pass, has_validity, registration_date, start_date, end_date, registered_by_user_id) VALUES (:client_id, :area, :subject_id, :habilitation_type, :has_pass, :has_validity, :registration_date, :start_date, :end_date, :user_id)');
            $stmt->execute($dates + ['client_id' => $clientId, 'area' => $area, 'subject_id' => $subjectId, 'habilitation_type' => $habilitationType, 'has_pass' => $hasPass === null ? null : (int) $hasPass, 'has_validity' => $hasValidity === null ? null : (int) $hasValidity, 'user_id' => (int) ($_SESSION['user']['id'] ?? 0) ?: null]);
            $id = (int) db()->lastInsertId();
        }
        db()->prepare('DELETE FROM client_pmi_habilitation_positions WHERE habilitation_id = ?')->execute([$id]);
        if ($area === 'personal') {
            if ($isGroup) { $positionIds = array_values($memberPositions[$subjectId]); }
            $insertPosition = db()->prepare('INSERT INTO client_pmi_habilitation_positions (habilitation_id, position_id) VALUES (?, ?)');
            foreach ($positionIds as $positionId) { $insertPosition->execute([$id, $positionId]); }
        }
        db()->commit();
    } else { throw new DomainException('Acción inválida.'); }
} catch (DomainException $e) {
    if (db()->inTransaction()) { db()->rollBack(); }
    $target .= '&error=' . rawurlencode($e->getMessage());
} catch (PDOException $e) {
    if (db()->inTransaction()) { db()->rollBack(); }
    $target .= '&error=' . rawurlencode($e->getCode() === '23000' ? 'Ya existe una habilitación para ese nombre.' : 'No se pudo guardar la habilitación.');
}
redirect($target);
