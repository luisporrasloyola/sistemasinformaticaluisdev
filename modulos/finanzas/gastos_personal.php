<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/cobranzas_valorizacion_periodo.php';
require_once __DIR__ . '/../../includes/finanzas_depositos_personal_match.php';
require_module_access('finanzas.gastos_personal');

function personal_per_diem_rules(): array
{
    return [
        'SHOUGANGBEIJING' => ['project' => 'SHOUGANG - BEIJING', 'weekday' => 40, 'weekend' => 40],
        'NSANDINALOMAS' => ['project' => 'NS ANDINA LOMAS', 'weekday' => 25, 'weekend' => 40],
        'ENELORYGEN' => ['project' => 'ENEL - ORYGEN', 'weekday' => 20, 'weekend' => 20],
    ];
}

function personal_per_diem(string $projectName, string $markDate): ?int
{
    $projectKey = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($projectName, 'UTF-8'));
    $rule = personal_per_diem_rules()[$projectKey] ?? null;
    if ($rule === null) return null;
    $weekday = (int) date('N', strtotime($markDate));
    return $weekday <= 5 ? $rule['weekday'] : $rule['weekend'];
}

/** Calcula el saldo al final de cada día visible; cada mes comienza con su saldo inicial. */
function personal_remaining_balances(int $workerId, array $visibleDates): array
{
    if (!$visibleDates) return [];
    $months = array_values(array_unique(array_map(static fn(string $date): string => substr($date, 0, 7), $visibleDates)));
    $monthStarts = array_map(static fn(string $month): string => $month . '-01', $months);
    $ranges = [];
    $rangeParams = [];
    foreach ($monthStarts as $start) {
        $ranges[] = '(mark_date BETWEEN ? AND ?)';
        $rangeParams[] = $start;
        $rangeParams[] = date('Y-m-t', strtotime($start));
    }
    $dateFilter = implode(' OR ', $ranges);
    $placeholders = implode(',', array_fill(0, count($monthStarts), '?'));

    $opening = [];
    $stmt = db()->prepare("SELECT balance_month, opening_balance FROM finance_personal_monthly_balances WHERE worker_id = ? AND balance_month IN ($placeholders)");
    $stmt->execute(array_merge([$workerId], $monthStarts));
    foreach ($stmt as $row) $opening[substr((string) $row['balance_month'], 0, 7)] = (int) round((float) $row['opening_balance'] * 100);

    $markedDays = [];
    $stmt = db()->prepare('SELECT am.mark_date, p.name AS project_name FROM attendance_marks am LEFT JOIN attendance_projects p ON p.id = am.project_id WHERE am.worker_id = ? AND (' . str_replace('mark_date', 'am.mark_date', $dateFilter) . ") ORDER BY am.mark_date, CASE WHEN am.mark_type = 'entrada' THEN 0 ELSE 1 END, am.marked_at, am.id");
    $stmt->execute(array_merge([$workerId], $rangeParams));
    foreach ($stmt as $row) {
        $date = (string) $row['mark_date'];
        if (!array_key_exists($date, $markedDays)) $markedDays[$date] = (string) ($row['project_name'] ?? '');
    }

    $overrides = [];
    $stmt = db()->prepare("SELECT mark_date, amount_override FROM finance_personal_per_diem_edits WHERE worker_id = ? AND ($dateFilter)");
    $stmt->execute(array_merge([$workerId], $rangeParams));
    foreach ($stmt as $row) if ($row['amount_override'] !== null) $overrides[(string) $row['mark_date']] = (int) round((float) $row['amount_override'] * 100);

    $additional = [];
    $stmt = db()->prepare("SELECT mark_date, SUM(amount) AS total FROM finance_personal_additional_expenses WHERE worker_id = ? AND ($dateFilter) GROUP BY mark_date");
    $stmt->execute(array_merge([$workerId], $rangeParams));
    foreach ($stmt as $row) $additional[(string) $row['mark_date']] = (int) round((float) $row['total'] * 100);

    $deposits = [];
    $depositFilter = str_replace('mark_date', 'deposit_date', $dateFilter);
    $stmt = db()->prepare("SELECT deposit_date, creditor, amount FROM finance_personal_deposits WHERE ($depositFilter)");
    $stmt->execute($rangeParams);
    $workers = personal_deposit_workers();
    $resolvedNames = [];
    foreach ($stmt as $row) {
        $creditor = (string) $row['creditor'];
        if (!array_key_exists($creditor, $resolvedNames)) $resolvedNames[$creditor] = personal_deposit_unique_worker_id($creditor, $workers);
        if ($resolvedNames[$creditor] !== $workerId) continue;
        $date = (string) $row['deposit_date'];
        $deposits[$date] = ($deposits[$date] ?? 0) + (int) round((float) $row['amount'] * 100);
    }

    $visible = array_fill_keys($visibleDates, true);
    $remainingByDate = [];
    foreach ($months as $month) {
        $dates = [];
        foreach (array_keys($markedDays + $deposits) as $date) {
            if (str_starts_with($date, $month . '-')) $dates[$date] = true;
        }
        ksort($dates);
        $balance = $opening[$month] ?? 0;
        foreach ($dates as $date => $_) {
            $balance += $deposits[$date] ?? 0;
            if (array_key_exists($date, $markedDays)) {
                $perDiem = $overrides[$date] ?? (personal_per_diem($markedDays[$date], $date) ?? 0) * 100;
                $balance -= $perDiem + ($additional[$date] ?? 0);
            }
            if (isset($visible[$date])) $remainingByDate[$date] = $balance;
        }
    }
    return $remainingByDate;
}


