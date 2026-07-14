<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$workerId = (int) ($_POST['worker_id'] ?? 0);
$positionId = (int) ($_POST['position_id'] ?? 0);
$requirementId = (int) ($_POST['requirement_id'] ?? 0);
$registrationDate = (string) ($_POST['registration_date'] ?? '');
$startDate = (string) ($_POST['start_date'] ?? '');
$endDate = (string) ($_POST['end_date'] ?? '');

if (!$workerId || !$positionId || !$requirementId || !$registrationDate || !$startDate || !$endDate) {
    json_response(['ok' => false, 'message' => 'Complete todos los campos obligatorios.'], 400);
}

if (!current_user_can_document('requisitos.pmi_individual', $requirementId, 'upload')) {
    json_response(['ok' => false, 'message' => 'No tiene permisos para guardar este requisito.'], 403);
}

if (strtotime($endDate) < strtotime($startDate)) {
    json_response(['ok' => false, 'message' => 'La fecha fin no puede ser menor a la fecha inicio.'], 400);
}

try {
    $pdf = upload_file($_FILES['pdf'] ?? [], 'requisitos', ['application/pdf']);

    if ($id > 0) {
        $currentStmt = db()->prepare('SELECT file_path FROM worker_requirements WHERE id = :id');
        $currentStmt->execute(['id' => $id]);
        $current = $currentStmt->fetch();

        $sql = 'UPDATE worker_requirements SET requirement_id=:requirement_id, registration_date=:registration_date,
            start_date=:start_date, end_date=:end_date, observations=:observations';
        $params = [
            'requirement_id' => $requirementId,
            'registration_date' => $registrationDate,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'observations' => trim((string) ($_POST['observations'] ?? '')),
            'id' => $id,
        ];
        if ($pdf['path']) {
            delete_uploaded_file($current['file_path'] ?? null);
            $sql .= ', file_path=:file_path, original_file_name=:original_file_name';
            $params['file_path'] = $pdf['path'];
            $params['original_file_name'] = $pdf['name'];
        }
        $sql .= ' WHERE id=:id';
        db()->prepare($sql)->execute($params);
    } else {
        $stmt = db()->prepare('INSERT INTO worker_requirements
            (worker_id, position_id, requirement_id, registration_date, start_date, end_date, observations, file_path, original_file_name, registered_by_user_id)
            VALUES (:worker_id, :position_id, :requirement_id, :registration_date, :start_date, :end_date, :observations, :file_path, :original_file_name, :registered_by_user_id)');
        $stmt->execute([
            'worker_id' => $workerId,
            'position_id' => $positionId,
            'requirement_id' => $requirementId,
            'registration_date' => $registrationDate,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'observations' => trim((string) ($_POST['observations'] ?? '')),
            'file_path' => $pdf['path'],
            'original_file_name' => $pdf['name'],
            'registered_by_user_id' => (int) (current_user()['id'] ?? 0) ?: null,
        ]);
    }
    json_response(['ok' => true]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_response(['ok' => false, 'message' => 'Este requisito ya existe para el trabajador y puesto seleccionados.'], 409);
    }
    json_response(['ok' => false, 'message' => 'No se pudo guardar el requisito.'], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
