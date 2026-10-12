<?php

/**
 * Mesin laporan akuntansi per cabang.
 *
 * Sumber data utama:
 * - Penjualan POS: transactions + transaction_items
 * - Retur: sales_returns + sales_return_items
 * - Pemasukan/pengeluaran operasional: finance_transactions
 * - Pembelian stok: purchases
 * - Pelunasan piutang pelanggan: customer_debt_payments
 * - Nilai persediaan: product_branch_stocks + products
 */

function accounting_table_exists(PDO $db, string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function accounting_column_exists(PDO $db, string $table, string $column): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table . $column)) return false;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function accounting_safe_date(?string $value, string $fallback): string
{
    $value = trim((string)$value);
    if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $time = strtotime($value . ' 00:00:00');
        if ($time !== false && date('Y-m-d', $time) === $value) return $value;
    }
    return $fallback;
}

function accounting_main_branch_id(PDO $db): int
{
    try {
        $id = (int)$db->query("SELECT id FROM branches WHERE is_main=1 ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($id > 0) return $id;
        $id = (int)$db->query("SELECT id FROM branches ORDER BY id ASC LIMIT 1")->fetchColumn();
        return $id > 0 ? $id : 1;
    } catch (Throwable $e) {
        return 1;
    }
}

function accounting_branch_list(PDO $db): array
{
    if (!accounting_table_exists($db, 'branches')) return [];
    try {
        return $db->query("SELECT id, code, name, is_main, is_active FROM branches ORDER BY is_active DESC, is_main DESC, name ASC")->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function accounting_query(PDO $db, string $sql, array $params = []): array
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function accounting_number($value): float
{
    return is_numeric($value) ? (float)$value : 0.0;
}

function accounting_payment_label(string $method): string
{
    $method = strtolower(trim($method));
    $labels = [
        'cash' => 'Tunai',
        'tunai' => 'Tunai',
        'qris' => 'QRIS',
        'qris_pakasir' => 'QRIS Pakasir',
        'qris_manual' => 'QRIS Manual',
        'transfer' => 'Transfer Bank',
        'bank_transfer' => 'Transfer Bank',
        'debit' => 'Kartu Debit',
        'credit' => 'Kartu Kredit',
        'card' => 'Kartu',
        'ewallet' => 'E-Wallet',
        'debt' => 'Piutang',
        'other' => 'Lainnya',
    ];
    if (isset($labels[$method])) return $labels[$method];
    if ($method === '') return 'Lainnya';
    return ucwords(str_replace(['_', '-'], ' ', $method));
}

function accounting_finance_sql_parts(PDO $db): array
{
    $mainId = accounting_main_branch_id($db);
    $parts = [];
    $joins = [];

    if (accounting_column_exists($db, 'finance_transactions', 'branch_id')) {
        $parts[] = 'ft.branch_id';
    }
    if (accounting_table_exists($db, 'purchases')) {
        $joins[] = "LEFT JOIN purchases fp ON ft.reference_type='purchase' AND fp.id=ft.reference_id";
        $parts[] = 'fp.branch_id';
    }
    if (accounting_table_exists($db, 'sales_returns') && accounting_table_exists($db, 'transactions')) {
        $joins[] = "LEFT JOIN sales_returns fsr ON ft.reference_type='sales_return' AND fsr.id=ft.reference_id";
        $joins[] = "LEFT JOIN transactions frt ON frt.id=fsr.transaction_id";
        $parts[] = 'frt.branch_id';
    }
    if (accounting_table_exists($db, 'users') && accounting_column_exists($db, 'users', 'branch_id')) {
        $joins[] = 'LEFT JOIN users fu ON fu.id=ft.user_id';
        $parts[] = 'fu.branch_id';
    }
    $parts[] = (string)$mainId;

    return [
        'branch_expr' => 'COALESCE(' . implode(',', $parts) . ')',
        'joins' => implode("\n", $joins),
    ];
}

function accounting_blank_branch(array $branch): array
{
    return [
        'branch_id' => (int)($branch['id'] ?? 0),
        'branch_code' => (string)($branch['code'] ?? ''),
        'branch_name' => (string)($branch['name'] ?? 'Cabang'),
        'is_active' => (int)($branch['is_active'] ?? 1),
        'gross_sales' => 0.0,
        'refunds' => 0.0,
        'net_sales' => 0.0,
        'cogs_sold' => 0.0,
        'cogs_returned' => 0.0,
        'cogs' => 0.0,
        'gross_profit' => 0.0,
        'other_income' => 0.0,
        'operating_expense' => 0.0,
        'net_profit' => 0.0,
        'sales_collected' => 0.0,
        'debt_collections' => 0.0,
        'purchase_paid' => 0.0,
        'cash_in' => 0.0,
        'cash_out' => 0.0,
        'net_cashflow' => 0.0,
        'transaction_count' => 0,
        'average_transaction' => 0.0,
        'margin' => 0.0,
        'receivables' => 0.0,
        'payables' => 0.0,
        'inventory_value' => 0.0,
    ];
}

function accounting_build_report(PDO $db, string $from, string $to, int $selectedBranchId = 0): array
{
    $from = accounting_safe_date($from, date('Y-m-01'));
    $to = accounting_safe_date($to, date('Y-m-d'));
    if ($from > $to) [$from, $to] = [$to, $from];

    $allBranches = accounting_branch_list($db);
    if (!$allBranches) {
        $mainId = accounting_main_branch_id($db);
        $allBranches = [[
            'id' => $mainId,
            'code' => 'PUSAT',
            'name' => setting('store_name', 'Cabang Pusat'),
            'is_main' => 1,
            'is_active' => 1,
        ]];
    }

    $branchById = [];
    foreach ($allBranches as $branch) {
        $branchById[(int)$branch['id']] = $branch;
    }
    if ($selectedBranchId > 0 && !isset($branchById[$selectedBranchId])) {
        $selectedBranchId = 0;
    }

    $selectedBranches = [];
    foreach ($allBranches as $branch) {
        if ($selectedBranchId === 0 || (int)$branch['id'] === $selectedBranchId) {
            $selectedBranches[(int)$branch['id']] = accounting_blank_branch($branch);
        }
    }
    $selectedIds = array_keys($selectedBranches);
    $selectedSet = array_fill_keys($selectedIds, true);
    $mainId = accounting_main_branch_id($db);

    $dailyByBranch = [];
    $period = new DatePeriod(new DateTime($from), new DateInterval('P1D'), (new DateTime($to))->modify('+1 day'));
    foreach ($selectedIds as $branchId) {
        $dailyByBranch[$branchId] = [];
        foreach ($period as $date) {
            $key = $date->format('Y-m-d');
            $dailyByBranch[$branchId][$key] = [
                'date' => $key,
                'gross_sales' => 0.0,
                'refunds' => 0.0,
                'net_sales' => 0.0,
                'cogs_sold' => 0.0,
                'cogs_returned' => 0.0,
                'cogs' => 0.0,
                'gross_profit' => 0.0,
                'other_income' => 0.0,
                'operating_expense' => 0.0,
                'net_profit' => 0.0,
                'sales_collected' => 0.0,
                'debt_collections' => 0.0,
                'purchase_paid' => 0.0,
                'cash_in' => 0.0,
                'cash_out' => 0.0,
                'net_cashflow' => 0.0,
                'transaction_count' => 0,
            ];
        }
    }

    $addMetric = static function (array &$branches, array &$daily, array $row, string $metric, string $sourceKey, bool $integer = false) use ($selectedSet): void {
        $branchId = (int)($row['branch_id'] ?? 0);
        $date = (string)($row['report_date'] ?? '');
        if (!isset($selectedSet[$branchId]) || !isset($branches[$branchId])) return;
        $value = $integer ? (int)($row[$sourceKey] ?? 0) : accounting_number($row[$sourceKey] ?? 0);
        $branches[$branchId][$metric] += $value;
        if ($date !== '' && isset($daily[$branchId][$date])) {
            $daily[$branchId][$date][$metric] += $value;
        }
    };

    if (accounting_table_exists($db, 'transactions')) {
        $rows = accounting_query($db, "
            SELECT COALESCE(t.branch_id, {$mainId}) AS branch_id,
                   DATE(t.transaction_date) AS report_date,
                   SUM(COALESCE(t.grand_total,0)) AS gross_sales,
                   SUM(GREATEST(0, LEAST(COALESCE(t.grand_total,0), COALESCE(t.paid_amount,0)-COALESCE(t.change_amount,0)))) AS sales_collected,
                   COUNT(*) AS transaction_count
            FROM transactions t
            WHERE t.status IN ('success','refunded')
              AND DATE(t.transaction_date) BETWEEN ? AND ?
            GROUP BY COALESCE(t.branch_id, {$mainId}), DATE(t.transaction_date)
        ", [$from, $to]);
        foreach ($rows as $row) {
            $addMetric($selectedBranches, $dailyByBranch, $row, 'gross_sales', 'gross_sales');
            $addMetric($selectedBranches, $dailyByBranch, $row, 'sales_collected', 'sales_collected');
            $addMetric($selectedBranches, $dailyByBranch, $row, 'transaction_count', 'transaction_count', true);
        }
    }

    if (accounting_table_exists($db, 'transaction_items') && accounting_table_exists($db, 'transactions')) {
        $rows = accounting_query($db, "
            SELECT COALESCE(t.branch_id, {$mainId}) AS branch_id,
                   DATE(t.transaction_date) AS report_date,
                   SUM(COALESCE(ti.purchase_price,0) * COALESCE(ti.qty,0)) AS cogs_sold
            FROM transactions t
            JOIN transaction_items ti ON ti.transaction_id=t.id
            WHERE t.status IN ('success','refunded')
              AND DATE(t.transaction_date) BETWEEN ? AND ?
            GROUP BY COALESCE(t.branch_id, {$mainId}), DATE(t.transaction_date)
        ", [$from, $to]);
        foreach ($rows as $row) $addMetric($selectedBranches, $dailyByBranch, $row, 'cogs_sold', 'cogs_sold');
    }

    if (accounting_table_exists($db, 'sales_returns') && accounting_table_exists($db, 'transactions')) {
        $rows = accounting_query($db, "
            SELECT COALESCE(t.branch_id, {$mainId}) AS branch_id,
                   DATE(sr.return_date) AS report_date,
                   SUM(COALESCE(sr.total_refund,0)) AS refunds
            FROM sales_returns sr
            JOIN transactions t ON t.id=sr.transaction_id
            WHERE sr.status='completed'
              AND DATE(sr.return_date) BETWEEN ? AND ?
            GROUP BY COALESCE(t.branch_id, {$mainId}), DATE(sr.return_date)
        ", [$from, $to]);
        foreach ($rows as $row) $addMetric($selectedBranches, $dailyByBranch, $row, 'refunds', 'refunds');
    }

    if (accounting_table_exists($db, 'sales_returns') && accounting_table_exists($db, 'sales_return_items') && accounting_table_exists($db, 'transaction_items') && accounting_table_exists($db, 'transactions')) {
        $rows = accounting_query($db, "
            SELECT COALESCE(t.branch_id, {$mainId}) AS branch_id,
                   DATE(sr.return_date) AS report_date,
                   SUM(COALESCE(ti.purchase_price,0) * COALESCE(sri.qty,0)) AS cogs_returned
            FROM sales_returns sr
            JOIN transactions t ON t.id=sr.transaction_id
            JOIN sales_return_items sri ON sri.return_id=sr.id
            LEFT JOIN transaction_items ti ON ti.id=sri.transaction_item_id
            WHERE sr.status='completed'
              AND DATE(sr.return_date) BETWEEN ? AND ?
            GROUP BY COALESCE(t.branch_id, {$mainId}), DATE(sr.return_date)
        ", [$from, $to]);
        foreach ($rows as $row) $addMetric($selectedBranches, $dailyByBranch, $row, 'cogs_returned', 'cogs_returned');
    }

    if (accounting_table_exists($db, 'finance_transactions')) {
        $finance = accounting_finance_sql_parts($db);
        $categoryJoin = accounting_table_exists($db, 'finance_categories') ? 'LEFT JOIN finance_categories fc ON fc.id=ft.category_id' : '';
        $categoryName = accounting_table_exists($db, 'finance_categories') ? "LOWER(TRIM(COALESCE(fc.name,'')))" : "''";
        $rows = accounting_query($db, "
            SELECT {$finance['branch_expr']} AS branch_id,
                   ft.transaction_date AS report_date,
                   SUM(CASE
                       WHEN ft.type='income'
                        AND LOWER(TRIM(COALESCE(ft.reference_type,''))) NOT IN ('transaction','sale','sales','pos')
                        AND {$categoryName} NOT IN ('penjualan toko','penjualan','penjualan pos')
                       THEN COALESCE(ft.amount,0) ELSE 0 END) AS other_income,
                   SUM(CASE
                       WHEN ft.type='expense'
                        AND LOWER(TRIM(COALESCE(ft.reference_type,''))) NOT IN ('purchase','sales_return')
                        AND {$categoryName} NOT IN ('belanja stok','pembelian stok','stok barang')
                       THEN COALESCE(ft.amount,0) ELSE 0 END) AS operating_expense
            FROM finance_transactions ft
            {$categoryJoin}
            {$finance['joins']}
            WHERE ft.transaction_date BETWEEN ? AND ?
            GROUP BY {$finance['branch_expr']}, ft.transaction_date
        ", [$from, $to]);
        foreach ($rows as $row) {
            $addMetric($selectedBranches, $dailyByBranch, $row, 'other_income', 'other_income');
            $addMetric($selectedBranches, $dailyByBranch, $row, 'operating_expense', 'operating_expense');
        }
    }

    if (accounting_table_exists($db, 'purchases')) {
        $rows = accounting_query($db, "
            SELECT COALESCE(p.branch_id, {$mainId}) AS branch_id,
                   p.purchase_date AS report_date,
                   SUM(COALESCE(p.paid_amount,0)) AS purchase_paid
            FROM purchases p
            WHERE p.status='completed' AND p.purchase_date BETWEEN ? AND ?
            GROUP BY COALESCE(p.branch_id, {$mainId}), p.purchase_date
        ", [$from, $to]);
        foreach ($rows as $row) $addMetric($selectedBranches, $dailyByBranch, $row, 'purchase_paid', 'purchase_paid');
    }

    if (accounting_table_exists($db, 'customer_debt_payments')) {
        $rows = accounting_query($db, "
            SELECT COALESCE(cdp.branch_id, {$mainId}) AS branch_id,
                   DATE(cdp.created_at) AS report_date,
                   SUM(COALESCE(cdp.amount,0)) AS debt_collections
            FROM customer_debt_payments cdp
            WHERE DATE(cdp.created_at) BETWEEN ? AND ?
            GROUP BY COALESCE(cdp.branch_id, {$mainId}), DATE(cdp.created_at)
        ", [$from, $to]);
        foreach ($rows as $row) $addMetric($selectedBranches, $dailyByBranch, $row, 'debt_collections', 'debt_collections');
    }

    // Snapshot saldo berjalan untuk kebutuhan meeting/manajemen.
    if (accounting_table_exists($db, 'customer_debts')) {
        $rows = accounting_query($db, "
            SELECT COALESCE(cd.branch_id, {$mainId}) AS branch_id,
                   SUM(COALESCE(cd.remaining_amount,0)) AS receivables
            FROM customer_debts cd
            WHERE COALESCE(cd.remaining_amount,0) > 0 AND cd.status!='paid'
            GROUP BY COALESCE(cd.branch_id, {$mainId})
        ");
        foreach ($rows as $row) {
            $bid = (int)$row['branch_id'];
            if (isset($selectedBranches[$bid])) $selectedBranches[$bid]['receivables'] += accounting_number($row['receivables']);
        }
    }

    if (accounting_table_exists($db, 'debts')) {
        $debtBranchExpr = (accounting_column_exists($db, 'debts', 'branch_id') ? 'd.branch_id,' : '')
            . (accounting_column_exists($db, 'users', 'branch_id') ? 'du.branch_id,' : '') . $mainId;
        $debtUserJoin = accounting_column_exists($db, 'users', 'branch_id') ? 'LEFT JOIN users du ON du.id=d.user_id' : '';
        $rows = accounting_query($db, "
            SELECT COALESCE({$debtBranchExpr}) AS branch_id,
                   SUM(GREATEST(0, COALESCE(d.amount,0)-COALESCE(d.paid_amount,0))) AS payables
            FROM debts d
            {$debtUserJoin}
            WHERE d.status!='paid'
            GROUP BY COALESCE({$debtBranchExpr})
        ");
        foreach ($rows as $row) {
            $bid = (int)$row['branch_id'];
            if (isset($selectedBranches[$bid])) $selectedBranches[$bid]['payables'] += accounting_number($row['payables']);
        }
    }

    if (accounting_table_exists($db, 'product_branch_stocks') && accounting_table_exists($db, 'products')) {
        $rows = accounting_query($db, "
            SELECT pbs.branch_id,
                   SUM(GREATEST(0, COALESCE(pbs.stock,0)) * COALESCE(p.purchase_price,0)) AS inventory_value
            FROM product_branch_stocks pbs
            JOIN products p ON p.id=pbs.product_id
            GROUP BY pbs.branch_id
        ");
        foreach ($rows as $row) {
            $bid = (int)$row['branch_id'];
            if (isset($selectedBranches[$bid])) $selectedBranches[$bid]['inventory_value'] += accounting_number($row['inventory_value']);
        }
    }

    foreach ($selectedBranches as $branchId => &$branch) {
        $branch['net_sales'] = $branch['gross_sales'] - $branch['refunds'];
        $branch['cogs'] = $branch['cogs_sold'] - $branch['cogs_returned'];
        $branch['gross_profit'] = $branch['net_sales'] - $branch['cogs'];
        $branch['net_profit'] = $branch['gross_profit'] + $branch['other_income'] - $branch['operating_expense'];
        $branch['cash_in'] = $branch['sales_collected'] + $branch['debt_collections'] + $branch['other_income'];
        $branch['cash_out'] = $branch['purchase_paid'] + $branch['refunds'] + $branch['operating_expense'];
        $branch['net_cashflow'] = $branch['cash_in'] - $branch['cash_out'];
        $branch['average_transaction'] = $branch['transaction_count'] > 0 ? $branch['net_sales'] / $branch['transaction_count'] : 0.0;
        $branch['margin'] = abs($branch['net_sales']) > 0.0001 ? ($branch['net_profit'] / $branch['net_sales']) * 100 : 0.0;

        foreach ($dailyByBranch[$branchId] as &$day) {
            $day['net_sales'] = $day['gross_sales'] - $day['refunds'];
            $day['cogs'] = $day['cogs_sold'] - $day['cogs_returned'];
            $day['gross_profit'] = $day['net_sales'] - $day['cogs'];
            $day['net_profit'] = $day['gross_profit'] + $day['other_income'] - $day['operating_expense'];
            $day['cash_in'] = $day['sales_collected'] + $day['debt_collections'] + $day['other_income'];
            $day['cash_out'] = $day['purchase_paid'] + $day['refunds'] + $day['operating_expense'];
            $day['net_cashflow'] = $day['cash_in'] - $day['cash_out'];
        }
        unset($day);
    }
    unset($branch);

    $summary = accounting_blank_branch(['id' => 0, 'code' => 'ALL', 'name' => 'Semua Cabang', 'is_active' => 1]);
    foreach ($selectedBranches as $branch) {
        foreach ([
            'gross_sales','refunds','net_sales','cogs_sold','cogs_returned','cogs','gross_profit','other_income',
            'operating_expense','net_profit','sales_collected','debt_collections','purchase_paid','cash_in','cash_out',
            'net_cashflow','receivables','payables','inventory_value'
        ] as $metric) {
            $summary[$metric] += accounting_number($branch[$metric]);
        }
        $summary['transaction_count'] += (int)$branch['transaction_count'];
    }
    $summary['average_transaction'] = $summary['transaction_count'] > 0 ? $summary['net_sales'] / $summary['transaction_count'] : 0.0;
    $summary['margin'] = abs($summary['net_sales']) > 0.0001 ? ($summary['net_profit'] / $summary['net_sales']) * 100 : 0.0;

    $daily = [];
    foreach ($period as $date) {
        $key = $date->format('Y-m-d');
        $row = [
            'date' => $key,
            'gross_sales' => 0.0,
            'refunds' => 0.0,
            'net_sales' => 0.0,
            'cogs' => 0.0,
            'gross_profit' => 0.0,
            'other_income' => 0.0,
            'operating_expense' => 0.0,
            'net_profit' => 0.0,
            'cash_in' => 0.0,
            'cash_out' => 0.0,
            'net_cashflow' => 0.0,
            'transaction_count' => 0,
        ];
        foreach ($selectedIds as $bid) {
            $source = $dailyByBranch[$bid][$key] ?? [];
            foreach (['gross_sales','refunds','net_sales','cogs','gross_profit','other_income','operating_expense','net_profit','cash_in','cash_out','net_cashflow'] as $metric) {
                $row[$metric] += accounting_number($source[$metric] ?? 0);
            }
            $row['transaction_count'] += (int)($source['transaction_count'] ?? 0);
        }
        $daily[] = $row;
    }

    $paymentMap = [];
    if (accounting_table_exists($db, 'transactions')) {
        $rows = accounting_query($db, "
            SELECT COALESCE(t.branch_id, {$mainId}) AS branch_id,
                   COALESCE(NULLIF(TRIM(t.payment_method),''),'other') AS payment_method,
                   SUM(GREATEST(0, LEAST(COALESCE(t.grand_total,0), COALESCE(t.paid_amount,0)-COALESCE(t.change_amount,0)))) AS amount
            FROM transactions t
            WHERE t.status IN ('success','refunded') AND DATE(t.transaction_date) BETWEEN ? AND ?
            GROUP BY COALESCE(t.branch_id, {$mainId}), COALESCE(NULLIF(TRIM(t.payment_method),''),'other')
        ", [$from, $to]);
        foreach ($rows as $row) {
            $bid = (int)$row['branch_id'];
            if (!isset($selectedSet[$bid])) continue;
            $method = accounting_payment_label((string)$row['payment_method']);
            $paymentMap[$method] = ($paymentMap[$method] ?? 0) + accounting_number($row['amount']);
        }
    }
    if (accounting_table_exists($db, 'customer_debt_payments')) {
        $rows = accounting_query($db, "
            SELECT COALESCE(cdp.branch_id, {$mainId}) AS branch_id,
                   COALESCE(NULLIF(TRIM(cdp.payment_method),''),'other') AS payment_method,
                   SUM(COALESCE(cdp.amount,0)) AS amount
            FROM customer_debt_payments cdp
            WHERE DATE(cdp.created_at) BETWEEN ? AND ?
            GROUP BY COALESCE(cdp.branch_id, {$mainId}), COALESCE(NULLIF(TRIM(cdp.payment_method),''),'other')
        ", [$from, $to]);
        foreach ($rows as $row) {
            $bid = (int)$row['branch_id'];
            if (!isset($selectedSet[$bid])) continue;
            $method = accounting_payment_label((string)$row['payment_method']) . ' - Pelunasan';
            $paymentMap[$method] = ($paymentMap[$method] ?? 0) + accounting_number($row['amount']);
        }
    }
    arsort($paymentMap);
    $payments = [];
    foreach ($paymentMap as $label => $amount) $payments[] = ['label' => $label, 'amount' => $amount];

    $expenseMap = [];
    if (accounting_table_exists($db, 'finance_transactions')) {
        $finance = accounting_finance_sql_parts($db);
        $categoryJoin = accounting_table_exists($db, 'finance_categories') ? 'LEFT JOIN finance_categories fc ON fc.id=ft.category_id' : '';
        $categoryName = accounting_table_exists($db, 'finance_categories') ? "COALESCE(NULLIF(TRIM(fc.name),''),'Tanpa Kategori')" : "'Tanpa Kategori'";
        $categoryLower = accounting_table_exists($db, 'finance_categories') ? "LOWER(TRIM(COALESCE(fc.name,'')))" : "''";
        $rows = accounting_query($db, "
            SELECT {$finance['branch_expr']} AS branch_id,
                   {$categoryName} AS category_name,
                   SUM(COALESCE(ft.amount,0)) AS amount
            FROM finance_transactions ft
            {$categoryJoin}
            {$finance['joins']}
            WHERE ft.type='expense'
              AND ft.transaction_date BETWEEN ? AND ?
              AND LOWER(TRIM(COALESCE(ft.reference_type,''))) NOT IN ('purchase','sales_return')
              AND {$categoryLower} NOT IN ('belanja stok','pembelian stok','stok barang')
            GROUP BY {$finance['branch_expr']}, {$categoryName}
        ", [$from, $to]);
        foreach ($rows as $row) {
            $bid = (int)$row['branch_id'];
            if (!isset($selectedSet[$bid])) continue;
            $label = (string)$row['category_name'];
            $expenseMap[$label] = ($expenseMap[$label] ?? 0) + accounting_number($row['amount']);
        }
    }
    arsort($expenseMap);
    $expenseCategories = [];
    foreach ($expenseMap as $label => $amount) $expenseCategories[] = ['label' => $label, 'amount' => $amount];

    return [
        'from' => $from,
        'to' => $to,
        'branch_id' => $selectedBranchId,
        'branch_name' => $selectedBranchId > 0 && isset($branchById[$selectedBranchId]) ? (string)$branchById[$selectedBranchId]['name'] : 'Semua Cabang',
        'all_branches' => array_values($allBranches),
        'branches' => array_values($selectedBranches),
        'summary' => $summary,
        'daily' => $daily,
        'payments' => $payments,
        'expense_categories' => $expenseCategories,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

function accounting_chart_rows(array $daily, int $maxDailyPoints = 100): array
{
    if (count($daily) <= $maxDailyPoints) {
        foreach ($daily as &$row) {
            $row['label'] = date('d M', strtotime($row['date']));
        }
        unset($row);
        return $daily;
    }

    $monthly = [];
    foreach ($daily as $row) {
        $key = substr((string)$row['date'], 0, 7);
        if (!isset($monthly[$key])) {
            $monthly[$key] = [
                'date' => $key . '-01',
                'label' => date('M Y', strtotime($key . '-01')),
                'net_sales' => 0.0,
                'gross_profit' => 0.0,
                'net_profit' => 0.0,
                'cash_in' => 0.0,
                'cash_out' => 0.0,
                'net_cashflow' => 0.0,
                'transaction_count' => 0,
            ];
        }
        foreach (['net_sales','gross_profit','net_profit','cash_in','cash_out','net_cashflow'] as $metric) {
            $monthly[$key][$metric] += accounting_number($row[$metric] ?? 0);
        }
        $monthly[$key]['transaction_count'] += (int)($row['transaction_count'] ?? 0);
    }
    return array_values($monthly);
}