$workerId = max(0, (int) ($_GET['trabajador_id'] ?? 0));
if (is_personal_role()) {
    $linkedWorkerId = current_user_worker_id();
    if (!$linkedWorkerId || ($workerId > 0 && $workerId !== $linkedWorkerId)) {
        http_response_code(403);
        exit('Solo puede acceder a sus propios gastos.');
    }
    $workerId = $linkedWorkerId;
}
$worker = null;
$periodError = '';
if ($workerId > 0) {
    $stmt = db()->prepare('SELECT w.id, w.full_name, w.document_type, w.document_number, c.name AS company FROM workers w LEFT JOIN companies c ON c.id = w.company_id WHERE w.id = ?');
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch();
    if (!$worker) {
        http_response_code(404);
        exit('Trabajador no encontrado.');
    }
    $latestMarkStmt = db()->prepare('SELECT MAX(mark_date) FROM attendance_marks WHERE worker_id = ?');
    $latestMarkStmt->execute([$workerId]);
    $latestMark = $latestMarkStmt->fetchColumn();
    $defaultMonth = $latestMark ? substr((string) $latestMark, 0, 7) : date('Y-m');
    try {
        $period = valorizacion_periodo($_GET, $defaultMonth);
    } catch (InvalidArgumentException $error) {
        $periodError = $error->getMessage();
        $period = valorizacion_periodo([], $defaultMonth);
    }
    $openingBalance = null;
    if ($period['mode'] === 'mes') {
        $balanceStmt = db()->prepare('SELECT opening_balance FROM finance_personal_monthly_balances WHERE worker_id = ? AND balance_month = ?');
        $balanceStmt->execute([$workerId, $period['month'] . '-01']);
        $savedBalance = $balanceStmt->fetchColumn();
        if ($savedBalance !== false) $openingBalance = (string) $savedBalance;
    }
    $monthNames = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $openingBalanceMonthLabel = $monthNames[(int) substr($period['month'], 5, 2)] . ' de ' . substr($period['month'], 0, 4);
    $isDateRange = $period['mode'] === 'fechas';
    $pageSize = 20;
    $page = max(1, (int) ($_GET['pagina'] ?? 1));
    $totalDays = 0;
    $totalPages = 1;
    $pageDates = [];
    if ($isDateRange) {
        $countStmt = db()->prepare('SELECT COUNT(DISTINCT mark_date) FROM attendance_marks WHERE worker_id = ? AND mark_date BETWEEN ? AND ?');
        $countStmt->execute([$workerId, $period['start'], $period['end']]);
        $totalDays = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalDays / $pageSize));
        $page = min($page, $totalPages);
        $datesStmt = db()->prepare('SELECT DISTINCT mark_date FROM attendance_marks WHERE worker_id = ? AND mark_date BETWEEN ? AND ? ORDER BY mark_date DESC LIMIT ? OFFSET ?');
        $datesStmt->bindValue(1, $workerId, PDO::PARAM_INT);
        $datesStmt->bindValue(2, $period['start']);
        $datesStmt->bindValue(3, $period['end']);
        $datesStmt->bindValue(4, $pageSize, PDO::PARAM_INT);
        $datesStmt->bindValue(5, ($page - 1) * $pageSize, PDO::PARAM_INT);
        $datesStmt->execute();
        $pageDates = $datesStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    // Una fila por día marcado. Si hay varias marcaciones, se toma primero la entrada.
    $markedDays = [];
    if (!$isDateRange || $pageDates) {
        $dateFilter = $isDateRange ? 'am.mark_date IN (' . implode(',', array_fill(0, count($pageDates), '?')) . ')' : 'am.mark_date BETWEEN ? AND ?';
        $marksStmt = db()->prepare("SELECT am.mark_date, am.mark_type, l.name AS location_name, p.name AS project_name
            FROM attendance_marks am
            LEFT JOIN attendance_locations l ON l.id = am.location_id
            LEFT JOIN attendance_projects p ON p.id = am.project_id
            WHERE am.worker_id = ? AND $dateFilter
            ORDER BY am.mark_date DESC, CASE WHEN am.mark_type = 'entrada' THEN 0 ELSE 1 END, am.marked_at, am.id");
        $marksStmt->execute($isDateRange ? array_merge([$workerId], $pageDates) : [$workerId, $period['start'], $period['end']]);
        foreach ($marksStmt as $mark) {
            $date = (string) $mark['mark_date'];
            if (!isset($markedDays[$date])) $markedDays[$date] = $mark;
        }
    }
    $savedEdits = [];
    $expensesByDate = [];
    $depositsByDate = [];
    $depositPhotosByDate = [];
    if ($markedDays) {
        $selectedDates = array_keys($markedDays);
        $placeholders = implode(',', array_fill(0, count($selectedDates), '?'));
        $editsStmt = db()->prepare("SELECT e.mark_date, e.amount_override, e.observations, u.name AS registered_by FROM finance_personal_per_diem_edits e LEFT JOIN users u ON u.id = e.updated_by_user_id WHERE e.worker_id = ? AND e.mark_date IN ($placeholders)");
        $editsStmt->execute(array_merge([$workerId], $selectedDates));
        foreach ($editsStmt as $edit) $savedEdits[(string) $edit['mark_date']] = $edit;
        $expensesStmt = db()->prepare("SELECT mark_date, amount, reason FROM finance_personal_additional_expenses WHERE worker_id = ? AND mark_date IN ($placeholders) ORDER BY mark_date, sort_order, id");
        $expensesStmt->execute(array_merge([$workerId], $selectedDates));
        foreach ($expensesStmt as $expense) $expensesByDate[(string) $expense['mark_date']][] = ['amount' => (string) $expense['amount'], 'reason' => (string) $expense['reason']];
        $depositsStmt = db()->prepare("SELECT id, deposit_date, creditor, amount FROM finance_personal_deposits WHERE deposit_date IN ($placeholders)");
        $depositsStmt->execute($selectedDates);
        $deposits = $depositsStmt->fetchAll();
        if ($deposits) {
            $allWorkers = personal_deposit_workers();
            $resolvedCreditors = [];
            foreach ($deposits as $deposit) {
                $creditor = (string) $deposit['creditor'];
                if (!array_key_exists($creditor, $resolvedCreditors)) {
                    $resolvedCreditors[$creditor] = personal_deposit_unique_worker_id($creditor, $allWorkers);
                }
                if ($resolvedCreditors[$creditor] !== $workerId) continue;
                $depositDate = (string) $deposit['deposit_date'];
                $depositsByDate[$depositDate] = ($depositsByDate[$depositDate] ?? 0) + (int) round((float) $deposit['amount'] * 100);
                $depositPhotosByDate[$depositDate][] = ['id' => (int) $deposit['id'], 'amount' => (string) $deposit['amount']];
            }
        }
    }
    $periodExpenseTotal = 0.0;
    $periodPerDiemTotal = 0.0;
    $periodDepositTotalCents = array_sum($depositsByDate);
    if ($isDateRange && $totalDays > 0) {
        $overridesStmt = db()->prepare('SELECT mark_date, amount_override FROM finance_personal_per_diem_edits WHERE worker_id = ? AND mark_date BETWEEN ? AND ?');
        $overridesStmt->execute([$workerId, $period['start'], $period['end']]);
        $periodOverrides = [];
        foreach ($overridesStmt as $edit) $periodOverrides[(string) $edit['mark_date']] = $edit['amount_override'];
        $totalMarksStmt = db()->prepare("SELECT am.mark_date, p.name AS project_name FROM attendance_marks am
            LEFT JOIN attendance_projects p ON p.id = am.project_id
            WHERE am.worker_id = ? AND am.mark_date BETWEEN ? AND ?
            ORDER BY am.mark_date DESC, CASE WHEN am.mark_type = 'entrada' THEN 0 ELSE 1 END, am.marked_at, am.id");
        $totalMarksStmt->execute([$workerId, $period['start'], $period['end']]);
        $countedDates = [];
        foreach ($totalMarksStmt as $mark) {
            $date = (string) $mark['mark_date'];
            if (isset($countedDates[$date])) continue;
            $countedDates[$date] = true;
            $override = $periodOverrides[$date] ?? null;
            $periodPerDiemTotal += $override !== null ? (float) $override : (float) (personal_per_diem((string) ($mark['project_name'] ?? ''), $date) ?? 0);
        }
        $additionalTotalStmt = db()->prepare('SELECT COALESCE(SUM(a.amount), 0) FROM finance_personal_additional_expenses a WHERE a.worker_id = ? AND a.mark_date BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM attendance_marks am WHERE am.worker_id = a.worker_id AND am.mark_date = a.mark_date)');
        $additionalTotalStmt->execute([$workerId, $period['start'], $period['end']]);
        $periodExpenseTotal = $periodPerDiemTotal + (float) $additionalTotalStmt->fetchColumn();
        $periodDepositTotalCents = 0;
        $periodDepositsStmt = db()->prepare('SELECT d.creditor, d.amount FROM finance_personal_deposits d WHERE d.deposit_date BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM attendance_marks am WHERE am.worker_id = ? AND am.mark_date = d.deposit_date)');
        $periodDepositsStmt->execute([$period['start'], $period['end'], $workerId]);
        $allWorkers = personal_deposit_workers();
        $resolvedCreditors = [];
        foreach ($periodDepositsStmt as $deposit) {
            $creditor = (string) $deposit['creditor'];
            if (!array_key_exists($creditor, $resolvedCreditors)) $resolvedCreditors[$creditor] = personal_deposit_unique_worker_id($creditor, $allWorkers);
            if ($resolvedCreditors[$creditor] === $workerId) $periodDepositTotalCents += (int) round((float) $deposit['amount'] * 100);
        }
    }
    $remainingByDate = personal_remaining_balances($workerId, array_keys($markedDays));
} else {
    $stmt = db()->query('SELECT w.id, w.full_name, w.document_type, w.document_number, c.name AS company FROM workers w LEFT JOIN companies c ON c.id = w.company_id ORDER BY c.name, w.full_name, w.id');
    $workers = $stmt->fetchAll();
}
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <div class="d-flex align-items-center gap-2">
            <h1 class="mb-0">Gastos de personal</h1>
            <?php if ($worker): ?><button class="per-diem-info-trigger" type="button" data-bs-toggle="modal" data-bs-target="#personalPerDiemRulesModal" title="Ver reglas de viáticos" aria-label="Ver reglas de viáticos"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><?php endif; ?>
        </div>
        <p><?= $worker ? 'Ficha de gastos del trabajador.' : 'Seleccione un trabajador para consultar sus gastos.' ?></p>
    </div>
    <?php if ($worker && !is_personal_role()): ?><a class="btn btn-outline-primary" href="<?= APP_URL ?>/modulos/finanzas/gastos_personal.php"><i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Volver a la lista</a><?php endif; ?>
</div>
<?php if ($worker): ?>
<section class="work-panel personal-expense-worker-panel <?= $period['mode'] === 'mes' ? '' : 'personal-expense-worker-panel-wide' ?>">
    <div class="personal-expense-worker-details">
        <div><span class="personal-expense-detail-label">Trabajador</span><h2 class="h5 mb-0"><?= e($worker['full_name']) ?></h2></div>
        <div><span class="personal-expense-detail-label"><?= e($worker['document_type'] ?: 'Documento') ?></span><strong><?= e($worker['document_number']) ?></strong></div>
        <div><span class="personal-expense-detail-label">Empresa</span><strong><?= e($worker['company'] ?: 'Sin empresa') ?></strong></div>
    </div>
    <?php if ($period['mode'] === 'mes'): ?>
    <div class="personal-opening-balance" id="personalOpeningBalance" data-month="<?= e($period['month']) ?>" data-balance="<?= e($openingBalance ?? '') ?>">
        <div class="personal-opening-copy">
            <span class="personal-opening-label"><i class="fa-solid fa-wallet" aria-hidden="true"></i>Saldo inicial de <?= e($openingBalanceMonthLabel) ?></span>
            <div class="personal-opening-value-row"><strong id="personalOpeningBalanceValue"><?= $openingBalance !== null ? 'S/ ' . number_format((float) $openingBalance, 2, '.', ',') : 'Sin registrar' ?></strong><button class="btn btn-sm btn-outline-primary" type="button" id="editPersonalOpeningBalance" title="Editar saldo inicial" aria-label="Editar saldo inicial"><i class="fa-solid fa-pen" aria-hidden="true"></i></button></div>
        </div>
        <div class="personal-opening-editor" id="personalOpeningBalanceEditor" hidden>
            <div class="input-group input-group-sm"><span class="input-group-text">S/</span><input class="form-control" id="personalOpeningBalanceInput" type="number" min="0" max="999999999.99" step="0.01" inputmode="decimal" aria-label="Saldo inicial del mes"></div>
            <button class="btn btn-sm btn-primary" type="button" id="savePersonalOpeningBalance" title="Guardar saldo" aria-label="Guardar saldo"><i class="fa-solid fa-check" aria-hidden="true"></i></button>
            <button class="btn btn-sm btn-outline-secondary" type="button" id="cancelPersonalOpeningBalance" title="Cancelar" aria-label="Cancelar edición"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
    </div>
    <?php endif; ?>
</section>
<section class="work-panel personal-expense-records-panel">
    <h2 class="h5 mb-3">Registros de gastos</h2>
    <?php if ($periodError !== ''): ?><div class="alert alert-warning py-2" role="alert"><?= e($periodError) ?></div><?php endif; ?>
    <form class="personal-expense-period mb-3" method="get" action="<?= e(APP_URL . '/modulos/finanzas/gastos_personal.php') ?>">
        <input type="hidden" name="trabajador_id" value="<?= (int) $workerId ?>">
        <div class="personal-expense-period-field"><label for="expensePeriodMode">Periodo</label><select class="form-select form-select-sm" id="expensePeriodMode" name="periodo"><option value="mes" <?= $period['mode'] === 'mes' ? 'selected' : '' ?>>Por mes</option><option value="fechas" <?= $period['mode'] === 'fechas' ? 'selected' : '' ?>>Entre fechas</option></select></div>
        <div class="personal-expense-period-field" id="expensePeriodMonthField" <?= $period['mode'] !== 'mes' ? 'hidden' : '' ?>><label for="expensePeriodMonth">Mes</label><input class="form-control form-control-sm" id="expensePeriodMonth" name="mes" type="month" value="<?= e($period['month']) ?>" <?= $period['mode'] === 'mes' ? 'required' : 'disabled' ?>></div>
        <div class="personal-expense-period-field" id="expensePeriodFromField" <?= $period['mode'] !== 'fechas' ? 'hidden' : '' ?>><label for="expensePeriodFrom">Desde</label><input class="form-control form-control-sm" id="expensePeriodFrom" name="desde" type="date" value="<?= e($period['from'] ?: $period['start']) ?>" <?= $period['mode'] === 'fechas' ? 'required' : 'disabled' ?>></div>
        <div class="personal-expense-period-field" id="expensePeriodToField" <?= $period['mode'] !== 'fechas' ? 'hidden' : '' ?>><label for="expensePeriodTo">Hasta</label><input class="form-control form-control-sm" id="expensePeriodTo" name="hasta" type="date" value="<?= e($period['to'] ?: $period['end']) ?>" <?= $period['mode'] === 'fechas' ? 'required' : 'disabled' ?>></div>
        <button class="btn btn-outline-primary" type="submit">Ver</button>
    </form>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="personalPerDiemTable">
            <thead class="table-light"><tr><th>Fecha</th><th>Ubicación</th><th>Proyecto</th><th>Viáticos</th><th>Observaciones</th><th>Gastos adicionales</th><th>Motivo</th><th>Total</th><th>Depósitos</th><th>Saldo restante</th><th>Registrado por</th><th>Acción</th></tr></thead>
            <tbody>
                <?php foreach ($markedDays as $date => $mark): $automaticPerDiem = personal_per_diem((string) ($mark['project_name'] ?? ''), $date); $savedEdit = $savedEdits[$date] ?? null; $perDiem = $savedEdit && $savedEdit['amount_override'] !== null ? (float) $savedEdit['amount_override'] : $automaticPerDiem; $expenses = $expensesByDate[$date] ?? []; $additionalTotal = array_sum(array_map(static fn($item) => (float) $item['amount'], $expenses)); $observations = (string) ($savedEdit['observations'] ?? ''); $total = $perDiem !== null || $expenses ? (float) ($perDiem ?? 0) + $additionalTotal : null; if (!$isDateRange) { $periodPerDiemTotal += (float) ($perDiem ?? 0); $periodExpenseTotal += (float) ($total ?? 0); } ?>
                <tr>
                    <td class="text-nowrap"><?= e(date('d/m/Y', strtotime($date))) ?></td>
                    <td><?= e($mark['location_name'] ?: '—') ?></td>
                    <td><?= e($mark['project_name'] ?: '—') ?></td>
                    <td class="text-nowrap per-diem-amount-cell"><?= $perDiem !== null ? 'S/ ' . number_format((float) $perDiem, ((float) $perDiem == (int) $perDiem ? 0 : 2), '.', ',') : '—' ?></td>
                    <td class="per-diem-observations-cell" style="white-space:pre-line"><?= $observations !== '' ? e($observations) : '—' ?></td>
                    <td class="additional-expense-cell text-nowrap"><?php if ($expenses): foreach ($expenses as $expense): ?><div class="expense-display-line">S/ <?= number_format((float) $expense['amount'], 2, '.', ',') ?></div><?php endforeach; else: ?>—<?php endif; ?></td>
                    <td class="additional-reason-cell"><?php if ($expenses): foreach ($expenses as $expense): ?><div class="expense-display-line"><?= $expense['reason'] !== '' ? e($expense['reason']) : '—' ?></div><?php endforeach; else: ?>—<?php endif; ?></td>
                    <td class="total-expense-cell text-nowrap"><?= $total !== null ? 'S/ ' . number_format($total, 2, '.', ',') : '—' ?></td>
                    <td class="deposit-amount-cell text-nowrap"><?= isset($depositsByDate[$date]) ? 'S/ ' . number_format($depositsByDate[$date] / 100, 2, '.', ',') : '—' ?></td>
                    <td class="remaining-balance-cell text-nowrap"><?= isset($remainingByDate[$date]) ? 'S/ ' . number_format($remainingByDate[$date] / 100, 2, '.', ',') : '—' ?></td>
                    <td class="registered-by-cell"><?= !empty($savedEdit['registered_by']) ? e($savedEdit['registered_by']) : '—' ?></td>
                    <td class="per-diem-actions-cell"><div class="d-flex gap-1"><button class="btn btn-sm btn-outline-primary edit-personal-per-diem" type="button" data-mark-date="<?= e($date) ?>" data-automatic="<?= $automaticPerDiem !== null ? (int) $automaticPerDiem : '' ?>" data-override="<?= $savedEdit && $savedEdit['amount_override'] !== null ? e((string) $savedEdit['amount_override']) : '' ?>" data-expenses="<?= e(json_encode($expenses, JSON_UNESCAPED_UNICODE)) ?>" data-observations="<?= e($observations) ?>" aria-label="Editar gastos del <?= e(date('d/m/Y', strtotime($date))) ?>" title="Editar gastos"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><?php if (!empty($depositPhotosByDate[$date])): ?><button class="btn btn-sm btn-outline-primary view-personal-deposits" type="button" data-bs-toggle="modal" data-bs-target="#personalDepositPhotosModal" data-deposit-photos="<?= e(json_encode($depositPhotosByDate[$date])) ?>" data-date="<?= e(date('d/m/Y', strtotime($date))) ?>" aria-label="Ver comprobantes de depósitos del <?= e(date('d/m/Y', strtotime($date))) ?>" title="Ver comprobantes de depósitos"><i class="fa-solid fa-file-image" aria-hidden="true"></i></button><?php endif; ?></div></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$markedDays): ?><tr><td colspan="12" class="text-center text-muted py-4">No hay marcaciones en el periodo seleccionado.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($markedDays): ?><tfoot><tr><td colspan="3"></td><td class="text-nowrap" aria-label="Total de viáticos">S/ <?= number_format($periodPerDiemTotal, 2, '.', ',') ?></td><td colspan="3"></td><td class="text-nowrap" aria-label="Total de gastos">S/ <?= number_format($periodExpenseTotal, 2, '.', ',') ?></td><td class="text-nowrap" aria-label="Total de depósitos">S/ <?= number_format($periodDepositTotalCents / 100, 2, '.', ',') ?></td><td colspan="3"></td></tr></tfoot><?php endif; ?>
        </table>
    </div>
    <?php if ($isDateRange && $totalPages > 1): ?>
    <nav class="personal-expense-pagination" aria-label="Páginas de gastos del periodo">
        <span>Mostrando <?= ($page - 1) * $pageSize + 1 ?>–<?= min($page * $pageSize, $totalDays) ?> de <?= $totalDays ?> fechas · Página <?= $page ?> de <?= $totalPages ?></span>
        <div class="d-flex gap-2">
            <?php $pageQuery = ['trabajador_id' => $workerId, 'periodo' => 'fechas', 'desde' => $period['from'], 'hasta' => $period['to']]; ?>
            <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL . '/modulos/finanzas/gastos_personal.php?' . http_build_query($pageQuery + ['pagina' => $page - 1])) ?>">Anterior</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL . '/modulos/finanzas/gastos_personal.php?' . http_build_query($pageQuery + ['pagina' => $page + 1])) ?>">Siguiente</a><?php endif; ?>
        </div>
    </nav>
    <?php endif; ?>
