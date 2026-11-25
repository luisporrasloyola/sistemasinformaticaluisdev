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

/** Prioriza el trabajador vinculado al depósito y solo usa nombres para registros antiguos. */
function personal_expense_deposit_is_worker(array $deposit, int $workerId, array $workers, array &$resolvedNames): bool
{
    if ($deposit['worker_id'] !== null) return (int) $deposit['worker_id'] === $workerId;
    $creditor = (string) $deposit['creditor'];
    if (!array_key_exists($creditor, $resolvedNames)) {
        $resolvedNames[$creditor] = personal_deposit_unique_worker_id($creditor, $workers);
    }
    return $resolvedNames[$creditor] === $workerId;
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
    $stmt = db()->prepare("SELECT mark_date, amount_override, honorarios FROM finance_personal_per_diem_edits WHERE worker_id = ? AND ($dateFilter)");
    $stmt->execute(array_merge([$workerId], $rangeParams));
    $honorarios = [];
    foreach ($stmt as $row) {
        if ($row['amount_override'] !== null) $overrides[(string) $row['mark_date']] = (int) round((float) $row['amount_override'] * 100);
        if ($row['honorarios'] !== null) $honorarios[(string) $row['mark_date']] = (int) round((float) $row['honorarios'] * 100);
    }

    $additional = [];
    $stmt = db()->prepare("SELECT mark_date, SUM(amount) AS total FROM finance_personal_additional_expenses WHERE worker_id = ? AND ($dateFilter) GROUP BY mark_date");
    $stmt->execute(array_merge([$workerId], $rangeParams));
    foreach ($stmt as $row) $additional[(string) $row['mark_date']] = (int) round((float) $row['total'] * 100);

    $hourPurchases = [];
    $purchaseFilter = str_replace('mark_date', 'purchase_date', $dateFilter);
    try {
        $stmt = db()->prepare("SELECT purchase_date, SUM(total_amount) AS total
            FROM attendance_hour_purchases
            WHERE worker_id = ? AND ($purchaseFilter)
            GROUP BY purchase_date");
        $stmt->execute(array_merge([$workerId], $rangeParams));
        foreach ($stmt as $row) {
            $hourPurchases[(string) $row['purchase_date']] = (int) round((float) $row['total'] * 100);
        }
    } catch (PDOException $error) {
        if ($error->getCode() !== '42S02') throw $error;
    }

    $deposits = [];
    $outgoing = [];
    $depositFilter = str_replace('mark_date', 'deposit_date', $dateFilter);
    $stmt = db()->prepare("SELECT deposit_date, creditor, depositor, amount, worker_id FROM finance_personal_deposits WHERE ($depositFilter)");
    $stmt->execute($rangeParams);
    $workers = personal_deposit_workers();
    $resolvedNames = [];
    $resolvedDepositors = [];
    foreach ($stmt as $row) {
        $date = (string) $row['deposit_date'];
        $amountCents = (int) round((float) $row['amount'] * 100);
        if (personal_expense_deposit_is_worker($row, $workerId, $workers, $resolvedNames)) {
            $deposits[$date] = ($deposits[$date] ?? 0) + $amountCents;
        }
        $depositor = (string) ($row['depositor'] ?? '');
        if (!array_key_exists($depositor, $resolvedDepositors)) {
            $resolvedDepositors[$depositor] = personal_expense_depositor_worker_id($depositor, $workers);
        }
        if ($resolvedDepositors[$depositor] === $workerId) {
            $outgoing[$date] = ($outgoing[$date] ?? 0) + $amountCents;
        }
    }

    $visible = array_fill_keys($visibleDates, true);
    $remainingByDate = [];
    foreach ($months as $month) {
        $dates = [];
        foreach (array_keys($markedDays + $deposits + $outgoing + $hourPurchases) as $date) {
            if (str_starts_with($date, $month . '-')) $dates[$date] = true;
        }
        ksort($dates);
        $balance = $opening[$month] ?? 0;
        foreach ($dates as $date => $_) {
            $balance += $deposits[$date] ?? 0;
            if (array_key_exists($date, $markedDays) || isset($deposits[$date]) || isset($outgoing[$date]) || isset($hourPurchases[$date])) {
                $perDiem = $overrides[$date] ?? (personal_per_diem($markedDays[$date] ?? '', $date) ?? 0) * 100;
                $balance -= $perDiem + ($additional[$date] ?? 0) + ($outgoing[$date] ?? 0) + ($hourPurchases[$date] ?? 0) + ($honorarios[$date] ?? 0);
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
    $latestDepositStmt = db()->prepare('SELECT MAX(deposit_date) FROM finance_personal_deposits WHERE worker_id = ?');
    $latestDepositStmt->execute([$workerId]);
    $latestDeposit = $latestDepositStmt->fetchColumn();
    $latestPurchase = '';
    try {
        $latestPurchaseStmt = db()->prepare('SELECT MAX(purchase_date) FROM attendance_hour_purchases WHERE worker_id = ?');
        $latestPurchaseStmt->execute([$workerId]);
        $latestPurchase = (string) ($latestPurchaseStmt->fetchColumn() ?: '');
    } catch (PDOException $error) {
        if ($error->getCode() !== '42S02') throw $error;
    }
    $latestActivity = max((string) ($latestMark ?: ''), (string) ($latestDeposit ?: ''), $latestPurchase);
    $defaultMonth = $latestActivity !== '' ? substr($latestActivity, 0, 7) : date('Y-m');
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
    $workerDeposits = [];
    $workerOutgoingDeposits = [];
    $workerHourPurchases = [];
    $depositDates = [];
    $allDepositWorkers = personal_deposit_workers();
    $resolvedCreditors = [];
    $resolvedDepositors = [];
    $allDepositsStmt = db()->prepare('SELECT d.id, d.deposit_date, d.creditor, d.depositor, er.reason AS expense_reason, d.amount, d.worker_id, d.image_path, d.image_original_name, COALESCE(editor.name, author.name) AS registered_by FROM finance_personal_deposits d LEFT JOIN finance_personal_expense_reasons er ON er.deposit_id = d.id AND er.worker_id = ? LEFT JOIN users author ON author.id = d.registered_by_user_id LEFT JOIN users editor ON editor.id = d.last_edited_by_user_id WHERE d.deposit_date BETWEEN ? AND ? ORDER BY d.deposit_date DESC, d.id DESC');
    $allDepositsStmt->execute([$workerId, $period['start'], $period['end']]);
    foreach ($allDepositsStmt as $deposit) {
        $date = (string) $deposit['deposit_date'];
        if (personal_expense_deposit_is_worker($deposit, $workerId, $allDepositWorkers, $resolvedCreditors)) {
            $workerDeposits[$date][] = $deposit;
            $depositDates[$date] = true;
        }
        $depositor = (string) ($deposit['depositor'] ?? '');
        if (!array_key_exists($depositor, $resolvedDepositors)) {
            $resolvedDepositors[$depositor] = personal_expense_depositor_worker_id($depositor, $allDepositWorkers);
        }
        if ($resolvedDepositors[$depositor] === $workerId) {
            $workerOutgoingDeposits[$date][] = $deposit;
            $depositDates[$date] = true;
        }
    }
    try {
        $hourPurchasesStmt = db()->prepare('SELECT p.purchase_date, p.hours_decimal, p.total_amount,
                COALESCE(u.name, \'\') AS registered_by
            FROM attendance_hour_purchases p
            LEFT JOIN users u ON u.id=p.registered_by_user_id
            WHERE p.worker_id=? AND p.purchase_date BETWEEN ? AND ?
            ORDER BY p.purchase_date DESC, p.id DESC');
        $hourPurchasesStmt->execute([$workerId, $period['start'], $period['end']]);
        foreach ($hourPurchasesStmt as $purchase) {
            $date = (string) $purchase['purchase_date'];
            $workerHourPurchases[$date][] = $purchase;
            $depositDates[$date] = true;
        }
    } catch (PDOException $error) {
        if ($error->getCode() !== '42S02') throw $error;
    }
    $bulkAllPages = $isDateRange && (string) ($_GET['editar_masivo'] ?? '') === '1';
    $pageSize = $bulkAllPages ? 400 : 20;
    $page = max(1, (int) ($_GET['pagina'] ?? 1));
    $totalDays = 0;
    $totalPages = 1;
    $pageDates = [];
    if ($isDateRange) {
        $datesStmt = db()->prepare('SELECT DISTINCT mark_date FROM attendance_marks WHERE worker_id = ? AND mark_date BETWEEN ? AND ?');
        $datesStmt->execute([$workerId, $period['start'], $period['end']]);
        $allDates = $depositDates;
        foreach ($datesStmt->fetchAll(PDO::FETCH_COLUMN) as $date) $allDates[(string) $date] = true;
        $pageDates = array_keys($allDates);
        rsort($pageDates);
        $totalDays = count($pageDates);
        $totalPages = max(1, (int) ceil($totalDays / $pageSize));
        $page = min($page, $totalPages);
        $pageDates = array_slice($pageDates, ($page - 1) * $pageSize, $pageSize);
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
    $outgoingExpensesByDate = [];
    $depositsByDate = [];
    $depositPhotosByDate = [];
    if ($markedDays || $depositDates) {
        $selectedDates = $isDateRange ? $pageDates : array_keys($markedDays + $depositDates);
        $placeholders = implode(',', array_fill(0, count($selectedDates), '?'));
        $editsStmt = db()->prepare("SELECT e.mark_date, e.amount_override, e.honorarios, e.observations, e.updated_by_user_id,
            COALESCE(u.name, '') AS registered_by, COALESCE(u.role, '') AS registered_by_role
            FROM finance_personal_per_diem_edits e
            LEFT JOIN users u ON u.id = e.updated_by_user_id
            WHERE e.worker_id = ? AND e.mark_date IN ($placeholders)");
        $editsStmt->execute(array_merge([$workerId], $selectedDates));
        foreach ($editsStmt as $edit) $savedEdits[(string) $edit['mark_date']] = $edit;
        $expensesStmt = db()->prepare("SELECT mark_date, amount, reason FROM finance_personal_additional_expenses WHERE worker_id = ? AND mark_date IN ($placeholders) ORDER BY mark_date, sort_order, id");
        $expensesStmt->execute(array_merge([$workerId], $selectedDates));
        foreach ($expensesStmt as $expense) $expensesByDate[(string) $expense['mark_date']][] = ['amount' => (string) $expense['amount'], 'reason' => (string) $expense['reason']];
    }
    $displayDays = $markedDays;
    foreach ($workerDeposits as $date => $deposits) {
        if ($isDateRange && !in_array($date, $pageDates, true)) continue;
        if (!isset($displayDays[$date])) $displayDays[$date] = null;
        foreach ($deposits as $deposit) {
            $depositsByDate[$date] = ($depositsByDate[$date] ?? 0) + (int) round((float) $deposit['amount'] * 100);
            if (!empty($deposit['image_path'])) {
                $depositPhotosByDate[$date][] = [
                    'id' => (int) $deposit['id'], 'amount' => (string) $deposit['amount'],
                    'isPdf' => strtolower(pathinfo((string) $deposit['image_original_name'], PATHINFO_EXTENSION)) === 'pdf',
                ];
            }
        }
    }
    foreach ($workerOutgoingDeposits as $date => $deposits) {
        if ($isDateRange && !in_array($date, $pageDates, true)) continue;
        if (!array_key_exists($date, $displayDays)) $displayDays[$date] = null;
        foreach ($deposits as $deposit) {
            $outgoingExpensesByDate[$date][] = [
                'amount' => (string) $deposit['amount'],
                'reason' => (string) ($deposit['expense_reason'] ?? $deposit['creditor']),
                'deposit_id' => (int) $deposit['id'],
            ];
        }
    }
    foreach ($workerHourPurchases as $date => $purchases) {
        if ($isDateRange && !in_array($date, $pageDates, true)) continue;
        if (!array_key_exists($date, $displayDays)) $displayDays[$date] = null;
        foreach ($purchases as $purchase) {
            $outgoingExpensesByDate[$date][] = [
                'amount' => (string) $purchase['total_amount'],
                'reason' => 'Compra de ' . number_format((float) $purchase['hours_decimal'], 2, '.', '') . ' h',
                'source' => 'hour_purchase',
            ];
        }
    }
    krsort($displayDays);
    $periodExpenseTotal = 0.0;
    $periodPerDiemTotal = 0.0;
    $periodHonorariosTotal = 0.0;
    $periodDepositTotalCents = array_sum($depositsByDate);
    if ($isDateRange && $totalDays > 0) {
        $overridesStmt = db()->prepare('SELECT mark_date, amount_override, honorarios FROM finance_personal_per_diem_edits WHERE worker_id = ? AND mark_date BETWEEN ? AND ?');
        $overridesStmt->execute([$workerId, $period['start'], $period['end']]);
        $periodOverrides = [];
        foreach ($overridesStmt as $edit) {
            $periodOverrides[(string) $edit['mark_date']] = $edit['amount_override'];
            $periodHonorariosTotal += (float) ($edit['honorarios'] ?? 0);
        }
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
        foreach (array_keys($workerDeposits + $workerOutgoingDeposits) as $date) {
            if (!isset($countedDates[$date]) && isset($periodOverrides[$date])) $periodPerDiemTotal += (float) $periodOverrides[$date];
        }
        $additionalTotalStmt = db()->prepare('SELECT COALESCE(SUM(a.amount), 0) FROM finance_personal_additional_expenses a WHERE a.worker_id = ? AND a.mark_date BETWEEN ? AND ?');
        $additionalTotalStmt->execute([$workerId, $period['start'], $period['end']]);
        $periodExpenseTotal = $periodPerDiemTotal + (float) $additionalTotalStmt->fetchColumn() + $periodHonorariosTotal;
        foreach ($workerOutgoingDeposits as $deposits) {
            foreach ($deposits as $deposit) $periodExpenseTotal += (float) $deposit['amount'];
        }
        foreach ($workerHourPurchases as $purchases) {
            foreach ($purchases as $purchase) $periodExpenseTotal += (float) $purchase['total_amount'];
        }
        $periodDepositTotalCents = 0;
        foreach ($workerDeposits as $deposits) {
            foreach ($deposits as $deposit) $periodDepositTotalCents += (int) round((float) $deposit['amount'] * 100);
        }
    }
    $remainingByDate = personal_remaining_balances($workerId, array_keys($displayDays));
} else {
    $companies = db()->query('SELECT id, name FROM companies ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC);
    $lifeCompanyId = null;
    foreach ($companies as $company) {
        if (mb_strtolower(trim((string) $company['name']), 'UTF-8') === 'life maquinarias') {
            $lifeCompanyId = (int) $company['id'];
            break;
        }
    }
    $requestedCompany = (string) ($_GET['empresa_id'] ?? ($lifeCompanyId ?? ''));
    $selectedCompanyId = $requestedCompany === '' ? null : filter_var($requestedCompany, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($selectedCompanyId !== null && $selectedCompanyId !== false && !in_array((int) $selectedCompanyId, array_map(static fn(array $company): int => (int) $company['id'], $companies), true)) {
        $selectedCompanyId = false;
    }
    if ($selectedCompanyId === false) $selectedCompanyId = $lifeCompanyId;
    $sql = 'SELECT w.id, w.full_name, w.document_type, w.document_number, c.name AS company FROM workers w LEFT JOIN companies c ON c.id = w.company_id';
    if ($selectedCompanyId !== null) $sql .= ' WHERE w.company_id = ?';
    $sql .= ' ORDER BY c.name, w.full_name, w.id';
    $stmt = db()->prepare($sql);
    $stmt->execute($selectedCompanyId === null ? [] : [(int) $selectedCompanyId]);
    $workers = $stmt->fetchAll();
    $listBankAccounts = [];
    if ($workers) {
        $workerIdsForBanks = array_map(static fn(array $person): int => (int) $person['id'], $workers);
        $bankPlaceholders = implode(',', array_fill(0, count($workerIdsForBanks), '?'));
        try {
            $bankStmt = db()->prepare("SELECT a.worker_id, b.name AS bank_name, a.account_number, a.currency, a.cci
                FROM worker_bank_accounts a JOIN worker_bank_catalog b ON b.id = a.bank_id
                WHERE a.worker_id IN ($bankPlaceholders) ORDER BY a.worker_id, a.id");
            $bankStmt->execute($workerIdsForBanks);
            foreach ($bankStmt as $account) {
                $listBankAccounts[(int) $account['worker_id']][] = $account;
            }
        } catch (PDOException $error) {
            if ($error->getCode() !== '42S02') throw $error;
        }
    }
    $listBalances = [];
    if ($workers) {
        $workerIds = array_fill_keys(array_map(static fn(array $person): int => (int) $person['id'], $workers), true);
        $latestDates = [];
        $marksStmt = db()->query('SELECT worker_id, MAX(mark_date) AS latest_date FROM attendance_marks GROUP BY worker_id');
        foreach ($marksStmt as $mark) {
            $id = (int) $mark['worker_id'];
            if (isset($workerIds[$id])) $latestDates[$id] = (string) $mark['latest_date'];
        }
        $depositWorkers = personal_deposit_workers();
        $resolvedCreditors = [];
        $resolvedDepositors = [];
        $depositsStmt = db()->query('SELECT deposit_date, creditor, depositor, worker_id FROM finance_personal_deposits');
        foreach ($depositsStmt as $deposit) {
            $creditor = (string) $deposit['creditor'];
            if ($deposit['worker_id'] === null && !array_key_exists($creditor, $resolvedCreditors)) {
                $resolvedCreditors[$creditor] = personal_deposit_unique_worker_id($creditor, $depositWorkers);
            }
            $creditorWorkerId = $deposit['worker_id'] !== null ? (int) $deposit['worker_id'] : $resolvedCreditors[$creditor];
            $depositor = (string) ($deposit['depositor'] ?? '');
            if (!array_key_exists($depositor, $resolvedDepositors)) {
                $resolvedDepositors[$depositor] = personal_expense_depositor_worker_id($depositor, $depositWorkers);
            }
            foreach ([$creditorWorkerId, $resolvedDepositors[$depositor]] as $id) {
                if ($id !== null && isset($workerIds[$id])) {
                    $latestDates[$id] = max($latestDates[$id] ?? '', (string) $deposit['deposit_date']);
                }
            }
        }
        foreach ($latestDates as $id => $date) {
            $balance = personal_remaining_balances((int) $id, [$date]);
            if (isset($balance[$date])) $listBalances[$id] = $balance[$date];
        }
    }
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
            <div class="personal-opening-value-row"><strong id="personalOpeningBalanceValue"><?= $openingBalance !== null ? 'S/ ' . number_format((float) $openingBalance, 2, '.', ',') : 'Sin registrar' ?></strong><?php if (!is_personal_role()): ?><button class="btn btn-sm btn-outline-primary" type="button" id="editPersonalOpeningBalance" title="Editar saldo inicial" aria-label="Editar saldo inicial"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><?php endif; ?></div>
        </div>
        <?php if (!is_personal_role()): ?><div class="personal-opening-editor" id="personalOpeningBalanceEditor" hidden>
            <div class="input-group input-group-sm"><span class="input-group-text">S/</span><input class="form-control" id="personalOpeningBalanceInput" type="number" min="0" max="999999999.99" step="0.01" inputmode="decimal" aria-label="Saldo inicial del mes"></div>
            <button class="btn btn-sm btn-primary" type="button" id="savePersonalOpeningBalance" title="Guardar saldo" aria-label="Guardar saldo"><i class="fa-solid fa-check" aria-hidden="true"></i></button>
            <button class="btn btn-sm btn-outline-secondary" type="button" id="cancelPersonalOpeningBalance" title="Cancelar" aria-label="Cancelar edición"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div><?php endif; ?>
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
        <button class="btn btn-outline-primary" type="button" id="bulkEditPersonalExpenses"><i class="fa-solid fa-pen-to-square me-1" aria-hidden="true"></i>Editar masivamente</button>
        <button class="btn btn-outline-primary" type="button" id="bulkAssignHonorarios"><i class="fa-solid fa-coins me-1" aria-hidden="true"></i>Asignar honorarios</button>
        <button class="btn btn-primary" type="button" id="bulkSavePersonalExpenses" hidden><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Guardar</button>
        <button class="btn btn-outline-secondary" type="button" id="bulkCancelPersonalExpenses" hidden>Cancelar</button>
    </form>
    <div class="personal-honorarios-toolbar mb-3" id="bulkHonorariosToolbar" hidden>
        <label for="bulkHonorariosAmount">Honorarios para las fechas seleccionadas (S/)</label>
        <input class="form-control form-control-sm" id="bulkHonorariosAmount" type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" placeholder="Ej. 150.00">
        <strong id="bulkHonorariosCurrent" class="personal-honorarios-current"></strong>
        <span id="bulkHonorariosCount" aria-live="polite"></span>
        <small>El importe nuevo reemplaza al anterior en las fechas marcadas. Desmarque las fechas que no deben recibir honorarios y pulse Guardar.</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="personalPerDiemTable">
            <thead class="table-light"><tr><th>Fecha</th><th>Ubicación</th><th>Proyecto</th><th>Viáticos</th><th>Observaciones</th><th>Gastos adicionales</th><th>Motivo</th><th>Honorarios</th><th>Total</th><th>Depósitos</th><th>Saldo restante</th><th>Registrado por</th><th>Acción</th></tr></thead>
            <tbody>
                <?php foreach ($displayDays as $date => $mark): $automaticPerDiem = personal_per_diem((string) ($mark['project_name'] ?? ''), $date); $savedEdit = $savedEdits[$date] ?? null; $perDiem = $savedEdit && $savedEdit['amount_override'] !== null ? (float) $savedEdit['amount_override'] : $automaticPerDiem; $honorarios = $savedEdit && $savedEdit['honorarios'] !== null ? (float) $savedEdit['honorarios'] : null; $manualExpenses = $expensesByDate[$date] ?? []; $expenses = array_merge($manualExpenses, $outgoingExpensesByDate[$date] ?? []); $additionalTotal = array_sum(array_map(static fn($item) => (float) $item['amount'], $expenses)); $observations = (string) ($savedEdit['observations'] ?? ''); $editorId = (int) ($savedEdit['updated_by_user_id'] ?? 0); $canEditExpense = !is_personal_role() || $editorId === 0 || $editorId === (int) (current_user()['id'] ?? 0); $editorName = trim((string) ($savedEdit['registered_by'] ?? '')); $editorRole = (string) ($savedEdit['registered_by_role'] ?? ''); $editLockMessage = $editorRole === 'Administrador' ? 'Este registro fue modificado o registrado por el Administrador: ' . ($editorName !== '' ? $editorName : 'el usuario responsable') . '. Para modificarlo, comuníquese con el Administrador.' : 'Este registro fue modificado o registrado por ' . ($editorName !== '' ? $editorName : 'otro usuario') . '. Solo puede modificar los registros que usted mismo realizó.'; $total = $perDiem !== null || $expenses || $honorarios !== null ? (float) ($perDiem ?? 0) + $additionalTotal + (float) ($honorarios ?? 0) : null; if (!$isDateRange) { $periodPerDiemTotal += (float) ($perDiem ?? 0); $periodHonorariosTotal += (float) ($honorarios ?? 0); $periodExpenseTotal += (float) ($total ?? 0); } ?>
                <tr>
                    <td class="text-nowrap"><?= e(date('d/m/Y', strtotime($date))) ?></td>
                    <td><?= e(($mark['location_name'] ?? null) ?: '—') ?></td>
                    <td><?= e(($mark['project_name'] ?? null) ?: '—') ?></td>
                    <td class="text-nowrap per-diem-amount-cell"><?= $perDiem !== null ? 'S/ ' . number_format((float) $perDiem, ((float) $perDiem == (int) $perDiem ? 0 : 2), '.', ',') : '—' ?></td>
                    <td class="per-diem-observations-cell" style="white-space:pre-line"><?= $observations !== '' ? e($observations) : '—' ?></td>
                    <td class="additional-expense-cell text-nowrap"><?php if ($expenses): foreach ($expenses as $expenseIndex => $expense): ?><div class="expense-display-line expense-paired expense-pair-<?= $expenseIndex % 4 ?>"><span>S/ <?= number_format((float) $expense['amount'], 2, '.', ',') ?></span><svg class="expense-pair-arrow" viewBox="0 0 100 20" preserveAspectRatio="none" aria-hidden="true"><path class="expense-pair-arrow-line" d="M2 10 C30 8 48 12 96 10"/><path class="expense-pair-arrow-tip" d="M84 3 L97 10 L84 17"/></svg></div><?php endforeach; else: ?>—<?php endif; ?></td>
                    <td class="additional-reason-cell"><?php if ($expenses): foreach ($expenses as $expenseIndex => $expense): ?><div class="expense-display-line expense-paired expense-pair-<?= $expenseIndex % 4 ?>"><?= $expense['reason'] !== '' ? e($expense['reason']) : '—' ?></div><?php endforeach; else: ?>—<?php endif; ?></td>
                    <td class="honorarios-cell text-nowrap"><?= $honorarios !== null ? 'S/ ' . number_format($honorarios, 2, '.', ',') : '—' ?></td>
                    <td class="total-expense-cell text-nowrap"><?= $total !== null ? 'S/ ' . number_format($total, 2, '.', ',') : '—' ?></td>
                    <td class="deposit-amount-cell text-nowrap"><?= isset($depositsByDate[$date]) ? 'S/ ' . number_format($depositsByDate[$date] / 100, 2, '.', ',') : '—' ?></td>
                    <td class="remaining-balance-cell text-nowrap"><?= isset($remainingByDate[$date]) ? 'S/ ' . number_format($remainingByDate[$date] / 100, 2, '.', ',') : '—' ?></td>
                    <td class="registered-by-cell"><?= !empty($savedEdit['registered_by']) ? e($savedEdit['registered_by']) : '—' ?></td>
                    <td class="per-diem-actions-cell"><div class="d-flex gap-1"><button class="btn btn-sm btn-outline-primary edit-personal-per-diem" type="button" data-mark-date="<?= e($date) ?>" data-automatic="<?= $automaticPerDiem !== null ? (int) $automaticPerDiem : '' ?>" data-override="<?= $savedEdit && $savedEdit['amount_override'] !== null ? e((string) $savedEdit['amount_override']) : '' ?>" data-honorarios="<?= $savedEdit && $savedEdit['honorarios'] !== null ? e((string) $savedEdit['honorarios']) : '' ?>" data-expenses="<?= e(json_encode($manualExpenses, JSON_UNESCAPED_UNICODE)) ?>" data-linked-expenses="<?= e(json_encode($outgoingExpensesByDate[$date] ?? [], JSON_UNESCAPED_UNICODE)) ?>" data-observations="<?= e($observations) ?>" data-can-edit="<?= $canEditExpense ? '1' : '0' ?>" data-lock-message="<?= e($editLockMessage) ?>" aria-label="<?= $canEditExpense ? 'Editar' : 'Consultar restricción de edición' ?> de gastos del <?= e(date('d/m/Y', strtotime($date))) ?>" title="<?= $canEditExpense ? 'Editar gastos' : 'Edición restringida' ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i></button><?php if (!empty($depositPhotosByDate[$date])): ?><button class="btn btn-sm btn-outline-primary view-personal-deposits" type="button" data-bs-toggle="modal" data-bs-target="#personalDepositPhotosModal" data-deposit-photos="<?= e(json_encode($depositPhotosByDate[$date])) ?>" data-date="<?= e(date('d/m/Y', strtotime($date))) ?>" aria-label="Ver comprobantes de depósitos del <?= e(date('d/m/Y', strtotime($date))) ?>" title="Ver comprobantes de depósitos"><i class="fa-solid fa-file-image" aria-hidden="true"></i></button><?php endif; ?></div></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$displayDays): ?><tr><td colspan="13" class="text-center text-muted py-4">No hay marcaciones ni depósitos en el periodo seleccionado.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($displayDays): ?><tfoot><tr><td colspan="3"></td><td class="text-nowrap" aria-label="Total de viáticos">S/ <?= number_format($periodPerDiemTotal, 2, '.', ',') ?></td><td colspan="3"></td><td class="text-nowrap" aria-label="Total de honorarios">S/ <?= number_format($periodHonorariosTotal, 2, '.', ',') ?></td><td class="text-nowrap" aria-label="Total de gastos">S/ <?= number_format($periodExpenseTotal, 2, '.', ',') ?></td><td class="text-nowrap" aria-label="Total de depósitos">S/ <?= number_format($periodDepositTotalCents / 100, 2, '.', ',') ?></td><td colspan="3"></td></tr></tfoot><?php endif; ?>
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
.personal-honorarios-toolbar { display:flex; align-items:center; flex-wrap:wrap; gap:8px 12px; padding:12px 14px; border:1px solid #cbd8eb; border-radius:9px; background:#f7f9fc; }
.personal-honorarios-toolbar[hidden] { display:none!important; }
.personal-honorarios-toolbar label { color:#172b4d; font-size:.8rem; font-weight:700; }
.personal-honorarios-toolbar input { width:145px; }
.personal-honorarios-toolbar span { color:#0b3b80; font-size:.78rem; font-weight:700; }
.personal-honorarios-toolbar .personal-honorarios-current { color:#485a70; font-size:.78rem; }
.personal-honorarios-toolbar small { flex-basis:100%; color:#526b91; }
.personal-expense-records-panel .table-responsive { border:1px solid #d7e2f0; border-radius:7px; }
.personal-expense-pagination { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-top:14px; color:#526b91; font-size:.78rem; }
.personal-expense-pagination .btn { border-radius:7px; }
#personalPerDiemTable { width:100%; min-width:1030px; table-layout:fixed; color:#172b4d; }
#personalPerDiemTable:has(.per-diem-editing) { width:1700px; min-width:1700px; table-layout:fixed; }
#personalPerDiemTable:has(.per-diem-editing) thead th:nth-child(1) { width:90px; }
#personalPerDiemTable:has(.per-diem-editing) thead th:nth-child(2) { width:110px; }
#personalPerDiemTable:has(.per-diem-editing) thead th:nth-child(3) { width:105px; }
#personalPerDiemTable:has(.per-diem-editing) thead th:nth-child(4) { width:105px; }
#personalPerDiemTable:has(.per-diem-editing) thead th:nth-child(5) { width:210px; }
#personalPerDiemTable:has(.per-diem-editing) thead th:nth-child(6) { width:185px; }
#personalPerDiemTable:has(.per-diem-editing) thead th:nth-child(7) { width:255px; }
#personalPerDiemTable:has(.per-diem-editing) thead th:nth-child(8) { width:115px; }
#personalPerDiemTable:has(.per-diem-editing) thead th:nth-child(9) { width:105px; }
#personalPerDiemTable:has(.per-diem-editing) thead th:nth-child(10) { width:110px; }
#personalPerDiemTable:has(.per-diem-editing) thead th:nth-child(11) { width:125px; }
#personalPerDiemTable:has(.per-diem-editing) thead th:nth-child(12) { width:100px; }
#personalPerDiemTable:has(.per-diem-editing) thead th:nth-child(13) { width:85px; }
#personalPerDiemTable thead th { padding:.65rem .25rem; background:#f7f9fc; border-bottom:1px solid #cbd8eb; color:#0f172a; font-size:.62rem; font-weight:800; text-transform:uppercase; vertical-align:middle; white-space:normal; overflow-wrap:anywhere; }
#personalPerDiemTable thead th:nth-child(1) { width:7%; }
#personalPerDiemTable thead th:nth-child(2) { width:7%; }
#personalPerDiemTable thead th:nth-child(3) { width:7%; }
#personalPerDiemTable thead th:nth-child(4) { width:7%; }
#personalPerDiemTable thead th:nth-child(5) { width:6%; }
#personalPerDiemTable thead th:nth-child(6) { width:10%; }
#personalPerDiemTable thead th:nth-child(7) { width:13%; }
#personalPerDiemTable thead th:nth-child(8) { width:7%; }
#personalPerDiemTable thead th:nth-child(9) { width:7%; }
#personalPerDiemTable thead th:nth-child(10) { width:8%; }
#personalPerDiemTable thead th:nth-child(11) { width:8%; }
#personalPerDiemTable thead th:nth-child(12) { width:6%; }
#personalPerDiemTable thead th:nth-child(13) { width:7%; }
#personalPerDiemTable tbody td { padding:.5rem .25rem; border-bottom:1px solid #dce5f0; font-size:.72rem; vertical-align:middle; overflow-wrap:anywhere; }
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
#personalPerDiemTable tr.per-diem-editing .form-control { width:100%; min-width:0; font-size:.72rem; padding:.3rem .4rem; }
#personalPerDiemTable tr.per-diem-editing .additional-expense-input { flex:1 1 0; }
#personalPerDiemTable tr.per-diem-editing .remove-additional-expense { flex:none; width:30px; padding:.25rem; }
#personalPerDiemTable tr.per-diem-editing .add-additional-expense { white-space:nowrap; font-size:.7rem; padding:.3rem .45rem; }
#personalPerDiemTable tr.per-diem-bulk-editing > td { background:#f3f7fd; }
#personalPerDiemTable tr.per-diem-bulk-editing > td:first-child { box-shadow:inset 3px 0 #2563eb; }
#personalPerDiemTable tr.per-diem-editing > .additional-expense-cell,#personalPerDiemTable tr.per-diem-editing > .additional-reason-cell,#personalPerDiemTable tr.per-diem-bulk-editing > .additional-expense-cell,#personalPerDiemTable tr.per-diem-bulk-editing > .additional-reason-cell { vertical-align:top; }
#personalPerDiemTable.per-diem-bulk-mode .per-diem-actions-cell .edit-personal-per-diem { display:none; }
#personalPerDiemTable.per-diem-bulk-mode { min-width:1350px; table-layout:auto; }
#personalPerDiemTable .bulk-linked-expense { padding:4px 0; color:#52627a; font-size:.76rem; white-space:normal; }
#personalPerDiemTable .per-diem-observations-cell:has(textarea) { min-width:260px; white-space:normal!important; }
#personalPerDiemTable .additional-expense-cell:has(input) { min-width:185px; }
#personalPerDiemTable .additional-reason-cell:has(textarea) { min-width:220px; white-space:normal!important; }
#personalPerDiemTable .honorarios-cell:has(input) { min-width:130px; }
#personalPerDiemTable .bulk-honorarios-toggle { display:flex; align-items:center; gap:5px; margin-bottom:4px; font-size:.72rem; white-space:nowrap; }
#personalPerDiemTable .bulk-honorarios-toggle input { margin:0; }
#personalPerDiemTable .expense-display-line { min-height:2rem; padding:.25rem 0; border-bottom:1px solid #e5eaf0; }
#personalPerDiemTable .expense-display-line:last-child { border-bottom:0; }
#personalPerDiemTable .expense-pair-0 { --expense-pair-bg:#eaf3ff; --expense-pair-accent:#2563eb; --expense-pair-border:#bed6ff; }
#personalPerDiemTable .expense-pair-1 { --expense-pair-bg:#eaf8ef; --expense-pair-accent:#168451; --expense-pair-border:#bce6cc; }
#personalPerDiemTable .expense-pair-2 { --expense-pair-bg:#fff3e5; --expense-pair-accent:#b65b0a; --expense-pair-border:#f7d6ad; }
#personalPerDiemTable .expense-pair-3 { --expense-pair-bg:#f3edff; --expense-pair-accent:#7140b8; --expense-pair-border:#d9c6fa; }
#personalPerDiemTable .additional-expense-cell .expense-paired,#personalPerDiemTable .additional-reason-cell .expense-paired { position:relative; box-sizing:border-box; margin-bottom:3px; padding:.3rem .4rem; border:0; border-radius:5px; background:var(--expense-pair-bg); color:#172b4d; }
#personalPerDiemTable .additional-expense-cell .expense-paired { font-weight:700; }
#personalPerDiemTable .additional-expense-cell .expense-display-line.expense-paired { display:flex; align-items:center; }
#personalPerDiemTable .expense-pair-arrow { flex:1 1 0; min-width:15px; height:20px; margin-left:6px; margin-right:-10px; overflow:visible; pointer-events:none; }
#personalPerDiemTable .expense-pair-arrow path { fill:none; stroke-linecap:round; stroke-linejoin:round; vector-effect:non-scaling-stroke; }
#personalPerDiemTable .expense-pair-arrow-line { stroke:var(--expense-pair-accent); stroke-width:3; }
#personalPerDiemTable .expense-pair-arrow-tip { stroke:var(--expense-pair-accent); stroke-width:2.5; }
#personalPerDiemTable .expense-linked-amount .expense-pair-arrow { flex:0 0 38px; width:38px; margin-left:4px; margin-right:-10px; }
#personalPerDiemTable .expense-manual-amount .expense-pair-arrow { flex:0 0 30px; width:30px; margin-left:0; margin-right:-10px; }
#personalPerDiemTable .expense-manual-amount .form-control { flex:1 1 0; min-width:0; }
#personalPerDiemTable .expense-edit-line { min-height:2.75rem; margin-bottom:.35rem; }
#personalPerDiemTable .additional-reason-list > .expense-edit-line,#personalPerDiemTable .bulk-expense-reasons > .expense-edit-line { display:flex; align-items:center; }
#personalPerDiemTable .additional-expense-cell > .bulk-linked-expense,#personalPerDiemTable .additional-reason-cell > .linked-expense-line { display:flex; align-items:center; min-height:2.75rem; margin-bottom:.35rem; }
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
            const image = document.createElement(photo.isPdf ? 'iframe' : 'img');
            image.src = `${photoEndpoint}?id=${encodeURIComponent(photo.id)}`;
            if (photo.isPdf) {
                image.title = `Comprobante PDF ${index + 1} del ${trigger.dataset.date}`;
                image.style.width = '100%';
                image.style.height = '65vh';
            } else {
                image.alt = `Comprobante ${index + 1} del ${trigger.dataset.date}`;
                image.loading = index === 0 ? 'eager' : 'lazy';
            }
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
    if (openingBalance && document.getElementById('editPersonalOpeningBalance')) {
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
    const alignDisplayedExpenses = () => {
        for (const row of table.tBodies[0].rows) {
            const amounts = [...row.querySelectorAll('.additional-expense-cell > .expense-display-line')];
            const reasons = [...row.querySelectorAll('.additional-reason-cell > .expense-display-line')];
            for (const line of [...amounts, ...reasons]) line.style.minHeight = '';
            amounts.forEach((amount, index) => {
                const reason = reasons[index];
                if (!reason) return;
                const height = Math.max(amount.offsetHeight, reason.offsetHeight);
                amount.style.minHeight = `${height}px`;
                reason.style.minHeight = `${height}px`;
            });
        }
    };
    requestAnimationFrame(alignDisplayedExpenses);
    window.addEventListener('resize', () => requestAnimationFrame(alignDisplayedExpenses));
    const colorExpensePairs = (amountCell, reasonCell) => {
        const amounts = [
            ...amountCell.querySelectorAll('.additional-expense-list > .expense-edit-line, .bulk-expense-amounts > .expense-edit-line'),
            ...amountCell.querySelectorAll(':scope > .bulk-linked-expense')
        ];
        const reasons = [
            ...reasonCell.querySelectorAll('.additional-reason-list > .expense-edit-line, .bulk-expense-reasons > .expense-edit-line'),
            ...reasonCell.querySelectorAll(':scope > .linked-expense-line')
        ];
        amounts.forEach((amount, index) => {
            for (const item of [amount, reasons[index]]) {
                if (!item) continue;
                item.classList.remove('expense-pair-0', 'expense-pair-1', 'expense-pair-2', 'expense-pair-3');
                item.classList.add('expense-paired', `expense-pair-${index % 4}`);
            }
        });
    };
    const addLinkedExpense = (amountCell, reasonCell, linked) => {
        const note = document.createElement('div');
        note.className = 'bulk-linked-expense expense-linked-amount';
        const label = document.createElement('span');
        label.textContent = `S/ ${Number(linked.amount).toFixed(2)} · ${linked.source === 'hour_purchase' ? 'Compra de horas' : 'Depósito vinculado'}`;
        note.append(label);
        note.insertAdjacentHTML('beforeend', '<svg class="expense-pair-arrow" viewBox="0 0 100 20" preserveAspectRatio="none" aria-hidden="true"><path class="expense-pair-arrow-line" d="M2 10 C30 8 48 12 96 10"/><path class="expense-pair-arrow-tip" d="M84 3 L97 10 L84 17"/></svg>');
        const addButton = amountCell.querySelector('.add-additional-expense, .bulk-add-expense');
        if (addButton) amountCell.insertBefore(note, addButton);
        else amountCell.append(note);
        const line = document.createElement('div');
        line.className = 'expense-edit-line linked-expense-line';
        if (linked.deposit_id) {
            const input = document.createElement('textarea');
            input.className = 'form-control form-control-sm linked-expense-reason-input';
            input.rows = 1;
            input.maxLength = 1000;
            input.setAttribute('aria-label', 'Motivo del gasto vinculado');
            input.dataset.depositId = linked.deposit_id;
            input.dataset.initialReason = linked.reason;
            input.value = linked.reason;
            line.append(input);
        } else {
            line.className = 'bulk-linked-expense linked-expense-line';
            line.textContent = linked.reason;
        }
        reasonCell.append(line);
        colorExpensePairs(amountCell, reasonCell);
    };
    const changedLinkedReasons = reasonCell => [...reasonCell.querySelectorAll('.linked-expense-reason-input')]
        .filter(input => input.value.trim() !== input.dataset.initialReason.trim())
        .map(input => ({deposit_id: Number(input.dataset.depositId), reason: input.value.trim()}));
    let activeEdit = null;
    const bulkButton = document.getElementById('bulkEditPersonalExpenses');
    const assignHonorariosButton = document.getElementById('bulkAssignHonorarios');
    const bulkSave = document.getElementById('bulkSavePersonalExpenses');
    const bulkCancel = document.getElementById('bulkCancelPersonalExpenses');
    const honorariosToolbar = document.getElementById('bulkHonorariosToolbar');
    const commonHonorarios = document.getElementById('bulkHonorariosAmount');
    const currentHonorarios = document.getElementById('bulkHonorariosCurrent');
    const honorariosCount = document.getElementById('bulkHonorariosCount');
    let honorariosRequested = false;
    const bulkRows = new Map();
    const updateHonorariosCount = () => {
        const selected = [...bulkRows.values()].filter(edit => edit.honorariosCell.querySelector('.bulk-honorarios-enabled')?.checked).length;
        const newAmount = Number(commonHonorarios.value);
        honorariosCount.textContent = `${selected} de ${bulkRows.size} fechas seleccionadas` +
            (commonHonorarios.value !== '' && commonHonorarios.validity.valid ? ` · Nuevo: S/ ${newAmount.toFixed(2)} por fecha` : '');
    };
    commonHonorarios.addEventListener('input', () => {
        updateHonorariosCount();
        if (commonHonorarios.value === '' || !commonHonorarios.validity.valid) return;
        for (const edit of bulkRows.values()) {
            if (edit.honorariosCell.querySelector('.bulk-honorarios-enabled').checked) {
                edit.honorariosCell.querySelector('.bulk-honorarios').value = commonHonorarios.value;
            }
        }
    });
    table.addEventListener('change', event => {
        if (!event.target.matches('.bulk-honorarios-enabled')) return;
        const input = event.target.closest('.honorarios-cell').querySelector('.bulk-honorarios');
        input.disabled = !event.target.checked;
        input.value = event.target.checked ? (commonHonorarios.value !== '' && commonHonorarios.validity.valid ? commonHonorarios.value : (bulkRows.get(event.target.closest('tr').querySelector('.edit-personal-per-diem').dataset.markDate)?.initial.honorarios || '')) : '';
        updateHonorariosCount();
    });
    const addBulkExpense = (edit, amount = '', reason = '') => {
        const amounts = edit.additionalCell.querySelector('.bulk-expense-amounts');
        const reasons = edit.reasonCell.querySelector('.bulk-expense-reasons');
        if (amounts.children.length >= 30) return;
        const amountLine = document.createElement('div');
        amountLine.className = 'expense-edit-line expense-manual-amount d-flex align-items-center gap-1';
        amountLine.innerHTML = '<input class="form-control form-control-sm bulk-expense-amount" type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" aria-label="Importe del gasto adicional"><button class="btn btn-sm btn-outline-danger bulk-remove-expense" type="button" aria-label="Quitar gasto"><i class="fa-solid fa-trash" aria-hidden="true"></i></button><svg class="expense-pair-arrow" viewBox="0 0 100 20" preserveAspectRatio="none" aria-hidden="true"><path class="expense-pair-arrow-line" d="M2 10 C30 8 48 12 96 10"/><path class="expense-pair-arrow-tip" d="M84 3 L97 10 L84 17"/></svg>';
        amountLine.querySelector('input').value = amount;
        const reasonLine = document.createElement('div');
        reasonLine.className = 'expense-edit-line';
        reasonLine.innerHTML = '<textarea class="form-control form-control-sm bulk-expense-reason" rows="1" maxlength="1000" aria-label="Motivo del gasto adicional"></textarea>';
        reasonLine.querySelector('textarea').value = reason;
        amounts.append(amountLine);
        reasons.append(reasonLine);
        colorExpensePairs(edit.additionalCell, edit.reasonCell);
    };
    const closeBulk = () => {
        for (const edit of bulkRows.values()) {
            for (const [cell, html] of edit.original) cell.innerHTML = html;
            edit.observationsCell.style.whiteSpace = 'pre-line';
            edit.reasonCell.style.whiteSpace = 'pre-line';
            edit.row.classList.remove('per-diem-bulk-editing');
        }
        bulkRows.clear();
        table.classList.remove('per-diem-bulk-mode');
        bulkButton.hidden = false;
        assignHonorariosButton.hidden = false;
        bulkSave.hidden = true;
        bulkCancel.hidden = true;
        honorariosToolbar.hidden = true;
        commonHonorarios.value = '';
        currentHonorarios.textContent = '';
        honorariosRequested = false;
        requestAnimationFrame(alignDisplayedExpenses);
    };
    assignHonorariosButton.addEventListener('click', () => {
        honorariosRequested = true;
        if (!bulkRows.size) bulkButton.click();
        if (!bulkRows.size) return;
        commonHonorarios.focus();
    });
    bulkButton.addEventListener('click', () => {
        if (<?= $isDateRange && $totalPages > 1 && !$bulkAllPages ? 'true' : 'false' ?>) {
            const url = new URL(window.location.href);
            url.searchParams.set('editar_masivo', '1');
            if (honorariosRequested) url.searchParams.set('asignar_honorarios', '1');
            url.searchParams.delete('pagina');
            window.location.assign(url.toString());
            return;
        }
        if (activeEdit) cancelEdit();
        const lockedMessages = [];
        for (const button of table.querySelectorAll('.edit-personal-per-diem')) {
            if (button.dataset.canEdit !== '1') {
                lockedMessages.push(button.dataset.lockMessage || 'Solo puede modificar los registros que usted mismo realizó.');
                continue;
            }
            const row = button.closest('tr');
            const amountCell = row.querySelector('.per-diem-amount-cell');
            const observationsCell = row.querySelector('.per-diem-observations-cell');
            const additionalCell = row.querySelector('.additional-expense-cell');
            const reasonCell = row.querySelector('.additional-reason-cell');
            const honorariosCell = row.querySelector('.honorarios-cell');
            const original = [amountCell, observationsCell, additionalCell, reasonCell, honorariosCell].map(cell => [cell, cell.innerHTML]);
            const edit = {row, amountCell, observationsCell, additionalCell, reasonCell, honorariosCell, original, date:button.dataset.markDate,
                initial:{amount:button.dataset.override, honorarios:button.dataset.honorarios, observations:button.dataset.observations, expenses:JSON.parse(button.dataset.expenses || '[]')}};
            row.classList.add('per-diem-bulk-editing');
            amountCell.innerHTML = '<input class="form-control form-control-sm bulk-per-diem-amount" type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" aria-label="Viáticos en soles">';
            amountCell.querySelector('input').value = button.dataset.override !== '' ? button.dataset.override : button.dataset.automatic;
            observationsCell.style.whiteSpace = 'normal';
            observationsCell.innerHTML = '<textarea class="form-control form-control-sm bulk-per-diem-observations" rows="2" maxlength="10000" aria-label="Observaciones"></textarea>';
            observationsCell.querySelector('textarea').value = button.dataset.observations;
            additionalCell.innerHTML = '<div class="bulk-expense-amounts"></div><button class="btn btn-sm btn-outline-primary bulk-add-expense mt-1" type="button"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Agregar gasto</button>';
            reasonCell.style.whiteSpace = 'normal';
            reasonCell.innerHTML = '<div class="bulk-expense-reasons"></div>';
            honorariosCell.innerHTML = '<label class="bulk-honorarios-toggle"><input class="form-check-input bulk-honorarios-enabled" type="checkbox" checked> Aplicar</label><input class="form-control form-control-sm bulk-honorarios" type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" aria-label="Honorarios en soles">';
            honorariosCell.querySelector('.bulk-honorarios').value = button.dataset.honorarios;
            for (const expense of edit.initial.expenses) addBulkExpense(edit, expense.amount, expense.reason);
            for (const linked of JSON.parse(button.dataset.linkedExpenses || '[]')) {
                addLinkedExpense(additionalCell, reasonCell, linked);
            }
            bulkRows.set(edit.date, edit);
        }
        if (lockedMessages.length) {
            Swal.fire('Edición restringida', lockedMessages[0], 'info');
        }
        if (!bulkRows.size) return;
        table.classList.add('per-diem-bulk-mode');
        bulkButton.hidden = true;
        assignHonorariosButton.hidden = true;
        bulkSave.hidden = false;
        bulkCancel.hidden = false;
        honorariosToolbar.hidden = false;
        const previous = [...bulkRows.values()].map(edit => edit.initial.honorarios).filter(value => value !== '');
        const previousTotal = previous.reduce((sum, value) => sum + Number(value), 0);
        currentHonorarios.textContent = previous.length
            ? `Actual: S/ ${previousTotal.toFixed(2)} en ${previous.length} ${previous.length === 1 ? 'fecha' : 'fechas'}`
            : 'Actual: sin honorarios registrados';
        updateHonorariosCount();
        requestAnimationFrame(alignDisplayedExpenses);
    });
    bulkCancel.addEventListener('click', () => {
        if (<?= $bulkAllPages ? 'true' : 'false' ?>) {
            const url = new URL(window.location.href);
            url.searchParams.delete('editar_masivo');
            url.searchParams.delete('asignar_honorarios');
            window.location.assign(url.toString());
            return;
        }
        closeBulk();
    });
    table.addEventListener('click', event => {
        const add = event.target.closest('.bulk-add-expense');
        if (add) { const edit = bulkRows.get(add.closest('tr').querySelector('.edit-personal-per-diem').dataset.markDate); if (edit) addBulkExpense(edit); return; }
        const remove = event.target.closest('.bulk-remove-expense');
        if (!remove) return;
        const edit = bulkRows.get(remove.closest('tr').querySelector('.edit-personal-per-diem').dataset.markDate);
        if (!edit) return;
        const line = remove.closest('.expense-edit-line');
        const index = [...edit.additionalCell.querySelector('.bulk-expense-amounts').children].indexOf(line);
        line.remove();
        edit.reasonCell.querySelector('.bulk-expense-reasons').children[index]?.remove();
        colorExpensePairs(edit.additionalCell, edit.reasonCell);
    });
    bulkSave.addEventListener('click', async () => {
        if (!commonHonorarios.reportValidity()) return;
        const changes = [];
        for (const edit of bulkRows.values()) {
            const amountInput = edit.amountCell.querySelector('input');
            if (!amountInput.reportValidity()) return;
            const honorariosInput = edit.honorariosCell.querySelector('.bulk-honorarios');
            if (!honorariosInput.reportValidity()) return;
            const amounts = [...edit.additionalCell.querySelectorAll('.bulk-expense-amount')];
            const reasons = [...edit.reasonCell.querySelectorAll('.bulk-expense-reason')];
            const expenses = [];
            for (let i = 0; i < amounts.length; i++) {
                if (!amounts[i].reportValidity()) return;
                reasons[i].setCustomValidity(amounts[i].value === '' && reasons[i].value.trim() ? 'Ingrese el importe o quite este gasto.' : '');
                if (!reasons[i].reportValidity()) return;
                if (amounts[i].value !== '') expenses.push({amount:amounts[i].value, reason:reasons[i].value.trim()});
            }
            const observations = edit.observationsCell.querySelector('textarea').value.trim();
            const amount = amountInput.value.trim();
            const linkedExpenses = changedLinkedReasons(edit.reasonCell);
            const initialAmount = edit.initial.amount !== '' ? edit.initial.amount : edit.row.querySelector('.edit-personal-per-diem').dataset.automatic;
            const otherFieldsChanged = amount !== initialAmount || honorariosInput.value.trim() !== edit.initial.honorarios || observations !== edit.initial.observations.trim() || JSON.stringify(expenses) !== JSON.stringify(edit.initial.expenses);
            if (otherFieldsChanged || linkedExpenses.length) {
                changes.push({mark_date:edit.date, amount_override:amount, honorarios:honorariosInput.value.trim(), observations, additional_expenses:expenses, linked_expenses:linkedExpenses, reason_only:!otherFieldsChanged});
            }
        }
        if (!changes.length) { bulkCancel.click(); return; }
        const data = new FormData();
        data.set('csrf_token', <?= json_encode(csrf_token()) ?>);
        data.set('worker_id', <?= (int) $workerId ?>);
        data.set('rows', JSON.stringify(changes));
        bulkSave.disabled = true;
        try {
            const response = await fetch(<?= json_encode(APP_URL . '/modulos/finanzas/guardar_viaticos_personal_masivo.php') ?>, {method:'POST', body:data, credentials:'same-origin'});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudieron guardar los cambios.');
            if (<?= $bulkAllPages ? 'true' : 'false' ?>) {
                const url = new URL(window.location.href);
                url.searchParams.delete('editar_masivo');
                url.searchParams.delete('asignar_honorarios');
                window.location.assign(url.toString());
            } else window.location.reload();
        } catch (error) {
            Swal.fire('Atención', error.message, 'warning');
            bulkSave.disabled = false;
        }
    });
    if (<?= $bulkAllPages ? 'true' : 'false' ?>) {
        if (new URL(window.location.href).searchParams.has('asignar_honorarios')) assignHonorariosButton.click();
        else bulkButton.click();
    }
    function addExpenseLine(amount = '', reason = '') {
        if (!activeEdit) return;
        const amountList = activeEdit.additionalCell.querySelector('.additional-expense-list');
        const reasonList = activeEdit.reasonCell.querySelector('.additional-reason-list');
        if (amountList.children.length >= 30) return;
        const amountLine = document.createElement('div');
        amountLine.className = 'expense-edit-line expense-manual-amount d-flex align-items-center gap-1';
        amountLine.innerHTML = '<input class="form-control form-control-sm additional-expense-input" type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" aria-label="Importe del gasto adicional"><button class="btn btn-sm btn-outline-danger remove-additional-expense" type="button" title="Quitar gasto" aria-label="Quitar gasto"><i class="fa-solid fa-trash" aria-hidden="true"></i></button><svg class="expense-pair-arrow" viewBox="0 0 100 20" preserveAspectRatio="none" aria-hidden="true"><path class="expense-pair-arrow-line" d="M2 10 C30 8 48 12 96 10"/><path class="expense-pair-arrow-tip" d="M84 3 L97 10 L84 17"/></svg>';
        amountLine.querySelector('input').value = amount;
        const reasonLine = document.createElement('div');
        reasonLine.className = 'expense-edit-line';
        reasonLine.innerHTML = '<textarea class="form-control form-control-sm additional-reason-input" rows="1" maxlength="1000" aria-label="Motivo del gasto adicional"></textarea>';
        reasonLine.querySelector('textarea').value = reason;
        amountList.append(amountLine);
        reasonList.append(reasonLine);
        colorExpensePairs(activeEdit.additionalCell, activeEdit.reasonCell);
    }
    function cancelEdit() {
        if (!activeEdit) return;
        const {row, amountCell, observationsCell, additionalCell, reasonCell, honorariosCell, totalCell, actionsCell, original} = activeEdit;
        amountCell.innerHTML = original.amount;
        observationsCell.innerHTML = original.observations;
        additionalCell.innerHTML = original.additional;
        reasonCell.innerHTML = original.reason;
        honorariosCell.innerHTML = original.honorarios;
        totalCell.innerHTML = original.total;
        actionsCell.innerHTML = original.actions;
        observationsCell.style.whiteSpace = 'pre-line';
        reasonCell.style.whiteSpace = 'pre-line';
        row.classList.remove('per-diem-editing');
        activeEdit = null;
        requestAnimationFrame(alignDisplayedExpenses);
    }
    table.addEventListener('click', async event => {
        const button = event.target.closest('button');
        if (!button || !table.contains(button)) return;
        if (button.classList.contains('add-additional-expense')) { addExpenseLine(); return; }
        if (button.classList.contains('remove-additional-expense') && activeEdit) {
            const index = [...activeEdit.additionalCell.querySelector('.additional-expense-list').children].indexOf(button.closest('.expense-edit-line'));
            button.closest('.expense-edit-line').remove();
            activeEdit.reasonCell.querySelector('.additional-reason-list').children[index]?.remove();
            colorExpensePairs(activeEdit.additionalCell, activeEdit.reasonCell);
            return;
        }
        if (button.classList.contains('cancel-personal-per-diem')) { cancelEdit(); return; }
        if (button.classList.contains('edit-personal-per-diem')) {
            if (button.dataset.canEdit !== '1') {
                Swal.fire(
                    'Edición restringida',
                    button.dataset.lockMessage || 'Solo puede modificar los registros que usted mismo realizó.',
                    'info'
                );
                return;
            }
            cancelEdit();
            const row = button.closest('tr');
            const amountCell = row.querySelector('.per-diem-amount-cell');
            const observationsCell = row.querySelector('.per-diem-observations-cell');
            const additionalCell = row.querySelector('.additional-expense-cell');
            const reasonCell = row.querySelector('.additional-reason-cell');
            const honorariosCell = row.querySelector('.honorarios-cell');
            const totalCell = row.querySelector('.total-expense-cell');
            const actionsCell = row.querySelector('.per-diem-actions-cell');
            const original = {amount: amountCell.innerHTML, observations: observationsCell.innerHTML, additional: additionalCell.innerHTML, reason: reasonCell.innerHTML, honorarios: honorariosCell.innerHTML, total: totalCell.innerHTML, actions: actionsCell.innerHTML};
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
            honorariosCell.innerHTML = '<input class="form-control form-control-sm honorarios-input" type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" aria-label="Honorarios en soles">';
            honorariosCell.querySelector('input').value = button.dataset.honorarios;
            actionsCell.innerHTML = '<div class="d-flex gap-1"><button class="btn btn-sm btn-primary save-personal-per-diem" type="button" title="Guardar" aria-label="Guardar cambios"><i class="fa-solid fa-check" aria-hidden="true"></i></button><button class="btn btn-sm btn-outline-secondary cancel-personal-per-diem" type="button" title="Cancelar" aria-label="Cancelar edición"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>';
            activeEdit = {row, amountCell, observationsCell, additionalCell, reasonCell, honorariosCell, totalCell, actionsCell, original, markDate};
            expenses.forEach(expense => addExpenseLine(expense.amount, expense.reason));
            for (const linked of JSON.parse(button.dataset.linkedExpenses || '[]')) addLinkedExpense(additionalCell, reasonCell, linked);
            activeEdit.initial = {amount:amountInput.value, honorarios:honorariosCell.querySelector('input').value, observations, expenses:JSON.stringify(expenses)};
            requestAnimationFrame(alignDisplayedExpenses);
            amountInput.focus();
            return;
        }
        if (!button.classList.contains('save-personal-per-diem') || !activeEdit) return;
        const {amountCell, observationsCell, additionalCell, reasonCell, honorariosCell, markDate} = activeEdit;
        const amountInput = amountCell.querySelector('input');
        const observationsInput = observationsCell.querySelector('.per-diem-observations-input');
        if (!amountInput.reportValidity()) return;
        const honorariosInput = honorariosCell.querySelector('input');
        if (!honorariosInput.reportValidity()) return;
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
        data.set('honorarios', honorariosInput.value.trim());
        const linkedExpenses = changedLinkedReasons(reasonCell);
        const initial = activeEdit.initial;
        const otherFieldsChanged = amountInput.value !== initial.amount || honorariosInput.value.trim() !== initial.honorarios || observationsInput.value.trim() !== initial.observations.trim() || JSON.stringify(expenses) !== initial.expenses;
        data.set('additional_expenses', JSON.stringify(expenses));
        data.set('linked_expenses', JSON.stringify(linkedExpenses));
        data.set('reason_only', linkedExpenses.length && !otherFieldsChanged ? '1' : '0');
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
    <form id="personalExpensesCompanyForm" method="get" action="<?= e(APP_URL . '/modulos/finanzas/gastos_personal.php') ?>">
        <label class="form-label" for="personalExpensesCompany">Empresa</label>
        <select class="form-select select2-searchable" id="personalExpensesCompany" name="empresa_id" data-placeholder="Buscar empresa">
            <option value="" <?= $selectedCompanyId === null ? 'selected' : '' ?>>Todas las empresas</option>
            <?php foreach ($companies as $company): ?><option value="<?= (int) $company['id'] ?>" <?= $selectedCompanyId === (int) $company['id'] ? 'selected' : '' ?>><?= e($company['name']) ?></option><?php endforeach; ?>
        </select>
    </form>
    <div class="table-responsive">
        <table class="table table-hover align-middle data-table mb-0" id="gastosPersonalTable">
            <thead><tr><th>Empresa</th><th>Personal</th><th>Cuenta bancaria</th><th>Saldo restante</th><th class="text-end" data-orderable="false">Acción</th></tr></thead>
            <tbody>
                <?php if ($selectedCompanyId === null || $selectedCompanyId === $lifeCompanyId): ?><tr>
                    <td>Life Maquinarias</td>
                    <td><strong>Cuenta soles empresa</strong></td>
                    <td>—</td>
                    <td>—</td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/modulos/finanzas/gastos_cuenta_empresa.php" aria-label="Ver gastos de Cuenta soles empresa"><i class="fa-solid fa-eye me-1" aria-hidden="true"></i>Ver gastos</a></td>
                </tr><?php endif; ?>
                <?php foreach ($workers as $person): ?>
                <tr>
                    <td><?= e($person['company'] ?: 'Sin empresa') ?></td>
                    <td><strong><?= e($person['full_name']) ?></strong><small class="d-block text-muted"><?= e(($person['document_type'] ?: 'Documento') . ': ' . $person['document_number']) ?></small></td>
                    <td class="personal-expense-bank-cell">
                        <?php foreach ($listBankAccounts[(int) $person['id']] ?? [] as $account): ?>
                            <div class="personal-expense-bank-account">
                                <strong><?= e($account['bank_name']) ?></strong> <span><?= $account['currency'] === 'USD' ? 'Dólares (USD)' : 'Soles (PEN)' ?></span>
                                <small>N.º <?= e($account['account_number']) ?> · CCI <?= e($account['cci']) ?></small>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($listBankAccounts[(int) $person['id']])): ?>—<?php endif; ?>
                    </td>
                    <td class="text-nowrap" data-order="<?= isset($listBalances[(int) $person['id']]) ? (int) $listBalances[(int) $person['id']] : '' ?>"><?= isset($listBalances[(int) $person['id']]) ? 'S/ ' . number_format($listBalances[(int) $person['id']] / 100, 2, '.', ',') : '—' ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/modulos/finanzas/gastos_personal.php?trabajador_id=<?= (int) $person['id'] ?>" aria-label="Ver gastos de <?= e($person['full_name']) ?>"><i class="fa-solid fa-eye me-1" aria-hidden="true"></i>Ver gastos</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<style>
#gastosPersonalTable { font-size:.83rem; }
#gastosPersonalTable th { font-size:.78rem; white-space:nowrap; }
#gastosPersonalTable th,#gastosPersonalTable td { padding:.5rem .55rem; }
#gastosPersonalTable .personal-expense-bank-cell { min-width:190px; max-width:300px; overflow-wrap:anywhere; }
#gastosPersonalTable .personal-expense-bank-account + .personal-expense-bank-account { border-top:1px solid #e4eaf2; margin-top:.35rem; padding-top:.35rem; }
#gastosPersonalTable .personal-expense-bank-account strong { font-weight:650; }
#gastosPersonalTable .personal-expense-bank-account span { color:#64748b; font-size:.76rem; }
#gastosPersonalTable .personal-expense-bank-account small { display:block; font-size:.76rem; line-height:1.3; }
#gastosPersonalTable_wrapper > .row:first-child > .col-md-6:last-child { flex:0 0 72%; max-width:72%; }
#gastosPersonalTable_wrapper > .row:first-child > .col-md-6:first-child { flex:0 0 28%; max-width:28%; }
#gastosPersonalTable_filter { display:flex; align-items:center; justify-content:end; gap:30px; flex-wrap:wrap; }
#personalExpensesCompanyForm { display:flex; align-items:center; gap:9px; width:auto; text-align:left; }
#personalExpensesCompanyForm .form-label { flex:none; margin:0; font:inherit; color:inherit; font-weight:400; }
#personalExpensesCompanyForm .form-select,#personalExpensesCompanyForm .select2-container { width:240px!important; max-width:100%; }
#personalExpensesCompanyForm .select2-container--bootstrap4 .select2-selection--single { height:34px; min-height:34px; }
@media(max-width:767px) { #gastosPersonalTable_wrapper > .row:first-child > .col-md-6 { flex:0 0 100%!important; max-width:100%!important; } #gastosPersonalTable_filter { justify-content:flex-start; } }
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!window.jQuery) return;
    window.jQuery('#gastosPersonalTable').on('init.dt', () => {
        const filter = document.getElementById('gastosPersonalTable_filter');
        const form = document.getElementById('personalExpensesCompanyForm');
        if (filter && form) {
            filter.prepend(form);
            const searchLabel = filter.querySelector(':scope > label');
            const companyLabel = form.querySelector('label');
            if (searchLabel && companyLabel) {
                const style = window.getComputedStyle(searchLabel);
                companyLabel.style.fontFamily = style.fontFamily;
                companyLabel.style.fontSize = style.fontSize;
                companyLabel.style.fontWeight = style.fontWeight;
                companyLabel.style.color = style.color;
            }
        }
    });
    window.jQuery('#personalExpensesCompany').on('change', function () { this.form.requestSubmit(); });
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
