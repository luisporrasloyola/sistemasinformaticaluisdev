<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/status_alerts.php';
require_module_access('dashboard');

$hasObservationAudit = true;

$summaryRows = db()->query("SELECT
        wr.id AS requirement_row_id,
        wr.end_date,
        COALESCE(c.name, 'Sin empresa') AS company,
        COALESCE(p.name, 'Sin puesto asignado') AS position_name,
        COALESCE(rc.name, 'No tiene requisitos') AS requirement_name,
        wr.requirement_id
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    LEFT JOIN worker_positions wp ON wp.worker_id = w.id
    LEFT JOIN positions p ON p.id = wp.position_id
    LEFT JOIN worker_requirements wr ON wr.worker_id = w.id AND wr.position_id = p.id
    LEFT JOIN requirements_catalog rc ON rc.id = wr.requirement_id")->fetchAll();

$companies = [];
$positions = [];
$requirements = [];
$counts = ['verde' => 0, 'amarillo' => 0, 'rojo' => 0];

foreach ($summaryRows as $row) {
    $hasRequirement = !empty($row['requirement_row_id']);
    if ($hasRequirement) {
        $status = status_alert_document_status($row['end_date'], 'requisitos.pmi_individual', (int) $row['requirement_id'], true);
        $counts[$status['key']]++;
    }

    $company = (string) $row['company'];
    $positionText = $row['position_name'] ? (string) $row['position_name'] : 'Sin puesto asignado';
    $requirementText = $hasRequirement ? (string) $row['requirement_name'] : 'No tiene requisitos';

    $companies[$company] = true;
    $positions[$positionText] = true;
    $requirements[$requirementText] = true;
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
$total = count($summaryRows);
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

function dashboard_document_status(string $endDate, int $requirementId): array
{
    return status_alert_document_status($endDate, 'requisitos.pmi_individual', $requirementId, true);
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
    if ((str_starts_with($value, 'Administrador ') || str_starts_with($value, 'Gestor ')) && str_contains($value, "\n")) {
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
        <div class="dashboard-filter-company">
            <label class="form-label">Empresa</label>
            <select class="form-select select2-searchable" id="dashboardEmpresaFilter" data-placeholder="Buscar empresa" data-no-results="No se encontraron empresas">
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
            <select class="form-select select2-searchable" id="dashboardPuestoFilter" data-placeholder="Buscar puesto de trabajo" data-no-results="No se encontraron puestos">
                <option value="">Todos</option>
                <?php foreach (array_keys($positions) as $position): ?>
                    <option value="<?= e($position) ?>"><?= e($position) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label">Requisito</label>
            <select class="form-select select2-searchable" id="dashboardRequisitoFilter" data-placeholder="Buscar requisito" data-no-results="No se encontraron requisitos">
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
        <div>
            <label class="form-label">Estado de observación</label>
            <select class="form-select" id="dashboardObservationStateFilter">
                <option value="">Todos</option>
                <option value="approved">Conforme</option>
                <option value="observed">Observado</option>
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
                <tr>
                    <td colspan="7" class="text-muted text-center py-4">
                        <i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando registros...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <!-- Paginador del Dashboard -->
    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div class="text-muted small" id="dashboardPaginationInfo">
            Mostrando 0 a 0 de 0 registros
        </div>
        <nav>
            <ul class="pagination pagination-sm mb-0" id="dashboardPagination">
                <!-- Páginas dinámicas -->
            </ul>
        </nav>
    </div>
</div>

<div class="modal fade" id="dashboardObservationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
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
                <label class="form-label" for="dashboardObservationText">Nueva observación</label>
                <textarea class="form-control" name="observation" id="dashboardObservationText" rows="3" maxlength="3000" placeholder="Escriba una nueva observación..."></textarea>
                <small class="text-muted d-block mt-2">Se agregará al historial sin eliminar las observaciones anteriores.</small>
                <div class="observation-readonly-notice d-none" id="dashboardObservationReadonlyNotice">
                    <i class="fa-solid fa-lock"></i>
                    <span>Modo de consulta. Solo los gestores autorizados para este requisito pueden agregar observaciones.</span>
                </div>
                <div class="requirement-audit-box observation-history-box mt-3 d-none" id="dashboardObservationAuditBox">
                    <h6 id="dashboardObservationHistoryTitle">Historial de observaciones</h6>
                    <div id="dashboardObservationAuditList"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button>
                <button class="btn btn-primary d-none" type="submit" id="dashboardObservationSubmitBtn"><i class="fa-solid fa-plus me-2"></i>Agregar observación</button>
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
window.dashboardCanManageObservations = <?= (is_admin() || is_gestor_role()) ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', () => {
    const modalElement = document.getElementById('dashboardPdfPreviewModal');
    const frame = document.getElementById('dashboardPdfPreviewFrame');
    const title = document.getElementById('dashboardPdfPreviewTitle');
    const modal = modalElement && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalElement) : null;
    
    modalElement?.addEventListener('hidden.bs.modal', () => {
        if (frame) frame.src = '';
    });

    const observationModalElement = document.getElementById('dashboardObservationModal');
    const observationForm = document.getElementById('dashboardObservationForm');
    const observationModal = observationModalElement && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(observationModalElement) : null;
    const observationText = document.getElementById('dashboardObservationText');
    const observationSubmitBtn = document.getElementById('dashboardObservationSubmitBtn');
    const observationReadonlyNotice = document.getElementById('dashboardObservationReadonlyNotice');
    let originalObservationText = '';
    let canObserveCurrentRequirement = false;

    const setObservationPermission = (allowed) => {
        canObserveCurrentRequirement = window.dashboardCanManageObservations === true && allowed === true;
        if (observationText) {
            observationText.readOnly = !canObserveCurrentRequirement;
            observationText.classList.toggle('observation-input-locked', !canObserveCurrentRequirement);
            observationText.placeholder = canObserveCurrentRequirement
                ? 'Escriba una nueva observación...'
                : 'No tiene autorización para agregar observaciones a este requisito.';
        }
        observationReadonlyNotice?.classList.toggle('d-none', canObserveCurrentRequirement);
        observationSubmitBtn?.classList.add('d-none');
    };

    const syncObservationSubmit = () => {
        if (!canObserveCurrentRequirement) {
            observationSubmitBtn?.classList.add('d-none');
            return;
        }
        const changed = (observationText?.value || '').trim() !== '';
        if (observationSubmitBtn) {
            observationSubmitBtn.innerHTML = '<i class="fa-solid fa-plus me-2"></i>Agregar observación';
        }
        observationSubmitBtn?.classList.toggle('d-none', !changed);
    };

    const dashboardTable = document.getElementById('dashboardPersonalTable');
    
    dashboardTable?.addEventListener('click', async (event) => {
        // 1. PDF/Image preview
        const pdfButton = event.target.closest('.dashboard-pdf-preview-btn');
        if (pdfButton && pdfButton.dataset.pdfUrl) {
            if (!modal || !frame || !title) return;
            title.textContent = pdfButton.dataset.pdfTitle || 'Previsualizar documento';
            frame.src = pdfButton.dataset.pdfUrl || '';
            modal.show();
            return;
        }

        // 2. Observations
        const obsButton = event.target.closest('.dashboard-observation-btn');
        if (obsButton && obsButton.dataset.requirementId) {
            if (!observationModal) return;
            document.getElementById('dashboardObservationRequirementId').value = obsButton.dataset.requirementId || '';
            document.getElementById('dashboardObservationWorker').textContent = obsButton.dataset.workerName || '';
            document.getElementById('dashboardObservationRequirement').textContent = obsButton.dataset.requirementName ? `Requisito: ${obsButton.dataset.requirementName}` : '';
            document.getElementById('dashboardObservationText').value = '';
            setObservationPermission(false);
            originalObservationText = '';
            syncObservationSubmit();

            const statusBox = document.getElementById('dashboardObservationStatusBox');
            const statusBadge = document.getElementById('dashboardObservationStatusBadge');
            const statusDate = document.getElementById('dashboardObservationStatusDate');
            const statusUser = document.getElementById('dashboardObservationStatusUser');
            const resolvedInfo = document.getElementById('dashboardObservationResolvedInfo');
            const status = obsButton.dataset.observationStatus || 'none';
            statusBox?.classList.toggle('d-none', status === 'none' && !obsButton.dataset.observationAt);
            if (statusBadge) {
                statusBadge.className = 'badge';
                statusBadge.classList.add(status === 'approved' ? 'text-bg-success' : 'text-bg-warning');
                statusBadge.textContent = obsButton.dataset.observationLabel || 'Observado';
            }
            if (statusDate) statusDate.textContent = obsButton.dataset.observationAt ? `Actualizado: ${obsButton.dataset.observationAt}` : '';
            if (statusUser) statusUser.textContent = obsButton.dataset.observationBy ? `Última observación por: ${obsButton.dataset.observationBy}` : '';
            if (resolvedInfo) {
                resolvedInfo.textContent = obsButton.dataset.resolvedAt
                    ? `Conforme por: ${obsButton.dataset.resolvedBy || 'Administrador'} - ${obsButton.dataset.resolvedAt}`
                    : '';
            }
            renderDashboardObservationAudit(null, []);
            observationModal.show();

            try {
                const response = await fetch(`<?= APP_URL ?>/servicios/obtener_requisito.php?id=${encodeURIComponent(obsButton.dataset.requirementId || '')}`);
                const data = await response.json();
                if (data.ok) {
                    setObservationPermission(data.can_observe === true);
                    renderDashboardObservationAudit(data.row || null, data.activity || []);
                }
            } catch (error) {
                renderDashboardObservationAudit(null, []);
            }
            return;
        }

        // 3. Mark Conforme
        const approveButton = event.target.closest('.dashboard-approve-observation-btn');
        if (approveButton && approveButton.dataset.requirementId) {
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
            body.append('id', approveButton.dataset.requirementId || '');

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
            return;
        }
    });

    observationForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!canObserveCurrentRequirement) return;
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
    const title = document.getElementById('dashboardObservationHistoryTitle');
    if (!box || !list) return;

    const entries = (activity || [])
        .filter((entry) => entry.action_type === 'observacion_registrada')
        .map((entry) => ({
            author: entry.user_name || 'Usuario',
            role: observationRoleLabel(entry.user_role),
            date: entry.created_at,
            content: cleanLegacyObservation(entry.description)
        }))
        .filter((entry) => entry.content !== '');

    if (!entries.length && row?.observations) {
        entries.push({
            author: row.observation_by || 'Usuario',
            role: observationRoleLabel(row.observation_by_role),
            date: row.observation_at,
            content: cleanLegacyObservation(row.observations)
        });
    }

    if (!entries.length) {
        box.classList.add('d-none');
        list.innerHTML = '';
        return;
    }

    box.classList.remove('d-none');
    if (title) title.textContent = `Historial de observaciones (${entries.length})`;
    list.innerHTML = `<div class="observation-timeline">${entries.map((entry) => `
        <article class="observation-entry">
            <div class="observation-entry-header">
                <span class="observation-avatar"><i class="fa-solid fa-user"></i></span>
                <div class="observation-author">
                    <strong>${escapeDashboardHtml(entry.author)}</strong>
                    <span>${escapeDashboardHtml(entry.role)}</span>
                </div>
                <time>${escapeDashboardHtml(formatDashboardAuditDate(entry.date))}</time>
            </div>
            <p>${escapeDashboardHtml(entry.content)}</p>
        </article>
    `).join('')}</div>`;
}

function cleanLegacyObservation(value) {
    const text = String(value || '').trim();
    return text.replace(/^(?:Administrador|Gestor) .+ tiene esta observaci[oó]n:\s*/iu, '').trim();
}

function observationRoleLabel(role) {
    const normalized = String(role || '').trim().toLowerCase();
    if (normalized === 'admin' || normalized === 'administrador') return 'Administrador';
    if (normalized === 'gestor') return 'Gestor';
    return 'Responsable';
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






