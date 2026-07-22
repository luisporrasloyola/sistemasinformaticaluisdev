<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.calendario_laboral');
verify_csrf($_POST['csrf_token'] ?? null);

$id = (int) ($_POST['id'] ?? 0);
$date = trim((string) ($_POST['calendar_date'] ?? ''));
$endDate = trim((string) ($_POST['end_date'] ?? ''));
$eventType = trim((string) ($_POST['event_type'] ?? ''));
$name = trim((string) ($_POST['name'] ?? ''));
$scopeType = trim((string) ($_POST['scope_type'] ?? 'all'));
$workerId = (int) ($_POST['worker_id'] ?? 0);

$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
    json_response(['ok' => false, 'message' => 'Ingrese una fecha valida.'], 400);
}
if (!in_array($eventType, ['holiday', 'non_working', 'vacation', 'permission', 'rest'], true)) {
    json_response(['ok' => false, 'message' => 'Seleccione un tipo de dia valido.'], 400);
}
if (!in_array($scopeType, ['all', 'worker'], true) || $name === '') {
    json_response(['ok' => false, 'message' => 'Complete el motivo y el alcance.'], 400);
}
if ($scopeType === 'worker' && $workerId <= 0) {
    json_response(['ok' => false, 'message' => 'Seleccione el trabajador.'], 400);
}
if (in_array($eventType, ['vacation', 'permission'], true) && $scopeType !== 'worker') {
    json_response(['ok' => false, 'message' => 'Las vacaciones y permisos deben asignarse a un trabajador.'], 400);
}

$endDate = $eventType === 'vacation' ? $endDate : $date;
$parsedEndDate = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
if (!$parsedEndDate || $parsedEndDate->format('Y-m-d') !== $endDate || $endDate < $date) {
    json_response(['ok' => false, 'message' => 'Revise el periodo de vacaciones.'], 400);
}
if ($parsedDate->diff($parsedEndDate)->days > 366) {
    json_response(['ok' => false, 'message' => 'El periodo no puede superar 366 dias.'], 400);
}

$companyId = 0;
$workerId = $scopeType === 'worker' ? $workerId : 0;
$duplicate = db()->prepare("SELECT id FROM attendance_calendar_days
    WHERE calendar_date <= :end_date
      AND COALESCE(end_date, calendar_date) >= :calendar_date
      AND scope_type = :scope_type
      AND COALESCE(company_id, 0) = :company_id
      AND COALESCE(worker_id, 0) = :worker_id
      AND status = 1
      AND id <> :id
    LIMIT 1");
$duplicate->execute([
    'calendar_date' => $date,
    'end_date' => $endDate,
    'scope_type' => $scopeType,
    'company_id' => $companyId,
    'worker_id' => $workerId,
    'id' => $id,
]);
if ($duplicate->fetch()) {
    json_response(['ok' => false, 'message' => 'Ya existe una configuracion para esa fecha y alcance.'], 409);
}

$params = [
    'calendar_date' => $date,
    'end_date' => $endDate,
    'event_type' => $eventType,
    'name' => $name,
    'scope_type' => $scopeType,
    'company_id' => $companyId ?: null,
    'worker_id' => $workerId ?: null,
];

if ($id > 0) {
    $params['id'] = $id;
    $stmt = db()->prepare("UPDATE attendance_calendar_days SET
        calendar_date = :calendar_date, end_date = :end_date, event_type = :event_type, name = :name,
        scope_type = :scope_type, company_id = :company_id, worker_id = :worker_id
        WHERE id = :id AND status = 1");
    $stmt->execute($params);
} else {
    $params['created_by_user_id'] = (int) ($_SESSION['user']['id'] ?? 0) ?: null;
    $stmt = db()->prepare("INSERT INTO attendance_calendar_days
        (calendar_date, end_date, event_type, name, scope_type, company_id, worker_id, status, created_by_user_id)
        VALUES
        (:calendar_date, :end_date, :event_type, :name, :scope_type, :company_id, :worker_id, 1, :created_by_user_id)");
    $stmt->execute($params);
    $id = (int) db()->lastInsertId();
}

json_response(['ok' => true, 'id' => $id]);
