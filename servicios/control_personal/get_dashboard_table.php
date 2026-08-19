<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/status_alerts.php';

require_login();
require_module_access('dashboard');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Helper functions (copied from panel.php for compatibility)
function table_editable_observation(string $value): string
{
    if ((str_starts_with($value, 'Administrador ') || str_starts_with($value, 'Gestor ')) && str_contains($value, "\n")) {
        return trim((string) substr($value, (int) strpos($value, "\n") + 1));
    }
    return $value;
}

function table_format_datetime(mixed $value): string
{
    if (!$value) {
        return '';
    }
    try {
        return (new DateTimeImmutable((string) $value))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return '';
    }
}

function table_observation_status_meta(string $status): array
{
    return match ($status) {
        'observed' => [
            'label' => 'Observado',
            'badge' => 'text-bg-warning',
            'row_class' => 'dashboard-row-observed',
            'button_class' => 'btn-outline-warning',
            'button_title' => 'Observado',
        ],
        'approved' => [
            'label' => 'Conforme',
            'badge' => 'text-bg-success',
            'row_class' => '',
            'button_class' => 'btn-outline-success',
            'button_title' => 'Conforme',
        ],
        default => [
            'label' => 'Sin observacion',
            'badge' => 'text-bg-secondary',
            'row_class' => '',
            'button_class' => 'btn-outline-success',
            'button_title' => 'Conforme',
        ],
    };
}

// 1. Parse request parameters
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(1, (int) ($_GET['limit'] ?? 10));
$offset = ($page - 1) * $limit;

$filterCompany = trim((string) ($_GET['company'] ?? ''));
$filterName = trim((string) ($_GET['name'] ?? ''));
$filterPosition = trim((string) ($_GET['position'] ?? ''));
$filterRequirement = trim((string) ($_GET['requirement'] ?? ''));
$filterState = trim((string) ($_GET['state'] ?? ''));
$filterObservationState = trim((string) ($_GET['observation_state'] ?? ''));

// 2. Base query using a derived table so we can filter by computed columns in SQL
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

$sqlSelect = "SELECT * FROM (
    SELECT
        wr.id AS requirement_row_id,
        wr.end_date,
        w.id AS worker_id,
        w.full_name,
        w.document_number,
        COALESCE(c.name, 'Sin empresa') AS company,
        p.id AS position_id,
        p.name AS position_name,
        wr.requirement_id,
        rc.name AS requirement_name,
        wr.observations,
        wr.observation_status,
        wr.observation_at,
        wr.observation_resolved_at,
        observed_by.name AS observation_by,
        resolved_by.name AS observation_resolved_by,
        wr.file_path,
        wr.original_file_name,
        u.name AS registered_by,
        CASE 
            WHEN wr.id IS NULL THEN 'sin_estado'
            WHEN wr.end_date <= :today1 THEN 'rojo'
            WHEN DATE_SUB(wr.end_date, INTERVAL COALESCE(sas.warning_days, 30) DAY) <= :today2 THEN 'amarillo'
            ELSE 'verde'
        END AS computed_state,
        CASE
            WHEN wr.observation_status = 'corrected' THEN 'observed'
            WHEN COALESCE(wr.observations, '') != '' AND wr.observation_status = 'none' THEN 'observed'
            ELSE COALESCE(wr.observation_status, 'none')
        END AS computed_observation_status
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    LEFT JOIN worker_positions wp ON wp.worker_id = w.id
    LEFT JOIN positions p ON p.id = wp.position_id
    LEFT JOIN worker_requirements wr ON wr.worker_id = w.id AND wr.position_id = p.id
    LEFT JOIN requirements_catalog rc ON rc.id = wr.requirement_id
    LEFT JOIN users u ON u.id = wr.registered_by_user_id
    LEFT JOIN users observed_by ON observed_by.id = wr.observation_by_user_id
    LEFT JOIN users resolved_by ON resolved_by.id = wr.observation_resolved_by_user_id
    LEFT JOIN status_alert_settings sas ON sas.scope_key = 'requisitos.pmi_individual' AND sas.catalog_id = wr.requirement_id
) t WHERE 1=1";

