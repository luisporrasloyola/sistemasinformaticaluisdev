<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_role('Administrador');

verify_csrf($_POST['csrf_token'] ?? null);

$assignmentIds = array_values(array_unique(array_filter(
    array_map('intval', explode(',', (string) ($_POST['assignment_ids'] ?? ''))),
    static fn(int $id): bool => $id > 0
)));
$validFrom = trim((string) ($_POST['valid_from'] ?? ''));
$validUntil = !empty($_POST['no_end']) ? null : trim((string) ($_POST['valid_until'] ?? ''));

if (!$assignmentIds) {
    json_response(['ok' => false, 'message' => 'No se encontraron asignaciones para actualizar.'], 422);
}
$fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $validFrom);
$untilDate = $validUntil ? DateTimeImmutable::createFromFormat('!Y-m-d', $validUntil) : null;
if (!$fromDate || $fromDate->format('Y-m-d') !== $validFrom
    || ($validUntil !== null && (!$untilDate || $untilDate->format('Y-m-d') !== $validUntil))) {
    json_response(['ok' => false, 'message' => 'Defina un periodo de vigencia válido.'], 422);
}
if ($untilDate && $untilDate < $fromDate) {
    json_response(['ok' => false, 'message' => 'La fecha final no puede ser anterior a la fecha de inicio.'], 422);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
    $assignmentStmt = $pdo->prepare("SELECT id, location_id, schedule_id
        FROM attendance_assignments WHERE id IN ({$placeholders}) AND status = 1 FOR UPDATE");
    $assignmentStmt->execute($assignmentIds);
    $rows = $assignmentStmt->fetchAll();
    if (count($rows) !== count($assignmentIds)) {
        throw new RuntimeException('Una o más asignaciones ya no están vigentes. Actualice la página e inténtelo nuevamente.');
    }
    $groupKeys = array_unique(array_map(
        static fn(array $row): string => (string) $row['location_id'] . '-' . (string) $row['schedule_id'],
        $rows
    ));
    if (count($groupKeys) !== 1) {
        throw new RuntimeException('Las asignaciones seleccionadas no pertenecen al mismo lugar y horario.');
    }

    $markParams = array_merge($assignmentIds, [$validFrom]);
    $outsideCondition = 'mark_date < ?';
    if ($validUntil !== null) {
        $outsideCondition .= ' OR mark_date > ?';
        $markParams[] = $validUntil;
    }
    $markStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_marks
        WHERE assignment_id IN ({$placeholders}) AND ({$outsideCondition})");
    $markStmt->execute($markParams);
    if ((int) $markStmt->fetchColumn() > 0) {
        throw new RuntimeException('No se puede aplicar ese periodo porque existen marcaciones fuera de la nueva vigencia. Amplíe las fechas para conservarlas dentro del periodo.');
    }

    $programParams = array_merge($assignmentIds, [$validFrom]);
    $programOutside = 'program_date < ?';
    if ($validUntil !== null) {
        $programOutside .= ' OR program_date > ?';
        $programParams[] = $validUntil;
    }
    $programStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_programs
        WHERE assignment_id IN ({$placeholders}) AND status = 'programada' AND ({$programOutside})");
    $programStmt->execute($programParams);
    if ((int) $programStmt->fetchColumn() > 0) {
        throw new RuntimeException('Existen jornadas extraordinarias fuera de la nueva vigencia. Ajuste el periodo o reprograme primero esas jornadas.');
    }

    $update = $pdo->prepare("UPDATE attendance_assignments SET valid_from = ?, valid_until = ?
        WHERE id IN ({$placeholders}) AND status = 1");
    $update->execute(array_merge([$validFrom, $validUntil], $assignmentIds));
    $pdo->commit();
    json_response([
        'ok' => true,
        'updated_count' => $update->rowCount(),
        'message' => 'La vigencia del grupo se actualizó correctamente.',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo actualizar la vigencia del grupo.';
    json_response(['ok' => false, 'message' => $message], $e instanceof RuntimeException ? 422 : 500);
}
