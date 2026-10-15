<?php

function sales_returns_install(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS sales_returns (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        return_number VARCHAR(80) NOT NULL,
        transaction_id BIGINT NOT NULL,
        customer_id BIGINT NULL,
        user_id INT NULL,
        type ENUM('refund','exchange','store_credit') NOT NULL DEFAULT 'refund',
        status ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
        total_refund DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        reason TEXT NULL,
        note TEXT NULL,
        return_date DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY sales_returns_number_unique (return_number),
        KEY idx_sales_returns_transaction (transaction_id),
        KEY idx_sales_returns_user (user_id),
        KEY idx_sales_returns_status (status),
        KEY idx_sales_returns_date (return_date),
        CONSTRAINT fk_sales_returns_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
        CONSTRAINT fk_sales_returns_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS sales_return_items (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        return_id BIGINT NOT NULL,
        transaction_item_id BIGINT NOT NULL,
        product_id INT NULL,
        product_name VARCHAR(180) NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        restock TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sales_return_items_return (return_id),
        KEY idx_sales_return_items_trx_item (transaction_item_id),
        KEY idx_sales_return_items_product (product_id),
        CONSTRAINT fk_sales_return_items_return FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON DELETE CASCADE,
        CONSTRAINT fk_sales_return_items_trx_item FOREIGN KEY (transaction_item_id) REFERENCES transaction_items(id) ON DELETE CASCADE,
        CONSTRAINT fk_sales_return_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function sales_returns_table_exists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function sales_returns_next_number(PDO $db): string
{
    $prefix = 'RET-' . date('Ymd') . '-';
    $stmt = $db->prepare("SELECT return_number FROM sales_returns WHERE return_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string)($stmt->fetchColumn() ?: '');
    $next = 1;

    if ($last !== '') {
        $next = ((int)substr($last, -4)) + 1;
    }

    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function sales_returns_returned_qty(PDO $db, int $transactionItemId): int
{
    $stmt = $db->prepare("SELECT COALESCE(SUM(sri.qty),0) FROM sales_return_items sri JOIN sales_returns sr ON sr.id = sri.return_id WHERE sri.transaction_item_id = ? AND sr.status = 'completed'");
    $stmt->execute([$transactionItemId]);
    return (int)$stmt->fetchColumn();
}

function sales_returns_fetch_transaction(PDO $db, int $transactionId): ?array
{
    $stmt = $db->prepare("SELECT t.*, u.name AS cashier_name, c.name AS customer_name, c.phone AS customer_phone
        FROM transactions t
        LEFT JOIN users u ON u.id = t.cashier_id
        LEFT JOIN customers c ON c.id = t.customer_id
        WHERE t.id = ? LIMIT 1");
    $stmt->execute([$transactionId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function sales_returns_fetch_transaction_by_invoice(PDO $db, string $invoice): ?array
{
    $stmt = $db->prepare("SELECT t.*, u.name AS cashier_name, c.name AS customer_name, c.phone AS customer_phone
        FROM transactions t
        LEFT JOIN users u ON u.id = t.cashier_id
        LEFT JOIN customers c ON c.id = t.customer_id
        WHERE t.invoice_number = ? LIMIT 1");
    $stmt->execute([$invoice]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function sales_returns_fetch_items_with_available(PDO $db, int $transactionId): array
{
    $stmt = $db->prepare("SELECT ti.* FROM transaction_items ti WHERE ti.transaction_id = ? ORDER BY ti.id ASC");
    $stmt->execute([$transactionId]);
    $items = $stmt->fetchAll();

    foreach ($items as &$item) {
        $returned = sales_returns_returned_qty($db, (int)$item['id']);
        $item['returned_qty'] = $returned;
        $item['available_qty'] = max(0, (int)$item['qty'] - $returned);
    }

    return $items;
}

function sales_returns_update_transaction_status(PDO $db, int $transactionId): void
{
    $stmt = $db->prepare("SELECT COALESCE(SUM(qty),0) FROM transaction_items WHERE transaction_id = ?");
    $stmt->execute([$transactionId]);
    $soldQty = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(sri.qty),0)
        FROM sales_return_items sri
        JOIN sales_returns sr ON sr.id = sri.return_id
        WHERE sr.transaction_id = ? AND sr.status = 'completed'");
    $stmt->execute([$transactionId]);
    $returnedQty = (int)$stmt->fetchColumn();

    if ($soldQty > 0 && $returnedQty >= $soldQty) {
        $up = $db->prepare("UPDATE transactions SET status = 'refunded' WHERE id = ?");
        $up->execute([$transactionId]);
    }
}

function sales_returns_insert_finance_expense(PDO $db, int $returnId, string $returnNumber, float $amount): void
{
    if ($amount <= 0 || !sales_returns_table_exists($db, 'finance_transactions')) {
        return;
    }

    try {
        $stmt = $db->prepare("INSERT INTO finance_transactions (category_id, user_id, type, amount, title, description, reference_type, reference_id, transaction_date, created_at)
            VALUES (NULL, ?, 'expense', ?, ?, ?, 'sales_return', ?, ?, ?)");
        $stmt->execute([
            Auth::id(),
            $amount,
            'Refund ' . $returnNumber,
            'Pengeluaran refund/retur penjualan ' . $returnNumber,
            $returnId,
            date('Y-m-d'),
            now_datetime(),
        ]);
    } catch (Throwable $e) {
        // modul retur tetap jalan walaupun modul finance gagal
    }
}

function sales_returns_notify(PDO $db, string $title, string $message): void
{
    if (!sales_returns_table_exists($db, 'notifications')) {
        return;
    }

    try {
        $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (NULL, ?, ?, 'info', 0, ?)");
        $stmt->execute([$title, $message, now_datetime()]);
    } catch (Throwable $e) {
        // abaikan notifikasi jika enum/struktur beda
    }
}
