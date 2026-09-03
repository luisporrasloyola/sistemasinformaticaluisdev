<?php
require_once __DIR__ . '/../../../includes/security.php';
require_once __DIR__ . '/../../../includes/upload.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/file_safety.php';
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

if (!current_user_can_document('empresa_maquirenta.pmi_individual', $requirementId, 'upload')) {
    json_response(['ok' => false, 'message' => 'No tiene permisos para guardar este requisito.'], 403);
}

if (strtotime($endDate) < strtotime($startDate)) {
    json_response(['ok' => false, 'message' => 'La fecha fin no puede ser menor a la fecha inicio.'], 400);
}

try {
    $pdf = upload_file($_FILES['pdf'] ?? [], 'empresa_maquirenta/requisitos', [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ]);
    $pdo = db();
    $currentUserId = (int) (current_user()['id'] ?? 0) ?: null;
    $cleanObservation = static function (string $value): string {
        $value = trim($value);
        if (preg_match('/^(?:Administrador|Gestor) .+ tiene esta observaci[oó]n:\R(.*)$/us', $value, $matches)) {
            return trim((string) ($matches[1] ?? ''));
        }
        return $value;
    };

    if ($id > 0) {
        $currentStmt = $pdo->prepare('SELECT wr.*, rc.name AS requirement_name, registered_by.role AS registered_by_role
            FROM empresa_maquirenta_formato_requisitos wr
            LEFT JOIN empresa_maquirenta_formato_requisitos_catalogo rc ON rc.id = wr.requirement_id
            LEFT JOIN users registered_by ON registered_by.id = wr.registered_by_user_id
            WHERE wr.id = :id');
        $currentStmt->execute(['id' => $id]);
        $current = $currentStmt->fetch();
        if (!$current) {
            json_response(['ok' => false, 'message' => 'El requisito no existe.'], 404);
        }

        $registeredByAdmin = in_array(
            mb_strtolower(trim((string) ($current['registered_by_role'] ?? '')), 'UTF-8'),
            ['admin', 'administrador'],
            true
        );
        $canEditObservations = is_admin() || (is_gestor_role() && !$registeredByAdmin);
        $postedObservation = $cleanObservation((string) ($_POST['observations'] ?? ''));
        $hasNewObservation = $postedObservation !== '';
        if ($hasNewObservation && !$canEditObservations) {
            json_response(['ok' => false, 'message' => 'No tiene autorización para agregar observaciones a este requisito.'], 403);
        }
        $sql = 'UPDATE empresa_maquirenta_formato_requisitos SET requirement_id=:requirement_id, registration_date=:registration_date,
            start_date=:start_date, end_date=:end_date';
        $params = [
            'requirement_id' => $requirementId,
            'registration_date' => $registrationDate,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'id' => $id,
        ];
        if ($canEditObservations && $hasNewObservation) {
            $sql .= ', observations=:observations';
            $params['observations'] = $postedObservation;
        }
        if ($pdf['path']) {
            delete_empresa_maquirenta_formato_file($current['file_path'] ?? null);
            $sql .= ', file_path=:file_path, original_file_name=:original_file_name';
            $params['file_path'] = $pdf['path'];
            $params['original_file_name'] = $pdf['name'];
        }

        $changes = [];
        if ((int) $current['requirement_id'] !== $requirementId) {
            $newRequirementStmt = $pdo->prepare('SELECT name FROM empresa_maquirenta_formato_requisitos_catalogo WHERE id = :id');
            $newRequirementStmt->execute(['id' => $requirementId]);
            $changes[] = 'cambió el requisito de "' . (string) ($current['requirement_name'] ?? '') . '" a "' . (string) $newRequirementStmt->fetchColumn() . '"';
        }
        if ((string) $current['registration_date'] !== $registrationDate) {
            $changes[] = 'cambió F. Registro';
        }
        if ((string) $current['start_date'] !== $startDate) {
            $changes[] = 'cambió F. Inicio';
        }
        if ((string) $current['end_date'] !== $endDate) {
            $changes[] = 'cambió F. Fin';
        }
        $previousObservation = $cleanObservation((string) ($current['observations'] ?? ''));
        $observationChanged = $canEditObservations && $hasNewObservation;
        if ($pdf['path']) {
            $changes[] = 'subió un nuevo archivo adjunto: ' . (string) $pdf['name'];
        }

        $currentObservationStatus = (string) ($current['observation_status'] ?? 'none');
        if ($canEditObservations && $observationChanged) {
            $sql .= ", observation_status=:observation_status, observation_by_user_id=:observation_by_user_id, observation_at=:observation_at,
                observation_resolved_by_user_id=NULL, observation_resolved_at=NULL";
            $params['observation_status'] = $postedObservation !== '' ? 'observed' : 'none';
            $params['observation_by_user_id'] = $postedObservation !== '' ? $currentUserId : null;
            $params['observation_at'] = $postedObservation !== '' ? date('Y-m-d H:i:s') : null;
        } elseif ($changes && in_array($currentObservationStatus, ['observed', 'corrected'], true)) {
            $sql .= ", observation_status='observed'";
        }

        $sql .= ' WHERE id=:id';
        $pdo->prepare($sql)->execute($params);

        if ($observationChanged) {
            $observationLog = $pdo->prepare('INSERT INTO empresa_maquirenta_formato_requisito_actividad
                (worker_requirement_id, user_id, action_type, description, created_at)
                VALUES (:worker_requirement_id, :user_id, :action_type, :description, :created_at)');
            $historyCount = $pdo->prepare("SELECT COUNT(*) FROM empresa_maquirenta_formato_requisito_actividad
                WHERE worker_requirement_id = :id AND action_type = 'observacion_registrada'");
            $historyCount->execute(['id' => $id]);

            if ((int) $historyCount->fetchColumn() === 0 && $previousObservation !== '') {
                $observationLog->execute([
                    'worker_requirement_id' => $id,
                    'user_id' => (int) ($current['observation_by_user_id'] ?? 0) ?: null,
                    'action_type' => 'observacion_registrada',
                    'description' => $previousObservation,
                    'created_at' => $current['observation_at'] ?: date('Y-m-d H:i:s'),
                ]);
            }

            if ($postedObservation !== '') {
                $observationLog->execute([
                    'worker_requirement_id' => $id,
                    'user_id' => $currentUserId,
                    'action_type' => 'observacion_registrada',
                    'description' => $postedObservation,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        if ($changes) {
            $log = $pdo->prepare('INSERT INTO empresa_maquirenta_formato_requisito_actividad (worker_requirement_id, user_id, action_type, description)
                VALUES (:worker_requirement_id, :user_id, :action_type, :description)');
            $log->execute([
                'worker_requirement_id' => $id,
                'user_id' => $currentUserId,
                'action_type' => $pdf['path'] ? 'documento_actualizado' : 'registro_editado',
                'description' => ucfirst(implode('; ', $changes)) . '.',
            ]);
        }
    } else {
        $initialObservation = $cleanObservation((string) ($_POST['observations'] ?? ''));
        $hasInitialObservation = $initialObservation !== '';
        $stmt = $pdo->prepare('INSERT INTO empresa_maquirenta_formato_requisitos
            (worker_id, position_id, requirement_id, registration_date, start_date, end_date, observations, file_path, original_file_name, registered_by_user_id,
                observation_status, observation_by_user_id, observation_at)
            VALUES (:worker_id, :position_id, :requirement_id, :registration_date, :start_date, :end_date, :observations, :file_path, :original_file_name, :registered_by_user_id,
                :observation_status, :observation_by_user_id, :observation_at)');
        $stmt->execute([
            'worker_id' => $workerId,
            'position_id' => $positionId,
            'requirement_id' => $requirementId,
            'registration_date' => $registrationDate,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'observations' => $initialObservation,
            'file_path' => $pdf['path'],
            'original_file_name' => $pdf['name'],
            'registered_by_user_id' => $currentUserId,
            'observation_status' => $hasInitialObservation ? 'observed' : 'none',
            'observation_by_user_id' => $hasInitialObservation ? $currentUserId : null,
            'observation_at' => $hasInitialObservation ? date('Y-m-d H:i:s') : null,
        ]);
        $newId = (int) $pdo->lastInsertId();
        $log = $pdo->prepare('INSERT INTO empresa_maquirenta_formato_requisito_actividad (worker_requirement_id, user_id, action_type, description)
            VALUES (:worker_requirement_id, :user_id, :action_type, :description)');
        $log->execute([
            'worker_requirement_id' => $newId,
            'user_id' => $currentUserId,
            'action_type' => 'registro_creado',
            'description' => $pdf['path'] ? 'Registro creado con archivo adjunto: ' . (string) $pdf['name'] . '.' : 'Registro creado.',
        ]);
        if ($hasInitialObservation) {
            $log->execute([
                'worker_requirement_id' => $newId,
                'user_id' => $currentUserId,
                'action_type' => 'observacion_registrada',
                'description' => $initialObservation,
            ]);
        }
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
