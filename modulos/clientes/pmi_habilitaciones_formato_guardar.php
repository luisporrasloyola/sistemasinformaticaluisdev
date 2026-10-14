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
$catalogId = max(0, (int) ($_POST['catalog_id'] ?? 0));
$search = trim((string) ($_POST['habilitation_buscar'] ?? ''));
$page = max(1, (int) ($_POST['habilitation_pagina'] ?? 1));
$target = 'modulos/clientes/pmi.php?' . http_build_query(['cliente_id' => $clientId, 'tab' => 'personal',
    'habilitacion_buscar' => mb_substr($search, 0, 100), 'habilitacion_pagina' => $page]);
$registration = trim((string) ($_POST['registration_date'] ?? ''));
$start = trim((string) ($_POST['start_date'] ?? ''));
$end = trim((string) ($_POST['end_date'] ?? ''));
$newFile = null;
$oldFile = null;

try {
    if ($clientId <= 0 || $habilitationId <= 0 || $catalogId <= 0) throw new DomainException('Seleccione un formato de personal válido.');
    foreach ([$registration, $start, $end] as $date) {
        if ($date === '') continue;
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            throw new DomainException('Ingrese fechas válidas.');
        }
    }
    if ($registration === '') throw new DomainException('Ingrese la fecha de registro.');
    if ($start !== '' && $end !== '' && $end < $start) throw new DomainException('La fecha de fin debe ser posterior al inicio.');

    $habilitation = db()->prepare("SELECT 1 FROM client_pmi_habilitations
        WHERE id=:habilitation_id AND client_id=:client_id AND area='personal' LIMIT 1");
    $habilitation->execute(['habilitation_id' => $habilitationId, 'client_id' => $clientId]);
    if (!$habilitation->fetchColumn()) throw new DomainException('Esta habilitación no corresponde a Personal.');
    $catalog = db()->prepare('SELECT 1 FROM client_pmi_format_catalog c
        WHERE c.id=:catalog_id AND c.client_id=:client_id
          AND EXISTS (SELECT 1 FROM client_pmi_format_documents d
              WHERE d.client_id=c.client_id AND d.catalog_id=c.id) LIMIT 1');
    $catalog->execute(['catalog_id' => $catalogId, 'client_id' => $clientId]);
    if (!$catalog->fetchColumn()) throw new DomainException('El formato ya no está disponible en Formatos editables.');

    $newFile = upload_file($_FILES['file'] ?? [], 'clientes_pmi_formatos_personal', document_attachment_mimes())['path'];
    db()->beginTransaction();
    $current = db()->prepare('SELECT id, file_path FROM client_pmi_habilitation_formats
        WHERE habilitation_id=:habilitation_id AND catalog_id=:catalog_id FOR UPDATE');
    $current->execute(['habilitation_id' => $habilitationId, 'catalog_id' => $catalogId]);
    $record = $current->fetch();
    $oldFile = $record['file_path'] ?? null;
    if ($record) {
        $save = db()->prepare('UPDATE client_pmi_habilitation_formats SET registration_date=:registration,
            start_date=:start_date,end_date=:end_date,file_path=:file_path,registered_by_user_id=:user_id
            WHERE id=:id');
        $save->execute(['registration' => $registration, 'start_date' => $start ?: null, 'end_date' => $end ?: null,
            'file_path' => $newFile ?: $oldFile, 'user_id' => (int) (current_user()['id'] ?? 0) ?: null,
            'id' => (int) $record['id']]);
    } else {
        $save = db()->prepare('INSERT INTO client_pmi_habilitation_formats
            (habilitation_id,catalog_id,registration_date,start_date,end_date,file_path,registered_by_user_id)
            VALUES (:habilitation_id,:catalog_id,:registration,:start_date,:end_date,:file_path,:user_id)');
        $save->execute(['habilitation_id' => $habilitationId, 'catalog_id' => $catalogId,
            'registration' => $registration, 'start_date' => $start ?: null, 'end_date' => $end ?: null,
            'file_path' => $newFile, 'user_id' => (int) (current_user()['id'] ?? 0) ?: null]);
    }
    db()->commit();
    if ($newFile && $oldFile) delete_uploaded_file($oldFile);
    redirect($target);
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    if ($newFile) delete_uploaded_file($newFile);
    $message = $error instanceof DomainException ? $error->getMessage() : 'No se pudo guardar el formato de personal.';
    redirect($target . '&error=' . rawurlencode($message));
}
