<?php
function supplier_return_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function supplier_return_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function supplier_returns_install(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS supplier_returns (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        return_number VARCHAR(100) NOT NULL UNIQUE,
        supplier_id BIGINT NULL,
        purchase_id BIGINT NULL,
        branch_id BIGINT NULL,
        user_id BIGINT NULL,
        total_items INT NOT NULL DEFAULT 0,
        total_qty INT NOT NULL DEFAULT 0,
        total_value DECIMAL(15,2) NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'completed',
        reason VARCHAR(80) NULL,
        note TEXT NULL,
        return_date DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_supplier_returns_supplier (supplier_id),
        INDEX idx_supplier_returns_purchase (purchase_id),
        INDEX idx_supplier_returns_branch (branch_id),
        INDEX idx_supplier_returns_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS supplier_return_items (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        supplier_return_id BIGINT NOT NULL,
        product_id BIGINT NOT NULL,
        product_name VARCHAR(255) NOT NULL,
        qty INT NOT NULL DEFAULT 0,
        unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
        reason VARCHAR(80) NULL,
        note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_supplier_return_items_return (supplier_return_id),
        INDEX idx_supplier_return_items_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (supplier_return_table_exists($db, 'stock_movements') && !supplier_return_column_exists($db, 'stock_movements', 'branch_id')) {
        $db->exec("ALTER TABLE stock_movements ADD COLUMN branch_id BIGINT NULL AFTER user_id");
    }
}

function supplier_return_next_number(PDO $db): string
{
    $prefix = 'RS-' . date('Ymd') . '-';
    $stmt = $db->prepare("SELECT return_number FROM supplier_returns WHERE return_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $parts = explode('-', (string)$last);
        $next = ((int)end($parts)) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function supplier_return_reasons(): array
{
    return [
        'rusak' => 'Barang rusak',
        'expired' => 'Expired',
        'salah_kirim' => 'Salah kirim',
        'cacat' => 'Cacat produksi',
        'lebih_kirim' => 'Lebih kirim',
        'lainnya' => 'Lainnya',
    ];
}

function supplier_return_reason_label(?string $reason): string
{
    $reasons = supplier_return_reasons();
    return $reasons[$reason ?: 'lainnya'] ?? 'Lainnya';
}

function supplier_return_branch_id(PDO $db): ?int
{
    if (function_exists('branch_current_id')) {
        try { return branch_current_id($db); } catch (Throwable $e) {}
    }
    return null;
}

function supplier_return_update_stock(PDO $db, int $productId, int $qty, ?int $branchId, int $returnId, string $note): void
{
    $stmt = $db->prepare("SELECT * FROM products WHERE id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        throw new Exception('Produk retur tidak ditemukan.');
    }

    $before = (int)$product['stock'];

    if ($branchId && supplier_return_table_exists($db, 'product_branch_stocks')) {
        $stmt = $db->prepare("SELECT stock FROM product_branch_stocks WHERE product_id=? AND branch_id=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$productId, $branchId]);
        $branchStock = $stmt->fetchColumn();

        if ($branchStock === false) {
            $stmt = $db->prepare("INSERT INTO product_branch_stocks (branch_id, product_id, stock, minimum_stock, created_at) SELECT ?, id, stock, minimum_stock, NOW() FROM products WHERE id=?");
            $stmt->execute([$branchId, $productId]);
            $stmt = $db->prepare("SELECT stock FROM product_branch_stocks WHERE product_id=? AND branch_id=? LIMIT 1 FOR UPDATE");
            $stmt->execute([$productId, $branchId]);
            $branchStock = $stmt->fetchColumn();
        }

        $before = (int)$branchStock;
        if ($qty > $before) {
            throw new Exception('Stok cabang produk ' . $product['name'] . ' tidak cukup. Stok tersedia: ' . $before);
        }

        $after = $before - $qty;
        $stmt = $db->prepare("UPDATE product_branch_stocks SET stock=?, updated_at=NOW() WHERE product_id=? AND branch_id=?");
        $stmt->execute([$after, $productId, $branchId]);

        $isMain = 0;
        if (supplier_return_table_exists($db, 'branches')) {
            $stmt = $db->prepare("SELECT is_main FROM branches WHERE id=? LIMIT 1");
            $stmt->execute([$branchId]);
            $isMain = (int)$stmt->fetchColumn();
        }
        if ($isMain === 1) {
            $db->prepare("UPDATE products SET stock=?, updated_at=NOW() WHERE id=?")->execute([$after, $productId]);
        }
    } else {
        if ($qty > $before) {
            throw new Exception('Stok produk ' . $product['name'] . ' tidak cukup. Stok tersedia: ' . $before);
        }

        $after = $before - $qty;
        $db->prepare("UPDATE products SET stock=?, updated_at=NOW() WHERE id=?")->execute([$after, $productId]);
    }

    if (supplier_return_column_exists($db, 'stock_movements', 'branch_id')) {
        $stmt = $db->prepare("INSERT INTO stock_movements (product_id,user_id,branch_id,type,qty,stock_before,stock_after,reference_type,reference_id,note,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->execute([$productId, Auth::id(), $branchId, 'supplier_return', $qty, $before, $after, 'supplier_return', $returnId, $note]);
    } else {
        $stmt = $db->prepare("INSERT INTO stock_movements (product_id,user_id,type,qty,stock_before,stock_after,reference_type,reference_id,note,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->execute([$productId, Auth::id(), 'supplier_return', $qty, $before, $after, 'supplier_return', $returnId, $note]);
    }
}

function supplier_return_purchase_label_expr(PDO $db): string
{
    if (!supplier_return_table_exists($db, 'purchases')) {
        return "CAST(id AS CHAR)";
    }

    $candidates = ['invoice_number', 'purchase_number', 'reference_number', 'ref_number', 'code', 'number'];
    foreach ($candidates as $col) {
        if (supplier_return_column_exists($db, 'purchases', $col)) {
            return $col;
        }
    }

    return "CONCAT('PO-', id)";
}

function supplier_return_purchase_date_expr(PDO $db): string
{
    $candidates = ['purchase_date', 'transaction_date', 'date', 'created_at'];
    foreach ($candidates as $col) {
        if (supplier_return_column_exists($db, 'purchases', $col)) {
            return $col;
        }
    }
    return 'id';
}

function supplier_return_purchase_total_expr(PDO $db): string
{
    $candidates = ['grand_total', 'total_amount', 'total', 'subtotal'];
    foreach ($candidates as $col) {
        if (supplier_return_column_exists($db, 'purchases', $col)) {
            return $col;
        }
    }
    return '0';
}


?>