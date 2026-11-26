<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/pmi_requirement_order.php';

$items = [
    ['kind' => 'record', 'id' => 1],
    ['kind' => 'folder', 'id' => 1],
    ['kind' => 'record', 'id' => 2],
    ['kind' => 'folder', 'id' => 2],
];
$cases = 0;
foreach ($items as $index => $moving) {
    foreach ($items as $target) {
        foreach (['before', 'after'] as $placement) {
            $destination = pmi_order_destination($items, $index, '', true, $target['id'], $target['kind'], $placement);
            $result = pmi_order_insert($items, $index, $destination);
            $expected = [];
            if ($moving === $target) {
                $expected = $items;
            } else {
                foreach ($items as $item) {
                    if ($item === $moving) continue;
                    if ($item === $target && $placement === 'before') $expected[] = $moving;
                    $expected[] = $item;
                    if ($item === $target && $placement === 'after') $expected[] = $moving;
                }
            }
            if ($result !== $expected) throw new RuntimeException('Incorrect drag insertion.');
            $cases++;
        }
    }
}
$rejected = false;
try {
    pmi_order_destination($items, 0, '', true, 999, 'record', 'after');
} catch (RuntimeException $error) {
    $rejected = true;
}
if (!$rejected) throw new RuntimeException('An unavailable target was accepted.');
$records = array_map(static fn(int $id): array => ['kind' => 'record', 'id' => $id], [20, 30, 40]);
$destination = pmi_order_destination($records, 2, '', true, 20, 'record', 'before');
if (array_column(pmi_order_insert($records, 2, $destination), 'id') !== [40, 20, 30]) {
    throw new RuntimeException('Folder ordering failed.');
}
echo "OK: {$cases} mixed folder/record movements, folder ordering and unavailable targets verified.\n";
