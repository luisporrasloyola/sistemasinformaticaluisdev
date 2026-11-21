<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_once __DIR__ . '/../../includes/attendance_report_closure.php';

require_role('Administrador');
require_module_access('control_personal.reporte_asistencias');
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = (int) ($_POST['worker_id'] ?? 0);
$restDate = trim((string) ($_POST['rest_date'] ?? ''));
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $restDate);
if ($workerId <= 0 || !$date || $date->format('Y-m-d') !== $restDate) {
    json_response(['ok' => false, 'message' => 'Revise el trabajador y la fecha del descanso.'], 422);
}

try {
    if (!attendance_report_rest_justifications_available()) {
        throw new DomainException('Falta habilitar la tabla de justificantes de descanso. Ejecute la migración correspondiente.');
    }
    attendance_report_assert_calendar_editable($restDate, $restDate, [$workerId]);
    $report = attendance_report_build($restDate, $restDate, $workerId);
    $restRow = $report['individual_rows'][0] ?? null;
    if (!$restRow || (string) ($restRow['state_key'] ?? '') !== 'rest') {
        throw new DomainException('Solo se puede adjuntar un justificante a un descanso (D).');
    }
    $attachment = upload_file(
        $_FILES['justification_file'] ?? [],
        'justificantes_descanso',
        document_attachment_mimes(),
        ['pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp']]
    );
    if (!$attachment['path'] || !$attachment['name']) {
        throw new DomainException('Adjunte un PDF o una imagen como justificante.');
    }

    $pdo = db();
    $oldFile = null;
    try {
        $pdo->beginTransaction();
        $oldStmt = $pdo->prepare('SELECT file_path FROM attendance_rest_justifications WHERE worker_id = ? AND rest_date = ? FOR UPDATE');
        $oldStmt->execute([$workerId, $restDate]);
        $oldFile = $oldStmt->fetchColumn() ?: null;
        $save = $pdo->prepare('INSERT INTO attendance_rest_justifications
            (worker_id, rest_date, file_path, original_file_name, uploaded_by_user_id)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), original_file_name = VALUES(original_file_name),
                uploaded_by_user_id = VALUES(uploaded_by_user_id), updated_at = CURRENT_TIMESTAMP');
        $save->execute([$workerId, $restDate, $attachment['path'], $attachment['name'], (int) (current_user()['id'] ?? 0) ?: null]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        delete_uploaded_file((string) $attachment['path']);
        throw $error;
    }
    if ($oldFile && $oldFile !== $attachment['path']) delete_uploaded_file((string) $oldFile);
    json_response(['ok' => true, 'message' => 'Justificante guardado. El descanso ahora figura como DJ y no afecta los cálculos.',
        'file_url' => APP_URL . '/' . ltrim((string) $attachment['path'], '/')]);
} catch (DomainException $error) {
    json_response(['ok' => false, 'message' => $error->getMessage()], 409);
} catch (Throwable $error) {
    error_log('Error al guardar justificante de descanso: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudo guardar el justificante del descanso.'], 500);
}
