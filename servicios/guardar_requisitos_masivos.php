<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../config/database.php';
require_login();

verify_csrf($_POST['csrf_token'] ?? null);

$rows = $_POST['rows'] ?? [];
$bulkRequirements = array_values(array_unique(array_map('intval', $_POST['bulk_requirements'] ?? [])));
$allowedRequirements = db()->query("SELECT id FROM requirements_catalog WHERE name IN ('SCTR', 'VIDA LEY')")->fetchAll(PDO::FETCH_COLUMN);
$allowedRequirements = array_map('intval', $allowedRequirements);
$bulkRequirements = array_values(array_intersect($bulkRequirements, $allowedRequirements));
$registrationDate = (string) ($_POST['registration_date'] ?? '');
$startDate = (string) ($_POST['start_date'] ?? '');
$endDate = (string) ($_POST['end_date'] ?? '');
$saved = 0;
$errors = [];
$bulkUploads = [];

if (!$rows) {
    json_response(['ok' => false, 'message' => 'No hay registros para guardar.'], 400);
}

$selectedRows = array_filter($rows, static fn ($row) => !empty($row['selected']));
if (!$selectedRows) {
    json_response(['ok' => false, 'message' => 'Seleccione al menos un registro.'], 400);
}


if (!$bulkRequirements) {
    json_response(['ok' => false, 'message' => 'Seleccione SCTR o VIDA LEY en la aplicación masiva.'], 400);
}

if (!$registrationDate || !$startDate || !$endDate) {
    json_response(['ok' => false, 'message' => 'Complete F. Registro, F. Inicio y F. Fin en la aplicación masiva.'], 400);
}

