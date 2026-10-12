<?php
function branch_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function branch_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function branch_install(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS branches (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(160) NOT NULL,
        phone VARCHAR(50) NULL,
        address TEXT NULL,
        is_main TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_branches_active (is_active),
        INDEX idx_branches_main (is_main)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $count = (int) $db->query("SELECT COUNT(*) FROM branches")->fetchColumn();
    if ($count === 0) {
        $storeName = setting('store_name', 'Cabang Pusat') ?: 'Cabang Pusat';
        $stmt = $db->prepare("INSERT INTO branches (code, name, phone, address, is_main, is_active, created_at) VALUES (?, ?, ?, ?, 1, 1, NOW())");
        $stmt->execute(['PUSAT', $storeName, setting('store_phone', ''), setting('store_address', '')]);
    }

    $mainId = branch_main_id($db);

    if (branch_table_exists($db, 'users') && !branch_column_exists($db, 'users', 'branch_id')) {
        $db->exec("ALTER TABLE users ADD COLUMN branch_id BIGINT NULL AFTER role_id");
        $db->exec("ALTER TABLE users ADD INDEX idx_users_branch (branch_id)");
        $db->prepare("UPDATE users SET branch_id = ? WHERE branch_id IS NULL")->execute([$mainId]);
    }

    if (branch_table_exists($db, 'transactions') && !branch_column_exists($db, 'transactions', 'branch_id')) {
        $db->exec("ALTER TABLE transactions ADD COLUMN branch_id BIGINT NULL AFTER customer_id");
        $db->exec("ALTER TABLE transactions ADD INDEX idx_transactions_branch (branch_id)");
        $db->prepare("UPDATE transactions SET branch_id = ? WHERE branch_id IS NULL")->execute([$mainId]);
    }

    if (branch_table_exists($db, 'stock_movements') && !branch_column_exists($db, 'stock_movements', 'branch_id')) {
        $db->exec("ALTER TABLE stock_movements ADD COLUMN branch_id BIGINT NULL AFTER user_id");
        $db->exec("ALTER TABLE stock_movements ADD INDEX idx_stock_movements_branch (branch_id)");
        $db->prepare("UPDATE stock_movements SET branch_id = ? WHERE branch_id IS NULL")->execute([$mainId]);
    }

    if (branch_table_exists($db, 'purchases') && !branch_column_exists($db, 'purchases', 'branch_id')) {
        $db->exec("ALTER TABLE purchases ADD COLUMN branch_id BIGINT NULL AFTER user_id");
        $db->exec("ALTER TABLE purchases ADD INDEX idx_purchases_branch (branch_id)");
        $db->prepare("UPDATE purchases SET branch_id = ? WHERE branch_id IS NULL")->execute([$mainId]);
    }

    if (branch_table_exists($db, 'stock_opnames') && !branch_column_exists($db, 'stock_opnames', 'branch_id')) {
        $db->exec("ALTER TABLE stock_opnames ADD COLUMN branch_id BIGINT NULL AFTER user_id");
        $db->exec("ALTER TABLE stock_opnames ADD INDEX idx_stock_opnames_branch (branch_id)");
        $db->prepare("UPDATE stock_opnames SET branch_id = ? WHERE branch_id IS NULL")->execute([$mainId]);
    }

    $db->exec("CREATE TABLE IF NOT EXISTS product_branch_stocks (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        branch_id BIGINT NOT NULL,
        product_id BIGINT NOT NULL,
        stock INT NOT NULL DEFAULT 0,
        minimum_stock INT NOT NULL DEFAULT 0,
        rack VARCHAR(80) NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_product_branch (branch_id, product_id),
        INDEX idx_pbs_branch (branch_id),
        INDEX idx_pbs_product (product_id),
        INDEX idx_pbs_stock (stock)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("
        INSERT IGNORE INTO product_branch_stocks (branch_id, product_id, stock, minimum_stock, created_at)
        SELECT b.id, p.id,
               CASE WHEN b.is_main = 1 THEN p.stock ELSE 0 END AS stock,
               p.minimum_stock,
               NOW()
        FROM branches b
        CROSS JOIN products p
    ");
}

function branch_main_id(PDO $db): int
{
    $id = (int) $db->query("SELECT id FROM branches WHERE is_main = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($id > 0) return $id;
    $id = (int) $db->query("SELECT id FROM branches ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($id > 0) {
        $db->prepare("UPDATE branches SET is_main = 1 WHERE id = ?")->execute([$id]);
        return $id;
    }
    return 1;
}

function branch_is_admin(): bool
{
    return in_array(Auth::role(), ['super_admin', 'owner'], true);
}

function branch_current_id(PDO $db): int
{
    branch_install($db);
    if (branch_is_admin() && isset($_GET['branch_id'])) {
        $bid = (int) $_GET['branch_id'];
        if ($bid > 0) {
            $stmt = $db->prepare("SELECT id FROM branches WHERE id = ? AND is_active = 1");
            $stmt->execute([$bid]);
            if ($stmt->fetchColumn()) $_SESSION['active_branch_id'] = $bid;
        }
    }
    if (branch_is_admin() && !empty($_SESSION['active_branch_id'])) {
        $stmt = $db->prepare("SELECT id FROM branches WHERE id = ? AND is_active = 1");
        $stmt->execute([(int) $_SESSION['active_branch_id']]);
        $id = (int) $stmt->fetchColumn();
        if ($id > 0) return $id;
    }
    $stmt = $db->prepare("SELECT branch_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([Auth::id()]);
    $userBranchId = (int) $stmt->fetchColumn();
    if ($userBranchId > 0) {
        $stmt = $db->prepare("SELECT id FROM branches WHERE id = ? AND is_active = 1");
        $stmt->execute([$userBranchId]);
        if ($stmt->fetchColumn()) return $userBranchId;
    }
    return branch_main_id($db);
}

function branch_current(PDO $db): array
{
    $id = branch_current_id($db);
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: ['id' => $id, 'code' => 'PUSAT', 'name' => 'Cabang Pusat'];
}

function branch_all(PDO $db, bool $activeOnly = true): array
{
    branch_install($db);
    $sql = "SELECT * FROM branches" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY is_main DESC, name ASC";
    return $db->query($sql)->fetchAll();
}

function branch_name(PDO $db, ?int $branchId): string
{
    if (!$branchId) return '-';
    $stmt = $db->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$branchId]);
    return (string) ($stmt->fetchColumn() ?: '-');
}

function branch_sync_product(PDO $db, int $productId): void
{
    // Jangan panggil branch_install() di sini.
    // Fungsi ini sering dipakai di dalam transaksi POS; DDL CREATE/ALTER dari branch_install()
    // bisa memicu implicit commit MySQL dan menyebabkan error: "There is no active transaction".
    $stmt = $db->prepare("
        INSERT IGNORE INTO product_branch_stocks (branch_id, product_id, stock, minimum_stock, created_at)
        SELECT b.id, p.id, CASE WHEN b.is_main=1 THEN p.stock ELSE 0 END, p.minimum_stock, NOW()
        FROM branches b
        JOIN products p ON p.id = ?
    ");
    $stmt->execute([$productId]);
}

function branch_stock(PDO $db, int $branchId, int $productId): array
{
    branch_sync_product($db, $productId);
    $stmt = $db->prepare("SELECT * FROM product_branch_stocks WHERE branch_id = ? AND product_id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$branchId, $productId]);
    return $stmt->fetch() ?: ['stock' => 0, 'minimum_stock' => 0];
}
