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

$rows = db()->query("SELECT
        wr.id AS requirement_row_id,
        wr.end_date,
        w.id AS worker_id,
        w.full_name,
        w.document_number,
        COALESCE(c.name, 'Sin empresa') AS company,
        p.id AS position_id,
        p.name AS position_name,
        rc.name AS requirement_name
    FROM workers w
    LEFT JOIN companies c ON c.id = w.company_id
    LEFT JOIN worker_positions wp ON wp.worker_id = w.id
    LEFT JOIN positions p ON p.id = wp.position_id
    LEFT JOIN worker_requirements wr ON wr.worker_id = w.id AND wr.position_id = p.id
    LEFT JOIN requirements_catalog rc ON rc.id = wr.requirement_id
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
        'state_key' => $stateKey,
        'state_text' => $stateText,
        'state_class' => $stateClass,
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
            </tr>
            </thead>
            <tbody>
            <?php foreach ($dashboardRows as $item): ?>
                <tr data-company="<?= e(mb_strtolower($item['company'], 'UTF-8')) ?>" data-name="<?= e(mb_strtolower($item['name'] . ' ' . $item['document'], 'UTF-8')) ?>" data-position="<?= e(mb_strtolower($item['position'], 'UTF-8')) ?>" data-requirement="<?= e(mb_strtolower($item['requirement'], 'UTF-8')) ?>" data-state="<?= e($item['state_key']) ?>">
                    <td><?= e($item['company']) ?></td>
                    <td>
                        <strong><?= e($item['name']) ?></strong>
                        <span class="d-block text-muted small"><?= e($item['document']) ?></span>
                    </td>
                    <td><?= e($item['position']) ?></td>
                    <td><?= e($item['requirement']) ?></td>
                    <td><span class="badge <?= e($item['state_class']) ?>"><?= e($item['state_text']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
window.dashboardEjecutivoData = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>






