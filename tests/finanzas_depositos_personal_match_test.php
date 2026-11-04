<?php
require_once __DIR__ . '/../includes/finanzas_depositos_personal_match.php';

$workers = [
    ['id' => 1, 'full_name' => 'Luis Porras'],
    ['id' => 2, 'full_name' => 'Diego Angelo Leon Corac'],
    ['id' => 3, 'full_name' => 'Micher Panduro'],
    ['id' => 4, 'full_name' => 'Daniel Ponce Aysana'],
];

foreach ([
    'Luis Por*' => 1,
    'LEON CORAC ANGELO DIEGO' => 2,
    'MICHER PANDURO' => 3,
    'Ponce Aysana D.' => 4,
    'Luis Por' => null,
] as $creditor => $expected) {
    if (personal_deposit_unique_worker_id($creditor, $workers) !== $expected) {
        throw new RuntimeException("Asociación incorrecta para {$creditor}");
    }
}

$workers[] = ['id' => 5, 'full_name' => 'Luis Portilla'];
if (personal_deposit_unique_worker_id('Luis Por*', $workers) !== null) {
    throw new RuntimeException('Un nombre ambiguo no debe asociarse a ningún trabajador');
}

echo "Asociación de depósitos verificada.\n";