if (strtotime($endDate) < strtotime($startDate)) {
    json_response(['ok' => false, 'message' => 'F. Fin no puede ser menor a F. Inicio.'], 400);
}
try {
    if ($bulkRequirements) {
        $bulkFiles = $_FILES['bulk_documents'] ?? [];
        foreach ($bulkRequirements as $requirementId) {
            $bulkFile = normalize_row_file($bulkFiles, $requirementId);
            if (!$bulkFile) {
                json_response(['ok' => false, 'message' => 'Adjunte el documento PDF para cada requisito masivo seleccionado.'], 400);
            }
            $bulkUploads[$requirementId] = upload_file($bulkFile, 'requisitos', ['application/pdf']);
        }
    }

    db()->beginTransaction();

    $positionsStmt = db()->prepare('SELECT position_id FROM worker_positions WHERE worker_id = :worker_id ORDER BY position_id');

    foreach ($rows as $index => $row) {
        if (empty($row['selected'])) {
            continue;
        }

        $workerId = (int) ($row['worker_id'] ?? 0);
        if (!$workerId) {
            $errors[] = 'Fila ' . ((int) $index + 1) . ': trabajador inválido.';
            continue;
        }

        $positionsStmt->execute(['worker_id' => $workerId]);
        $positionIds = array_map('intval', $positionsStmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$positionIds) {
            $errors[] = 'Fila ' . ((int) $index + 1) . ': el trabajador no tiene puestos de trabajo asignados.';
            continue;
        }

        foreach ($positionIds as $positionId) {
            foreach ($bulkRequirements as $requirementId) {
                $uploaded = [
                    'path' => duplicate_uploaded_file($bulkUploads[$requirementId]['path'], 'requisitos'),
                    'name' => $bulkUploads[$requirementId]['name'],
                ];
                save_requirement_record($workerId, $positionId, $requirementId, $registrationDate, $startDate, $endDate, $uploaded);
                $saved++;
            }
        }
    }

    if ($saved === 0) {
        db()->rollBack();
        cleanup_bulk_uploads($bulkUploads);
        json_response(['ok' => false, 'message' => $errors ? implode("\n", $errors) : 'Seleccione al menos un registro.'], 400);
    }

    db()->commit();
    cleanup_bulk_uploads($bulkUploads);
    json_response(['ok' => true, 'saved' => $saved, 'errors' => $errors]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    cleanup_bulk_uploads($bulkUploads);
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}

function save_requirement_record(int $workerId, int $positionId, int $requirementId, string $registrationDate, string $startDate, string $endDate, array $uploaded): void
{
    $currentStmt = db()->prepare('SELECT id, file_path, observation_status FROM worker_requirements
        WHERE worker_id = :worker_id AND position_id = :position_id AND requirement_id = :requirement_id
        LIMIT 1');
    $currentStmt->execute([
        'worker_id' => $workerId,
        'position_id' => $positionId,
        'requirement_id' => $requirementId,
    ]);
    $current = $currentStmt->fetch();

    if ($current) {
        delete_uploaded_file($current['file_path'] ?? null);
        $extraStatusSql = in_array((string) ($current['observation_status'] ?? 'none'), ['observed', 'corrected'], true)
            ? ", observation_status = 'corrected'"
            : '';
        $stmt = db()->prepare('UPDATE worker_requirements
            SET registration_date = :registration_date, start_date = :start_date, end_date = :end_date,
                observations = :observations, file_path = :file_path, original_file_name = :original_file_name' . $extraStatusSql . '
            WHERE id = :id');
        $stmt->execute([
            'registration_date' => $registrationDate,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'observations' => 'Carga masiva PMI',
            'file_path' => $uploaded['path'],
            'original_file_name' => $uploaded['name'],
            'id' => (int) $current['id'],
        ]);
        log_bulk_requirement_activity((int) $current['id'], 'documento_actualizado', 'Carga masiva PMI actualizó el documento PDF: ' . (string) $uploaded['name'] . '.');
        return;
    }

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
        'observations' => 'Carga masiva PMI',
        'file_path' => $uploaded['path'],
        'original_file_name' => $uploaded['name'],
        'registered_by_user_id' => (int) (current_user()['id'] ?? 0) ?: null,
    ]);
    log_bulk_requirement_activity((int) db()->lastInsertId(), 'registro_creado', 'Carga masiva PMI creó el registro con documento PDF: ' . (string) $uploaded['name'] . '.');
}

function log_bulk_requirement_activity(int $requirementRowId, string $actionType, string $description): void
{
    if ($requirementRowId <= 0) {
        return;
    }

    $stmt = db()->prepare('INSERT INTO worker_requirement_activity_log (worker_requirement_id, user_id, action_type, description)
        VALUES (:worker_requirement_id, :user_id, :action_type, :description)');
    $stmt->execute([
        'worker_requirement_id' => $requirementRowId,
        'user_id' => (int) (current_user()['id'] ?? 0) ?: null,
        'action_type' => $actionType,
        'description' => $description,
    ]);
}

function normalize_row_file(array $files, int $index): ?array
{
    if (!isset($files['name'][$index])) {
        return null;
    }

    return [
        'name' => $files['name'][$index],
        'type' => $files['type'][$index] ?? '',
        'tmp_name' => $files['tmp_name'][$index] ?? '',
        'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$index] ?? 0,
    ];
}

function duplicate_uploaded_file(string $relativePath, string $folder): string
{
    $root = realpath(UPLOAD_PATH);
    $source = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath);

    if (!$root || !$source || !str_starts_with($source, $root) || !is_file($source)) {
        throw new RuntimeException('No se pudo preparar el documento masivo.');
    }

    $extension = pathinfo($source, PATHINFO_EXTENSION) ?: 'pdf';
    $dir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $safeName = bin2hex(random_bytes(16)) . '.' . $extension;
    $target = $dir . DIRECTORY_SEPARATOR . $safeName;
    if (!copy($source, $target)) {
        throw new RuntimeException('No se pudo copiar el documento masivo.');
    }

    return 'archivos/' . $folder . '/' . $safeName;
}

function cleanup_bulk_uploads(array $bulkUploads): void
{
    foreach ($bulkUploads as $upload) {
        delete_uploaded_file($upload['path'] ?? null);
    }
}


