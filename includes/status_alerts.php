<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

const STATUS_ALERT_DEFAULT_WARNING_DAYS = 30;

function status_alert_scopes(): array
{
    return [
        'requisitos.pmi_individual' => [
            'label' => 'Requisitos - PMI Individual',
            'table' => 'requirements_catalog',
            'name_column' => 'name',
            'active_column' => 'status',
        ],
        'maquinaria.documentos' => [
            'label' => 'Maquinaria - Documentos',
            'table' => 'maquinaria_documentos_catalogo',
            'name_column' => 'nombre',
            'active_column' => 'estado',
        ],
        'empresa.documentos' => [
            'label' => 'Empresa - Documentos',
            'table' => 'empresa_documentos_catalogo',
            'name_column' => 'nombre',
            'active_column' => 'estado',
        ],
        'empresa.seguridad' => [
            'label' => 'Empresa - Seguridad',
            'table' => 'empresa_seguridad_catalogo',
            'name_column' => 'nombre',
            'active_column' => 'estado',
        ],
        'empresa.calidad' => [
            'label' => 'Empresa - Calidad',
            'table' => 'empresa_calidad_catalogo',
            'name_column' => 'nombre',
            'active_column' => 'estado',
        ],
        'empresa.medio_ambiente' => [
            'label' => 'Empresa - Medio ambiente',
            'table' => 'empresa_medio_ambiente_catalogo',
            'name_column' => 'nombre',
            'active_column' => 'estado',
        ],
    ];
}

function status_alert_catalog_items(): array
{
    $items = [];
    foreach (status_alert_scopes() as $scopeKey => $scope) {
        $table = $scope['table'];
        $nameColumn = $scope['name_column'];
        $activeColumn = $scope['active_column'];
        try {
            $rows = db()->query("SELECT id, {$nameColumn} AS name FROM {$table} WHERE {$activeColumn} = 1 ORDER BY {$nameColumn}")->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }
        $items[$scopeKey] = [
            'label' => $scope['label'],
            'items' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ], $rows),
        ];
    }
    return $items;
}

function status_alert_warning_days(string $scopeKey, int $catalogId): int
{
    static $cache = null;

    if ($catalogId <= 0) {
        return STATUS_ALERT_DEFAULT_WARNING_DAYS;
    }

    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query('SELECT scope_key, catalog_id, warning_days FROM status_alert_settings')->fetchAll();
            foreach ($rows as $row) {
                $cache[(string) $row['scope_key']][(int) $row['catalog_id']] = max(0, (int) $row['warning_days']);
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }

    return $cache[$scopeKey][$catalogId] ?? STATUS_ALERT_DEFAULT_WARNING_DAYS;
}

function status_alert_document_status(string $endDate, string $scopeKey, int $catalogId, bool $withKey = false): array
{
    $today = new DateTimeImmutable('today');
    $end = new DateTimeImmutable($endDate);
    $warningDays = status_alert_warning_days($scopeKey, $catalogId);
    $warningLimit = $today->modify('+' . $warningDays . ' days');

    if ($end < $today) {
        return $withKey
            ? ['key' => 'rojo', 'label' => 'NO APTO', 'class' => 'text-bg-danger']
            : ['label' => 'NO APTO', 'class' => 'text-bg-danger'];
    }

    if ($end <= $warningLimit) {
        return $withKey
            ? ['key' => 'amarillo', 'label' => 'POR VENCER', 'class' => 'text-bg-warning']
            : ['label' => 'POR VENCER', 'class' => 'text-bg-warning'];
    }

    return $withKey
        ? ['key' => 'verde', 'label' => 'APTO', 'class' => 'text-bg-success']
        : ['label' => 'APTO', 'class' => 'text-bg-success'];
}
