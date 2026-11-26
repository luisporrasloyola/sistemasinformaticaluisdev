<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_once __DIR__ . '/../../includes/attendance_report_closure.php';
require_module_access('control_personal.reporte_asistencias');
header('Content-Type: application/json; charset=utf-8');
if (!is_admin()) json_response(['ok' => false, 'message' => 'Solo un administrador puede duplicar horas extras.'], 403);
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = filter_var($_POST['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$month = (string) ($_POST['month'] ?? '');
$changes = json_decode((string) ($_POST['changes'] ?? ''), true);
if (!$workerId || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) || !is_array($changes)
    || !$changes || count($changes) > 31 || !attendance_report_worked_doublings_available()) {
    json_response(['ok' => false, 'message' => 'La selección no es válida o falta instalar la tabla de duplicaciones.'], 422);
}
$from = $month . '-01';
$to = (new DateTimeImmutable($from))->format('Y-m-t');
$requested = [];
foreach ($changes as $change) {
    if (!is_array($change) || !isset($change['date']) || !is_string($change['date'])
        || !array_key_exists('enabled', $change) || !is_bool($change['enabled'])) {
        json_response(['ok' => false, 'message' => 'La selección contiene un dato no válido.'], 422);
    }
    $date = $change['date'];
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < $from || $date > $to || isset($requested[$date])) {
        json_response(['ok' => false, 'message' => 'La selección contiene una fecha no válida o repetida.'], 422);
    }
    $requested[$date] = $change['enabled'];
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    attendance_report_assert_range_editable((int) $workerId, $from, $to, $pdo, true);
    $report = attendance_report_build($from, $to, (int) $workerId);
    if (!$report['worker']) throw new DomainException('No se encontró al trabajador.');
    $eligible = [];
    foreach ($report['individual_rows'] as $row) {
        if ($row['entry'] !== '-' && $row['exit'] !== '-' && (int) ($row['base_overtime_minutes'] ?? 0) > 0) {
            $eligible[(string) $row['date']] = true;
        }
    }
    $toEnable = array_filter($requested);
    if (array_diff_key($toEnable, $eligible)) {
        throw new DomainException('Una de las fechas ya no tiene entrada, salida y horas extras. Actualice el reporte.');
    }
    $userId = (int) (current_user()['id'] ?? 0) ?: null;
    $enable = $pdo->prepare('INSERT INTO attendance_worked_hour_doublings (worker_id, work_date, enabled_by_user_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE enabled_by_user_id = VALUES(enabled_by_user_id), enabled_at = NOW()');
    $disable = $pdo->prepare('DELETE FROM attendance_worked_hour_doublings WHERE worker_id = ? AND work_date = ?');
    foreach ($requested as $date => $enabled) {
        if ($enabled) $enable->execute([(int) $workerId, $date, $userId]);
        else $disable->execute([(int) $workerId, $date]);
    }
    $pdo->commit();
    json_response(['ok' => true, 'message' => 'Duplicación de horas extras guardada.', 'changed' => count($requested)]);
} catch (DomainException $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $error->getMessage()], 409);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Error al guardar duplicación de horas extras: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudieron guardar las duplicaciones.'], 500);
}
