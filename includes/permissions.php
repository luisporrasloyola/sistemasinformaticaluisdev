<?php
declare(strict_types=1);

function permission_modules_catalog(): array
{
    return [
        'dashboard' => ['label' => 'Dashboard', 'children' => []],
        'control_personal' => [
            'label' => 'Control de personal',
            'children' => [
                'control_personal.dashboard' => 'Dashboard de asistencia',
                'control_personal.personal' => 'Personal',
                'control_personal.horarios' => 'Horarios',
                'control_personal.puntos_marcacion' => 'Puntos de marcacion',
                'control_personal.asignaciones' => 'Asignaciones',
                'control_personal.control_asistencia' => 'Control de asistencia',
                'control_personal.reportes' => 'Reportes',
            ],
        ],
        'requisitos' => [
            'label' => 'Requisitos',
            'children' => [
                'requisitos.pmi_individual' => 'PMI Individual',
                'requisitos.pmi_masivo' => 'Requisito PMI Masivo',
            ],
        ],
        'maquinaria' => [
            'label' => 'Maquinaria',
            'children' => [
                'maquinaria.datos_generales' => 'Datos generales',
                'maquinaria.documentos' => 'Documentos',
            ],
        ],
        'empresa' => [
            'label' => 'Empresa',
            'children' => [
                'empresa.datos_generales' => 'Datos generales',
                'empresa.documentos' => 'Documentos',
                'empresa.seguridad' => 'Seguridad',
                'empresa.calidad' => 'Calidad',
                'empresa.medio_ambiente' => 'Medio ambiente',
            ],
        ],
        'usuarios' => ['label' => 'Usuarios', 'children' => []],
    ];
}

function permission_document_scopes(): array
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

function permission_module_keys_flat(): array
{
    $keys = [];
    foreach (permission_modules_catalog() as $key => $module) {
        $keys[] = $key;
        foreach (array_keys($module['children']) as $childKey) {
            $keys[] = $childKey;
        }
    }
    return $keys;
}

function permission_default_modules_for_role(string $role): array
{
    $all = permission_module_keys_flat();
    if ($role === 'Administrador') {
        return array_fill_keys($all, true);
    }
    if ($role === 'Personal') {
        return [
            'control_personal' => true,
            'control_personal.control_asistencia' => true,
        ];
    }
    return [];
}

function permission_catalog_items(): array
{
    $items = [];
    foreach (permission_document_scopes() as $scopeKey => $scope) {
        $table = $scope['table'];
        $nameColumn = $scope['name_column'];
        $activeColumn = $scope['active_column'];
        try {
            $rows = db()->query("SELECT id, {$nameColumn} AS name FROM {$table} WHERE {$activeColumn} = 1 ORDER BY id")->fetchAll();
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

function permission_payload_for_user(int $userId, string $role): array
{
    $defaultModules = permission_default_modules_for_role($role);
    $modulePermissions = $defaultModules;
    $documentPermissions = [];

    if ($userId > 0) {
        try {
            $stmt = db()->prepare('SELECT module_key, can_access FROM user_module_permissions WHERE user_id = :user_id');
            $stmt->execute(['user_id' => $userId]);
            $storedModules = $stmt->fetchAll();
            if ($storedModules) {
                $modulePermissions = [];
                foreach ($storedModules as $row) {
                    if ((int) $row['can_access'] === 1) {
                        $modulePermissions[(string) $row['module_key']] = true;
                    }
                }
            }
        } catch (Throwable $e) {
            $modulePermissions = $defaultModules;
        }

        try {
            $stmt = db()->prepare('SELECT scope_key, catalog_id, can_view, can_upload, can_manage_catalog FROM user_document_permissions WHERE user_id = :user_id');
            $stmt->execute(['user_id' => $userId]);
            foreach ($stmt->fetchAll() as $row) {
                $scope = (string) $row['scope_key'];
                $catalogId = (int) $row['catalog_id'];
                $documentPermissions[$scope][(string) $catalogId] = [
                    'view' => (int) $row['can_view'] === 1,
                    'upload' => (int) $row['can_upload'] === 1,
                    'manage' => (int) ($row['can_manage_catalog'] ?? 0) === 1,
                ];
            }
        } catch (Throwable $e) {
            $documentPermissions = [];
        }
    }

    return [
        'modules' => array_keys(array_filter($modulePermissions)),
        'documents' => $documentPermissions,
    ];
}

function save_user_permissions(int $userId, string $role, array $post): void
{
    $moduleKeys = permission_module_keys_flat();
    $selectedModules = array_values(array_intersect($moduleKeys, array_map('strval', (array) ($post['module_permissions'] ?? []))));

    if ($role === 'Administrador') {
        $selectedModules = $moduleKeys;
    } elseif ($role === 'Personal') {
        $selectedModules = array_keys(permission_default_modules_for_role('Personal'));
    }

    db()->prepare('DELETE FROM user_module_permissions WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    if ($selectedModules) {
        $stmt = db()->prepare('INSERT INTO user_module_permissions (user_id, module_key, can_access) VALUES (:user_id, :module_key, 1)');
        foreach ($selectedModules as $moduleKey) {
            $stmt->execute(['user_id' => $userId, 'module_key' => $moduleKey]);
        }
    }

    db()->prepare('DELETE FROM user_document_permissions WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    if ($role === 'Personal') {
        return;
    }

    $viewPermissions = (array) ($post['document_view_permissions'] ?? []);
    $uploadPermissions = (array) ($post['document_upload_permissions'] ?? []);
    $managePermissions = (array) ($post['document_manage_permissions'] ?? []);
    $validScopes = permission_document_scopes();
    $insert = db()->prepare('INSERT INTO user_document_permissions (user_id, scope_key, catalog_id, can_view, can_upload, can_manage_catalog)
        VALUES (:user_id, :scope_key, :catalog_id, :can_view, :can_upload, :can_manage_catalog)');

    foreach ($validScopes as $scopeKey => $_scope) {
        $viewIds = array_map('intval', (array) ($viewPermissions[$scopeKey] ?? []));
        $uploadIds = array_map('intval', (array) ($uploadPermissions[$scopeKey] ?? []));
        $manageIds = array_map('intval', (array) ($managePermissions[$scopeKey] ?? []));
        if ($role === 'Administrador') {
            $catalog = permission_catalog_items()[$scopeKey]['items'] ?? [];
            $viewIds = array_map(static fn(array $item): int => (int) $item['id'], $catalog);
            $uploadIds = $viewIds;
            $manageIds = $viewIds;
        }
        foreach (array_unique(array_merge($viewIds, $uploadIds, $manageIds)) as $catalogId) {
            if ($catalogId <= 0) continue;
            $insert->execute([
                'user_id' => $userId,
                'scope_key' => $scopeKey,
                'catalog_id' => $catalogId,
                'can_view' => in_array($catalogId, $viewIds, true) ? 1 : 0,
                'can_upload' => in_array($catalogId, $uploadIds, true) ? 1 : 0,
                'can_manage_catalog' => in_array($catalogId, $manageIds, true) ? 1 : 0,
            ]);
        }
    }
}
