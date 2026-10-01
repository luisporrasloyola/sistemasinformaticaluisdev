<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_module_access('control_personal.reporte_asistencias');
header('Content-Type: application/json; charset=utf-8');
if (is_personal_role()) json_response(['ok' => false, 'message' => 'No tiene permiso para autorizar horas extra.'], 403);
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = filter_var($_POST['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$from = (string) ($_POST['date_from'] ?? '');
$to = (string) ($_POST['date_to'] ?? '');
$dates = json_decode((string) ($_POST['selected_dates'] ?? ''), true);
$changedDates = json_decode((string) ($_POST['changed_dates'] ?? ''), true);
$validDate = static function (string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
};
if (!$workerId || !$validDate($from) || !$validDate($to) || $from > $to || !is_array($dates) || !is_array($changedDates) || count($dates) > 5000 || count($changedDates) > 5000) {
    json_response(['ok' => false, 'message' => 'La selección no es válida.'], 422);
}
foreach (array_merge($dates, $changedDates) as $date) {
    if (!is_string($date) || !$validDate($date) || $date < $from || $date > $to) {
        json_response(['ok' => false, 'message' => 'La selección contiene una fecha no válida.'], 422);
    }
}

try {
    $report = attendance_report_build($from, $to, (int) $workerId);
    if (!$report['worker']) json_response(['ok' => false, 'message' => 'No se encontró al trabajador.'], 404);
    $eligible = [];
    foreach ($report['individual_rows'] as $row) {
        if ($row['early_overtime_eligible'] ?? false) {
            $eligible[(string) $row['date']] = true;
        }
    }
    $selected = array_fill_keys($dates, true);
    $datesToChange = array_fill_keys($changedDates, true);
    if (array_diff_key($selected, $eligible) || array_diff_key($datesToChange, $eligible)) json_response(['ok' => false, 'message' => 'Hay jornadas que no tienen una entrada anticipada elegible. Actualice el reporte.'], 422);

    $pdo = db();
    $pdo->beginTransaction();
    $lookup = $pdo->prepare('SELECT work_date, is_authorized FROM attendance_early_overtime_authorizations WHERE worker_id = ? AND work_date BETWEEN ? AND ? FOR UPDATE');
    $lookup->execute([(int) $workerId, $from, $to]);
    $existing = [];
    foreach ($lookup->fetchAll() as $record) $existing[(string) $record['work_date']] = (bool) $record['is_authorized'];
    $user = current_user();
    $userId = (int) ($user['id'] ?? 0) ?: null;
    $userName = (string) ($user['name'] ?? 'Responsable');
    $insert = $pdo->prepare('INSERT INTO attendance_early_overtime_authorizations (worker_id, work_date, is_authorized, authorized_by_user_id, authorized_by_name, authorized_at) VALUES (?, ?, 1, ?, ?, NOW())');
    $authorize = $pdo->prepare('UPDATE attendance_early_overtime_authorizations SET is_authorized = 1, authorized_by_user_id = ?, authorized_by_name = ?, authorized_at = NOW(), revoked_by_user_id = NULL, revoked_by_name = NULL, revoked_at = NULL WHERE worker_id = ? AND work_date = ?');
    $revoke = $pdo->prepare('UPDATE attendance_early_overtime_authorizations SET is_authorized = 0, revoked_by_user_id = ?, revoked_by_name = ?, revoked_at = NOW() WHERE worker_id = ? AND work_date = ?');
    $changedCount = 0;
    foreach ($datesToChange as $date => $_) {
        $wantAuthorization = isset($selected[$date]);
        if (isset($existing[$date]) && $existing[$date] === $wantAuthorization) continue;
        if ($wantAuthorization) {
            if (array_key_exists($date, $existing)) $authorize->execute([$userId, $userName, (int) $workerId, $date]);
            else $insert->execute([(int) $workerId, $date, $userId, $userName]);
        } elseif (array_key_exists($date, $existing)) {
            $revoke->execute([$userId, $userName, (int) $workerId, $date]);
        } else continue;
        $changedCount++;
    }
    $pdo->commit();
    json_response(['ok' => true, 'message' => 'Autorizaciones guardadas.', 'changed' => $changedCount]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Error al guardar autorizaciones de horas extra de entrada: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'No se pudieron guardar las autorizaciones. Revise el registro de errores del servidor.'], 500);
}
