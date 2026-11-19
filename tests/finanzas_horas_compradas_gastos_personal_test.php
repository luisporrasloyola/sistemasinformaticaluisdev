<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function purchased_hours_expense_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$screen = file_get_contents(dirname(__DIR__) . '/modulos/finanzas/gastos_personal.php');
foreach ([
    'FROM attendance_hour_purchases',
    "'reason' => 'Compra de ' . number_format((float) \$purchase['hours_decimal'], 2, '.', '') . ' h'",
    "'source' => 'hour_purchase'",
    "linked.source === 'hour_purchase' ? 'Compra de horas' : 'Depósito vinculado'",
    '$balance -= $perDiem + ($additional[$date] ?? 0) + ($outgoing[$date] ?? 0) + ($hourPurchases[$date] ?? 0)',
    "\$periodExpenseTotal += (float) \$purchase['total_amount'];",
] as $required) {
    purchased_hours_expense_check(
        str_contains($screen, $required),
        'La compra de horas no se refleja correctamente en gastos de personal: ' . $required
    );
}

echo "Compras de horas reflejadas en gastos de personal.\n";
