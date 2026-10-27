<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('clientes.pmi');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('modulos/clientes/pmi.php');
verify_csrf($_POST['csrf_token'] ?? null);

$clientId = max(0, (int) ($_POST['client_id'] ?? 0));
$habilitationId = max(0, (int) ($_POST['habilitation_id'] ?? 0));
$search = trim((string) ($_POST['habilitation_buscar'] ?? ''));
$search = function_exists('mb_substr') ? mb_substr($search, 0, 100) : substr($search, 0, 100);
$page = max(1, (int) ($_POST['habilitation_pagina'] ?? 1));
$target = 'modulos/clientes/pmi.php?' . http_build_query([
    'cliente_id' => $clientId, 'tab' => 'personal', 'habilitacion_buscar' => $search, 'habilitacion_pagina' => $page,
]);
$newFile = null;
$oldFile = null;

try {
    if (!$clientId || !$habilitationId) throw new DomainException('Habilitación inválida.');
    $check = db()->prepare("SELECT 1 FROM client_pmi_habilitations WHERE id = ? AND client_id = ? AND area = 'personal' AND has_pass = 1");
    $check->execute([$habilitationId, $clientId]);
    if (!$check->fetchColumn()) throw new DomainException('Esta habilitación no tiene pase habilitado.');

    $extensions = format_attachment_extensions();
    $extensions = array_intersect_key($extensions, array_flip(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp']));
    $mimes = array_values(array_unique(array_merge(...array_values($extensions))));
    $newFile = upload_file($_FILES['pass_file'] ?? [], 'clientes_pmi_pases', $mimes, $extensions)['path'];
    if (!$newFile) throw new DomainException('Seleccione un archivo de pase.');

    db()->beginTransaction();
    $current = db()->prepare("SELECT pass_file_path FROM client_pmi_habilitations WHERE id = ? AND client_id = ? AND area = 'personal' AND has_pass = 1 FOR UPDATE");
    $current->execute([$habilitationId, $clientId]);
    $row = $current->fetch();
    if (!$row) throw new DomainException('La habilitación cambió; vuelva a intentarlo.');
    $oldFile = $row['pass_file_path'];
    $save = db()->prepare('UPDATE client_pmi_habilitations SET pass_file_path = ? WHERE id = ? AND client_id = ?');
    $save->execute([$newFile, $habilitationId, $clientId]);
    db()->commit();
    if ($oldFile) delete_uploaded_file($oldFile);
    redirect($target);
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    if ($newFile) delete_uploaded_file($newFile);
    $message = $error instanceof DomainException || $error instanceof RuntimeException
        ? $error->getMessage() : 'No se pudo guardar el pase.';
    redirect($target . '&error=' . rawurlencode($message));
}
