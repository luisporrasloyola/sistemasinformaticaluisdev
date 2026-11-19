<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/attendance_report_data.php';
require_once __DIR__ . '/../../includes/attendance_report_closure.php';

require_role('Administrador');
require_module_access('control_personal.reporte_asistencias');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
verify_csrf($_POST['csrf_token'] ?? null);

$workerId = filter_var($_POST['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$reportMonth = trim((string) ($_POST['report_month'] ?? ''));
$purchaseIdRaw = trim((string) ($_POST['purchase_id'] ?? ''));
$purchaseId = $purchaseIdRaw === '' ? null : filter_var($purchaseIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = (string) ($_POST['action'] ?? 'save');
$purchaseDate = (string) ($_POST['purchase_date'] ?? '');
$hoursText = trim((string) ($_POST['hours_decimal'] ?? ''));
$rateText = trim((string) ($_POST['hourly_rate'] ?? ''));
if (!$workerId || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $reportMonth)
    || !in_array($action, ['save', 'delete'], true) || $purchaseId === false || ($action === 'delete' && !$purchaseId)) {
    json_response(['ok' => false, 'message' => 'La solicitud de compra no es válida.'], 422);
}
if ($action === 'save') {
    $parsedPurchaseDate = DateTimeImmutable::createFromFormat('!Y-m-d', $purchaseDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchaseDate)
        || !$parsedPurchaseDate || $parsedPurchaseDate->format('Y-m-d') !== $purchaseDate
        || $purchaseDate > date('Y-m-d') || substr($purchaseDate, 0, 7) !== $reportMonth) {
        json_response(['ok' => false, 'message' => 'Seleccione una fecha válida dentro del mes del reporte que no sea futura.'], 422);
    }
    if (!preg_match('/^\d{1,4}(?:\.\d{1,2})?$/', $hoursText) || (float) $hoursText <= 0
        || !preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $rateText) || (float) $rateText <= 0) {
        json_response(['ok' => false, 'message' => 'Ingrese horas decimales y tarifa por hora mayores que cero, con hasta dos decimales.'], 422);
    }
}
$hoursParts = explode('.', $hoursText, 2);
$rateParts = explode('.', $rateText, 2);
$hoursHundredths = $action === 'save' ? (int) $hoursParts[0] * 100 + (int) str_pad($hoursParts[1] ?? '', 2, '0') : 0;
$rateCents = $action === 'save' ? (int) $rateParts[0] * 100 + (int) str_pad($rateParts[1] ?? '', 2, '0') : 0;
$purchasedMinutes = $action === 'save' ? intdiv($hoursHundredths * 60 + 50, 100) : 0;
$totalCents = $action === 'save' ? intdiv($hoursHundredths * $rateCents + 50, 100) : 0;
if ($action === 'save' && ($purchasedMinutes < 1 || $totalCents < 1 || $totalCents > 999999999999)) {
    json_response(['ok' => false, 'message' => 'Revise las horas y la tarifa ingresadas.'], 422);
}

$pdo = db();
try {
    $pdo->beginTransaction();
    $workerStmt = $pdo->prepare('SELECT id FROM workers WHERE id = ? FOR UPDATE');
    $workerStmt->execute([$workerId]);
    if (!$workerStmt->fetchColumn()) throw new DomainException('El trabajador no existe.');
    $existing = null;
    if ($purchaseId) {
        $existingStmt = $pdo->prepare('SELECT id, purchase_date, purchased_minutes FROM attendance_hour_purchases WHERE id = ? AND worker_id = ? FOR UPDATE');
        $existingStmt->execute([$purchaseId, $workerId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing || substr((string) $existing['purchase_date'], 0, 7) !== $reportMonth) {
            throw new DomainException('La compra ya no se encuentra en este reporte. Actualice la página.');
        }
    }
    attendance_report_assert_editable((int) $workerId, $reportMonth . '-01', $pdo, true);
    $laterClosedStmt = $pdo->prepare('SELECT 1 FROM attendance_report_month_closures WHERE worker_id = ? AND report_month > ? AND admin_closed_at IS NOT NULL AND staff_closed_at IS NOT NULL LIMIT 1');
    $laterClosedStmt->execute([$workerId, $reportMonth]);
    if ($laterClosedStmt->fetchColumn()) throw new DomainException('Hay un reporte posterior cerrado. No se puede cambiar su saldo de horas.');
    if ($action === 'delete') {
        $deleteStmt = $pdo->prepare('DELETE FROM attendance_hour_purchases WHERE id = ? AND worker_id = ?');
        $deleteStmt->execute([$purchaseId, $workerId]);
        $pdo->commit();
        json_response(['ok' => true, 'message' => 'Compra de horas eliminada.']);
    }
    $available = (int) attendance_report_build_hours_ledger((int) $workerId, $reportMonth)['current']['closing_minutes'];
    $available += (int) ($existing['purchased_minutes'] ?? 0);
    if ($purchasedMinutes > $available) throw new DomainException('Las horas compradas superan el saldo vigente del trabajador.');
    $values = [$purchaseDate, number_format($hoursHundredths / 100, 2, '.', ''),
        number_format($rateCents / 100, 2, '.', ''), number_format($totalCents / 100, 2, '.', ''), $purchasedMinutes];
    if ($existing) {
        $stmt = $pdo->prepare('UPDATE attendance_hour_purchases SET purchase_date = ?, hours_decimal = ?, hourly_rate = ?, total_amount = ?, purchased_minutes = ? WHERE id = ? AND worker_id = ?');
        $stmt->execute(array_merge($values, [$purchaseId, $workerId]));
    } else {
        $stmt = $pdo->prepare('INSERT INTO attendance_hour_purchases (purchase_date, hours_decimal, hourly_rate, total_amount, purchased_minutes, worker_id, registered_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute(array_merge($values, [$workerId, (int) (current_user()['id'] ?? 0) ?: null]));
    }
    $pdo->commit();
    json_response(['ok' => true, 'message' => $existing ? 'Compra de horas actualizada.' : 'Compra de horas registrada.']);
} catch (DomainException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $error->getMessage()], 409);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Error al registrar compra de horas: ' . get_class($error));
    json_response(['ok' => false, 'message' => 'No se pudo registrar la compra de horas.'], 500);
}
