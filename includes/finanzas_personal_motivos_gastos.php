<?php
declare(strict_types=1);

/** @return array<int, array{deposit_id:int, reason:string}> */
function personal_expense_linked_reasons(mixed $raw): array
{
    $items = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($items) || !array_is_list($items) || count($items) > 100) {
        throw new InvalidArgumentException('La lista de motivos vinculados no es válida.');
    }
    $seen = [];
    $result = [];
    foreach ($items as $item) {
        $id = is_array($item) ? filter_var($item['deposit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $reason = is_array($item) ? ($item['reason'] ?? null) : null;
        if (!$id || !is_string($reason) || mb_strlen(trim($reason)) > 1000 || isset($seen[$id])) {
            throw new InvalidArgumentException('Revise los motivos de los depósitos vinculados.');
        }
        $seen[$id] = true;
        $result[] = ['deposit_id' => (int) $id, 'reason' => trim($reason)];
    }
    return $result;
}

/** Guarda solo los motivos de depósitos que aparecen como gasto del trabajador en esa fecha. */
function personal_expense_save_linked_reasons(PDO $pdo, int $workerId, string $date, array $items, array $workers): void
{
    if (!$items) return;
    $find = $pdo->prepare('SELECT depositor FROM finance_personal_deposits WHERE id = ? AND deposit_date = ? FOR UPDATE');
    $save = $pdo->prepare('INSERT INTO finance_personal_expense_reasons (deposit_id, worker_id, reason) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE reason = VALUES(reason)');
    foreach ($items as $item) {
        $find->execute([$item['deposit_id'], $date]);
        $depositor = $find->fetchColumn();
        $find->closeCursor();
        if ($depositor === false || personal_expense_depositor_worker_id((string) $depositor, $workers) !== $workerId) {
            throw new DomainException('Un depósito vinculado ya no corresponde al trabajador o la fecha. Actualice la página.');
        }
        $save->execute([$item['deposit_id'], $workerId, $item['reason']]);
    }
}
