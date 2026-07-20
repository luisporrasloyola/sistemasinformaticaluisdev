<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function attendance_calendar_events_between(string $startDate, string $endDate): array
{
    $stmt = db()->prepare("SELECT acd.*, c.name AS company_name, w.full_name AS worker_name,
            u.name AS created_by_name
        FROM attendance_calendar_days acd
        LEFT JOIN companies c ON c.id = acd.company_id
        LEFT JOIN workers w ON w.id = acd.worker_id
        LEFT JOIN users u ON u.id = acd.created_by_user_id
        WHERE acd.status = 1
          AND acd.event_type IN ('holiday', 'non_working', 'vacation')
          AND acd.calendar_date <= :end_date
          AND COALESCE(acd.end_date, acd.calendar_date) >= :start_date
        ORDER BY acd.calendar_date, acd.id");
    $stmt->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    $events = [];
    foreach ($stmt->fetchAll() as $event) {
        $eventStart = max($startDate, (string) $event['calendar_date']);
        $eventEnd = min($endDate, (string) ($event['end_date'] ?: $event['calendar_date']));
        $cursor = new DateTimeImmutable($eventStart);
        $lastDate = new DateTimeImmutable($eventEnd);
        while ($cursor <= $lastDate) {
            $events[$cursor->format('Y-m-d')][] = $event;
            $cursor = $cursor->modify('+1 day');
        }
    }
    return $events;
}

function attendance_calendar_resolve_event(
    array $eventsByDate,
    string $date,
    int $workerId,
    int $companyId
): ?array {
    $selected = null;
    $selectedPriority = -1;

    foreach ($eventsByDate[$date] ?? [] as $event) {
        $scope = (string) ($event['scope_type'] ?? 'all');
        $matches = $scope === 'all'
            || ($scope === 'company' && $companyId > 0 && (int) $event['company_id'] === $companyId)
            || ($scope === 'worker' && $workerId > 0 && (int) $event['worker_id'] === $workerId);
        if (!$matches) {
            continue;
        }

        $priority = match ($scope) {
            'worker' => 3,
            'company' => 2,
            default => 1,
        };
        if ($priority > $selectedPriority
            || ($priority === $selectedPriority && (int) $event['id'] > (int) ($selected['id'] ?? 0))) {
            $selected = $event;
            $selectedPriority = $priority;
        }
    }

    return $selected;
}

function attendance_calendar_event_for_worker(string $date, int $workerId, int $companyId): ?array
{
    return attendance_calendar_resolve_event(
        attendance_calendar_events_between($date, $date),
        $date,
        $workerId,
        $companyId
    );
}

function attendance_calendar_effective_schedule(?array $weeklySchedule, ?array $calendarEvent): ?array
{
    return $calendarEvent ? null : $weeklySchedule;
}

function attendance_calendar_event_label(string $eventType): string
{
    return match ($eventType) {
        'holiday' => 'Feriado',
        'non_working' => 'No laborable',
        'vacation' => 'Vacaciones',
        default => 'Evento laboral',
    };
}
