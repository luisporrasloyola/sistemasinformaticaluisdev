<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.control_asistencia');

$workerId = (int) ($_GET['worker_id'] ?? 0);
if (is_personal_role()) {
    $workerId = (int) current_user_worker_id();
}

if ($workerId <= 0) {
    json_response(['ok' => false, 'message' => 'Seleccione un trabajador.'], 400);
}

$stmt = db()->prepare("SELECT am.id, am.marked_at, am.mark_type, am.distance_meters,
        am.final_status, am.photo_path, w.full_name, l.name AS location_name
    FROM attendance_marks am
    JOIN workers w ON w.id = am.worker_id
    JOIN attendance_locations l ON l.id = am.location_id
    WHERE am.worker_id = :worker_id
    ORDER BY am.marked_at DESC, am.id DESC
    LIMIT 80");
$stmt->execute(['worker_id' => $workerId]);

$grouped = [];
foreach ($stmt->fetchAll() as $row) {
    $timestamp = strtotime((string) $row['marked_at']);
    $dateKey = date('Y-m-d', $timestamp);
    if (!isset($grouped[$dateKey])) {
        $grouped[$dateKey] = [
            'date_key' => $dateKey,
            'date' => date('d/m/Y', $timestamp),
            'worker' => (string) $row['full_name'],
            'locations' => [],
            'entry' => null,
            'exit' => null,
        ];
    }

    $grouped[$dateKey]['locations'][(string) $row['location_name']] = true;
    $mark = [
        'time' => date('H:i', $timestamp),
        'distance' => round((float) $row['distance_meters'], 2),
        'status' => (string) $row['final_status'],
        'photo_path' => $row['photo_path'] ? (string) $row['photo_path'] : null,
    ];
    if ((string) $row['mark_type'] === 'entrada') {
        $grouped[$dateKey]['entry'] = $mark;
    } elseif ((string) $row['mark_type'] === 'salida') {
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
        'location' => implode(', ', array_keys($day['locations'])),
        'entry' => $entry,
        'exit' => $exit,
        'daily_status' => $dailyStatus,
    ];
}

json_response(['ok' => true, 'rows' => $rows]);
