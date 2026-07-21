<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_calendar.php';
require_module_access('control_personal.control_asistencia');

verify_csrf($_POST['csrf_token'] ?? null);

$workerId = (int) ($_POST['worker_id'] ?? 0);
$markType = (string) ($_POST['mark_type'] ?? '');
$latitude = (float) ($_POST['latitude'] ?? 0);
$longitude = (float) ($_POST['longitude'] ?? 0);
$accuracy = (float) ($_POST['accuracy'] ?? 0);
$address = trim((string) ($_POST['address'] ?? ''));
$distance = (float) ($_POST['distance_meters'] ?? 0);
$observations = trim((string) ($_POST['observations'] ?? ''));
$photoData = (string) ($_POST['photo_data'] ?? '');
$evidenceData = (string) ($_POST['evidence_data'] ?? '');

if (is_personal_role()) {
    $workerId = (int) current_user_worker_id();
}

if ($workerId <= 0 || !in_array($markType, ['entrada', 'salida'], true)) {
    json_response(['ok' => false, 'message' => 'Datos de marcacion incompletos.'], 400);
}

if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || $accuracy <= 0) {
    json_response(['ok' => false, 'message' => 'Ubicacion GPS no valida.'], 400);
}

function save_base64_image(?string $dataUrl, string $folder): ?string
{
    $dataUrl = trim((string) $dataUrl);
    if ($dataUrl === '') {
        return null;
    }
    if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,/', $dataUrl, $matches)) {
        throw new RuntimeException('Formato de imagen no permitido.');
    }

    $extension = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
    $base64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
    $binary = base64_decode($base64, true);
    if ($binary === false || strlen($binary) > MAX_UPLOAD_SIZE) {
        throw new RuntimeException('No se pudo procesar la imagen.');
    }

    $dir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
    $target = $dir . DIRECTORY_SEPARATOR . $fileName;
    if (file_put_contents($target, $binary) === false) {
        throw new RuntimeException('No se pudo guardar la imagen.');
    }

    return 'archivos/' . $folder . '/' . $fileName;
}

