<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_projects.php';
ensure_quick_attendance_marking_schema();
require_module_access('control_personal.control_asistencia');

$workerId = (int) ($_GET['worker_id'] ?? 0);
if (is_personal_role()) {
    $workerId = (int) current_user_worker_id();
}

if ($workerId <= 0) {
    json_response(['ok' => false, 'message' => 'Seleccione un trabajador.'], 400);
}

$stmt = db()->prepare("SELECT am.id, am.assignment_id, am.mark_date, am.marked_at, am.mark_type, am.distance_meters,
        am.final_status, am.photo_path, w.full_name, l.name AS location_name, p.name AS project_name
    FROM attendance_marks am
    JOIN workers w ON w.id = am.worker_id
    JOIN attendance_locations l ON l.id = am.location_id
    LEFT JOIN attendance_projects p ON p.id = am.project_id
    WHERE am.worker_id = :worker_id
      AND NOT EXISTS (
          SELECT 1
          FROM attendance_manual_day_overrides override_day
          WHERE override_day.worker_id = am.worker_id
            AND override_day.mark_date = am.mark_date
            AND override_day.attendance_status = 'falta'
      )
    ORDER BY am.marked_at DESC, am.id DESC
    LIMIT 80");
$stmt->execute(['worker_id' => $workerId]);

$rawMarks = $stmt->fetchAll();
$grouped = [];
foreach ($rawMarks as $row) {
    $timestamp = strtotime((string) $row['marked_at']);
    $dateKey = (string) $row['mark_date'];
    if (!isset($grouped[$dateKey])) {
        $grouped[$dateKey] = [
            'date_key' => $dateKey,
            'date' => date('d/m/Y', strtotime($dateKey)),
            'worker' => (string) $row['full_name'],
            'entry' => null,
            'exit' => null,
        ];
    }

    $mark = [
        'time' => date('H:i', $timestamp),
        'distance' => round((float) $row['distance_meters'], 2),
        'status' => (string) $row['final_status'],
        'photo_path' => $row['photo_path'] ? (string) $row['photo_path'] : null,
        'location' => (string) $row['location_name'],
        'project' => (string) ($row['project_name'] ?? ''),
    ];
    if ((string) $row['mark_type'] === 'entrada') {
        $grouped[$dateKey]['entry'] = $mark;
    } elseif ((string) $row['mark_type'] === 'salida' && $grouped[$dateKey]['exit'] === null) {
        $grouped[$dateKey]['exit'] = $mark;
    }
}

$today = date('Y-m-d');
$rows = [];
foreach ($grouped as $day) {
    $entry = $day['entry'];
    $exit = $day['exit'];
    $statuses = array_filter([$entry['status'] ?? null, $exit['status'] ?? null]);

    if (in_array('fuera_del_radio', $statuses, true)) {
        $dailyStatus = ['label' => 'Fuera del radio', 'class' => 'text-bg-danger'];
    } elseif (($entry['status'] ?? '') === 'tardanza') {
        $dailyStatus = ['label' => 'Tardanza', 'class' => 'text-bg-warning'];
    } elseif (($exit['status'] ?? '') === 'salida_anticipada') {
        $dailyStatus = ['label' => 'Salida anticipada', 'class' => 'text-bg-warning'];
    } elseif ($entry && $exit) {
        $dailyStatus = ['label' => 'Completo', 'class' => 'text-bg-success'];
    } elseif ($entry) {
        $dailyStatus = $day['date_key'] === $today
            ? ['label' => 'En jornada', 'class' => 'text-bg-primary']
            : ['label' => 'Salida no registrada', 'class' => 'text-bg-secondary'];
    } else {
        $dailyStatus = ['label' => 'Entrada no registrada', 'class' => 'text-bg-secondary'];
    }

    $rows[] = [
        'date' => $day['date'],
        'worker' => $day['worker'],
        'entry' => $entry,
        'exit' => $exit,
        'daily_status' => $dailyStatus,
    ];
}

$marks = array_map(static function (array $row): array {
    return [
        'date'=>date('d/m/Y',strtotime((string)$row['mark_date'])),
        'time'=>date('H:i',strtotime((string)$row['marked_at'])),
        'type'=>$row['mark_type']==='entrada'?'Entrada':'Salida',
        'worker'=>(string)$row['full_name'],
        'location'=>(string)$row['location_name'],
        'project'=>(string)($row['project_name']??''),
        'distance'=>round((float)$row['distance_meters'],2),
        'status'=>(string)$row['final_status'],
        'photo_path'=>$row['photo_path']?(string)$row['photo_path']:null,
    ];
}, $rawMarks);
$movements = [];
$previousEntryByDate = [];
foreach (array_reverse($rawMarks) as $row) {
    if ((string) $row['mark_type'] !== 'entrada') {
        continue;
    }

    $dateKey = (string) $row['mark_date'];
    if (isset($previousEntryByDate[$dateKey])) {
        $previous = $previousEntryByDate[$dateKey];
        $startTimestamp = strtotime((string) $previous['marked_at']);
        $endTimestamp = strtotime((string) $row['marked_at']);
        $durationMinutes = max(0, (int) floor(($endTimestamp - $startTimestamp) / 60));
        $hours = intdiv($durationMinutes, 60);
        $minutes = $durationMinutes % 60;
        $duration = $hours > 0 ? $hours . ' h ' . $minutes . ' min' : $minutes . ' min';

        $movements[] = [
            'date' => date('d/m/Y', strtotime($dateKey)),
            'start' => date('H:i', $startTimestamp),
            'end' => date('H:i', $endTimestamp),
            'duration' => $duration,
            'origin' => (string) $previous['location_name'],
            'destination' => (string) $row['location_name'],
            'project' => (string) ($row['project_name'] ?? ''),
            'photo_path' => $row['photo_path'] ? (string) $row['photo_path'] : null,
            'status' => 'Registrado',
        ];
    }
    $previousEntryByDate[$dateKey] = $row;
}
$movements = array_reverse($movements);

$perPage = 10;
$marksPage = max(1, (int) ($_GET['marks_page'] ?? 1));
$movementsPage = max(1, (int) ($_GET['movements_page'] ?? 1));

$journeyMarks = [];
foreach ($rows as $day) {
    if ($day['entry']) {
        $journeyMarks[] = array_merge($day['entry'], [
            'date' => $day['date'], 'worker' => $day['worker'], 'type' => 'Entrada',
        ]);
    }
    if ($day['exit']) {
        $journeyMarks[] = array_merge($day['exit'], [
            'date' => $day['date'], 'worker' => $day['worker'], 'type' => 'Salida',
        ]);
    }
}

$marksTotal = count($journeyMarks);
$movementsTotal = count($movements);
$marksPages = max(1, (int) ceil($marksTotal / $perPage));
$movementsPages = max(1, (int) ceil($movementsTotal / $perPage));
$marksPage = min($marksPage, $marksPages);
$movementsPage = min($movementsPage, $movementsPages);

json_response([
    'ok' => true,
    'journey_marks' => array_slice($journeyMarks, ($marksPage - 1) * $perPage, $perPage),
    'movements' => array_slice($movements, ($movementsPage - 1) * $perPage, $perPage),
    'pagination' => [
               'marks' => ['page' => $marksPage, 'pages' => $marksPages, 'total' => $marksTotal, 'per_page' => $perPage],
        'movements' => ['page' => $movementsPage, 'pages' => $movementsPages, 'total' => $movementsTotal, 'per_page' => $perPage],
    ],
]);
