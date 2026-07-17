<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modulos/aliados/personal.php');
}

verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$fullName = trim((string) ($_POST['full_name'] ?? ''));
$documentType = (string) ($_POST['document_type'] ?? '');
$documentNumber = trim((string) ($_POST['document_number'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$companyInput = (string) ($_POST['company_id'] ?? '');
$positionInputs = $_POST['positions'] ?? [];

if ($fullName === '' || $documentNumber === '' || $companyInput === '' || empty($positionInputs)) {
    exit('Complete los campos obligatorios.');
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit('Correo inválido.');
}

$duplicateStmt = db()->prepare('SELECT id, full_name FROM workers WHERE document_number = :document_number AND id <> :id LIMIT 1');
$duplicateStmt->execute([
    'document_number' => $documentNumber,
    'id' => $id,
]);
$duplicateWorker = $duplicateStmt->fetch();

if ($duplicateWorker) {
    $message = 'El Nro. Documento ' . $documentNumber . ' ya está registrado para ' . $duplicateWorker['full_name'] . '.';
    $target = 'modulos/aliados/formulario_personal.php' . ($id > 0 ? '?id=' . $id . '&error=' : '?error=') . urlencode($message);
    redirect($target);
}

function resolve_catalog_id(string $table, string $input): int
{
    if (ctype_digit($input)) {
        return (int) $input;
    }
    $name = trim($input);
    if ($name === '') {
        throw new RuntimeException('Catálogo inválido.');
    }
    $stmt = db()->prepare("INSERT INTO {$table} (name) VALUES (:name) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
    $stmt->execute(['name' => $name]);
    return (int) db()->lastInsertId();
}

function personal_completo(array $workerData, array $positions, ?string $photoPath, ?string $signaturePath): bool
{
    foreach ($workerData as $field => $value) {
        if ($field === 'personal_observations') {
            continue;
        }
        if ($value === null || trim((string) $value) === '') {
            return false;
        }
    }
    return !empty($positions) && !empty($photoPath) && !empty($signaturePath);
}

try {
    db()->beginTransaction();
    $companyId = resolve_catalog_id('companies', $companyInput);
    $photo = upload_file($_FILES['photo'] ?? [], 'fotos', ['image/jpeg','image/png','image/webp']);
    $signature = upload_file($_FILES['signature'] ?? [], 'firmas', ['image/jpeg','image/png','image/webp']);

    $workerData = [
        'company_id' => $companyId,
        'full_name' => $fullName,
        'document_type' => $documentType,
        'document_number' => $documentNumber,
        'blood_type' => trim((string) ($_POST['blood_type'] ?? '')),
        'address' => trim((string) ($_POST['address'] ?? '')),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
        'email' => $email,
        'birth_date' => ($_POST['birth_date'] ?? '') ?: null,
        'personal_observations' => trim((string) ($_POST['personal_observations'] ?? '')),
    ];

    if ($id > 0) {
        $currentStmt = db()->prepare('SELECT photo_path, signature_path FROM workers WHERE id = :id');
        $currentStmt->execute(['id' => $id]);
        $current = $currentStmt->fetch();

        $finalPhotoPath = $photo['path'] ?: ($current['photo_path'] ?? null);
        $finalSignaturePath = $signature['path'] ?: ($current['signature_path'] ?? null);
        $calculatedStatus = personal_completo($workerData, $positionInputs, $finalPhotoPath, $finalSignaturePath) ? 1 : 0;

        $sql = 'UPDATE workers SET company_id=:company_id, full_name=:full_name, document_type=:document_type,
                document_number=:document_number, blood_type=:blood_type, address=:address, phone=:phone,
                email=:email, birth_date=:birth_date, personal_observations=:personal_observations, status=:status';
        $params = $workerData + [
            'status' => $calculatedStatus,
            'id' => $id,
        ];

        if ($photo['path']) {
            delete_uploaded_file($current['photo_path'] ?? null);
            $sql .= ', photo_path=:photo_path';
            $params['photo_path'] = $photo['path'];
        }
        if ($signature['path']) {
            delete_uploaded_file($current['signature_path'] ?? null);
            $sql .= ', signature_path=:signature_path';
            $params['signature_path'] = $signature['path'];
        }
        $sql .= ' WHERE id=:id';
        db()->prepare($sql)->execute($params);
    } else {
        $calculatedStatus = personal_completo($workerData, $positionInputs, $photo['path'], $signature['path']) ? 1 : 0;
        $stmt = db()->prepare('INSERT INTO workers
            (company_id, full_name, document_type, document_number, blood_type, address, phone, email, birth_date, personal_observations, status, photo_path, signature_path)
            VALUES (:company_id, :full_name, :document_type, :document_number, :blood_type, :address, :phone, :email, :birth_date, :personal_observations, :status, :photo_path, :signature_path)');
        $stmt->execute($workerData + [
            'status' => $calculatedStatus,
            'photo_path' => $photo['path'],
            'signature_path' => $signature['path'],
        ]);
        $id = (int) db()->lastInsertId();
    }

    db()->prepare('DELETE FROM worker_positions WHERE worker_id = :id')->execute(['id' => $id]);
    $insertPosition = db()->prepare('INSERT INTO worker_positions (worker_id, position_id) VALUES (:worker_id, :position_id)');
    foreach ($positionInputs as $positionInput) {
        $positionId = resolve_catalog_id('positions', (string) $positionInput);
        $insertPosition->execute(['worker_id' => $id, 'position_id' => $positionId]);
    }

    db()->commit();
    redirect('modulos/aliados/personal.php');
} catch (PDOException $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    if ($e->getCode() === '23000') {
        redirect('modulos/aliados/formulario_personal.php' . ($id > 0 ? '?id=' . $id . '&error=' : '?error=') . urlencode('El Nro. Documento ingresado ya está registrado. Verifique el personal existente o edite el registro correspondiente.'));
    }
    http_response_code(400);
    echo 'No se pudo guardar el personal. Intente nuevamente.';
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    http_response_code(400);
    echo 'No se pudo guardar el personal. Revise los datos ingresados e intente nuevamente.';
}




