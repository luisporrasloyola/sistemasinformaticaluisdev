<?php
declare(strict_types=1);

/** La posici?n final se calcula despu?s de retirar el elemento de origen. */
function pmi_order_destination(array $items, int $index, string $direction, bool $dragMove, int $targetId, string $targetKind, string $placement): int
{
    if (!$dragMove) return $index + ($direction === 'up' ? -1 : 1);
    $targetIndex = null;
    foreach ($items as $offset => $item) {
        if ((int) $item['id'] === $targetId && $item['kind'] === $targetKind) { $targetIndex = $offset; break; }
    }
    if ($targetIndex === null) throw new RuntimeException('El destino no pertenece al mismo grupo. Actualice la lista.');
    if ($targetIndex === $index) return $index;
    return $targetIndex - ($index < $targetIndex ? 1 : 0) + ($placement === 'after' ? 1 : 0);
}

function pmi_order_insert(array $items, int $index, int $destination): array
{
    $moving = array_splice($items, $index, 1);
    array_splice($items, $destination, 0, $moving);
    return $items;
}

