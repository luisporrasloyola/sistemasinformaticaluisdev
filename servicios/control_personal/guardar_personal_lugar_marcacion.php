<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.puntos_marcacion');
verify_csrf($_POST['csrf_token'] ?? null);

$locationId = max(0, (int) ($_POST['location_id'] ?? 0));
$workerIds = array_values(array_unique(array_filter(
    array_map('intval', (array) ($_POST['worker_ids'] ?? [])),
    static fn(int $workerId): bool => $workerId > 0
)));
$currentUserId = (int) ($_SESSION['user']['id'] ?? 0) ?: null;

if ($locationId <= 0) {
    json_response(['ok' => false, 'message' => 'Seleccione un lugar de marcación válido.'], 422);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $locationStmt = $pdo->prepare('SELECT id, name FROM attendance_locations WHERE id=:id AND status=1 LIMIT 1 FOR UPDATE');
    $locationStmt->execute(['id' => $locationId]);
    $location = $locationStmt->fetch();
    if (!$location) {
        throw new DomainException('El lugar de marcación no existe o ya no está disponible.');
    }

    $allWorkerIds = array_map('intval', $pdo->query('SELECT id FROM workers ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $allWorkerLookup = array_fill_keys($allWorkerIds, true);
    foreach ($workerIds as $workerId) {
        if (!isset($allWorkerLookup[$workerId])) {
            throw new DomainException('Uno de los trabajadores seleccionados ya no existe. Actualice la página e inténtelo nuevamente.');
        }
    }

    $protectedStmt = $pdo->prepare("SELECT am.worker_id
        FROM attendance_marks am
        WHERE am.location_id=:mark_location
          AND am.mark_date=CURDATE()
          AND am.mark_type='entrada'
          AND NOT EXISTS (
              SELECT 1
              FROM attendance_marks newer
              WHERE newer.worker_id=am.worker_id
                AND newer.mark_date=am.mark_date
                AND (newer.marked_at>am.marked_at OR (newer.marked_at=am.marked_at AND newer.id>am.id))
          )");    $protectedStmt->execute(['mark_location' => $locationId]);

    $protectedWorkerIds = array_map('intval', $protectedStmt->fetchAll(PDO::FETCH_COLUMN));
    $selectedLookup = array_fill_keys($workerIds, true);
    $missingProtected = array_values(array_filter(
        $protectedWorkerIds,
        static fn(int $workerId): bool => !isset($selectedLookup[$workerId])
    ));
    if ($missingProtected) {
        throw new DomainException('No puede retirar personal con una jornada abierta en este lugar. Primero debe registrar su salida.');
    }

    $isAllPersonnel = count($workerIds) === count($allWorkerIds)
        && !array_diff($allWorkerIds, $workerIds)
        && !array_diff($workerIds, $allWorkerIds);
    $accessMode = $isAllPersonnel ? 'all' : 'selected';

    $pdo->prepare('DELETE FROM attendance_location_workers WHERE location_id=:location_id')
        ->execute(['location_id' => $locationId]);

    if ($accessMode === 'selected' && $workerIds) {
        $insert = $pdo->prepare('INSERT INTO attendance_location_workers
            (location_id,worker_id,selected_by_user_id)
            VALUES (:location_id,:worker_id,:selected_by_user_id)');
        foreach ($workerIds as $workerId) {
            $insert->execute([
                'location_id' => $locationId,
                'worker_id' => $workerId,
                'selected_by_user_id' => $currentUserId,
            ]);
        }
    }

    $update = $pdo->prepare("UPDATE attendance_locations
        SET personnel_access_mode=:access_mode,
            personnel_access_configured=1,
            personnel_access_updated_by_user_id=:user_id,
            personnel_access_updated_at=NOW()
        WHERE id=:location_id");
    $update->execute([
        'access_mode' => $accessMode,
        'user_id' => $currentUserId,
        'location_id' => $locationId,
    ]);

    $pdo->commit();
    json_response([
        'ok' => true,
        'message' => $accessMode === 'all'
            ? 'El lugar quedó habilitado para todo el personal.'
            : 'El personal autorizado se guardó correctamente.',
        'location_id' => $locationId,
        'location_name' => $location['name'],
        'access_mode' => $accessMode,
        'selected_count' => count($workerIds),
        'total_workers' => count($allWorkerIds),
        'protected_count' => count($protectedWorkerIds),
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $controlled = $error instanceof DomainException;
    json_response([
        'ok' => false,
        'message' => $controlled ? $error->getMessage() : 'No se pudo guardar el personal autorizado para este lugar.',
    ], $controlled ? 409 : 500);
}