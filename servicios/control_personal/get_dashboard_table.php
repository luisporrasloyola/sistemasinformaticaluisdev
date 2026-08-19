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
function table_document_status(string $endDate, int $requirementId): array
{
    return status_alert_document_status($endDate, 'requisitos.pmi_individual', $requirementId, true);
}

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

// Normalize filter inputs
$normFilterCompany = mb_strtolower($filterCompany, 'UTF-8');
$normFilterName = mb_strtolower($filterName, 'UTF-8');
$normFilterPosition = mb_strtolower($filterPosition, 'UTF-8');
$normFilterRequirement = mb_strtolower($filterRequirement, 'UTF-8');

// 2. Fetch all raw rows matching basic database filters (faster than pulling all data)
// We join required tables to get name strings
$query = "SELECT
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
        u.name AS registered_by
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    LEFT JOIN worker_positions wp ON wp.worker_id = w.id
    LEFT JOIN positions p ON p.id = wp.position_id
    LEFT JOIN worker_requirements wr ON wr.worker_id = w.id AND wr.position_id = p.id
    LEFT JOIN requirements_catalog rc ON rc.id = wr.requirement_id
    LEFT JOIN users u ON u.id = wr.registered_by_user_id
    LEFT JOIN users observed_by ON observed_by.id = wr.observation_by_user_id
    LEFT JOIN users resolved_by ON resolved_by.id = wr.observation_resolved_by_user_id
    ORDER BY c.name, w.full_name, p.name, rc.name";

