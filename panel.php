<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/config/database.php';
require_module_access('dashboard');

$documentRows = db()->query("SELECT end_date FROM worker_requirements")->fetchAll();
$counts = ['verde' => 0, 'amarillo' => 0, 'rojo' => 0];

foreach ($documentRows as $doc) {
    $status = dashboard_document_status($doc['end_date']);
    $counts[$status['key']]++;
}

$hasObservationAudit = dashboard_db_column_exists('worker_requirements', 'observation_status');
$observationSelect = $hasObservationAudit
    ? "wr.observation_status,
        wr.observation_at,
        wr.observation_resolved_at,
        observed_by.name AS observation_by,
        resolved_by.name AS observation_resolved_by,"
    : "'none' AS observation_status,
        NULL AS observation_at,
        NULL AS observation_resolved_at,
        '' AS observation_by,
        '' AS observation_resolved_by,";
$observationJoin = $hasObservationAudit
    ? "LEFT JOIN users observed_by ON observed_by.id = wr.observation_by_user_id
    LEFT JOIN users resolved_by ON resolved_by.id = wr.observation_resolved_by_user_id"
    : '';

$rows = db()->query("SELECT
        wr.id AS requirement_row_id,
        wr.end_date,
        w.id AS worker_id,
        w.full_name,
        w.document_number,
        COALESCE(c.name, 'Sin empresa') AS company,
        p.id AS position_id,
        p.name AS position_name,
        rc.name AS requirement_name,
        wr.observations,
        {$observationSelect}
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
    {$observationJoin}
    ORDER BY c.name, w.full_name, p.name, rc.name")->fetchAll();

$dashboardRows = [];
$companyCounts = [];
$positionCounts = [];
$companies = [];
$positions = [];
$requirements = [];

$companyWorkerKeys = [];
$positionWorkerKeys = [];

foreach ($rows as $row) {
    $hasRequirement = !empty($row['requirement_row_id']);
    if ($hasRequirement) {
        $status = dashboard_document_status($row['end_date']);
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
    $editableObservation = dashboard_editable_observation($observationText);
    $observationStatus = $hasRequirement ? (string) ($row['observation_status'] ?? 'none') : 'none';
    if ($observationText !== '' && $observationStatus === 'none') {
        $observationStatus = 'observed';
    }
    $observationMeta = dashboard_observation_status_meta($observationStatus);
    $companies[$company] = true;
    $positions[$positionText] = true;

    $companyWorkerKey = $company . '|' . (int) $row['worker_id'];
    if (!isset($companyWorkerKeys[$companyWorkerKey])) {
        $companyCounts[$company] = ($companyCounts[$company] ?? 0) + 1;
        $companyWorkerKeys[$companyWorkerKey] = true;
    }

    $positionWorkerKey = $positionText . '|' . (int) $row['worker_id'];
    if (!isset($positionWorkerKeys[$positionWorkerKey])) {
        $positionCounts[$positionText] = ($positionCounts[$positionText] ?? 0) + 1;
        $positionWorkerKeys[$positionWorkerKey] = true;
    }

    $requirements[$requirementText] = true;

    $dashboardRows[] = [
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
        'observation_at' => dashboard_format_datetime($row['observation_at'] ?? null),
        'observation_resolved_by' => $hasRequirement ? (string) ($row['observation_resolved_by'] ?? '') : '',
        'observation_resolved_at' => dashboard_format_datetime($row['observation_resolved_at'] ?? null),
        'file_path' => $hasRequirement ? (string) ($row['file_path'] ?? '') : '',
        'file_name' => $hasRequirement ? (string) ($row['original_file_name'] ?? $requirementText . '.pdf') : '',
    ];
}
ksort($companies);
ksort($positions);
ksort($requirements);

$companyCounts = [];
$companyChartRows = db()->query("SELECT COALESCE(c.name, 'Sin empresa') AS company, COUNT(w.id) AS total
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    GROUP BY COALESCE(c.name, 'Sin empresa')
    ORDER BY total DESC, company ASC")->fetchAll();
foreach ($companyChartRows as $chartRow) {
    $companyCounts[(string) $chartRow['company']] = (int) $chartRow['total'];
}

$positionCounts = [];
$positionChartRows = db()->query("SELECT COALESCE(p.name, 'Sin puesto asignado') AS position_name, COUNT(DISTINCT wp.worker_id) AS total
    FROM worker_positions wp
    LEFT JOIN positions p ON p.id = wp.position_id
    GROUP BY COALESCE(p.name, 'Sin puesto asignado')
    ORDER BY total DESC, position_name ASC")->fetchAll();
foreach ($positionChartRows as $chartRow) {
    $positionCounts[(string) $chartRow['position_name']] = (int) $chartRow['total'];
}

$topCompanies = $companyCounts;
$topPositions = $positionCounts;
$total = count($dashboardRows);
$totalDocuments = array_sum($counts);

$chartPayload = [
    'status' => [
        'labels' => ['APTO', 'POR VENCER', 'NO APTO'],
        'values' => [$counts['verde'], $counts['amarillo'], $counts['rojo']],
        'total' => $totalDocuments,
    ],
    'companies' => [
        'labels' => array_keys($topCompanies),
        'values' => array_values($topCompanies),
    ],
    'positions' => [
        'labels' => array_keys($topPositions),
        'values' => array_values($topPositions),
    ],
];

function dashboard_document_status(string $endDate): array
{
    $today = new DateTimeImmutable('today');
    $end = new DateTimeImmutable($endDate);
    $warningLimit = $today->modify('+30 days');

    if ($end < $today) {
        return ['key' => 'rojo', 'label' => 'NO APTO'];
    }

    if ($end <= $warningLimit) {
        return ['key' => 'amarillo', 'label' => 'POR VENCER'];
    }

    return ['key' => 'verde', 'label' => 'APTO'];
}

function dashboard_db_column_exists(string $table, string $column): bool
{
    try {
        $stmt = db()->prepare('SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
            LIMIT 1');
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function dashboard_editable_observation(string $value): string
{
    if (str_starts_with($value, 'Administrador ') && str_contains($value, "\n")) {
        return trim((string) substr($value, (int) strpos($value, "\n") + 1));
    }

    return $value;
}

function dashboard_format_datetime(mixed $value): string
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

function dashboard_observation_status_meta(string $status): array
{
    return match ($status) {
        'observed' => [
            'label' => 'Observado',
            'badge' => 'text-bg-warning',
            'row_class' => 'dashboard-row-observed',
            'button_class' => 'btn-outline-warning',
            'button_title' => 'Observado',
        ],
        'corrected' => [
            'label' => 'Corregido por revisar',
            'badge' => 'text-bg-info',
            'row_class' => 'dashboard-row-corrected',
            'button_class' => 'btn-outline-info',
            'button_title' => 'Corregido por revisar',
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

require __DIR__ . '/includes/header.php';
?>
<div class="page-title dashboard-title">
    <div>
        <h1>Dashboard</h1>
        <p>Estado documental del personal por empresa y puesto de trabajo.</p>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="executive-card executive-green">
            <div class="executive-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#ffffff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <div class="executive-card-body">
                <span>Total en Verde</span>
                <strong><?= $counts['verde'] ?></strong>
                <small>Documentos Aptos</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="executive-card executive-yellow">
            <div class="executive-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="9" />
                    <polyline points="12 7 12 12 15 12"></polyline>
                </svg>
            </div>
            <div class="executive-card-body">
                <span>Total en Amarillo</span>
                <strong><?= $counts['amarillo'] ?></strong>
                <small>Documentos por vencer</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="executive-card executive-red">
            <div class="executive-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <div class="executive-card-body">
                <span>Total en Rojo</span>
                <strong><?= $counts['rojo'] ?></strong>
                <small>Documentos vencidos</small>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="work-panel dashboard-chart-panel">
            <h2>Sem&aacute;foro general</h2>
            <div class="dashboard-chart-wrapper">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="work-panel dashboard-chart-panel">
            <h2>Personal por empresa</h2>
            <div class="dashboard-chart-wrapper">
                <canvas id="companyChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="work-panel dashboard-chart-panel">
            <h2>Puestos principales</h2>
            <div class="dashboard-chart-wrapper">
                <canvas id="positionChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="work-panel dashboard-detail-panel">
    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
        <h2 class="mb-0">Detalle del personal</h2>
        <a class="btn btn-outline-primary" href="<?= APP_URL ?>/modulos/aliados/personal.php"><i class="fa-solid fa-list me-2"></i>Ver personal</a>
    </div>

    <div class="dashboard-filters mb-3">
        <div>
            <label class="form-label">Empresa</label>
            <select class="form-select" id="dashboardEmpresaFilter">
                <option value="">Todas</option>
                <?php foreach (array_keys($companies) as $company): ?>
                    <option value="<?= e($company) ?>"><?= e($company) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label">Apellidos y Nombres</label>
            <input class="form-control" id="dashboardNombreFilter" type="search" placeholder="Buscar personal">
        </div>
        <div>
            <label class="form-label">Puesto de trabajo</label>
            <select class="form-select" id="dashboardPuestoFilter">
                <option value="">Todos</option>
                <?php foreach (array_keys($positions) as $position): ?>
                    <option value="<?= e($position) ?>"><?= e($position) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label">Requisito</label>
            <select class="form-select" id="dashboardRequisitoFilter">
                <option value="">Todos</option>
                <?php foreach (array_keys($requirements) as $requirement): ?>
                    <option value="<?= e($requirement) ?>"><?= e($requirement) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label">Estado</label>
            <select class="form-select" id="dashboardEstadoFilter">
                <option value="">Todos</option>
                <option value="verde">APTO</option>
                <option value="amarillo">POR VENCER</option>
                <option value="rojo">NO APTO</option>
                <option value="sin_estado">SIN ESTADO</option>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle dashboard-table" id="dashboardPersonalTable">
            <thead>
            <tr>
                <th>Empresa</th>
                <th>Apellidos y Nombres</th>
                <th>Puesto de trabajo</th>
                <th>Requisito</th>
                <th>Estado</th>
                <th>Registrado por</th>
                <th>Acción</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($dashboardRows as $item): ?>
                <tr class="<?= e($item['observation_row_class']) ?>" data-company="<?= e(mb_strtolower($item['company'], 'UTF-8')) ?>" data-name="<?= e(mb_strtolower($item['name'] . ' ' . $item['document'], 'UTF-8')) ?>" data-position="<?= e(mb_strtolower($item['position'], 'UTF-8')) ?>" data-requirement="<?= e(mb_strtolower($item['requirement'], 'UTF-8')) ?>" data-state="<?= e($item['state_key']) ?>">
                    <td><?= e($item['company']) ?></td>
                    <td>
                        <strong><?= e($item['name']) ?></strong>
                        <span class="d-block text-muted small"><?= e($item['document']) ?></span>
                    </td>
                    <td><?= e($item['position']) ?></td>
                    <td><?= e($item['requirement']) ?></td>
                    <td><span class="badge <?= e($item['state_class']) ?>"><?= e($item['state_text']) ?></span></td>
                    <td><?= e($item['registered_by']) ?></td>
                    <td>
                        <div class="dashboard-action-group">
                            <?php if ($item['file_path'] !== ''): ?>
                                <button
                                    class="btn btn-sm btn-outline-danger dashboard-pdf-preview-btn"
                                    type="button"
                                    title="Previsualizar PDF"
                                    data-pdf-url="<?= e(APP_URL . '/' . $item['file_path']) ?>"
                                    data-pdf-title="<?= e($item['file_name']) ?>"
                                >
                                    <i class="fa-solid fa-file-pdf"></i>
                                </button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary dashboard-pdf-preview-btn" type="button" title="Sin PDF adjunto" disabled>
                                    <i class="fa-solid fa-file-pdf"></i>
                                </button>
                            <?php endif; ?>

                            <?php if ($item['requirement_row_id'] > 0 && $hasObservationAudit): ?>
                                <button
                                    class="btn btn-sm <?= e($item['observation_button_class']) ?> dashboard-observation-btn"
                                    type="button"
                                    title="<?= e($item['observation_button_title']) ?>"
                                    data-requirement-id="<?= (int) $item['requirement_row_id'] ?>"
                                    data-worker-name="<?= e($item['name']) ?>"
                                    data-requirement-name="<?= e($item['requirement']) ?>"
                                    data-observations="<?= e($item['editable_observation']) ?>"
                                    data-observation-status="<?= e($item['observation_status']) ?>"
                                    data-observation-label="<?= e($item['observation_label']) ?>"
                                    data-observation-by="<?= e($item['observation_by']) ?>"
                                    data-observation-at="<?= e($item['observation_at']) ?>"
                                    data-resolved-by="<?= e($item['observation_resolved_by']) ?>"
                                    data-resolved-at="<?= e($item['observation_resolved_at']) ?>"
                                >
                                    <i class="fa-solid fa-comment-dots"></i>
                                </button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary dashboard-observation-btn" type="button" title="<?= $hasObservationAudit ? 'Sin requisito registrado' : 'Ejecute el SQL de observaciones para habilitar' ?>" disabled>
                                    <i class="fa-solid fa-comment-dots"></i>
                                </button>
                            <?php endif; ?>

                            <?php if ($hasObservationAudit && is_admin() && in_array($item['observation_status'], ['observed', 'corrected'], true)): ?>
                                <button
                                    class="btn btn-sm btn-outline-success dashboard-approve-observation-btn"
                                    type="button"
                                    title="Marcar conforme"
                                    data-requirement-id="<?= (int) $item['requirement_row_id'] ?>"
                                >
                                    <i class="fa-solid fa-check"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="dashboardObservationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="dashboardObservationForm">
            <div class="modal-header">
                <h5 class="modal-title">Observación del requisito</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" id="dashboardObservationRequirementId">
                <div class="dashboard-observation-summary mb-3">
                    <strong id="dashboardObservationWorker"></strong>
                    <span id="dashboardObservationRequirement"></span>
                </div>
                <div class="dashboard-observation-status mb-3 d-none" id="dashboardObservationStatusBox">
                    <div class="d-flex align-items-center justify-content-between gap-2">
                        <span class="badge" id="dashboardObservationStatusBadge"></span>
                        <small id="dashboardObservationStatusDate"></small>
                    </div>
                    <small class="d-block mt-2" id="dashboardObservationStatusUser"></small>
                    <small class="d-block mt-1" id="dashboardObservationResolvedInfo"></small>
                </div>
                <label class="form-label" for="dashboardObservationText">Observación</label>
                <textarea class="form-control" name="observation" id="dashboardObservationText" rows="5" placeholder="Escriba la observación del administrador..."></textarea>
                <small class="text-muted d-block mt-2">Esta observación se verá al visualizar el requisito.</small>
                <div class="requirement-audit-box mt-3 d-none" id="dashboardObservationAuditBox">
                    <h6>Historial de observación y cambios</h6>
                    <div id="dashboardObservationAuditList"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button>
                <button class="btn btn-primary d-none" type="submit" id="dashboardObservationSubmitBtn"><i class="fa-solid fa-pen-to-square me-2"></i>Actualizar observación</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="dashboardPdfPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable dashboard-pdf-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dashboardPdfPreviewTitle">Previsualizar documento</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <iframe id="dashboardPdfPreviewFrame" title="Previsualizador PDF"></iframe>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
window.dashboardEjecutivoData = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE) ?>;
window.dashboardCanManageObservations = <?= is_admin() ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', () => {
    const modalElement = document.getElementById('dashboardPdfPreviewModal');
    const frame = document.getElementById('dashboardPdfPreviewFrame');
    const title = document.getElementById('dashboardPdfPreviewTitle');
    const modal = modalElement && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalElement) : null;
    const observationModalElement = document.getElementById('dashboardObservationModal');
    const observationForm = document.getElementById('dashboardObservationForm');
    const observationModal = observationModalElement && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(observationModalElement) : null;
    const observationText = document.getElementById('dashboardObservationText');
    const observationSubmitBtn = document.getElementById('dashboardObservationSubmitBtn');
    let originalObservationText = '';

    const syncObservationSubmit = () => {
        if (!window.dashboardCanManageObservations) {
            observationSubmitBtn?.classList.add('d-none');
            return;
        }
        const changed = (observationText?.value || '').trim() !== originalObservationText.trim();
        if (observationSubmitBtn) {
            observationSubmitBtn.innerHTML = originalObservationText.trim() === ''
                ? '<i class="fa-solid fa-pen-to-square me-2"></i>Registrar observación'
                : '<i class="fa-solid fa-pen-to-square me-2"></i>Actualizar observación';
        }
        observationSubmitBtn?.classList.toggle('d-none', !changed);
    };

    document.querySelectorAll('.dashboard-pdf-preview-btn[data-pdf-url]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!modal || !frame || !title) return;
            title.textContent = button.dataset.pdfTitle || 'Previsualizar documento';
            frame.src = button.dataset.pdfUrl || '';
            modal.show();
        });
    });

    modalElement?.addEventListener('hidden.bs.modal', () => {
        if (frame) frame.src = '';
    });

    document.querySelectorAll('.dashboard-observation-btn[data-requirement-id]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!observationModal) return;
            document.getElementById('dashboardObservationRequirementId').value = button.dataset.requirementId || '';
            document.getElementById('dashboardObservationWorker').textContent = button.dataset.workerName || '';
            document.getElementById('dashboardObservationRequirement').textContent = button.dataset.requirementName ? `Requisito: ${button.dataset.requirementName}` : '';
            document.getElementById('dashboardObservationText').value = button.dataset.observations || '';
            if (observationText) {
                observationText.readOnly = !window.dashboardCanManageObservations;
                observationText.placeholder = window.dashboardCanManageObservations
                    ? 'Escriba la observación del administrador...'
                    : 'Solo administradores pueden registrar observaciones.';
            }
            originalObservationText = button.dataset.observations || '';
            syncObservationSubmit();

            const statusBox = document.getElementById('dashboardObservationStatusBox');
            const statusBadge = document.getElementById('dashboardObservationStatusBadge');
            const statusDate = document.getElementById('dashboardObservationStatusDate');
            const statusUser = document.getElementById('dashboardObservationStatusUser');
            const resolvedInfo = document.getElementById('dashboardObservationResolvedInfo');
            const status = button.dataset.observationStatus || 'none';
            statusBox?.classList.toggle('d-none', status === 'none' && !button.dataset.observationAt);
            if (statusBadge) {
                statusBadge.className = 'badge';
                statusBadge.classList.add(status === 'corrected' ? 'text-bg-info' : (status === 'approved' ? 'text-bg-success' : 'text-bg-warning'));
                statusBadge.textContent = button.dataset.observationLabel || 'Observado';
            }
            if (statusDate) statusDate.textContent = button.dataset.observationAt ? `Observado: ${button.dataset.observationAt}` : '';
            if (statusUser) statusUser.textContent = button.dataset.observationBy ? `Observado por: ${button.dataset.observationBy}` : '';
            if (resolvedInfo) {
                resolvedInfo.textContent = button.dataset.resolvedAt
                    ? `Conforme por: ${button.dataset.resolvedBy || 'Administrador'} - ${button.dataset.resolvedAt}`
                    : '';
            }
            renderDashboardObservationAudit(null, []);
            observationModal.show();

            try {
                const response = await fetch(`<?= APP_URL ?>/servicios/obtener_requisito.php?id=${encodeURIComponent(button.dataset.requirementId || '')}`);
                const data = await response.json();
                if (data.ok) {
                    renderDashboardObservationAudit(data.row || null, data.activity || []);
                }
            } catch (error) {
                renderDashboardObservationAudit(null, []);
            }
        });
    });

    observationText?.addEventListener('input', syncObservationSubmit);

    document.querySelectorAll('.dashboard-approve-observation-btn').forEach((button) => {
        button.addEventListener('click', async () => {
            const result = await Swal.fire({
                title: '¿Marcar conforme?',
                text: 'La fila volverá a su estado visual normal y quedará registrado en el historial.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, conforme',
                cancelButtonText: 'Cancelar'
            });
            if (!result.isConfirmed) return;

            const body = new FormData();
            body.append('csrf_token', '<?= e(csrf_token()) ?>');
            body.append('id', button.dataset.requirementId || '');

            const response = await fetch('<?= APP_URL ?>/servicios/marcar_conforme_requisito.php', {
                method: 'POST',
                body
            });
            const data = await response.json();
            if (!data.ok) {
                Swal.fire('Atención', data.message || 'No se pudo registrar la conformidad.', 'warning');
                return;
            }
            window.location.reload();
        });
    });

    observationForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submitButton = observationForm.querySelector('button[type="submit"]');
        if (submitButton) submitButton.disabled = true;

        try {
            const response = await fetch('<?= APP_URL ?>/servicios/guardar_observacion_requisito.php', {
                method: 'POST',
                body: new FormData(observationForm)
            });
            const data = await response.json();
            if (!data.ok) {
                Swal.fire('Atención', data.message || 'No se pudo guardar la observación.', 'warning');
                return;
            }
            originalObservationText = observationText?.value || '';
            syncObservationSubmit();
            observationModal?.hide();
            window.location.reload();
        } catch (error) {
            Swal.fire('Atención', 'No se pudo guardar la observación.', 'warning');
        } finally {
            if (submitButton) submitButton.disabled = false;
        }
    });
});

