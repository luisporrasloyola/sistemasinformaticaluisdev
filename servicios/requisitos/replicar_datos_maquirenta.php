<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
}
verify_csrf($_POST['csrf_token'] ?? null);
$selection = json_decode((string) ($_POST['selection'] ?? ''), true);
if (!is_array($selection) || !$selection) {
    json_response(['ok' => false, 'message' => 'No hay personal seleccionado para replicar.'], 422);
}

$pdo = db();
$stats = ['workers' => 0, 'positions' => 0, 'requirements' => 0];
try {
    $pdo->beginTransaction();
    foreach ($selection as $selectedWorker) {
        $workerId = (int) ($selectedWorker['worker_id'] ?? 0);
        $selectedPositions = is_array($selectedWorker['positions'] ?? null) ? $selectedWorker['positions'] : [];
        if ($workerId <= 0 || !$selectedPositions) continue;

        $stmt = $pdo->prepare('SELECT w.*, c.name AS company_name FROM workers w LEFT JOIN companies c ON c.id=w.company_id WHERE w.id=? FOR UPDATE');
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
        if (!$worker) throw new RuntimeException('Uno de los trabajadores seleccionados ya no existe.');

        $companyId = null;
        if (!empty($worker['company_name'])) {
            $stmt = $pdo->prepare('INSERT INTO empresa_maquirenta_formato_empresas (name,status) VALUES (?,1) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),status=1');
            $stmt->execute([$worker['company_name']]);
            $companyId = (int) $pdo->lastInsertId();
        }
        $stmt = $pdo->prepare('INSERT INTO empresa_maquirenta_formato_personal
            (company_id,full_name,document_type,document_number,blood_type,address,phone,email,birth_date,personal_observations,status,photo_path,signature_path)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),company_id=VALUES(company_id),full_name=VALUES(full_name),document_type=VALUES(document_type),blood_type=VALUES(blood_type),address=VALUES(address),phone=VALUES(phone),email=VALUES(email),birth_date=VALUES(birth_date),personal_observations=VALUES(personal_observations),status=VALUES(status),photo_path=VALUES(photo_path),signature_path=VALUES(signature_path)');
        $stmt->execute([$companyId,$worker['full_name'],$worker['document_type'],$worker['document_number'],$worker['blood_type'],$worker['address'],$worker['phone'],$worker['email'],$worker['birth_date'],$worker['personal_observations'],$worker['status'],$worker['photo_path'],$worker['signature_path']]);
        $targetWorkerId = (int) $pdo->lastInsertId();
        $stats['workers']++;

        foreach ($selectedPositions as $selectedPosition) {
            $positionId = (int) ($selectedPosition['position_id'] ?? 0);
            if ($positionId <= 0) continue;
            $stmt = $pdo->prepare('SELECT p.name FROM worker_positions wp JOIN positions p ON p.id=wp.position_id WHERE wp.worker_id=? AND wp.position_id=?');
            $stmt->execute([$workerId,$positionId]);
            $positionName = $stmt->fetchColumn();
            if (!$positionName) continue;
            $stmt = $pdo->prepare('INSERT INTO empresa_maquirenta_formato_puestos (name,status) VALUES (?,1) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),status=1');
            $stmt->execute([$positionName]);
            $targetPositionId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT IGNORE INTO empresa_maquirenta_formato_personal_puestos (worker_id,position_id) VALUES (?,?)')->execute([$targetWorkerId,$targetPositionId]);
            $stats['positions']++;

            $requirementIds = array_values(array_unique(array_filter(array_map('intval', $selectedPosition['requirement_ids'] ?? []))));
            foreach ($requirementIds as $sourceRequirementId) {
                $stmt = $pdo->prepare('SELECT wr.*, rc.name AS requirement_name FROM worker_requirements wr JOIN requirements_catalog rc ON rc.id=wr.requirement_id WHERE wr.id=? AND wr.worker_id=? AND wr.position_id=?');
                $stmt->execute([$sourceRequirementId,$workerId,$positionId]);
                $requirement = $stmt->fetch();
                if (!$requirement) continue;
                $stmt = $pdo->prepare('INSERT INTO empresa_maquirenta_formato_requisitos_catalogo (name,status) VALUES (?,1) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),status=1');
                $stmt->execute([$requirement['requirement_name']]);
                $targetRequirementCatalogId = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT IGNORE INTO empresa_maquirenta_formato_puesto_requisitos (position_id,requirement_id) VALUES (?,?)')->execute([$targetPositionId,$targetRequirementCatalogId]);
                $stmt = $pdo->prepare('INSERT INTO empresa_maquirenta_formato_requisitos
                    (worker_id,position_id,requirement_id,registration_date,start_date,end_date,observations,observation_status,observation_by_user_id,observation_at,observation_resolved_by_user_id,observation_resolved_at,file_path,original_file_name,registered_by_user_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),registration_date=VALUES(registration_date),start_date=VALUES(start_date),end_date=VALUES(end_date),observations=VALUES(observations),observation_status=VALUES(observation_status),observation_by_user_id=VALUES(observation_by_user_id),observation_at=VALUES(observation_at),observation_resolved_by_user_id=VALUES(observation_resolved_by_user_id),observation_resolved_at=VALUES(observation_resolved_at),file_path=VALUES(file_path),original_file_name=VALUES(original_file_name),registered_by_user_id=VALUES(registered_by_user_id)');
                $stmt->execute([$targetWorkerId,$targetPositionId,$targetRequirementCatalogId,$requirement['registration_date'],$requirement['start_date'],$requirement['end_date'],$requirement['observations'],$requirement['observation_status'],$requirement['observation_by_user_id'],$requirement['observation_at'],$requirement['observation_resolved_by_user_id'],$requirement['observation_resolved_at'],$requirement['file_path'],$requirement['original_file_name'],$requirement['registered_by_user_id']]);
                $targetRequirementId = (int) $pdo->lastInsertId();
                $pdo->prepare('DELETE FROM empresa_maquirenta_formato_requisito_actividad WHERE worker_requirement_id=?')->execute([$targetRequirementId]);
                $activity = $pdo->prepare('SELECT user_id,action_type,description,created_at FROM worker_requirement_activity_log WHERE worker_requirement_id=? ORDER BY id');
                $activity->execute([$sourceRequirementId]);
                $insertActivity = $pdo->prepare('INSERT INTO empresa_maquirenta_formato_requisito_actividad (worker_requirement_id,user_id,action_type,description,created_at) VALUES (?,?,?,?,?)');
                foreach ($activity->fetchAll() as $entry) $insertActivity->execute([$targetRequirementId,$entry['user_id'],$entry['action_type'],$entry['description'],$entry['created_at']]);
                $stats['requirements']++;
            }
        }
    }
    if ($stats['workers'] === 0) throw new RuntimeException('Debe conservar al menos un trabajador y uno de sus puestos.');
    $pdo->commit();
    json_response(['ok' => true, 'message' => 'La información fue replicada correctamente.', 'stats' => $stats]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}