$whereClauses = [];
$queryParams = [
    'today1' => $today,
    'today2' => $today,
];

// Add filters to WHERE clause
if ($filterCompany !== '') {
    $whereClauses[] = "t.company = :company";
    $queryParams['company'] = $filterCompany;
}
if ($filterName !== '') {
    $whereClauses[] = "(t.full_name LIKE :name1 OR t.document_number LIKE :name2)";
    $queryParams['name1'] = '%' . $filterName . '%';
    $queryParams['name2'] = '%' . $filterName . '%';
}
if ($filterPosition !== '') {
    $whereClauses[] = "t.position_name = :position";
    $queryParams['position'] = $filterPosition;
}
if ($filterRequirement !== '') {
    $whereClauses[] = "t.requirement_name = :requirement";
    $queryParams['requirement'] = $filterRequirement;
}
if ($filterState !== '') {
    $whereClauses[] = "t.computed_state = :state";
    $queryParams['state'] = $filterState;
}
if ($filterObservationState !== '') {
    if ($filterObservationState === 'observed') {
        $whereClauses[] = "t.computed_observation_status = 'observed'";
    } elseif ($filterObservationState === 'approved') {
        $whereClauses[] = "t.computed_observation_status IN ('none', 'approved')";
    }
}

if (!empty($whereClauses)) {
    $sqlSelect .= " AND " . implode(" AND ", $whereClauses);
}

// Order by sorting columns
$sqlSelect .= " ORDER BY t.company, t.full_name, t.position_name, t.requirement_name";

