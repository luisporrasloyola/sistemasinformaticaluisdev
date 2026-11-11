<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_report_closure.php';
require_once __DIR__ . '/../../includes/attendance_labor_cycle.php';
require_module_access('control_personal.reporte_asistencias');
if (!is_admin()) json_response(['ok' => false, 'message' => 'Solo el administrador puede autorizar horas extras.'], 403);
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = filter_input(INPUT_POST, 'worker_id', FILTER_VALIDATE_INT);
$date = (string) ($_POST['work_date'] ?? '');
$entry = (string) ($_POST['allow_entry'] ?? '0');
$exit = (string) ($_POST['allow_exit'] ?? '0');
if (!$workerId || $workerId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
    || !in_array($entry, ['0', '1'], true) || !in_array($exit, ['0', '1'], true)) {
    json_response(['ok' => false, 'message' => 'Datos de autorización inválidos.'], 400);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    attendance_report_assert_editable($workerId, $date, $pdo, true);
    $stmt = $pdo->prepare('SELECT regime FROM attendance_labor_regimes WHERE worker_id = ? AND work_date = ? FOR UPDATE');
    $stmt->execute([$workerId, $date]);
    $saved = $stmt->fetchColumn();
    $history = attendance_labor_cycle_history($pdo, [(int) $workerId], $date);
    $state = attendance_labor_cycle_state($history[(int) $workerId] ?? [], $date);
    if ($state['regime'] !== '21x7') throw new DomainException('Esta fecha no pertenece al régimen 21×7.');
    if ($saved === false) {
        $pdo->prepare('INSERT INTO attendance_labor_regimes
            (worker_id, work_date, regime, allow_entry_overtime, allow_exit_overtime, selected_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$workerId, $date, '21x7', (int) $entry, (int) $exit, (int) (current_user()['id'] ?? 0) ?: null]);
    } else {
        $pdo->prepare('UPDATE attendance_labor_regimes SET allow_entry_overtime = ?, allow_exit_overtime = ? WHERE worker_id = ? AND work_date = ?')
            ->execute([(int) $entry, (int) $exit, $workerId, $date]);
    }
    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $message = $error instanceof DomainException ? $error->getMessage() : 'No se pudo guardar la autorización.';
    json_response(['ok' => false, 'message' => $message], 400);
}
