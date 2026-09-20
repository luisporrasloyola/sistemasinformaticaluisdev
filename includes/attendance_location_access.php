<?php
declare(strict_types=1);

function attendance_location_access_condition(string $locationAlias = 'l'): string
{
    return "(
        (
            {$locationAlias}.personnel_access_configured=0
            AND (
                EXISTS (
                    SELECT 1 FROM attendance_assignments automatic_assignment
                    WHERE automatic_assignment.location_id={$locationAlias}.id
                      AND automatic_assignment.worker_id=:automatic_assignment_worker
                      AND automatic_assignment.status=1
                      AND automatic_assignment.valid_from<=:automatic_date
                      AND (automatic_assignment.valid_until IS NULL OR automatic_assignment.valid_until>=:automatic_date_until)
                )
                OR EXISTS (
                    SELECT 1 FROM attendance_marks automatic_mark
                    WHERE automatic_mark.location_id={$locationAlias}.id
                      AND automatic_mark.worker_id=:automatic_mark_worker
                )
                OR (
                    NOT EXISTS (
                        SELECT 1 FROM attendance_assignments any_assignment
                        WHERE any_assignment.location_id={$locationAlias}.id
                          AND any_assignment.status=1
                          AND any_assignment.valid_from<=:automatic_any_date
                          AND (any_assignment.valid_until IS NULL OR any_assignment.valid_until>=:automatic_any_date_until)
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM attendance_marks any_mark
                        WHERE any_mark.location_id={$locationAlias}.id
                    )
                )
            )
        )
        OR (
            {$locationAlias}.personnel_access_configured=1
            AND {$locationAlias}.personnel_access_mode='all'
        )
        OR EXISTS (
            SELECT 1 FROM attendance_location_workers alw
            WHERE alw.location_id={$locationAlias}.id AND alw.worker_id=:access_worker
        )
        OR EXISTS (
            SELECT 1 FROM attendance_marks am
            WHERE am.location_id={$locationAlias}.id AND am.worker_id=:open_worker
              AND am.mark_date=:open_date AND am.mark_type='entrada'
              AND NOT EXISTS (
                  SELECT 1 FROM attendance_marks newer
                  WHERE newer.worker_id=am.worker_id AND newer.mark_date=am.mark_date
                    AND (newer.marked_at>am.marked_at OR (newer.marked_at=am.marked_at AND newer.id>am.id))
              )
        )
    )";
}

function attendance_location_access_params(int $workerId, ?string $date = null): array
{
    $date ??= date('Y-m-d');

    return [
        'automatic_assignment_worker' => $workerId,
        'automatic_date' => $date,
        'automatic_date_until' => $date,
        'automatic_mark_worker' => $workerId,
        'automatic_any_date' => $date,
        'automatic_any_date_until' => $date,
        'access_worker' => $workerId,
        'open_worker' => $workerId,
        'open_date' => $date,
    ];
}

function attendance_worker_can_use_location(PDO $pdo, int $workerId, int $locationId, ?string $date = null): bool
{
    if ($workerId <= 0 || $locationId <= 0) {
        return false;
    }

    $condition = attendance_location_access_condition('l');
    $stmt = $pdo->prepare("SELECT 1 FROM attendance_locations l
        WHERE l.id=:access_location AND l.status=1 AND {$condition}
        LIMIT 1");
    $stmt->execute(['access_location' => $locationId] + attendance_location_access_params($workerId, $date));

    return (bool) $stmt->fetchColumn();
}