try {
    $pdo = db();
    
    // 3. Count total matching records (Executed separately for performance)
    $sqlCount = "SELECT COUNT(*) FROM (" . $sqlSelect . ") count_t";
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($queryParams);
    $totalRecords = (int) $stmtCount->fetchColumn();

    $totalPages = (int) ceil($totalRecords / $limit);

    // 4. Paginated Select query with LIMIT and OFFSET (MySQL native pagination)
    $sqlSelectPaginated = $sqlSelect . " LIMIT :limit OFFSET :offset";
    
    $stmtSelect = $pdo->prepare($sqlSelectPaginated);
    
    // Bind all string params
    foreach ($queryParams as $paramKey => $paramVal) {
        $stmtSelect->bindValue($paramKey, $paramVal, PDO::PARAM_STR);
    }
    
    // Bind limit & offset as integers (crucial for MySQL PDO strict mode)
    $stmtSelect->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmtSelect->bindValue('offset', $offset, PDO::PARAM_INT);
    
    $stmtSelect->execute();
    $paginatedRows = $stmtSelect->fetchAll();

    // Render HTML rows
    $html = '';
    if (empty($paginatedRows)) {
        $html .= '<tr><td colspan="7" class="text-center text-muted py-4">No hay documentos para mostrar.</td></tr>';
    } else {
        foreach ($paginatedRows as $row) {
            $hasRequirement = !empty($row['requirement_row_id']);
            $stateKey = $row['computed_state'];
            
            $stateText = match ($stateKey) {
                'rojo' => 'NO APTO',
                'amarillo' => 'POR VENCER',
                'verde' => 'APTO',
                default => 'SIN ESTADO'
            };
            
            $stateClass = match ($stateKey) {
                'rojo' => 'text-bg-danger',
                'amarillo' => 'text-bg-warning',
                'verde' => 'text-bg-success',
                default => 'text-bg-secondary'
            };

            $company = (string) $row['company'];
            $positionText = $row['position_name'] ? (string) $row['position_name'] : 'Sin puesto asignado';
            $requirementText = $hasRequirement ? (string) $row['requirement_name'] : 'No tiene requisitos';
            $observationText = $hasRequirement ? (string) ($row['observations'] ?? '') : '';
            $editableObservation = table_editable_observation($observationText);
            $observationStatus = $row['computed_observation_status'];
            
            $observationMeta = table_observation_status_meta($observationStatus);

            $rowClass = e($observationMeta['row_class']);
            
            // Build buttons
            $fileButton = '';
            if ($hasRequirement && $row['file_path'] !== null && $row['file_path'] !== '') {
                $isImage = in_array(strtolower(pathinfo((string) $row['file_path'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
                $btnClass = $isImage ? 'btn-outline-primary' : 'btn-outline-danger';
                $iconClass = $isImage ? 'fa-file-image' : 'fa-file-pdf';
                $fileButton = '<button class="btn btn-sm ' . $btnClass . ' dashboard-pdf-preview-btn" type="button" title="Previsualizar archivo" data-pdf-url="' . e(APP_URL . '/' . $row['file_path']) . '" data-pdf-title="' . e($row['original_file_name'] ?? $requirementText . '.pdf') . '"><i class="fa-solid ' . $iconClass . '"></i></button>';
            } else {
                $fileButton = '<button class="btn btn-sm btn-outline-secondary dashboard-pdf-preview-btn" type="button" title="Sin archivo adjunto" disabled><i class="fa-solid fa-paperclip"></i></button>';
            }

            $obsButton = '';
            if ($hasRequirement) {
                $obsButton = '<button class="btn btn-sm ' . e($observationMeta['button_class']) . ' dashboard-observation-btn" type="button" title="' . e($observationMeta['button_title']) . '"
                    data-requirement-id="' . (int) $row['requirement_row_id'] . '"
                    data-worker-name="' . e($row['full_name']) . '"
                    data-requirement-name="' . e($requirementText) . '"
                    data-observations="' . e($editableObservation) . '"
                    data-observation-status="' . e($observationStatus) . '"
                    data-observation-label="' . e($observationMeta['label']) . '"
                    data-observation-by="' . e($row['observation_by'] ?? '') . '"
                    data-observation-at="' . e(table_format_datetime($row['observation_at'] ?? null)) . '"
                    data-resolved-by="' . e($row['observation_resolved_by'] ?? '') . '"
                    data-resolved-at="' . e(table_format_datetime($row['observation_resolved_at'] ?? null)) . '"
                ><i class="fa-solid fa-comment-dots"></i></button>';
            } else {
                $obsButton = '<button class="btn btn-sm btn-outline-secondary dashboard-observation-btn" type="button" title="Sin requisito registrado" disabled><i class="fa-solid fa-comment-dots"></i></button>';
            }

            $approveButton = '';
            if (is_admin() && $observationStatus === 'observed' && $hasRequirement) {
                $approveButton = ' <button class="btn btn-sm btn-outline-success dashboard-approve-observation-btn" type="button" title="Marcar conforme" data-requirement-id="' . (int) $row['requirement_row_id'] . '"><i class="fa-solid fa-check"></i></button>';
            }

            $html .= '<tr class="' . $rowClass . '"
                data-company="' . e(mb_strtolower($company, 'UTF-8')) . '"
                data-name="' . e(mb_strtolower($row['full_name'] . ' ' . $row['document_number'], 'UTF-8')) . '"
                data-position="' . e(mb_strtolower($positionText, 'UTF-8')) . '"
                data-requirement="' . e(mb_strtolower($requirementText, 'UTF-8')) . '"
                data-state="' . e($stateKey) . '"
                data-observation-state="' . e($observationStatus) . '"
            >';
            $html .= '<td>' . e($company) . '</td>';
            $html .= '<td><strong>' . e($row['full_name']) . '</strong><span class="d-block text-muted small">' . e($row['document_number']) . '</span></td>';
            $html .= '<td>' . e($positionText) . '</td>';
            $html .= '<td>' . e($requirementText) . '</td>';
            $html .= '<td><span class="badge ' . e($stateClass) . '">' . e($stateText) . '</span></td>';
            $html .= '<td>' . e($row['registered_by'] ?? '') . '</td>';
            $html .= '<td><div class="dashboard-action-group">' . $fileButton . ' ' . $obsButton . $approveButton . '</div></td>';
            $html .= '</tr>';
        }
    }

    echo json_encode([
        'ok' => true,
        'html' => $html,
        'total_records' => $totalRecords,
        'total_pages' => $totalPages,
        'current_page' => $page,
        'limit' => $limit,
        'start_record' => $totalRecords > 0 ? $offset + 1 : 0,
        'end_record' => min($offset + $limit, $totalRecords)
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'message' => 'Error al cargar la tabla: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
