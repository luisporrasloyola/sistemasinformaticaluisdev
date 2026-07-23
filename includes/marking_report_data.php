<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function marking_report_time(?string $time): string
{
    return $time ? substr($time, 0, 5) : '-';
}

function marking_report_status_label(?string $status): string
{
    return match ($status) {
        'puntual' => 'Puntual', 'tardanza' => 'Tardanza', 'salida_valida' => 'Salida',
        'salida_anticipada' => 'Salida anticipada',
        'tardanza_salida_anticipada' => 'Tardanza y salida anticipada',
        'fuera_del_radio' => 'Fuera de radio', 'dentro_del_radio' => 'Dentro del radio',
        default => $status ? ucfirst(str_replace('_', ' ', $status)) : '-',
    };
}

function marking_report_badge_class(?string $status): string
{
    return match ($status) {
        'puntual' => 'text-bg-success', 'salida_valida' => 'text-bg-primary',
        'tardanza' => 'text-bg-warning', 'salida_anticipada' => 'text-bg-early-exit',
        'tardanza_salida_anticipada' => 'text-bg-late-early-exit',
        'fuera_del_radio' => 'text-bg-danger', default => 'text-bg-secondary',
    };
}

function marking_report_allowed_statuses(): array
{
    return ['puntual', 'salida_valida', 'tardanza', 'tardanza_salida_anticipada', 'salida_anticipada'];
}

function marking_report_build(string $dateFrom, string $dateTo, int $workerId = 0, int $companyId = 0, int $locationId = 0, string $status = ''): array
{
    if (!in_array($status, marking_report_allowed_statuses(), true)) $status = '';
    $conditions = ['am.mark_date BETWEEN :desde AND :hasta'];
    $params = ['desde' => $dateFrom, 'hasta' => $dateTo];
    if ($workerId > 0) { $conditions[] = 'am.worker_id = :worker_id'; $params['worker_id'] = $workerId; }
    if ($companyId > 0) { $conditions[] = 'w.company_id = :company_id'; $params['company_id'] = $companyId; }
    if ($locationId > 0) { $conditions[] = 'am.location_id = :location_id'; $params['location_id'] = $locationId; }
    if ($status !== '') $params['status'] = $status;

    $sql = "SELECT am.*, w.full_name, w.document_number, c.name AS company,
            l.name AS location_name, l.radius_meters, s.name AS schedule_name,
            CASE WHEN am.mark_type = 'salida' AND am.final_status = 'salida_anticipada'
                AND EXISTS (SELECT 1 FROM attendance_marks entry_mark
                    WHERE entry_mark.worker_id = am.worker_id AND entry_mark.mark_date = am.mark_date
                    AND entry_mark.mark_type = 'entrada' AND entry_mark.final_status = 'tardanza')
                THEN 'tardanza_salida_anticipada' ELSE am.final_status END AS display_status
        FROM attendance_marks am
        JOIN workers w ON w.id = am.worker_id
        LEFT JOIN companies c ON c.id = w.company_id
        JOIN attendance_locations l ON l.id = am.location_id
        JOIN attendance_schedules s ON s.id = am.schedule_id
        WHERE " . implode(' AND ', $conditions)
        . ($status !== '' ? ' HAVING display_status = :status' : '') . ' ORDER BY am.marked_at DESC';
    $stmt = db()->prepare($sql); $stmt->execute($params);
    return $stmt->fetchAll();
}

function marking_report_catalogs(): array
{
    return [
        'workers' => db()->query("SELECT w.id, w.full_name, w.document_number FROM workers w ORDER BY w.full_name")->fetchAll(),
        'companies' => db()->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(),
        'locations' => db()->query('SELECT id, name FROM attendance_locations WHERE status = 1 ORDER BY name')->fetchAll(),
    ];
}
