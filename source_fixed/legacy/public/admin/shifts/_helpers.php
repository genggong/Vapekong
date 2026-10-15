<?php

function shift_ensure_tables(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS cashier_shifts (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        shift_code VARCHAR(80) NOT NULL,
        user_id INT NOT NULL,
        branch_id BIGINT NULL,
        location_name VARCHAR(150) NULL,
        opening_cash DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        closing_cash DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        cash_sales DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        non_cash_sales DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        total_refund DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        expected_cash DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        actual_cash DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        cash_difference DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        total_transactions INT NOT NULL DEFAULT 0,
        status ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
        opened_at DATETIME NOT NULL,
        closed_at DATETIME NULL,
        opening_note TEXT NULL,
        closing_note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY shift_code_unique (shift_code),
        KEY idx_cashier_shifts_user (user_id),
        KEY idx_cashier_shifts_branch (branch_id),
        KEY idx_cashier_shifts_status (status),
        KEY idx_cashier_shifts_opened (opened_at),
        KEY idx_cashier_shifts_closed (closed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!shift_column_exists($db, 'cashier_shifts', 'branch_id')) {
        $db->exec("ALTER TABLE cashier_shifts ADD COLUMN branch_id BIGINT NULL AFTER user_id");
        try { $db->exec("ALTER TABLE cashier_shifts ADD INDEX idx_cashier_shifts_branch (branch_id)"); } catch (Throwable $ignore) {}
    }
    if (!shift_column_exists($db, 'cashier_shifts', 'location_name')) {
        $db->exec("ALTER TABLE cashier_shifts ADD COLUMN location_name VARCHAR(150) NULL AFTER branch_id");
    }
}

function shift_column_exists(PDO $db, string $table, string $column): bool
{
    if ($table !== 'cashier_shifts') return false;
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function shift_table_exists(PDO $db, string $table): bool
{
    $allowed = ['transactions', 'sales_returns', 'cashier_shifts'];
    if (!in_array($table, $allowed, true)) return false;

    try {
        $db->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function shift_generate_code(): string
{
    return 'SHF-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
}

function shift_active(PDO $db, ?int $userId = null): ?array
{
    if ($userId) {
        $stmt = $db->prepare("SELECT cs.*, u.name AS user_name, b.name AS branch_name FROM cashier_shifts cs LEFT JOIN users u ON u.id=cs.user_id LEFT JOIN branches b ON b.id=cs.branch_id WHERE cs.status='open' AND cs.user_id=? ORDER BY cs.id DESC LIMIT 1");
        $stmt->execute([$userId]);
    } else {
        $stmt = $db->query("SELECT cs.*, u.name AS user_name, b.name AS branch_name FROM cashier_shifts cs LEFT JOIN users u ON u.id=cs.user_id LEFT JOIN branches b ON b.id=cs.branch_id WHERE cs.status='open' ORDER BY cs.id DESC LIMIT 1");
    }

    $row = $stmt->fetch();
    return $row ?: null;
}

function shift_find(PDO $db, int $id): ?array
{
    $stmt = $db->prepare("SELECT cs.*, u.name AS user_name, u.username AS username, b.name AS branch_name, b.code AS branch_code FROM cashier_shifts cs LEFT JOIN users u ON u.id=cs.user_id LEFT JOIN branches b ON b.id=cs.branch_id WHERE cs.id=? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function shift_calculate(PDO $db, array $shift): array
{
    $start = $shift['opened_at'];
    $end = !empty($shift['closed_at']) ? $shift['closed_at'] : now_datetime();
    $userId = (int)$shift['user_id'];

    $cashSales = 0.0;
    $nonCashSales = 0.0;
    $trxCount = 0;
    $refund = 0.0;

    if (shift_table_exists($db, 'transactions')) {
        $branchSql = !empty($shift['branch_id']) ? ' AND branch_id=?' : '';
        $params = [$userId, $start, $end];
        if (!empty($shift['branch_id'])) $params[] = (int)$shift['branch_id'];
        $stmt = $db->prepare("SELECT
            COALESCE(SUM(CASE WHEN LOWER(payment_method)='cash' THEN grand_total ELSE 0 END),0) AS cash_sales,
            COALESCE(SUM(CASE WHEN LOWER(payment_method)!='cash' THEN grand_total ELSE 0 END),0) AS non_cash_sales,
            COUNT(*) AS total_transactions
            FROM transactions
            WHERE cashier_id=? AND status='success' AND transaction_date BETWEEN ? AND ?{$branchSql}");
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        $cashSales = (float)($row['cash_sales'] ?? 0);
        $nonCashSales = (float)($row['non_cash_sales'] ?? 0);
        $trxCount = (int)($row['total_transactions'] ?? 0);
    }

    if (shift_table_exists($db, 'sales_returns')) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(total_refund),0) AS total FROM sales_returns WHERE user_id=? AND status='completed' AND return_date BETWEEN ? AND ?");
        $stmt->execute([$userId, $start, $end]);
        $refund = (float)($stmt->fetch()['total'] ?? 0);
    }

    $openingCash = (float)($shift['opening_cash'] ?? 0);
    $expectedCash = $openingCash + $cashSales - $refund;
    $actualCash = (float)($shift['actual_cash'] ?? 0);
    $difference = $actualCash > 0 || ($shift['status'] ?? '') === 'closed' ? $actualCash - $expectedCash : 0;

    return [
        'start' => $start,
        'end' => $end,
        'opening_cash' => $openingCash,
        'cash_sales' => $cashSales,
        'non_cash_sales' => $nonCashSales,
        'total_refund' => $refund,
        'expected_cash' => $expectedCash,
        'actual_cash' => $actualCash,
        'cash_difference' => $difference,
        'total_transactions' => $trxCount,
    ];
}

function shift_update_totals(PDO $db, array $shift, ?float $actualCash = null, ?string $closedAt = null): array
{
    if ($closedAt !== null) {
        $shift['closed_at'] = $closedAt;
    }
    if ($actualCash !== null) {
        $shift['actual_cash'] = $actualCash;
    }

    $calc = shift_calculate($db, $shift);

    $stmt = $db->prepare("UPDATE cashier_shifts SET cash_sales=?, non_cash_sales=?, total_refund=?, expected_cash=?, actual_cash=?, cash_difference=?, total_transactions=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([
        $calc['cash_sales'],
        $calc['non_cash_sales'],
        $calc['total_refund'],
        $calc['expected_cash'],
        $actualCash ?? (float)($shift['actual_cash'] ?? 0),
        $actualCash !== null ? ($actualCash - $calc['expected_cash']) : (float)($shift['cash_difference'] ?? 0),
        $calc['total_transactions'],
        (int)$shift['id'],
    ]);

    return $calc;
}

function shift_location_label(array $shift): string
{
    $branch = trim((string)($shift['branch_name'] ?? ''));
    $location = trim((string)($shift['location_name'] ?? ''));
    if ($branch !== '' && $location !== '') return $branch . ' • ' . $location;
    if ($branch !== '') return $branch;
    if ($location !== '') return $location;
    return '-';
}

function shift_status_badge(string $status): string
{
    $status = strtolower($status);
    if ($status === 'open') return '<span class="shift-badge success">Aktif</span>';
    if ($status === 'closed') return '<span class="shift-badge dark">Tutup</span>';
    return '<span class="shift-badge danger">Batal</span>';
}

function shift_diff_badge($amount): string
{
    $amount = (float)$amount;
    if ($amount > 0) return '<span class="shift-badge success">Lebih ' . rupiah($amount) . '</span>';
    if ($amount < 0) return '<span class="shift-badge danger">Kurang ' . rupiah(abs($amount)) . '</span>';
    return '<span class="shift-badge dark">Pas</span>';
}
