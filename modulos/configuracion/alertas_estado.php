<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/status_alerts.php';
require_module_access('configuracion.alertas_estado');

$message = isset($_GET['guardado']) ? 'Configuracion guardada correctamente.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? null);

    $daysPayload = (array) ($_POST['warning_days'] ?? []);
    $validScopes = status_alert_scopes();
    $catalogItems = status_alert_catalog_items();

    $delete = db()->prepare('DELETE FROM status_alert_settings WHERE scope_key = :scope_key AND catalog_id = :catalog_id');
    $save = db()->prepare('INSERT INTO status_alert_settings (scope_key, catalog_id, warning_days)
        VALUES (:scope_key, :catalog_id, :warning_days)
        ON DUPLICATE KEY UPDATE warning_days = VALUES(warning_days)');

    foreach ($daysPayload as $scopeKey => $scopeValues) {
        if (!isset($validScopes[$scopeKey])) {
            continue;
        }
        $validCatalogIds = array_flip(array_map(
            static fn(array $item): int => (int) $item['id'],
            $catalogItems[$scopeKey]['items'] ?? []
        ));

        foreach ((array) $scopeValues as $catalogId => $days) {
            $catalogId = (int) $catalogId;
            if ($catalogId <= 0 || !isset($validCatalogIds[$catalogId])) {
                continue;
            }

            $warningDays = max(0, min(3650, (int) $days));
            if ($warningDays === STATUS_ALERT_DEFAULT_WARNING_DAYS) {
                $delete->execute(['scope_key' => $scopeKey, 'catalog_id' => $catalogId]);
                continue;
            }

            $save->execute([
                'scope_key' => $scopeKey,
                'catalog_id' => $catalogId,
                'warning_days' => $warningDays,
            ]);
        }
    }

    redirect('modulos/configuracion/alertas_estado.php?guardado=1');
}

$catalogItems = status_alert_catalog_items();
$settings = [];
try {
    foreach (db()->query('SELECT scope_key, catalog_id, warning_days FROM status_alert_settings')->fetchAll() as $row) {
        $settings[(string) $row['scope_key']][(int) $row['catalog_id']] = (int) $row['warning_days'];
    }
} catch (Throwable $e) {
    $settings = [];
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Alertas del estado</h1>
        <p>Defina cuántos días antes del vencimiento se mostrará como POR VENCER.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= e($message) ?></div>
<?php endif; ?>

<form method="post" class="needs-validation" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <div class="work-panel mb-3">
        <div class="d-flex align-items-start gap-3">
            <div class="state-alert-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <h5 class="mb-1">Condición por defecto</h5>
                <p class="mb-1">Todo requisito o documento nuevo usa esta lógica si no tiene una configuración especial.</p>
                <div class="state-alert-rules">
                    <span><strong>NO APTO:</strong> fecha vencida menor a hoy.</span>
                    <span><strong>POR VENCER:</strong> desde hoy hasta hoy + <?= STATUS_ALERT_DEFAULT_WARNING_DAYS ?> días.</span>
                    <span><strong>APTO:</strong> mayor a hoy + <?= STATUS_ALERT_DEFAULT_WARNING_DAYS ?> días.</span>
                </div>
            </div>
        </div>
    </div>

    <div class="accordion config-alert-accordion" id="statusAlertAccordion">
        <?php $index = 0; ?>
        <?php foreach ($catalogItems as $scopeKey => $scope): ?>
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button <?= $index > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#statusAlertScope<?= $index ?>" aria-expanded="<?= $index === 0 ? 'true' : 'false' ?>">
                        <?= e($scope['label']) ?>
                    </button>
                </h2>
                <div id="statusAlertScope<?= $index ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>" data-bs-parent="#statusAlertAccordion">
                    <div class="accordion-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 config-alert-table">
                                <thead>
                                <tr>
                                    <th>Requisito / documento</th>
                                    <th class="text-center">Días para POR VENCER</th>
                                    <th>Lectura para el usuario</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($scope['items'] as $item): ?>
                                    <?php
                                    $catalogId = (int) $item['id'];
                                    $days = $settings[$scopeKey][$catalogId] ?? STATUS_ALERT_DEFAULT_WARNING_DAYS;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($item['name']) ?></strong>
                                            <?php if ($days === STATUS_ALERT_DEFAULT_WARNING_DAYS): ?>
                                                <small class="d-block text-muted">Usa la regla por defecto.</small>
                                            <?php else: ?>
                                                <small class="d-block text-primary">Regla personalizada.</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <input class="form-control form-control-sm config-alert-days-input" type="number" min="0" max="3650"
                                                name="warning_days[<?= e($scopeKey) ?>][<?= $catalogId ?>]"
                                                value="<?= (int) $days ?>" required>
                                        </td>
                                        <td>
                                            <span class="text-muted">Si la fecha de vencimiento está entre hoy y hoy +</span>
                                            <strong><span class="js-alert-days-preview"><?= (int) $days ?></span> días</strong>
                                            <span class="text-muted">se marcará como</span>
                                            <span class="badge text-bg-warning">POR VENCER</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($scope['items'])): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-4">No hay catálogos activos en este módulo.</td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php $index++; ?>
        <?php endforeach; ?>
    </div>

    <div class="sticky-form-actions">
        <button class="btn btn-outline-secondary" type="reset">Restaurar cambios</button>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar configuración</button>
    </div>
</form>

<script>
document.querySelectorAll('.config-alert-days-input').forEach((input) => {
    input.addEventListener('input', () => {
        const preview = input.closest('tr')?.querySelector('.js-alert-days-preview');
        if (preview) preview.textContent = input.value || '0';
    });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
