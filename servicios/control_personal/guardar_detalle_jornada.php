<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.asignaciones');
verify_csrf($_POST['csrf_token'] ?? null);

$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$journeyDate = substr(trim((string) ($_POST['journey_date'] ?? '')), 0, 10);
$activity = trim((string) ($_POST['activity'] ?? ''));
$instructions = trim((string) ($_POST['instructions'] ?? ''));
$action = (string) ($_POST['action'] ?? 'save');
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $journeyDate);

if ($assignmentId <= 0 || !$date || $date->format('Y-m-d') !== $journeyDate) {
    json_response(['ok' => false, 'message' => 'No se pudo identificar la jornada seleccionada.'], 422);
}
if (mb_strlen($activity) > 180 || mb_strlen($instructions) > 500) {
    json_response(['ok' => false, 'message' => 'La actividad o las indicaciones superan la longitud permitida.'], 422);
}

$stmt = db()->prepare("SELECT id, activity, instructions, status, valid_from, valid_until
    FROM attendance_assignments
    WHERE id=:id
    LIMIT 1");
$stmt->execute(['id' => $assignmentId]);
$assignment = $stmt->fetch();
if (!$assignment) {
    json_response(['ok' => false, 'message' => 'No se encontró la asignación relacionada con esta jornada.'], 404);
}

$journeyStmt = db()->prepare("SELECT
    EXISTS(SELECT 1 FROM attendance_programs ap
        WHERE ap.assignment_id=:assignment_program AND ap.program_date=:date_program AND ap.status='programada') AS has_program,
    EXISTS(SELECT 1
        FROM attendance_programs ap
        JOIN attendance_program_stops aps ON aps.program_id=ap.id
        WHERE ap.assignment_id=:assignment_route
          AND ap.program_date=:date_route
          AND ap.status='programada') AS has_route,
    EXISTS(SELECT 1 FROM attendance_assignments aa
        JOIN attendance_schedule_days sd ON sd.schedule_id=aa.schedule_id AND sd.status=1
        WHERE aa.id=:assignment_schedule
          AND aa.status=1
          AND aa.valid_from<=:regular_date_from
          AND (aa.valid_until IS NULL OR aa.valid_until>=:regular_date_until)
          AND sd.day_of_week=WEEKDAY(:date_schedule)+1) AS has_regular");
$journeyStmt->execute([
    'assignment_program' => $assignmentId, 'date_program' => $journeyDate,
    'assignment_route' => $assignmentId, 'date_route' => $journeyDate,
    'assignment_schedule' => $assignmentId,
    'regular_date_from' => $journeyDate,
    'regular_date_until' => $journeyDate,
    'date_schedule' => $journeyDate,
]);
$journey = $journeyStmt->fetch();
if (!$journey || (!(bool) $journey['has_program'] && !(bool) $journey['has_regular'])) {
    json_response(['ok' => false, 'message' => 'La fecha seleccionada no contiene un horario habitual ni una programación especial válida.'], 409);
}

// Los recorridos permanecen ligados a la asignación con la que fueron creados,
// incluso si después se reemplaza la asignación habitual. Sus cambios se
// conservan como una personalización exclusiva de esta jornada.
if ((bool) ($journey['has_route'] ?? false)) {
    if ($action === 'reset') {
        $delete = db()->prepare('DELETE FROM attendance_journey_overrides WHERE assignment_id=:assignment_id AND journey_date=:journey_date');
        $delete->execute(['assignment_id' => $assignmentId, 'journey_date' => $journeyDate]);
        json_response(['ok' => true, 'message' => 'La jornada volverá a utilizar los datos actuales de la asignación.']);
    }

    $saveRoute = db()->prepare("INSERT INTO attendance_journey_overrides
            (assignment_id, journey_date, activity, instructions, updated_by_user_id)
        VALUES (:assignment_id, :journey_date, :activity, :instructions, :user_id)
        ON DUPLICATE KEY UPDATE activity=VALUES(activity), instructions=VALUES(instructions),
            updated_by_user_id=VALUES(updated_by_user_id), updated_at=CURRENT_TIMESTAMP");
    $saveRoute->execute([
        'assignment_id' => $assignmentId,
        'journey_date' => $journeyDate,
        'activity' => $activity,
        'instructions' => $instructions,
        'user_id' => (int) current_user()['id'],
    ]);

    json_response(['ok' => true, 'message' => 'El recorrido del ' . $date->format('d/m/Y') . ' fue actualizado.']);
}

// Una programación especial ya es exclusiva de una fecha. Por ello se
// actualiza su propio registro para que todos los módulos muestren los mismos datos.
if ((bool) $journey['has_program']) {
    if ($action === 'reset') {
        $activity = (string) ($assignment['activity'] ?? '');
        $instructions = (string) ($assignment['instructions'] ?? '');
    }

    $updateProgram = db()->prepare("UPDATE attendance_programs
        SET activity=:activity, notes=:instructions
        WHERE assignment_id=:assignment_id
          AND program_date=:journey_date
          AND status='programada'");
    $updateProgram->execute([
        'activity' => $activity,
        'instructions' => $instructions,
        'assignment_id' => $assignmentId,
        'journey_date' => $journeyDate,
    ]);

    // Elimina personalizaciones antiguas para evitar dos fuentes de información
    // sobre la misma programación especial.
    $deleteOverride = db()->prepare('DELETE FROM attendance_journey_overrides WHERE assignment_id=:assignment_id AND journey_date=:journey_date');
    $deleteOverride->execute(['assignment_id' => $assignmentId, 'journey_date' => $journeyDate]);

    json_response([
        'ok' => true,
        'message' => $action === 'reset'
            ? 'La programación especial volvió a utilizar los datos de la asignación.'
            : 'La programación especial del ' . $date->format('d/m/Y') . ' fue actualizada.',
    ]);
}

if ($action === 'reset') {
    $delete = db()->prepare('DELETE FROM attendance_journey_overrides WHERE assignment_id=:assignment_id AND journey_date=:journey_date');
    $delete->execute(['assignment_id' => $assignmentId, 'journey_date' => $journeyDate]);
    json_response(['ok' => true, 'message' => 'La jornada volverá a utilizar la actividad y las indicaciones de la asignación.']);
}

$save = db()->prepare("INSERT INTO attendance_journey_overrides
        (assignment_id, journey_date, activity, instructions, updated_by_user_id)
    VALUES (:assignment_id, :journey_date, :activity, :instructions, :user_id)
    ON DUPLICATE KEY UPDATE activity=VALUES(activity), instructions=VALUES(instructions),
        updated_by_user_id=VALUES(updated_by_user_id), updated_at=CURRENT_TIMESTAMP");
$save->execute([
    'assignment_id' => $assignmentId,
    'journey_date' => $journeyDate,
    'activity' => $activity,
    'instructions' => $instructions,
    'user_id' => (int) current_user()['id'],
]);

json_response(['ok' => true, 'message' => 'Los datos se actualizaron únicamente para el ' . $date->format('d/m/Y') . '.']);