try {
    $rows = db()->query($query)->fetchAll();
    
    // Process and filter in PHP
    $filteredRows = [];
    
    foreach ($rows as $row) {
        $hasRequirement = !empty($row['requirement_row_id']);
        if ($hasRequirement) {
            $status = table_document_status($row['end_date'], (int) $row['requirement_id']);
            $stateKey = $status['key'];
            $stateText = $status['label'];
            $stateClass = match ($stateKey) {
                'rojo' => 'text-bg-danger',
                'amarillo' => 'text-bg-warning',
                default => 'text-bg-success',
            };
        } else {
            $stateKey = 'sin_estado';
            $stateText = 'SIN ESTADO';
            $stateClass = 'text-bg-secondary';
        }

        $company = (string) $row['company'];
        $positionText = $row['position_name'] ? (string) $row['position_name'] : 'Sin puesto asignado';
        $requirementText = $hasRequirement ? (string) $row['requirement_name'] : 'No tiene requisitos';
        $observationText = $hasRequirement ? (string) ($row['observations'] ?? '') : '';
        $editableObservation = table_editable_observation($observationText);
        $observationStatus = $hasRequirement ? (string) ($row['observation_status'] ?? 'none') : 'none';
        
        if ($observationStatus === 'corrected') {
            $observationStatus = 'observed';
        }
        if ($observationText !== '' && $observationStatus === 'none') {
            $observationStatus = 'observed';
        }
        $observationMeta = table_observation_status_meta($observationStatus);

        // Normalize text for filtering
        $normCompany = mb_strtolower($company, 'UTF-8');
        $normNameAndDoc = mb_strtolower($row['full_name'] . ' ' . $row['document_number'], 'UTF-8');
        $normPosition = mb_strtolower($positionText, 'UTF-8');
        $normRequirement = mb_strtolower($requirementText, 'UTF-8');

        // Apply filters
        if ($filterCompany !== '' && $normCompany !== $normFilterCompany) {
            continue;
        }
        if ($filterName !== '' && !str_contains($normNameAndDoc, $normFilterName)) {
            continue;
        }
        if ($filterPosition !== '' && $normPosition !== $normFilterPosition) {
            continue;
        }
        if ($filterRequirement !== '' && $normRequirement !== $normFilterRequirement) {
            continue;
        }
        if ($filterState !== '' && $stateKey !== $filterState) {
            continue;
        }
        if ($filterObservationState !== '') {
            if ($filterObservationState === 'observed' && $observationStatus !== 'observed') {
                continue;
            }
            if ($filterObservationState === 'approved' && !in_array($observationStatus, ['none', 'approved'], true)) {
                continue;
            }
        }

        $filteredRows[] = [
            'company' => $company,
            'name' => (string) $row['full_name'],
            'document' => (string) $row['document_number'],
            'position' => $positionText,
            'requirement' => $requirementText,
            'requirement_row_id' => $hasRequirement ? (int) $row['requirement_row_id'] : 0,
            'state_key' => $stateKey,
            'state_text' => $stateText,
            'state_class' => $stateClass,
            'registered_by' => $hasRequirement ? (string) ($row['registered_by'] ?? '') : '',
            'observations' => $observationText,
            'editable_observation' => $editableObservation,
            'observation_status' => $observationStatus,
            'observation_label' => $observationMeta['label'],
            'observation_badge' => $observationMeta['badge'],
            'observation_row_class' => $observationMeta['row_class'],
            'observation_button_class' => $observationMeta['button_class'],
            'observation_button_title' => $observationMeta['button_title'],
            'observation_by' => $hasRequirement ? (string) ($row['observation_by'] ?? '') : '',
            'observation_at' => table_format_datetime($row['observation_at'] ?? null),
            'observation_resolved_by' => $hasRequirement ? (string) ($row['observation_resolved_by'] ?? '') : '',
            'observation_resolved_at' => table_format_datetime($row['observation_resolved_at'] ?? null),
            'file_path' => $hasRequirement ? (string) ($row['file_path'] ?? '') : '',
            'file_name' => $hasRequirement ? (string) ($row['original_file_name'] ?? $requirementText . '.pdf') : '',
        ];
    }

    $totalRecords = count($filteredRows);
    $totalPages = (int) ceil($totalRecords / $limit);
    $paginatedRows = array_slice($filteredRows, $offset, $limit);

    // Render HTML rows
    $html = '';
    if (empty($paginatedRows)) {
        $html .= '<tr><td colspan="7" class="text-center text-muted py-4">No hay documentos para mostrar.</td></tr>';
    } else {
        foreach ($paginatedRows as $item) {
            $rowClass = e($item['observation_row_class']);
            $fileButton = '';
            if ($item['file_path'] !== '') {
                $isImage = in_array(strtolower(pathinfo($item['file_path'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
                $btnClass = $isImage ? 'btn-outline-primary' : 'btn-outline-danger';
                $iconClass = $isImage ? 'fa-file-image' : 'fa-file-pdf';
                $fileButton = '<button class="btn btn-sm ' . $btnClass . ' dashboard-pdf-preview-btn" type="button" title="Previsualizar archivo" data-pdf-url="' . e(APP_URL . '/' . $item['file_path']) . '" data-pdf-title="' . e($item['file_name']) . '"><i class="fa-solid ' . $iconClass . '"></i></button>';
            } else {
                $fileButton = '<button class="btn btn-sm btn-outline-secondary dashboard-pdf-preview-btn" type="button" title="Sin archivo adjunto" disabled><i class="fa-solid fa-paperclip"></i></button>';
            }

            $obsButton = '';
            if ($item['requirement_row_id'] > 0) {
                $obsButton = '<button class="btn btn-sm ' . e($item['observation_button_class']) . ' dashboard-observation-btn" type="button" title="' . e($item['observation_button_title']) . '"
                    data-requirement-id="' . (int) $item['requirement_row_id'] . '"
                    data-worker-name="' . e($item['name']) . '"
                    data-requirement-name="' . e($item['requirement']) . '"
                    data-observations="' . e($item['editable_observation']) . '"
                    data-observation-status="' . e($item['observation_status']) . '"
                    data-observation-label="' . e($item['observation_label']) . '"
                    data-observation-by="' . e($item['observation_by']) . '"
                    data-observation-at="' . e($item['observation_at']) . '"
                    data-resolved-by="' . e($item['observation_resolved_by']) . '"
                    data-resolved-at="' . e($item['observation_resolved_at']) . '"
                ><i class="fa-solid fa-comment-dots"></i></button>';
            } else {
                $obsButton = '<button class="btn btn-sm btn-outline-secondary dashboard-observation-btn" type="button" title="Sin requisito registrado" disabled><i class="fa-solid fa-comment-dots"></i></button>';
            }

            $approveButton = '';
            if (is_admin() && $item['observation_status'] === 'observed' && $item['requirement_row_id'] > 0) {
                $approveButton = ' <button class="btn btn-sm btn-outline-success dashboard-approve-observation-btn" type="button" title="Marcar conforme" data-requirement-id="' . (int) $item['requirement_row_id'] . '"><i class="fa-solid fa-check"></i></button>';
            }

            $html .= '<tr class="' . $rowClass . '"
                data-company="' . e(mb_strtolower($item['company'], 'UTF-8')) . '"
                data-name="' . e(mb_strtolower($item['name'] . ' ' . $item['document'], 'UTF-8')) . '"
                data-position="' . e(mb_strtolower($item['position'], 'UTF-8')) . '"
                data-requirement="' . e(mb_strtolower($item['requirement'], 'UTF-8')) . '"
                data-state="' . e($item['state_key']) . '"
                data-observation-state="' . e($item['observation_status']) . '"
            >';
            $html .= '<td>' . e($item['company']) . '</td>';
            $html .= '<td><strong>' . e($item['name']) . '</strong><span class="d-block text-muted small">' . e($item['document']) . '</span></td>';
            $html .= '<td>' . e($item['position']) . '</td>';
            $html .= '<td>' . e($item['requirement']) . '</td>';
            $html .= '<td><span class="badge ' . e($item['state_class']) . '">' . e($item['state_text']) . '</span></td>';
            $html .= '<td>' . e($item['registered_by']) . '</td>';
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
