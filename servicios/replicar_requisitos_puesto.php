<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/pmi_requirement_files.php';
require_once __DIR__ . '/../config/database.php';
require_role('Administrador');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);

function selected_ids($value): array
{
    if (!is_array($value)) return [];
    $ids = [];
    foreach ($value as $item) {
        if (!is_scalar($item) || !ctype_digit((string) $item) || (int) $item <= 0) return [];
        $ids[(int) $item] = (int) $item;
    }
    return array_values($ids);
}

$workerId = filter_var($_POST['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sourcePositionId = filter_var($_POST['source_position_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$requirementIds = selected_ids($_POST['requirement_ids'] ?? null);
$targetIds = selected_ids($_POST['target_position_ids'] ?? null);
if (!$workerId || !$sourcePositionId || !$requirementIds || !$targetIds || count($requirementIds) > 100 || count($targetIds) > 30 || count($requirementIds) * count($targetIds) > 1000 || in_array($sourcePositionId, $targetIds, true)) {
    json_response(['ok' => false, 'message' => 'Seleccione entre 1 y 100 requisitos y hasta 30 puestos válidos (máximo 1000 réplicas).'], 422);
}

$pdo = db();
$createdFiles = [];
try {
    $pdo->beginTransaction();
    $positionMarks = implode(',', array_fill(0, count($targetIds) + 1, '?'));
    $positionStmt = $pdo->prepare("SELECT p.id, p.name FROM worker_positions wp JOIN positions p ON p.id = wp.position_id WHERE wp.worker_id = ? AND p.id IN ($positionMarks)");
    $positionStmt->execute(array_merge([$workerId, $sourcePositionId], $targetIds));
    $positions = [];
    foreach ($positionStmt->fetchAll() as $position) $positions[(int) $position['id']] = $position;
    if (count($positions) !== count($targetIds) + 1) throw new RuntimeException('Alguno de los puestos seleccionados no pertenece al trabajador.');

    $sourceMarks = implode(',', array_fill(0, count($requirementIds), '?'));
    $sourceStmt = $pdo->prepare("SELECT * FROM worker_requirements WHERE worker_id = ? AND position_id = ? AND id IN ($sourceMarks) FOR UPDATE");
    $sourceStmt->execute(array_merge([$workerId, $sourcePositionId], $requirementIds));
    $sources = $sourceStmt->fetchAll();
    if (count($sources) !== count($requirementIds)) throw new RuntimeException('Alguno de los requisitos ya no pertenece al puesto actual. Actualice la página.');

    $check = $pdo->prepare('SELECT id FROM worker_requirements WHERE worker_id = ? AND position_id = ? AND requirement_id = ?');
    $insert = $pdo->prepare('INSERT INTO worker_requirements (worker_id,position_id,requirement_id,registration_date,start_date,end_date,observations,observation_status,observation_by_user_id,observation_at,observation_resolved_by_user_id,observation_resolved_at,file_path,original_file_name,document_type,word_file_path,word_original_file_name,image_file_path,image_original_file_name,registered_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $log = $pdo->prepare('INSERT INTO worker_requirement_activity_log (worker_requirement_id,user_id,action_type,description) VALUES (?,?,?,?)');
    $created = 0;
    $skipped = 0;
    $userId = current_user()['id'] ?? null;
    foreach ($sources as $source) {
        foreach ($targetIds as $targetId) {
            $check->execute([$workerId, $targetId, $source['requirement_id']]);
            if ($check->fetchColumn()) { $skipped++; continue; }
            $newPath = null;
            if (!empty($source['file_path'])) {
                $relative = preg_replace('#^archivos[/\\\\]#', '', (string) $source['file_path']);
                $absolute = UPLOAD_PATH . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
                $real = realpath($absolute);
                $root = realpath(UPLOAD_PATH);
                if (!$real || !$root || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) throw new RuntimeException('No se encontró un archivo adjunto original. No se replicó ningún registro.');
                $directory = dirname($relative);
                $extension = pathinfo($real, PATHINFO_EXTENSION);
                $newName = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
                $newRelative = ($directory === '.' ? '' : trim(str_replace('\\', '/', $directory), '/') . '/') . $newName;
                $newAbsolute = UPLOAD_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newRelative);
                if (!is_dir(dirname($newAbsolute)) && !mkdir(dirname($newAbsolute), 0775, true) && !is_dir(dirname($newAbsolute))) throw new RuntimeException('No se pudo preparar el directorio del archivo.');
                if (!copy($real, $newAbsolute)) throw new RuntimeException('No se pudo duplicar un archivo adjunto.');
                $newPath = 'archivos/' . $newRelative;
                $createdFiles[] = $newPath;
            }
            $newWordPath = pmi_requirement_copy_file($source['word_file_path'], $createdFiles);
            $newImagePath = pmi_requirement_copy_file($source['image_file_path'], $createdFiles);
            $insert->execute([$workerId,$targetId,$source['requirement_id'],$source['registration_date'],$source['start_date'],$source['end_date'],$source['observations'],$source['observation_status'],$source['observation_by_user_id'],$source['observation_at'],$source['observation_resolved_by_user_id'],$source['observation_resolved_at'],$newPath,$source['original_file_name'],$source['document_type'],$newWordPath,$source['word_original_file_name'],$newImagePath,$source['image_original_file_name'],$userId]);
            $log->execute([(int) $pdo->lastInsertId(),$userId,'requisito_replicado','Replicó el requisito desde el puesto original hacia ' . $positions[$targetId]['name'] . '.']);
            $created++;
        }
    }
    $pdo->commit();
    json_response(['ok' => true, 'created' => $created, 'skipped' => $skipped]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($createdFiles as $path) delete_uploaded_file($path);
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
