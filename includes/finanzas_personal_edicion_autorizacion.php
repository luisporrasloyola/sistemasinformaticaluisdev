<?php
declare(strict_types=1);

/**
 * Devuelve el primer registro de gasto que un usuario Personal no puede editar.
 * Debe invocarse dentro de la transacción que persiste los cambios.
 */
function personal_expense_locked_record(PDO $pdo, int $workerId, array $dates): ?array
{
    if (!is_personal_role() || $workerId <= 0 || !$dates) {
        return null;
    }

    $currentUserId = (int) (current_user()['id'] ?? 0);
    if ($currentUserId <= 0) {
        return ['message' => 'No se pudo validar el usuario que intenta modificar el registro.'];
    }

    $validDates = [];
    foreach ($dates as $date) {
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $validDates[$date] = true;
        }
    }
    if (!$validDates) {
        return null;
    }

    $dateList = array_keys($validDates);
    $placeholders = implode(',', array_fill(0, count($dateList), '?'));
    $stmt = $pdo->prepare("SELECT e.mark_date, e.updated_by_user_id,
            COALESCE(u.name, '') AS editor_name, COALESCE(u.role, '') AS editor_role
        FROM finance_personal_per_diem_edits e
        LEFT JOIN users u ON u.id=e.updated_by_user_id
        WHERE e.worker_id=? AND e.mark_date IN ($placeholders)
        FOR UPDATE");
    $stmt->execute(array_merge([$workerId], $dateList));

    foreach ($stmt as $record) {
        $editorId = (int) ($record['updated_by_user_id'] ?? 0);
        if ($editorId === 0 || $editorId === $currentUserId) {
            continue;
        }
        return $record;
    }

    return null;
}

function personal_expense_locked_message(array $record): string
{
    if (!empty($record['message'])) {
        return (string) $record['message'];
    }

    $name = trim((string) ($record['editor_name'] ?? ''));
    $name = $name !== '' ? $name : 'el usuario responsable';
    if ((string) ($record['editor_role'] ?? '') === 'Administrador') {
        return 'Este registro fue modificado o registrado por el Administrador: '
            . $name . '. Para modificarlo, comuníquese con el Administrador.';
    }

    return 'Este registro fue modificado o registrado por ' . $name
        . '. Solo puede modificar los registros que usted mismo realizó.';
}