</section>
<style>
.per-diem-info-trigger { display:inline-grid; place-items:center; flex:none; width:34px; height:34px; padding:0; border:1px solid #bdd1f5; border-radius:50%; background:#eef4ff; color:#2563eb; font-size:.9rem; box-shadow:0 2px 6px rgba(37,99,235,.1); transition:background-color .15s,box-shadow .15s,transform .15s; }
.per-diem-info-trigger:hover { background:#dfeaff; box-shadow:0 4px 10px rgba(37,99,235,.16); transform:translateY(-1px); }
.per-diem-info-trigger:focus-visible { outline:3px solid #93b4ff; outline-offset:2px; }
.personal-expense-worker-panel.work-panel { display:grid; grid-template-columns:minmax(0,1fr) minmax(255px,auto); align-items:center; gap:20px; margin-bottom:12px; border:1px solid #dedbd4!important; border-radius:15px!important; background:linear-gradient(115deg,#fbfaf7 0%,#f4f2ed 100%)!important; box-shadow:0 5px 18px rgba(60,55,45,.055)!important; }
.personal-expense-worker-panel-wide.work-panel { grid-template-columns:1fr; }
.personal-expense-worker-details { display:grid; grid-template-columns:minmax(180px,1fr) minmax(190px,1fr); gap:14px 24px; min-width:0; }
.personal-expense-worker-details > div:first-child { grid-column:1/-1; }
.personal-expense-worker-details h2 { color:#172b4d; font-weight:750; }
.personal-expense-worker-details strong { display:block; color:#172b4d; font-size:.88rem; overflow-wrap:anywhere; }
.personal-expense-detail-label { display:block; margin-bottom:3px; color:#455b79; font-size:.72rem; font-weight:800; letter-spacing:.035em; text-transform:uppercase; }
.personal-opening-balance { min-width:270px; max-width:350px; min-height:96px; padding:16px 18px; border:1px solid #ecd3a1; border-radius:13px; background:linear-gradient(135deg,#fff2d9 0%,#fffcf5 100%); box-shadow:0 5px 15px rgba(139,92,22,.09); }
.personal-opening-copy { min-width:0; }
.personal-opening-value-row { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:6px; }
.personal-opening-label { display:flex; align-items:center; gap:7px; margin-bottom:7px; color:#805a24; font-size:.7rem; font-weight:800; letter-spacing:.035em; line-height:1.35; text-transform:uppercase; }
.personal-opening-label i { display:inline-grid; place-items:center; flex:none; width:25px; height:25px; border-radius:7px; background:#fce6bc; color:#a36b18; font-size:.75rem; }
.personal-opening-balance strong { color:#75480d; font-size:1.45rem; font-weight:800; letter-spacing:-.02em; line-height:1.15; }
.personal-opening-balance .btn { flex:none; width:36px; height:36px; padding:0; border-radius:9px; background:#fff; border-color:#e6c98e; color:#885b17; box-shadow:0 2px 5px rgba(139,92,22,.07); }
.personal-opening-balance .btn:hover { background:#fff0d4; border-color:#d6ac62; color:#75480d; }
.personal-opening-editor { display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin-top:10px; padding-top:10px; border-top:1px solid #f0ddb9; }
.personal-opening-editor .input-group { width:min(155px,100%); }
.personal-opening-editor .input-group-text,.personal-opening-editor input { border-color:#ecd3a1; }
.personal-opening-editor .btn-primary { color:#fff; background:#a36b18; border-color:#a36b18; }
.personal-opening-editor .btn-primary:hover { color:#fff; background:#875711; border-color:#875711; }
.personal-opening-balance [hidden] { display:none!important; }
.personal-expense-records-panel.work-panel { border:1px solid #cbd8eb!important; border-radius:15px!important; box-shadow:0 5px 18px rgba(35,62,102,.045)!important; }
.personal-expense-records-panel > h2 { color:#172b4d; font-size:1rem; font-weight:750; }
.personal-expense-period { display:flex; align-items:end; gap:8px; flex-wrap:wrap; }
.personal-expense-period-field { min-width:125px; }
.personal-expense-period-field:has(input[type="month"]) { min-width:180px; }
.personal-expense-period-field label { display:block; margin-bottom:3px; color:#526b91; font-size:.7rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
.personal-expense-period .form-control,.personal-expense-period .form-select,.personal-expense-period .btn { height:38px; border-radius:8px; }
.personal-expense-period .form-control,.personal-expense-period .form-select { border-color:#cbd9eb; background:#fff; }
.personal-expense-period .form-control:focus,.personal-expense-period .form-select:focus { border-color:#4f83e6; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
.personal-expense-period [hidden] { display:none!important; }
.personal-expense-records-panel .table-responsive { border:1px solid #d7e2f0; border-radius:7px; }
.personal-expense-pagination { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-top:14px; color:#526b91; font-size:.78rem; }
.personal-expense-pagination .btn { border-radius:7px; }
#personalPerDiemTable { width:100%; min-width:930px; table-layout:fixed; color:#172b4d; }
#personalPerDiemTable thead th { padding:.65rem .25rem; background:#f7f9fc; border-bottom:1px solid #cbd8eb; color:#0f172a; font-size:.62rem; font-weight:800; text-transform:uppercase; vertical-align:middle; white-space:normal; overflow-wrap:anywhere; }
#personalPerDiemTable thead th:nth-child(1) { width:7%; }
#personalPerDiemTable thead th:nth-child(2) { width:8%; }
#personalPerDiemTable thead th:nth-child(3) { width:8%; }
#personalPerDiemTable thead th:nth-child(4) { width:8%; }
#personalPerDiemTable thead th:nth-child(5) { width:10%; }
#personalPerDiemTable thead th:nth-child(6) { width:8%; }
#personalPerDiemTable thead th:nth-child(7) { width:8%; }
#personalPerDiemTable thead th:nth-child(8) { width:8%; }
#personalPerDiemTable thead th:nth-child(9) { width:9%; }
#personalPerDiemTable thead th:nth-child(10) { width:9%; }
#personalPerDiemTable thead th:nth-child(11) { width:9%; }
#personalPerDiemTable thead th:nth-child(12) { width:8%; }
#personalPerDiemTable tbody td { padding:.5rem .25rem; border-bottom:1px solid #dce5f0; font-size:.72rem; vertical-align:middle; overflow-wrap:anywhere; }
#personalPerDiemTable:has(.per-diem-editing) { min-width:1350px; table-layout:auto; }
#personalPerDiemTable tbody tr:hover:not(.per-diem-editing) td { background:#f8fbff; }
#personalPerDiemTable tbody tr:last-child td { border-bottom:0; }
#personalPerDiemTable tfoot td { padding:.6rem .25rem; background:#f4f7fb; border-top:1px solid #d7e2f0; color:#0f172a; font-size:.78rem; font-weight:800; }
#personalPerDiemTable tfoot td:nth-child(2),#personalPerDiemTable tfoot td:nth-child(4),#personalPerDiemTable tfoot td:nth-child(5) { color:#0b3b80; font-size:.9rem; }
#personalPerDiemTable .per-diem-amount-cell,#personalPerDiemTable .total-expense-cell { font-weight:700; }
#personalPerDiemTable .total-expense-cell { color:#0b3b80; }
#personalPerDiemTable .deposit-amount-cell { color:#0b3b80; font-weight:700; }
#personalPerDiemTable .remaining-balance-cell { color:#0b3b80; font-weight:800; }
#personalPerDiemTable .registered-by-cell { overflow-wrap:anywhere; }
#personalPerDiemTable .per-diem-actions-cell { white-space:nowrap; }
#personalPerDiemTable .per-diem-actions-cell .btn { display:inline-grid; place-items:center; width:29px; height:29px; padding:0; border-radius:7px; }
#personalPerDiemTable .per-diem-actions-cell .btn-outline-primary { color:#172b4d; border-color:#cbd5e1; background:#fff; }
#personalPerDiemTable .per-diem-actions-cell .btn-outline-primary:hover { background:#eff6ff; border-color:#93b4e9; }
#personalPerDiemTable tr.per-diem-editing > td { background:#ececec; }
#personalPerDiemTable tr.per-diem-editing > td:first-child { box-shadow:inset 3px 0 #2563eb; }
#personalPerDiemTable tr.per-diem-editing .form-control { border-color:#cbd9eb; background:#fff; }
#personalPerDiemTable tr.per-diem-editing .form-control:focus { border-color:#4f83e6; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
#personalPerDiemTable .per-diem-observations-cell:has(textarea) { min-width:260px; white-space:normal!important; }
#personalPerDiemTable .additional-expense-cell:has(input) { min-width:155px; }
#personalPerDiemTable .additional-reason-cell:has(textarea) { min-width:220px; white-space:normal!important; }
#personalPerDiemTable .expense-display-line { min-height:2rem; padding:.25rem 0; border-bottom:1px solid #e5eaf0; }
#personalPerDiemTable .expense-display-line:last-child { border-bottom:0; }
#personalPerDiemTable .expense-edit-line { min-height:2.75rem; margin-bottom:.35rem; }
.personal-per-diem-rules-modal .modal-content { overflow:hidden; border:1px solid #cbd8eb; border-radius:15px; box-shadow:0 20px 55px rgba(15,35,70,.2); }
.personal-per-diem-rules-modal .modal-header { padding:16px 20px; background:#f4f7fb; border-bottom:1px solid #d7e2f0; }
.personal-per-diem-rules-modal .modal-title { display:flex; align-items:center; gap:10px; color:#172b4d; font-weight:750; }
.personal-per-diem-rules-modal .modal-title-icon { display:inline-grid; place-items:center; width:30px; height:30px; border-radius:8px; background:#e3eeff; color:#2563eb; font-size:.82rem; }
.personal-per-diem-rules-modal .modal-body { padding:18px 20px; }
.personal-per-diem-rules-modal .modal-body > p { color:#526b91!important; line-height:1.5; }
.personal-per-diem-rules-modal .table-responsive { border:1px solid #d7e2f0; border-radius:8px; }
.personal-per-diem-rules-modal table { margin:0; font-size:.83rem; }
.personal-per-diem-rules-modal thead th { padding:.7rem .65rem; background:#f7f9fc; border:0; border-bottom:1px solid #d7e2f0; color:#172b4d; font-size:.7rem; font-weight:800; text-transform:uppercase; white-space:nowrap; }
.personal-per-diem-rules-modal tbody td { padding:.68rem .65rem; border:0; border-bottom:1px solid #e2eaf3; color:#172b4d; }
.personal-per-diem-rules-modal tbody tr:last-child td { border-bottom:0; }
.personal-per-diem-rules-modal .rule-rate { color:#0b3b80; font-weight:750; }
.personal-per-diem-rules-modal .modal-footer { padding:12px 20px; background:#fbfcfe; border-top:1px solid #e2eaf3; }
.personal-per-diem-rules-modal .modal-footer .btn { border-radius:8px; min-width:80px; }
.personal-deposit-photos-modal .modal-content { border:1px solid #cbd8eb; border-radius:15px; overflow:hidden; }
.personal-deposit-photos-modal .modal-header { background:#f4f7fb; }
.personal-deposit-photos-list { display:flex; gap:12px; overflow-x:auto; scroll-snap-type:x mandatory; padding:4px 4px 12px; }
.personal-deposit-photo { flex:0 0 100%; min-width:0; margin:0; scroll-snap-align:start; text-align:center; }
.personal-deposit-photo img { display:block; max-width:100%; max-height:65vh; width:auto; height:auto; margin:auto; border:1px solid #d7e2f0; border-radius:8px; object-fit:contain; }
.personal-deposit-photo figcaption { margin-top:8px; color:#344761; font-size:.85rem; font-weight:700; }
@media(max-width:800px) { .personal-expense-worker-panel.work-panel { grid-template-columns:1fr; } .personal-opening-balance { max-width:none; min-width:0; } }
@media(max-width:550px) { .personal-opening-balance { padding:12px; } .personal-opening-balance strong { font-size:1.25rem; } .personal-opening-value-row .btn { width:32px; height:32px; } }
@media(max-width:700px) { .personal-expense-period-field { flex:1 1 140px; } .personal-expense-worker-details { grid-template-columns:1fr; gap:10px; } .personal-expense-worker-details > div:first-child { grid-column:auto; } }
</style>
<div class="modal fade personal-per-diem-rules-modal" id="personalPerDiemRulesModal" tabindex="-1" aria-labelledby="personalPerDiemRulesTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5 mb-0" id="personalPerDiemRulesTitle"><span class="modal-title-icon"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></span>Reglas de viáticos</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <p class="text-muted small">El importe diario depende del proyecto seleccionado al marcar asistencia y del día de la semana.</p>
                <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Proyecto</th><th>Lunes a viernes</th><th>Sábado y domingo</th></tr></thead><tbody>
                    <?php foreach (personal_per_diem_rules() as $rule): ?><tr><td><?= e($rule['project']) ?></td><td class="text-nowrap rule-rate">S/ <?= (int) $rule['weekday'] ?></td><td class="text-nowrap rule-rate">S/ <?= (int) $rule['weekend'] ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
                <p class="text-muted small mb-0 mt-3">Si la marcación no tiene uno de estos proyectos, no se asigna viático automático.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div>
        </div>
    </div>
</div>
<div class="modal fade personal-deposit-photos-modal" id="personalDepositPhotosModal" tabindex="-1" aria-labelledby="personalDepositPhotosTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title h5 mb-0" id="personalDepositPhotosTitle">Comprobantes de depósitos</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body"><div class="personal-deposit-photos-list" id="personalDepositPhotosList"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const photoModal = document.getElementById('personalDepositPhotosModal');
    const photoList = document.getElementById('personalDepositPhotosList');
    const photoEndpoint = <?= json_encode(APP_URL . '/servicios/finanzas/depositos_personal_archivo.php') ?>;
    photoModal.addEventListener('show.bs.modal', event => {
        photoList.replaceChildren();
        const trigger = event.relatedTarget;
        document.getElementById('personalDepositPhotosTitle').textContent = `Comprobantes de depósitos · ${trigger.dataset.date}`;
        const photos = JSON.parse(trigger.dataset.depositPhotos || '[]');
        for (const [index, photo] of photos.entries()) {
            const figure = document.createElement('figure');
            figure.className = 'personal-deposit-photo';
            const image = document.createElement('img');
            image.src = `${photoEndpoint}?id=${encodeURIComponent(photo.id)}`;
            image.alt = `Comprobante ${index + 1} del ${trigger.dataset.date}`;
            image.loading = index === 0 ? 'eager' : 'lazy';
            const caption = document.createElement('figcaption');
            caption.textContent = `Depósito ${index + 1} de ${photos.length} · S/ ${Number(photo.amount).toFixed(2)}`;
            figure.append(image, caption);
            photoList.appendChild(figure);
        }
    });
    photoModal.addEventListener('hidden.bs.modal', () => photoList.replaceChildren());
    const periodMode = document.getElementById('expensePeriodMode');
    const periodFields = [
        ['mes', document.getElementById('expensePeriodMonthField'), document.getElementById('expensePeriodMonth')],
        ['fechas', document.getElementById('expensePeriodFromField'), document.getElementById('expensePeriodFrom')],
        ['fechas', document.getElementById('expensePeriodToField'), document.getElementById('expensePeriodTo')]
    ];
    const updatePeriodFields = () => periodFields.forEach(([mode, wrapper, input]) => {
        const active = periodMode.value === mode;
        wrapper.hidden = !active;
        input.disabled = !active;
        input.required = active;
    });
    periodMode.addEventListener('change', updatePeriodFields);
    updatePeriodFields();
    const openingBalance = document.getElementById('personalOpeningBalance');
    if (openingBalance) {
        const editor = document.getElementById('personalOpeningBalanceEditor');
        const input = document.getElementById('personalOpeningBalanceInput');
        const editButton = document.getElementById('editPersonalOpeningBalance');
        const saveButton = document.getElementById('savePersonalOpeningBalance');
        const closeEditor = () => { editor.hidden = true; editButton.hidden = false; input.setCustomValidity(''); };
        editButton.addEventListener('click', () => {
            input.value = openingBalance.dataset.balance;
            editButton.hidden = true;
            editor.hidden = false;
            input.focus();
        });
        document.getElementById('cancelPersonalOpeningBalance').addEventListener('click', closeEditor);
        saveButton.addEventListener('click', async () => {
            if (!input.reportValidity()) return;
            const data = new FormData();
            data.set('csrf_token', <?= json_encode(csrf_token()) ?>);
            data.set('worker_id', <?= (int) $workerId ?>);
            data.set('month', openingBalance.dataset.month);
            data.set('amount', input.value.trim());
            saveButton.disabled = true;
            try {
                const response = await fetch(<?= json_encode(APP_URL . '/modulos/finanzas/guardar_saldo_inicial_personal.php') ?>, {method:'POST', body:data, credentials:'same-origin'});
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo guardar el saldo inicial.');
                window.location.reload();
            } catch (error) {
                Swal.fire('Atención', error.message, 'warning');
            } finally {
                saveButton.disabled = false;
            }
        });
    }
    const table = document.getElementById('personalPerDiemTable');
    let activeEdit = null;
    function addExpenseLine(amount = '', reason = '') {
        if (!activeEdit) return;
        const amountList = activeEdit.additionalCell.querySelector('.additional-expense-list');
        const reasonList = activeEdit.reasonCell.querySelector('.additional-reason-list');
        if (amountList.children.length >= 30) return;
        const amountLine = document.createElement('div');
        amountLine.className = 'expense-edit-line d-flex gap-1';
        amountLine.innerHTML = '<input class="form-control form-control-sm additional-expense-input" type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" aria-label="Importe del gasto adicional"><button class="btn btn-sm btn-outline-danger remove-additional-expense" type="button" title="Quitar gasto" aria-label="Quitar gasto"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>';
        amountLine.querySelector('input').value = amount;
        const reasonLine = document.createElement('div');
        reasonLine.className = 'expense-edit-line';
        reasonLine.innerHTML = '<textarea class="form-control form-control-sm additional-reason-input" rows="1" maxlength="1000" aria-label="Motivo del gasto adicional"></textarea>';
        reasonLine.querySelector('textarea').value = reason;
        amountList.append(amountLine);
        reasonList.append(reasonLine);
    }
    function cancelEdit() {
        if (!activeEdit) return;
        const {row, amountCell, observationsCell, additionalCell, reasonCell, totalCell, actionsCell, original} = activeEdit;
        amountCell.innerHTML = original.amount;
        observationsCell.innerHTML = original.observations;
        additionalCell.innerHTML = original.additional;
        reasonCell.innerHTML = original.reason;
        totalCell.innerHTML = original.total;
        actionsCell.innerHTML = original.actions;
        observationsCell.style.whiteSpace = 'pre-line';
        reasonCell.style.whiteSpace = 'pre-line';
        row.classList.remove('per-diem-editing');
        activeEdit = null;
    }
    table.addEventListener('click', async event => {
        const button = event.target.closest('button');
        if (!button || !table.contains(button)) return;
        if (button.classList.contains('add-additional-expense')) { addExpenseLine(); return; }
        if (button.classList.contains('remove-additional-expense') && activeEdit) {
            const index = [...activeEdit.additionalCell.querySelector('.additional-expense-list').children].indexOf(button.closest('.expense-edit-line'));
            button.closest('.expense-edit-line').remove();
            activeEdit.reasonCell.querySelector('.additional-reason-list').children[index]?.remove();
            return;
        }
        if (button.classList.contains('cancel-personal-per-diem')) { cancelEdit(); return; }
        if (button.classList.contains('edit-personal-per-diem')) {
            cancelEdit();
            const row = button.closest('tr');
            const amountCell = row.querySelector('.per-diem-amount-cell');
            const observationsCell = row.querySelector('.per-diem-observations-cell');
            const additionalCell = row.querySelector('.additional-expense-cell');
            const reasonCell = row.querySelector('.additional-reason-cell');
            const totalCell = row.querySelector('.total-expense-cell');
            const actionsCell = row.querySelector('.per-diem-actions-cell');
            const original = {amount: amountCell.innerHTML, observations: observationsCell.innerHTML, additional: additionalCell.innerHTML, reason: reasonCell.innerHTML, total: totalCell.innerHTML, actions: actionsCell.innerHTML};
            const markDate = button.dataset.markDate;
            const automatic = button.dataset.automatic;
            const override = button.dataset.override;
            const observations = button.dataset.observations;
            const expenses = JSON.parse(button.dataset.expenses || '[]');
            row.classList.add('per-diem-editing');
            amountCell.innerHTML = '<input class="form-control form-control-sm per-diem-input" type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" aria-label="Viáticos en soles">';
            const amountInput = amountCell.querySelector('input');
            amountInput.value = override !== '' ? override : automatic;
            observationsCell.style.whiteSpace = 'normal';
            observationsCell.innerHTML = '<textarea class="form-control form-control-sm per-diem-observations-input" rows="2" maxlength="10000" aria-label="Observaciones"></textarea>';
            observationsCell.querySelector('.per-diem-observations-input').value = observations;
            additionalCell.innerHTML = '<div class="additional-expense-list"></div><button class="btn btn-sm btn-outline-primary add-additional-expense mt-1" type="button"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Agregar gasto</button>';
            reasonCell.style.whiteSpace = 'normal';
            reasonCell.innerHTML = '<div class="additional-reason-list"></div>';
            actionsCell.innerHTML = '<div class="d-flex gap-1"><button class="btn btn-sm btn-primary save-personal-per-diem" type="button" title="Guardar" aria-label="Guardar cambios"><i class="fa-solid fa-check" aria-hidden="true"></i></button><button class="btn btn-sm btn-outline-secondary cancel-personal-per-diem" type="button" title="Cancelar" aria-label="Cancelar edición"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>';
            activeEdit = {row, amountCell, observationsCell, additionalCell, reasonCell, totalCell, actionsCell, original, markDate};
            expenses.forEach(expense => addExpenseLine(expense.amount, expense.reason));
            amountInput.focus();
            return;
        }
        if (!button.classList.contains('save-personal-per-diem') || !activeEdit) return;
        const {amountCell, observationsCell, additionalCell, reasonCell, markDate} = activeEdit;
        const amountInput = amountCell.querySelector('input');
        const observationsInput = observationsCell.querySelector('.per-diem-observations-input');
        if (!amountInput.reportValidity()) return;
        const additionalInputs = [...additionalCell.querySelectorAll('.additional-expense-input')];
        const reasonInputs = [...reasonCell.querySelectorAll('.additional-reason-input')];
        const expenses = [];
        for (let i = 0; i < additionalInputs.length; i++) {
            const expenseInput = additionalInputs[i];
            const reasonInput = reasonInputs[i];
            if (!expenseInput.reportValidity()) return;
            reasonInput.setCustomValidity(expenseInput.value === '' && reasonInput.value.trim() ? 'Ingrese el importe o quite este gasto.' : '');
            if (!reasonInput.reportValidity()) return;
            if (expenseInput.value !== '') expenses.push({amount: expenseInput.value, reason: reasonInput.value.trim()});
        }
        const data = new FormData();
        data.set('csrf_token', <?= json_encode(csrf_token()) ?>);
        data.set('worker_id', <?= (int) $workerId ?>);
        data.set('mark_date', markDate);
        data.set('amount_override', amountInput.value);
        data.set('additional_expenses', JSON.stringify(expenses));
        data.set('observations_direct', observationsInput.value.trim());
        button.disabled = true;
        try {
            const response = await fetch(<?= json_encode(APP_URL . '/modulos/finanzas/guardar_viatico_personal.php') ?>, {method: 'POST', body: data, credentials: 'same-origin'});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudieron guardar los cambios.');
            window.location.reload();
        } catch (error) {
            Swal.fire('Atención', error.message, 'warning');
            button.disabled = false;
        }
    });
});
</script>
<?php else: ?>
<div class="work-panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle data-table mb-0" id="gastosPersonalTable">
            <thead><tr><th>Empresa</th><th>Personal</th><th class="text-end" data-orderable="false">Acción</th></tr></thead>
            <tbody>
                <?php foreach ($workers as $person): ?>
                <tr>
                    <td><?= e($person['company'] ?: 'Sin empresa') ?></td>
                    <td><strong><?= e($person['full_name']) ?></strong><small class="d-block text-muted"><?= e(($person['document_type'] ?: 'Documento') . ': ' . $person['document_number']) ?></small></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/modulos/finanzas/gastos_personal.php?trabajador_id=<?= (int) $person['id'] ?>" aria-label="Ver gastos de <?= e($person['full_name']) ?>"><i class="fa-solid fa-eye me-1" aria-hidden="true"></i>Ver gastos</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
