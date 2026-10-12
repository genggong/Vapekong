<?php

function purchases_table_exists(PDO $db, string $table): bool
{
    $allowed = ['purchases', 'purchase_items', 'products', 'suppliers', 'stock_movements', 'finance_transactions', 'finance_categories', 'debts'];
    if (!in_array($table, $allowed, true)) return false;
    try {
        $db->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function purchases_column_exists(PDO $db, string $table, string $column): bool
{
    $allowed = ['purchases', 'purchase_items', 'products', 'suppliers', 'stock_movements', 'finance_transactions', 'finance_categories', 'debts'];
    if (!in_array($table, $allowed, true)) return false;
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($column));
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function purchases_install(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS purchases (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        purchase_number VARCHAR(80) NOT NULL,
        supplier_id INT NULL,
        user_id INT NULL,
        purchase_date DATE NOT NULL,
        supplier_invoice VARCHAR(120) NULL,
        payment_status ENUM('paid','debt') NOT NULL DEFAULT 'paid',
        subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
        discount DECIMAL(15,2) NOT NULL DEFAULT 0,
        shipping_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        grand_total DECIMAL(15,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        debt_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        status ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
        note TEXT NULL,
        cancelled_at DATETIME NULL,
        cancelled_by INT NULL,
        cancel_note TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY purchases_purchase_number_unique (purchase_number),
        KEY idx_purchases_supplier (supplier_id),
        KEY idx_purchases_user (user_id),
        KEY idx_purchases_date (purchase_date),
        KEY idx_purchases_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!purchases_column_exists($db, 'purchases', 'branch_id')) {
        $db->exec("ALTER TABLE purchases ADD COLUMN branch_id BIGINT NULL AFTER user_id");
        $db->exec("ALTER TABLE purchases ADD INDEX idx_purchases_branch (branch_id)");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS purchase_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        purchase_id BIGINT UNSIGNED NOT NULL,
        product_id INT NOT NULL,
        product_name VARCHAR(180) NOT NULL,
        product_code VARCHAR(80) NULL,
        unit VARCHAR(40) DEFAULT 'pcs',
        qty INT NOT NULL DEFAULT 0,
        purchase_price DECIMAL(15,2) NOT NULL DEFAULT 0,
        line_total DECIMAL(15,2) NOT NULL DEFAULT 0,
        stock_before INT NOT NULL DEFAULT 0,
        stock_after INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_purchase_items_purchase (purchase_id),
        KEY idx_purchase_items_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


    foreach ([
        'variant_id' => "BIGINT NULL AFTER product_id",
        'variant_name' => "VARCHAR(180) NULL AFTER product_name",
        'variant_sku' => "VARCHAR(120) NULL AFTER variant_name"
    ] as $col=>$def) {
        try { if (!purchases_column_exists($db,'purchase_items',$col)) $db->exec("ALTER TABLE purchase_items ADD COLUMN {$col} {$def}"); } catch(Throwable $e) {}
    }

    if (purchases_table_exists($db, 'finance_categories')) {
        $stmt = $db->prepare("SELECT id FROM finance_categories WHERE name=? AND type='expense' LIMIT 1");
        $stmt->execute(['Belanja Stok']);
        if (!$stmt->fetch()) {
            $db->prepare("INSERT INTO finance_categories (name,type,icon,color,is_active) VALUES ('Belanja Stok','expense','bi-box-seam','#dc2626',1)")->execute();
        }
    }
}

function purchases_number(PDO $db): string
{
    $prefix = 'PO-' . date('Ymd') . '-';
    $stmt = $db->prepare("SELECT purchase_number FROM purchases WHERE purchase_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetch()['purchase_number'] ?? null;
    $next = 1;
    if ($last) {
        $num = (int) substr($last, -4);
        $next = $num + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function purchases_money($amount): string
{
    return function_exists('rupiah') ? rupiah($amount) : 'Rp ' . number_format((float)$amount, 0, ',', '.');
}

function purchases_status_label(string $status): string
{
    if ($status === 'paid') return 'Lunas';
    if ($status === 'debt') return 'Hutang';
    if ($status === 'cancelled') return 'Dibatalkan';
    return ucfirst($status);
}

function purchases_get_expense_category_id(PDO $db): ?int
{
    if (!purchases_table_exists($db, 'finance_categories')) return null;
    $stmt = $db->prepare("SELECT id FROM finance_categories WHERE type='expense' AND name='Belanja Stok' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];

    $db->prepare("INSERT INTO finance_categories (name,type,icon,color,is_active) VALUES ('Belanja Stok','expense','bi-box-seam','#dc2626',1)")->execute();
    return (int)$db->lastInsertId();
}

function purchases_create_finance_expense(PDO $db, int $purchaseId, float $amount, string $purchaseNumber, string $note = ''): void
{
    if ($amount <= 0 || !purchases_table_exists($db, 'finance_transactions')) return;
    $categoryId = purchases_get_expense_category_id($db);
    $db->prepare("INSERT INTO finance_transactions (category_id,user_id,type,amount,title,description,reference_type,reference_id,transaction_date) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$categoryId, Auth::id(), 'expense', $amount, 'Pembelian Stok ' . $purchaseNumber, $note ?: 'Pembelian barang dari supplier', 'purchase', $purchaseId, date('Y-m-d')]);
}

function purchases_create_debt(PDO $db, int $purchaseId, ?int $supplierId, float $amount, string $purchaseNumber, string $note = ''): void
{
    if ($amount <= 0 || !purchases_table_exists($db, 'debts')) return;
    $title = 'Hutang Pembelian ' . $purchaseNumber;
    $stmt = $db->prepare("INSERT INTO debts (supplier_id,user_id,title,amount,paid_amount,due_date,status,note) VALUES (?,?,?,?,0,NULL,'unpaid',?)");
    $stmt->execute([$supplierId ?: null, Auth::id(), $title, $amount, $note ?: 'Hutang dari pembelian stok #' . $purchaseNumber]);
}

function purchases_create_notification(PDO $db, string $title, string $message, string $type = 'success'): void
{
    if (!purchases_table_exists($db, 'notifications')) return;
    try {
        $db->prepare("INSERT INTO notifications (user_id,title,message,type,is_read) VALUES (?,?,?,?,0)")->execute([Auth::id(), $title, $message, $type]);
    } catch (Throwable $e) {}
}
