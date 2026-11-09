<?php
require_once __DIR__ . '/../includes/finanzas_depositos_personal_match.php';

$workers = [
    ['id' => 1, 'full_name' => 'Luis Porras'],
    ['id' => 2, 'full_name' => 'Diego Angelo Leon Corac'],
    ['id' => 3, 'full_name' => 'Micher Panduro'],
    ['id' => 4, 'full_name' => 'Daniel Ponce Aysana'],
    ['id' => 5, 'full_name' => 'Franklin Alexander Nicho Espinoza'],
];

foreach ([
    'Luis Por*' => 1,
    'LEON CORAC ANGELO DIEGO' => 2,
    'MICHER PANDURO' => 3,
    'Ponce Aysana D.' => 4,
    'Luis Por' => null,
    'NICHO ESPINOZA FRANKLIN ALEXANDER' => 5,
    'Nicho Espinoza Franklin Alexan' => 5,
    'Nicho Espinoza Franklin Alex' => null,
    'Nicho Espinoza Alexan Franklin' => null,
] as $creditor => $expected) {
    if (personal_deposit_unique_worker_id($creditor, $workers) !== $expected) {
        throw new RuntimeException("Asociación incorrecta para {$creditor}");
    }
}

$franklinDeposits = ['Nicho Espinoza Franklin Alexan' => 30000, 'NICHO ESPINOZA FRANKLIN ALEXANDER' => 200000];
$franklinTotal = 0;
foreach ($franklinDeposits as $creditor => $cents) {
    if (personal_deposit_unique_worker_id($creditor, $workers) === 5) $franklinTotal += $cents;
}
if ($franklinTotal !== 230000) {
    throw new RuntimeException('Los dos depósitos de Franklin deben sumar S/ 2,300.00');
}

$workers[] = ['id' => 6, 'full_name' => 'Luis Portilla'];
if (personal_deposit_unique_worker_id('Luis Por*', $workers) !== null) {
    throw new RuntimeException('Un nombre ambiguo no debe asociarse a ningún trabajador');
}

$workers[] = ['id' => 7, 'full_name' => 'Franklin Alexandra Nicho Espinoza'];
if (personal_deposit_unique_worker_id('Nicho Espinoza Franklin Alexan', $workers) !== null) {
    throw new RuntimeException('Un nombre truncado ambiguo no debe asociarse a ningún trabajador');
}

echo "Asociación de depósitos verificada.\n";
