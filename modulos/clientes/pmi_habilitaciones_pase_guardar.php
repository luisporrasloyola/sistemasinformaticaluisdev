<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/pmi_project_scope.php';
require_module_access('clientes.pmi');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('modulos/clientes/pmi.php');
verify_csrf($_POST['csrf_token'] ?? null);

$clientId = max(0, (int) ($_POST['client_id'] ?? 0));
$habilitationId = max(0, (int) ($_POST['habilitation_id'] ?? 0));
$search = trim((string) ($_POST['habilitation_buscar'] ?? ''));
$search = function_exists('mb_substr') ? mb_substr($search, 0, 100) : substr($search, 0, 100);
$page = max(1, (int) ($_POST['habilitation_pagina'] ?? 1));
$target = 'modulos/clientes/pmi.php?' . http_build_query([
    'cliente_id' => $clientId, 'tab' => 'personal', 'habilitacion_area' => (($_POST['habilitacion_area'] ?? 'personal') === 'equipo' ? 'equipo' : 'personal'), 'habilitacion_buscar' => $search, 'habilitacion_pagina' => $page,
]);
$newFile = null;
$oldFile = null;

try {
    $projectId = pmi_project_id($clientId, $_POST);
    $target .= '&proyecto_id=' . $projectId;
    if (!$clientId || !$habilitationId) throw new DomainException('Habilitación inválida.');
    $check = db()->prepare("SELECT 1 FROM client_pmi_habilitations WHERE id = ? AND client_id = ? AND project_id = ? AND area = 'personal' AND has_pass = 1");
    $check->execute([$habilitationId, $clientId, $projectId]);
    if (!$check->fetchColumn()) throw new DomainException('Esta habilitación no tiene pase habilitado.');

    $extensions = format_attachment_extensions();
    $extensions = array_intersect_key($extensions, array_flip(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp']));
    $mimes = array_values(array_unique(array_merge(...array_values($extensions))));
    $newFile = upload_file($_FILES['pass_file'] ?? [], 'clientes_pmi_pases', $mimes, $extensions)['path'];
    if (!$newFile) throw new DomainException('Seleccione un archivo de pase.');

    db()->beginTransaction();
    $current = db()->prepare("SELECT pass_file_path, group_title, habilitation_type FROM client_pmi_habilitations WHERE id = ? AND client_id = ? AND project_id = ? AND area = 'personal' AND has_pass = 1 FOR UPDATE");
    $current->execute([$habilitationId, $clientId, $projectId]);
    $row = $current->fetch();
    if (!$row) throw new DomainException('La habilitación cambió; vuelva a intentarlo.');
    if ($row['habilitation_type'] === 'grupal' && trim((string) $row['group_title']) !== '') {
        $group = db()->prepare("SELECT pass_file_path FROM client_pmi_habilitations WHERE client_id = ? AND project_id = ? AND area = 'personal' AND habilitation_type = 'grupal' AND group_title = ? AND has_pass = 1 FOR UPDATE");
        $group->execute([$clientId, $projectId, $row['group_title']]);
        $oldFiles = array_values(array_unique(array_filter($group->fetchAll(PDO::FETCH_COLUMN))));
        $save = db()->prepare("UPDATE client_pmi_habilitations SET pass_file_path = ? WHERE client_id = ? AND project_id = ? AND area = 'personal' AND habilitation_type = 'grupal' AND group_title = ? AND has_pass = 1");
        $save->execute([$newFile, $clientId, $projectId, $row['group_title']]);
    } else {
        $oldFiles = array_filter([$row['pass_file_path']]);
        $save = db()->prepare('UPDATE client_pmi_habilitations SET pass_file_path = ? WHERE id = ? AND client_id = ? AND project_id = ?');
        $save->execute([$newFile, $habilitationId, $clientId, $projectId]);
    }
    db()->commit();
    try {
        $stillUsed = db()->prepare('SELECT 1 FROM client_pmi_habilitations WHERE pass_file_path = ? LIMIT 1');
        foreach ($oldFiles as $oldFile) {
            $stillUsed->execute([$oldFile]);
            if (!$stillUsed->fetchColumn()) delete_uploaded_file($oldFile);
        }
    } catch (Throwable $ignored) { /* El pase nuevo ya está guardado. */ }
    redirect($target);
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    if ($newFile) delete_uploaded_file($newFile);
    $message = $error instanceof DomainException || $error instanceof RuntimeException
        ? $error->getMessage() : 'No se pudo guardar el pase.';
    redirect($target . '&error=' . rawurlencode($message));
}