function renderDashboardObservationAudit(row, activity) {
    const box = document.getElementById('dashboardObservationAuditBox');
    const list = document.getElementById('dashboardObservationAuditList');
    if (!box || !list) return;

    const hasObservationContext = row?.observation_status && row.observation_status !== 'none' && row?.observation_at;
    if (!hasObservationContext) {
        box.classList.add('d-none');
        list.innerHTML = '';
        return;
    }

    const items = [];
    items.push({
        title: row.observation_status === 'corrected' ? 'Corregido por revisar' : (row.observation_status === 'approved' ? 'Conforme' : 'Observado'),
        body: `${row.observation_by || 'Administrador'} - ${formatDashboardAuditDate(row.observation_at)}`
    });

    if (row?.observation_resolved_at) {
        items.push({
            title: 'Conformidad registrada',
            body: `${row.observation_resolved_by || 'Administrador'} - ${formatDashboardAuditDate(row.observation_resolved_at)}`
        });
    }
    const observationTime = parseDashboardAuditDate(row.observation_at);
    (activity || []).filter((entry) => {
        if (['observacion', 'observacion_retirada', 'conformidad'].includes(entry.action_type || '')) {
            return true;
        }
        const entryTime = parseDashboardAuditDate(entry.created_at);
        return observationTime && entryTime && entryTime >= observationTime;
    }).forEach((entry) => {
        const userName = entry.user_name || 'Sistema';
        items.push({
            title: `${userName} hizo modificaciones: ${normalizeDashboardActivityText(entry.description || 'actividad registrada')}`,
            body: formatDashboardAuditDate(entry.created_at)
        });
    });

    if (!items.length) {
        box.classList.add('d-none');
        list.innerHTML = '';
        return;
    }

    box.classList.remove('d-none');
    list.innerHTML = items.map((item) => `
        <div class="requirement-audit-item">
            <strong>${escapeDashboardHtml(item.title)}</strong>
            <span>${escapeDashboardHtml(item.body)}</span>
        </div>
    `).join('');
}

function formatDashboardAuditDate(value) {
    if (!value) return '';
    const date = parseDashboardAuditDate(value);
    if (!date) return value;
    return date.toLocaleString('es-PE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function parseDashboardAuditDate(value) {
    if (!value) return null;
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? null : date;
}

function escapeDashboardHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
}

function normalizeDashboardActivityText(value) {
    let text = String(value || '').trim();
    if (!text) return 'actividad registrada.';
    text = text
        .replace(/^modificó observaciones;\s*/i, '')
        .replace(/;\s*modificó observaciones\.?$/i, '.')
        .replace(/;\s*modificó observaciones;\s*/i, '; ');
    return text.charAt(0).toLowerCase() + text.slice(1);
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>






