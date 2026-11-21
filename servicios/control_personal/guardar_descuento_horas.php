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
$idText = trim((string) ($_POST['discount_id'] ?? ''));
$discountId = $idText === '' ? null : filter_var($idText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = (string) ($_POST['action'] ?? 'save');
if (!$workerId || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $reportMonth)
    || !in_array($action, ['save', 'delete'], true) || $discountId === false || ($action === 'delete' && !$discountId)) {
    json_response(['ok' => false, 'message' => 'La solicitud de descuento no es válida.'], 422);
}

$date = trim((string) ($_POST['discount_date'] ?? ''));
$quantityText = trim((string) ($_POST['quantity_decimal'] ?? $_POST['hours_decimal'] ?? ''));
$discountUnit = (string) ($_POST['discount_unit'] ?? 'hour');
$rateText = trim((string) ($_POST['hourly_rate'] ?? ''));
$hoursHundredths = 0;
$rateCents = 0;
$discountedMinutes = 0;
$totalCents = 0;
if ($action === 'save') {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date || substr($date, 0, 7) !== $reportMonth) {
        json_response(['ok' => false, 'message' => 'Seleccione una fecha válida dentro del mes del reporte.'], 422);
    }
    if (!in_array($discountUnit, ['hour', 'day'], true)
        || !preg_match('/^\d{1,4}(?:\.\d{1,2})?$/D', $quantityText) || (float) $quantityText <= 0
        || !preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $rateText) || (float) $rateText <= 0) {
        json_response(['ok' => false, 'message' => 'Ingrese una cantidad y un precio por hora o por día mayores que cero, con hasta dos decimales.'], 422);
    }
    if ($discountUnit === 'day' && !attendance_report_hour_discount_days_available()) {
        json_response(['ok' => false, 'message' => 'Ejecute primero la actualización de descuentos por días en la base de datos.'], 422);
    }
    [$hoursWhole, $hoursFraction] = array_pad(explode('.', $quantityText, 2), 2, '');
    [$rateWhole, $rateFraction] = array_pad(explode('.', $rateText, 2), 2, '');
    $hoursHundredths = (int) $hoursWhole * 100 + (int) str_pad($hoursFraction, 2, '0');
    $rateCents = (int) $rateWhole * 100 + (int) str_pad($rateFraction, 2, '0');
    $discountedMinutes = intdiv($hoursHundredths * ($discountUnit === 'day' ? 510 : 60) + 50, 100);
    $totalCents = intdiv($hoursHundredths * $rateCents + 50, 100);
    if ($discountedMinutes < 1 || $totalCents < 1 || $totalCents > 999999999999) {
        json_response(['ok' => false, 'message' => 'Revise la cantidad y el monto ingresados.'], 422);
    }
}

$pdo = db();
try {
    $pdo->beginTransaction();
    $workerStmt = $pdo->prepare('SELECT id FROM workers WHERE id = ? FOR UPDATE');
    $workerStmt->execute([$workerId]);
    if (!$workerStmt->fetchColumn()) throw new DomainException('El trabajador no existe.');
    $existing = null;
    if ($discountId) {
        $stmt = $pdo->prepare('SELECT id, discount_date, discounted_minutes FROM attendance_hour_discounts WHERE id = ? AND worker_id = ? FOR UPDATE');
        $stmt->execute([$discountId, $workerId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing || substr((string) $existing['discount_date'], 0, 7) !== $reportMonth) {
            throw new DomainException('El descuento ya no se encuentra en este reporte. Actualice la página.');
        }
    }
    attendance_report_assert_editable((int) $workerId, $reportMonth . '-01', $pdo, true);
    $laterClosedStmt = $pdo->prepare('SELECT 1 FROM attendance_report_month_closures WHERE worker_id = ? AND report_month > ? AND admin_closed_at IS NOT NULL AND staff_closed_at IS NOT NULL LIMIT 1');
    $laterClosedStmt->execute([$workerId, $reportMonth]);
    if ($laterClosedStmt->fetchColumn()) throw new DomainException('Hay un reporte posterior cerrado. No se puede cambiar su saldo de horas.');
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM attendance_hour_discounts WHERE id = ? AND worker_id = ?')->execute([$discountId, $workerId]);
        $pdo->commit();
        json_response(['ok' => true]);
    }
    $quantity = number_format($hoursHundredths / 100, 2, '.', '');
    $hoursEquivalent = number_format($discountedMinutes / 60, 2, '.', '');
    $rate = number_format($rateCents / 100, 2, '.', '');
    $total = number_format($totalCents / 100, 2, '.', '');
    if (attendance_report_hour_discount_days_available()) {
        $values = [$date, $discountUnit, $quantity, $hoursEquivalent, $rate, $total, $discountedMinutes];
        if ($existing) {
            $pdo->prepare('UPDATE attendance_hour_discounts SET discount_date = ?, discount_unit = ?, quantity_decimal = ?, hours_decimal = ?, hourly_rate = ?, total_amount = ?, discounted_minutes = ? WHERE id = ? AND worker_id = ?')
                ->execute([...$values, $discountId, $workerId]);
        } else {
            $pdo->prepare('INSERT INTO attendance_hour_discounts (discount_date, discount_unit, quantity_decimal, hours_decimal, hourly_rate, total_amount, discounted_minutes, worker_id, registered_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([...$values, $workerId, (int) (current_user()['id'] ?? 0) ?: null]);
        }
    } else {
        $values = [$date, $quantity, $rate, $total, $discountedMinutes];
        if ($existing) {
            $pdo->prepare('UPDATE attendance_hour_discounts SET discount_date = ?, hours_decimal = ?, hourly_rate = ?, total_amount = ?, discounted_minutes = ? WHERE id = ? AND worker_id = ?')
                ->execute([...$values, $discountId, $workerId]);
        } else {
            $pdo->prepare('INSERT INTO attendance_hour_discounts (discount_date, hours_decimal, hourly_rate, total_amount, discounted_minutes, worker_id, registered_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([...$values, $workerId, (int) (current_user()['id'] ?? 0) ?: null]);
        }
    }
    $pdo->commit();
    json_response(['ok' => true]);
} catch (DomainException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok' => false, 'message' => $error->getMessage()], 409);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Error en descuento de horas: ' . get_class($error));
    json_response(['ok' => false, 'message' => 'No se pudo guardar el descuento.'], 500);
}