function meters_between(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

$stmt = db()->prepare("SELECT aa.id AS assignment_id,
        aa.worker_id, aa.location_id, aa.schedule_id,
        w.company_id,
        l.latitude AS location_latitude, l.longitude AS location_longitude, l.radius_meters,
        s.name AS schedule_name
    FROM attendance_assignments aa
    JOIN workers w ON w.id = aa.worker_id
    JOIN attendance_locations l ON l.id = aa.location_id
    JOIN attendance_schedules s ON s.id = aa.schedule_id
    WHERE aa.worker_id = :worker_id AND aa.status = 1
    ORDER BY aa.id DESC
    LIMIT 1");
$stmt->execute(['worker_id' => $workerId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    json_response(['ok' => false, 'message' => 'El trabajador no tiene asignacion activa.'], 404);
}

$today = date('Y-m-d');
$nowTime = date('H:i:s');
$markedAt = date('Y-m-d H:i:s');
$dayOfWeek = (int) date('N');

$stmt = db()->prepare('SELECT * FROM attendance_schedule_days
    WHERE schedule_id = :schedule_id AND day_of_week = :day_of_week AND status = 1
    LIMIT 1');
$stmt->execute([
    'schedule_id' => (int) $assignment['schedule_id'],
    'day_of_week' => $dayOfWeek,
]);
$weeklyScheduleDay = $stmt->fetch() ?: null;
$calendarEvent = attendance_calendar_event_for_worker(
    $today,
    $workerId,
    (int) ($assignment['company_id'] ?? 0)
);
$scheduleDay = attendance_calendar_effective_schedule($weeklyScheduleDay, $calendarEvent);

if (!$scheduleDay) {
    $message = $calendarEvent
        ? attendance_calendar_event_label((string) $calendarEvent['event_type']) . ': ' . (string) $calendarEvent['name'] . '. No corresponde marcar asistencia.'
        : 'No hay horario configurado para hoy.';
    json_response(['ok' => false, 'message' => $message], 400);
}

$duplicate = db()->prepare('SELECT id FROM attendance_marks WHERE worker_id = :worker_id AND mark_date = :mark_date AND mark_type = :mark_type LIMIT 1');
$duplicate->execute(['worker_id' => $workerId, 'mark_date' => $today, 'mark_type' => $markType]);
if ($duplicate->fetch()) {
    json_response(['ok' => false, 'message' => 'Ya existe una marcacion de ' . $markType . ' para hoy.'], 409);
}

$serverDistance = meters_between(
    $latitude,
    $longitude,
    (float) $assignment['location_latitude'],
    (float) $assignment['location_longitude']
);
$distance = $serverDistance;
$withinRadius = $distance <= (float) $assignment['radius_meters'];

if ($accuracy > (float) $assignment['radius_meters']) {
    json_response(['ok' => false, 'message' => 'La precision GPS supera el radio permitido del punto de marcacion.'], 400);
}

if (!$withinRadius) {
    json_response(['ok' => false, 'message' => 'Se encuentra fuera del radio permitido. Distancia: ' . round($distance, 2) . ' m.'], 400);
}

$scheduleStatus = 'puntual';
if ($markType === 'entrada') {
    $entryLimit = strtotime($today . ' ' . $scheduleDay['entry_end']);
    $scheduleStatus = strtotime($markedAt) <= $entryLimit ? 'puntual' : 'tardanza';
} else {
    $exitTime = strtotime($today . ' ' . ($scheduleDay['exit_time'] ?? $scheduleDay['exit_start']));
    $scheduleStatus = strtotime($markedAt) >= $exitTime ? 'salida_valida' : 'salida_anticipada';
}

$locationStatus = $withinRadius ? 'dentro_del_radio' : 'fuera_del_radio';
$finalStatus = !$withinRadius ? 'fuera_del_radio' : $scheduleStatus;

try {
    $photoPath = save_base64_image($photoData, 'marcaciones');
    if (!$photoPath) {
        json_response(['ok' => false, 'message' => 'Debe capturar una fotografia para marcar asistencia.'], 400);
    }
    $evidencePath = save_base64_image($evidenceData, 'marcaciones_evidencias');

    $stmt = db()->prepare('INSERT INTO attendance_marks
        (assignment_id, worker_id, location_id, schedule_id, mark_type, mark_date, mark_time, marked_at,
         latitude, longitude, accuracy_meters, address, distance_meters, within_radius,
         schedule_status, location_status, final_status, photo_path, evidence_path, observations)
        VALUES
        (:assignment_id, :worker_id, :location_id, :schedule_id, :mark_type, :mark_date, :mark_time, :marked_at,
         :latitude, :longitude, :accuracy_meters, :address, :distance_meters, :within_radius,
         :schedule_status, :location_status, :final_status, :photo_path, :evidence_path, :observations)');
    $stmt->execute([
        'assignment_id' => (int) $assignment['assignment_id'],
        'worker_id' => $workerId,
        'location_id' => (int) $assignment['location_id'],
        'schedule_id' => (int) $assignment['schedule_id'],
        'mark_type' => $markType,
        'mark_date' => $today,
        'mark_time' => $nowTime,
        'marked_at' => $markedAt,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'accuracy_meters' => $accuracy,
        'address' => $address ?: null,
        'distance_meters' => round($distance, 2),
        'within_radius' => $withinRadius ? 1 : 0,
        'schedule_status' => $scheduleStatus,
        'location_status' => $locationStatus,
        'final_status' => $finalStatus,
        'photo_path' => $photoPath,
        'evidence_path' => $evidencePath,
        'observations' => $observations ?: null,
    ]);

    json_response([
        'ok' => true,
        'message' => 'Marcacion registrada correctamente.',
        'status' => $finalStatus,
        'distance_meters' => round($distance, 2),
        'marked_at' => $markedAt,
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_response(['ok' => false, 'message' => 'Ya existe esta marcacion para hoy.'], 409);
    }
    json_response(['ok' => false, 'message' => 'No se pudo registrar la marcacion.'], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
