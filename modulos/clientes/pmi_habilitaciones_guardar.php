<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('modulos/clientes/pmi.php'); }
verify_csrf($_POST['csrf_token'] ?? null);
$clientId = max(0, (int) ($_POST['client_id'] ?? 0));
$target = 'modulos/clientes/pmi.php?cliente_id=' . $clientId . '&tab=personal';
try {
    $check = db()->prepare('SELECT 1 FROM clients WHERE id = ?');
    $check->execute([$clientId]);
    if (!$check->fetchColumn()) { throw new DomainException('Cliente no encontrado.'); }
    $id = max(0, (int) ($_POST['id'] ?? 0));
    if (($_POST['action'] ?? '') === 'delete') {
        $stmt = db()->prepare('DELETE FROM client_pmi_habilitations WHERE id = ? AND client_id = ?');
        $stmt->execute([$id, $clientId]);
    } elseif (($_POST['action'] ?? '') === 'save') {
        $area = (string) ($_POST['area'] ?? '');
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        if (!in_array($area, ['personal', 'equipo'], true) || $subjectId < 1) { throw new DomainException('Seleccione personal o equipo.'); }
        $table = $area === 'personal' ? 'workers' : 'maquinarias';
        $stmt = db()->prepare("SELECT 1 FROM {$table} WHERE id = ?");
        $stmt->execute([$subjectId]);
        if (!$stmt->fetchColumn()) { throw new DomainException('El nombre seleccionado ya no está disponible.'); }
        $positionIds = [];
        if ($area === 'personal') {
            $submitted = $_POST['position_ids'] ?? [];
            if (!is_array($submitted) || count($submitted) > 200) { throw new DomainException('Seleccione puestos válidos.'); }
            $positionIds = array_values(array_unique(array_filter(array_map('intval', $submitted), static fn(int $value): bool => $value > 0)));
            if (!$positionIds) { throw new DomainException('Seleccione al menos un puesto.'); }
            $placeholders = implode(',', array_fill(0, count($positionIds), '?'));
            $validPositions = db()->prepare("SELECT COUNT(*) FROM worker_positions WHERE worker_id = ? AND position_id IN ($placeholders)");
            $validPositions->execute(array_merge([$subjectId], $positionIds));
            if ((int) $validPositions->fetchColumn() !== count($positionIds)) { throw new DomainException('Uno de los puestos seleccionados ya no pertenece al trabajador.'); }
        }
        $dates = [];
        foreach (['registration_date', 'start_date', 'end_date'] as $field) {
            $value = (string) ($_POST[$field] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || DateTime::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') !== $value) { throw new DomainException('Ingrese fechas válidas.'); }
            $dates[$field] = $value;
        }
        if ($dates['end_date'] < $dates['start_date']) { throw new DomainException('La fecha de fin debe ser posterior al inicio.'); }
        db()->beginTransaction();
        if ($id) {
            $existing = db()->prepare('SELECT 1 FROM client_pmi_habilitations WHERE id = ? AND client_id = ? FOR UPDATE');
            $existing->execute([$id, $clientId]);
            if (!$existing->fetchColumn()) { throw new DomainException('Habilitación no encontrada.'); }
            $stmt = db()->prepare('UPDATE client_pmi_habilitations SET area = :area, subject_id = :subject_id, registration_date = :registration_date, start_date = :start_date, end_date = :end_date WHERE id = :id AND client_id = :client_id');
            $stmt->execute($dates + ['area' => $area, 'subject_id' => $subjectId, 'id' => $id, 'client_id' => $clientId]);
        } else {
            $stmt = db()->prepare('INSERT INTO client_pmi_habilitations (client_id, area, subject_id, registration_date, start_date, end_date, registered_by_user_id) VALUES (:client_id, :area, :subject_id, :registration_date, :start_date, :end_date, :user_id)');
            $stmt->execute($dates + ['client_id' => $clientId, 'area' => $area, 'subject_id' => $subjectId, 'user_id' => (int) ($_SESSION['user']['id'] ?? 0) ?: null]);
            $id = (int) db()->lastInsertId();
        }
        db()->prepare('DELETE FROM client_pmi_habilitation_positions WHERE habilitation_id = ?')->execute([$id]);
        if ($area === 'personal') {
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
