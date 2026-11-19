<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/finanzas_personal_edicion_autorizacion.php';

function personal_edit_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$adminMessage = personal_expense_locked_message([
    'editor_name' => 'María Administradora',
    'editor_role' => 'Administrador',
]);
personal_edit_check(
    $adminMessage === 'Este registro fue modificado o registrado por el Administrador: María Administradora. Para modificarlo, comuníquese con el Administrador.',
    'El mensaje para un registro administrativo debe identificar al Administrador.'
);

$personalMessage = personal_expense_locked_message([
    'editor_name' => 'Otro Personal',
    'editor_role' => 'Personal',
]);
personal_edit_check(
    str_contains($personalMessage, 'Solo puede modificar los registros que usted mismo realizó.'),
    'Un usuario Personal no debe poder modificar registros de otro usuario.'
);

$root = dirname(__DIR__);
$screen = file_get_contents($root . '/modulos/finanzas/gastos_personal.php');
$singleSave = file_get_contents($root . '/modulos/finanzas/guardar_viatico_personal.php');
$bulkSave = file_get_contents($root . '/modulos/finanzas/guardar_viaticos_personal_masivo.php');
foreach ([
    'data-can-edit=',
    'data-lock-message=',
    "button.dataset.canEdit !== '1'",
    'COALESCE(u.role, \'\') AS registered_by_role',
] as $required) {
    personal_edit_check(str_contains($screen, $required), 'La pantalla no aplica la restricción: ' . $required);
}
foreach ([$singleSave, $bulkSave] as $save) {
    personal_edit_check(
        str_contains($save, 'personal_expense_locked_record'),
        'El guardado debe validar la propiedad del registro en el servidor.'
    );
}

echo "Autorización de edición de gastos de personal verificada.\n";
