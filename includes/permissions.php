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
                'control_personal.calendario_laboral' => 'Calendario laboral',
                'control_personal.horarios' => 'Plantillas de horarios',
                'control_personal.puntos_marcacion' => 'Lugares de marcación',
                'control_personal.asignaciones' => 'Asignaciones',
                'control_personal.programacion' => 'Calendario y programación de jornadas',
                'control_personal.control_asistencia' => 'Control de asistencia',
                'control_personal.reportes' => 'Reporte de marcaciones',
                'control_personal.reporte_asistencias' => 'Reporte de asistencias',
            ],
        ],
        'requisitos' => [
            'label' => 'Requisitos',
            'children' => [
                // Se conserva la clave técnica para no invalidar permisos ya asignados.
                'control_personal.personal' => 'Personal',
                'requisitos.pmi_individual' => 'PMI Individual',
                'requisitos.pmi_masivo' => 'Requisito PMI Masivo',
            ],
        ],
        'maquinaria' => [
            'label' => 'Maquinaria',
            'children' => [
                'maquinaria.dashboard' => 'Dashboard',
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
        'empresa_maquirenta' => [
            'label' => 'Empresa Maquirenta',
            'children' => [
                'empresa_maquirenta.dashboard' => 'Dashboard',
                'empresa_maquirenta.datos_generales' => 'Datos generales',
                'empresa_maquirenta.documentos' => 'Central Térmica Ventanilla',
                'empresa_maquirenta.informes' => 'Central Térmica Ventanilla - Informes',
                'empresa_maquirenta.charla_preoperacional' => "Central Térmica Ventanilla - Charla preoperacional",
                'empresa_maquirenta.seguridad' => 'Central Térmica Santa Rosa',
                'empresa_maquirenta.informes_santa_rosa' => "Central Térmica Santa Rosa - Informes",
                'empresa_maquirenta.charla_preoperacional_santa_rosa' => 'Central Térmica Santa Rosa - Charla preoperacional',
                'empresa_maquirenta.formatos' => 'Formatos',
                'empresa_maquirenta.personal' => 'Personal',
                'empresa_maquirenta.pmi_individual' => 'PMI Individual',
                'empresa_maquirenta.pms' => 'Central Térmica Ventanilla - PMS',
                'empresa_maquirenta.permiso_trabajo' => 'Central Térmica Ventanilla - Permiso de trabajo',
                'empresa_maquirenta.pms_santa_rosa' => 'Central Térmica Santa Rosa - PMS',
                'empresa_maquirenta.permiso_trabajo_santa_rosa' => 'Central Térmica Santa Rosa - Permiso de trabajo',
            ],
        ],
        'usuarios' => ['label' => 'Usuarios', 'children' => []],
        'configuracion' => [
            'label' => 'Configuración',
            'children' => [
                'configuracion.alertas_estado' => 'Alertas del estado',
            ],
        ],
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
        'empresa_maquirenta.pmi_individual' => [
            'label' => 'Empresa Maquirenta - Formatos - PMI Individual',
            'table' => 'empresa_maquirenta_formato_requisitos_catalogo',
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
        'empresa_maquirenta.documentos' => [
            'label' => 'Empresa Maquirenta - Central Térmica Ventanilla',
            'table' => 'empresa_maquirenta_documentos_catalogo',
            'name_column' => 'nombre',
            'active_column' => 'estado',
        ],
        'empresa_maquirenta.seguridad' => [
            'label' => 'Empresa Maquirenta - Central Térmica Santa Rosa',
            'table' => 'empresa_maquirenta_seguridad_catalogo',
            'name_column' => 'nombre',
            'active_column' => 'estado',
        ],
        'empresa_maquirenta.formatos' => [
            'label' => 'Empresa Maquirenta - Formatos',
            'table' => 'empresa_maquirenta_formatos_catalogo',
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
            'control_personal.dashboard' => true,
            'control_personal.reporte_asistencias' => true,
            'requisitos' => true,
            'control_personal.personal' => true,
            'requisitos.pmi_individual' => true,
            'empresa_maquirenta' => true,
            'empresa_maquirenta.personal' => true,
            'empresa_maquirenta.pmi_individual' => true,
        ];
    }
    return [];
}

function permission_personal_configurable_modules(): array
{
    return [
        'control_personal.dashboard',
        'control_personal.reporte_asistencias',
        'control_personal.personal',
        'requisitos.pmi_individual',
        'empresa_maquirenta.personal',
        'empresa_maquirenta.pmi_individual',
    ];
}
function permission_personal_pmi_scopes(): array
{
    return ['requisitos.pmi_individual', 'empresa_maquirenta.pmi_individual'];
}

function permission_personal_default_requirement_names(): array
{
    return ['contrato de trabajo', 'camo', 'dni', 'sctr', 'vida ley', 'boleta firmada'];
}

function permission_normalize_requirement_name(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    return preg_replace('/[^a-z0-9]+/', ' ', $converted !== false ? $converted : $name) ?: '';
}

function permission_personal_default_documents(): array
{
    $permissions = [];
    $allowed = permission_personal_default_requirement_names();
    foreach (permission_catalog_items() as $scopeKey => $scope) {
        if (!in_array($scopeKey, permission_personal_pmi_scopes(), true)) continue;
        foreach ($scope['items'] as $item) {
            $normalized = trim(permission_normalize_requirement_name((string) $item['name']));
            $matches = in_array($normalized, $allowed, true);
            if (!$matches) continue;
            $permissions[$scopeKey][(string) (int) $item['id']] = ['view' => true, 'upload' => false, 'manage' => false];
        }
    }
    return $permissions;
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

function permission_default_documents_all(): array
{
    $permissions = [];
    foreach (permission_catalog_items() as $scopeKey => $scope) {
        foreach ($scope['items'] as $item) {
            $catalogId = (string) (int) $item['id'];
            $permissions[$scopeKey][$catalogId] = [
                'view' => true,
                'upload' => true,
                'manage' => true,
            ];
        }
    }
    return $permissions;
}

function permission_payload_for_user(int $userId, string $role): array
{
    $defaultModules = permission_default_modules_for_role($role);
    $modulePermissions = $defaultModules;
    $documentPermissions = $role === 'Personal' ? permission_personal_default_documents() : [];

    if ($role === 'Administrador') {
        return [
            'modules' => array_keys(array_filter($defaultModules)),
            'documents' => permission_default_documents_all(),
        ];
    }

    if ($userId > 0) {
        try {
            $stmt = db()->prepare('SELECT module_key, can_access FROM user_module_permissions WHERE user_id = :user_id');
            $stmt->execute(['user_id' => $userId]);
            $storedModules = $stmt->fetchAll();
            $hasPersonalConfiguration = $role === 'Personal' && (bool) array_filter($storedModules, static fn(array $row): bool => in_array((string) $row['module_key'], permission_personal_configurable_modules(), true));
            if ($storedModules && ($role !== 'Personal' || $hasPersonalConfiguration)) {
                $modulePermissions = $role === 'Personal' ? $defaultModules : [];
                foreach ($storedModules as $row) {
                    $storedKey = (string) $row['module_key'];
                    if ((int) $row['can_access'] === 1) $modulePermissions[$storedKey] = true;
                    elseif ($role === 'Personal') unset($modulePermissions[$storedKey]);
                }
            }
        } catch (Throwable $e) {
            $modulePermissions = $defaultModules;
        }

        try {
            $stmt = db()->prepare('SELECT scope_key, catalog_id, can_view, can_upload, can_manage_catalog FROM user_document_permissions WHERE user_id = :user_id');
            $stmt->execute(['user_id' => $userId]);
            $storedDocuments = $stmt->fetchAll();
            if ($storedDocuments && $role === 'Personal') $documentPermissions = [];
            foreach ($storedDocuments as $row) {
                $scope = (string) $row['scope_key'];
                $catalogId = (int) $row['catalog_id'];
                $documentPermissions[$scope][(string) $catalogId] = [
                    'view' => (int) $row['can_view'] === 1,
                    'upload' => (int) $row['can_upload'] === 1,
                    'manage' => (int) ($row['can_manage_catalog'] ?? 0) === 1,
                ];
            }
        } catch (Throwable $e) {
            $documentPermissions = $role === 'Personal' ? permission_personal_default_documents() : [];
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
        $allowed = permission_personal_configurable_modules();
        $selectedModules = array_values(array_intersect($selectedModules, $allowed));
        $selectedModules[] = 'control_personal';
        $selectedModules[] = 'control_personal.control_asistencia';
        if (array_intersect($selectedModules, ['control_personal.personal', 'requisitos.pmi_individual'])) $selectedModules[] = 'requisitos';
        if (array_intersect($selectedModules, ['empresa_maquirenta.personal', 'empresa_maquirenta.pmi_individual'])) $selectedModules[] = 'empresa_maquirenta';
        $selectedModules = array_values(array_unique($selectedModules));
    }

    db()->prepare('DELETE FROM user_module_permissions WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    if ($role === 'Personal') {
        $storedKeys = array_values(array_unique(array_merge(
            ['control_personal', 'control_personal.control_asistencia', 'requisitos', 'empresa_maquirenta'],
            permission_personal_configurable_modules()
        )));
        $stmt = db()->prepare('INSERT INTO user_module_permissions (user_id, module_key, can_access) VALUES (:user_id, :module_key, :can_access)');
        foreach ($storedKeys as $moduleKey) {
            $stmt->execute(['user_id' => $userId, 'module_key' => $moduleKey, 'can_access' => in_array($moduleKey, $selectedModules, true) ? 1 : 0]);
        }
    } elseif ($selectedModules) {
        $stmt = db()->prepare('INSERT INTO user_module_permissions (user_id, module_key, can_access) VALUES (:user_id, :module_key, 1)');
        foreach ($selectedModules as $moduleKey) $stmt->execute(['user_id' => $userId, 'module_key' => $moduleKey]);
    }

    db()->prepare('DELETE FROM user_document_permissions WHERE user_id = :user_id')->execute(['user_id' => $userId]);
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
        if ($role === 'Personal') {
            if (!in_array($scopeKey, permission_personal_pmi_scopes(), true)) continue;
            $uploadIds = [];
            $manageIds = [];
        }
        if ($role === 'Personal' && !$viewIds) {
            $catalog = permission_catalog_items()[$scopeKey]['items'] ?? [];
            $sentinelId = (int) ($catalog[0]['id'] ?? 0);
            if ($sentinelId > 0) {
                $insert->execute([
                    'user_id' => $userId,
                    'scope_key' => $scopeKey,
                    'catalog_id' => $sentinelId,
                    'can_view' => 0,
                    'can_upload' => 0,
                    'can_manage_catalog' => 0,
                ]);
            }
            continue;
        }
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
