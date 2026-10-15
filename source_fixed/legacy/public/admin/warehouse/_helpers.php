<?php

function warehouse_table_exists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function warehouse_column_exists(PDO $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function warehouse_index_exists(PDO $db, string $table, string $index): bool
{
    try {
        $s=$db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1");
        $s->execute([$table,$index]);
        return (bool)$s->fetchColumn();
    } catch(Throwable $e){ return false; }
}

function warehouse_install(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS warehouses (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(160) NOT NULL,
        phone VARCHAR(50) NULL,
        address TEXT NULL,
        is_main TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        note TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_warehouse_code (code),
        KEY idx_warehouse_main (is_main),
        KEY idx_warehouse_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if ((int)$db->query("SELECT COUNT(*) FROM warehouses")->fetchColumn() === 0) {
        $stmt = $db->prepare("INSERT INTO warehouses (code,name,phone,address,is_main,is_active,note) VALUES ('GDG-UTAMA','Gudang Utama',?,?,1,1,'Gudang pusat persediaan supplier')");
        $stmt->execute([setting('store_phone', ''), setting('store_address', '')]);
    }

    $db->exec("CREATE TABLE IF NOT EXISTS warehouse_stocks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        warehouse_id BIGINT UNSIGNED NOT NULL,
        product_id INT NOT NULL,
        stock INT NOT NULL DEFAULT 0,
        minimum_stock INT NOT NULL DEFAULT 0,
        purchase_price DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_warehouse_product (warehouse_id,product_id),
        KEY idx_ws_warehouse (warehouse_id),
        KEY idx_ws_product (product_id),
        KEY idx_ws_stock (stock)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


    $db->exec("CREATE TABLE IF NOT EXISTS warehouse_variant_stocks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        warehouse_id BIGINT UNSIGNED NOT NULL,
        product_id INT NOT NULL,
        variant_id BIGINT NOT NULL,
        stock INT NOT NULL DEFAULT 0,
        minimum_stock INT NOT NULL DEFAULT 0,
        purchase_price DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_warehouse_variant (warehouse_id,variant_id),
        KEY idx_wvs_product (product_id),
        KEY idx_wvs_stock (stock)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Bulk Request Stock membaca stok varian berdasarkan warehouse + produk.
    // Index komposit ini menghindari scan besar saat jumlah varian bertambah.
    try { if (!warehouse_index_exists($db,'warehouse_variant_stocks','idx_wvs_warehouse_product')) $db->exec("ALTER TABLE warehouse_variant_stocks ADD INDEX idx_wvs_warehouse_product(warehouse_id,product_id)"); } catch(Throwable $e) {}

    $db->exec("CREATE TABLE IF NOT EXISTS warehouse_stock_movements (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        warehouse_id BIGINT UNSIGNED NOT NULL,
        product_id INT NOT NULL,
        user_id INT NULL,
        type VARCHAR(50) NOT NULL,
        qty INT NOT NULL DEFAULT 0,
        stock_before INT NOT NULL DEFAULT 0,
        stock_after INT NOT NULL DEFAULT 0,
        unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        reference_type VARCHAR(80) NULL,
        reference_id BIGINT NULL,
        note TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_wsm_warehouse (warehouse_id),
        KEY idx_wsm_product (product_id),
        KEY idx_wsm_reference (reference_type,reference_id),
        KEY idx_wsm_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS branch_stock_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        request_number VARCHAR(80) NOT NULL,
        branch_id BIGINT NOT NULL,
        warehouse_id BIGINT UNSIGNED NOT NULL,
        requested_by INT NULL,
        status ENUM('pending','approved','fulfilled','rejected','cancelled') NOT NULL DEFAULT 'pending',
        total_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        note TEXT NULL,
        action_note TEXT NULL,
        approved_by INT NULL,
        approved_at DATETIME NULL,
        fulfilled_by INT NULL,
        fulfilled_at DATETIME NULL,
        rejected_by INT NULL,
        rejected_at DATETIME NULL,
        cancelled_by INT NULL,
        cancelled_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_stock_request_number (request_number),
        KEY idx_bsr_branch (branch_id),
        KEY idx_bsr_warehouse (warehouse_id),
        KEY idx_bsr_status (status),
        KEY idx_bsr_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS branch_stock_request_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id BIGINT UNSIGNED NOT NULL,
        product_id INT NOT NULL,
        product_name VARCHAR(180) NOT NULL,
        product_code VARCHAR(80) NULL,
        unit VARCHAR(40) NOT NULL DEFAULT 'pcs',
        qty_requested INT NOT NULL DEFAULT 0,
        qty_fulfilled INT NOT NULL DEFAULT 0,
        unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        line_total DECIMAL(15,2) NOT NULL DEFAULT 0,
        warehouse_stock_before INT NULL,
        warehouse_stock_after INT NULL,
        branch_stock_before INT NULL,
        branch_stock_after INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_bsri_request (request_id),
        KEY idx_bsri_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


    foreach ([
        'variant_id' => "BIGINT NULL AFTER product_id",
        'variant_name' => "VARCHAR(180) NULL AFTER product_name",
        'variant_sku' => "VARCHAR(120) NULL AFTER variant_name"
    ] as $col=>$def) {
        try { if (!warehouse_column_exists($db,'branch_stock_request_items',$col)) $db->exec("ALTER TABLE branch_stock_request_items ADD COLUMN {$col} {$def}"); } catch(Throwable $e) {}
    }
    try { if (!warehouse_index_exists($db,'branch_stock_request_items','idx_bsri_variant')) $db->exec("ALTER TABLE branch_stock_request_items ADD INDEX idx_bsri_variant(variant_id)"); } catch(Throwable $e) {}

    if (warehouse_table_exists($db, 'purchases')) {
        if (!warehouse_column_exists($db, 'purchases', 'stock_destination_type')) {
            $db->exec("ALTER TABLE purchases ADD COLUMN stock_destination_type ENUM('branch','warehouse') NOT NULL DEFAULT 'branch' AFTER user_id");
        }
        if (!warehouse_column_exists($db, 'purchases', 'warehouse_id')) {
            $db->exec("ALTER TABLE purchases ADD COLUMN warehouse_id BIGINT UNSIGNED NULL AFTER stock_destination_type");
            $db->exec("ALTER TABLE purchases ADD INDEX idx_purchases_warehouse (warehouse_id)");
        }
    }

    // Buat baris stok gudang untuk semua produk lama tanpa mengubah stok cabang/POS.
    $mainWarehouseId = warehouse_main_id($db);
    $stmt = $db->prepare("INSERT IGNORE INTO warehouse_stocks (warehouse_id,product_id,stock,minimum_stock,purchase_price,created_at)
        SELECT ?, p.id, 0, p.minimum_stock, p.purchase_price, NOW() FROM products p");
    $stmt->execute([$mainWarehouseId]);
}

function warehouse_main_id(PDO $db): int
{
    $id = (int)$db->query("SELECT id FROM warehouses WHERE is_main=1 AND is_active=1 ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($id > 0) return $id;
    $id = (int)$db->query("SELECT id FROM warehouses WHERE is_active=1 ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($id > 0) {
        $db->prepare("UPDATE warehouses SET is_main=1 WHERE id=?")->execute([$id]);
        return $id;
    }
    return 1;
}

function warehouse_all(PDO $db, bool $activeOnly = true): array
{
    warehouse_install($db);
    $sql = "SELECT * FROM warehouses" . ($activeOnly ? " WHERE is_active=1" : "") . " ORDER BY is_main DESC,name ASC";
    return $db->query($sql)->fetchAll();
}

function warehouse_name(PDO $db, int $warehouseId): string
{
    $stmt = $db->prepare("SELECT name FROM warehouses WHERE id=? LIMIT 1");
    $stmt->execute([$warehouseId]);
    return (string)($stmt->fetchColumn() ?: 'Gudang');
}

function warehouse_sync_product(PDO $db, int $warehouseId, int $productId): void
{
    $stmt = $db->prepare("INSERT IGNORE INTO warehouse_stocks (warehouse_id,product_id,stock,minimum_stock,purchase_price,created_at)
        SELECT ?,p.id,0,p.minimum_stock,p.purchase_price,NOW() FROM products p WHERE p.id=?");
    $stmt->execute([$warehouseId, $productId]);
}

function warehouse_stock(PDO $db, int $warehouseId, int $productId, bool $forUpdate = false): array
{
    warehouse_sync_product($db, $warehouseId, $productId);
    $sql = "SELECT * FROM warehouse_stocks WHERE warehouse_id=? AND product_id=? LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "");
    $stmt = $db->prepare($sql);
    $stmt->execute([$warehouseId, $productId]);
    return $stmt->fetch() ?: ['stock'=>0,'minimum_stock'=>0,'purchase_price'=>0];
}

function warehouse_request_number(PDO $db): string
{
    $prefix = 'RST-' . date('Ymd') . '-';
    $stmt = $db->prepare("SELECT request_number FROM branch_stock_requests WHERE request_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string)($stmt->fetchColumn() ?: '');
    $next = $last !== '' ? ((int)substr($last, -4) + 1) : 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function warehouse_request_status_label(string $status): string
{
    return [
        'pending' => 'Menunggu Gudang',
        'approved' => 'Disetujui',
        'fulfilled' => 'Stok Dikirim',
        'rejected' => 'Ditolak',
        'cancelled' => 'Dibatalkan',
    ][$status] ?? ucfirst($status);
}

function warehouse_request_status_class(string $status): string
{
    return [
        'pending' => 'warning',
        'approved' => 'info',
        'fulfilled' => 'success',
        'rejected' => 'danger',
        'cancelled' => 'muted',
    ][$status] ?? 'muted';
}

function warehouse_can_manage(): bool
{
    return in_array(Auth::role(), ['super_admin','owner'], true);
}

function warehouse_require_request_access(): void
{
    Auth::requireLogin();
    if (warehouse_can_manage()) return;
    if (Auth::can('pos.access')) return;
    flash('error', 'Kamu tidak punya akses ke Request Stock.');
    legacy_redirect('admin/dashboard.php');
}

function warehouse_require_manage(): void
{
    Auth::requireRole(['super_admin','owner']);
}

function warehouse_record_movement(PDO $db, int $warehouseId, int $productId, string $type, int $qty, int $before, int $after, float $unitCost, ?string $referenceType = null, ?int $referenceId = null, ?string $note = null): void
{
    $stmt = $db->prepare("INSERT INTO warehouse_stock_movements (warehouse_id,product_id,user_id,type,qty,stock_before,stock_after,unit_cost,reference_type,reference_id,note,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([$warehouseId,$productId,Auth::id(),$type,$qty,$before,$after,$unitCost,$referenceType,$referenceId,$note]);
}

function warehouse_notify(PDO $db, ?int $userId, string $title, string $message, string $type = 'info'): void
{
    if (!$userId || !warehouse_table_exists($db, 'notifications')) return;
    try {
        $stmt = $db->prepare("INSERT INTO notifications (user_id,title,message,type,is_read) VALUES (?,?,?,?,0)");
        $stmt->execute([$userId,$title,$message,$type]);
    } catch (Throwable $e) {
        // Notifikasi tidak boleh menggagalkan transaksi stok.
    }
}

function warehouse_variant_stock(PDO $db,int $warehouseId,int $productId,int $variantId,bool $forUpdate=false): array
{
    warehouse_install($db);
    $sql="SELECT * FROM warehouse_variant_stocks WHERE warehouse_id=? AND variant_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $s=$db->prepare($sql);$s->execute([$warehouseId,$variantId]);$row=$s->fetch();
    if($row) return $row;
    $db->prepare("INSERT IGNORE INTO warehouse_variant_stocks(warehouse_id,product_id,variant_id,stock,minimum_stock,purchase_price,created_at) VALUES(?,?,?,0,0,0,NOW())")->execute([$warehouseId,$productId,$variantId]);
    $s=$db->prepare($sql);$s->execute([$warehouseId,$variantId]);
    return $s->fetch() ?: ['stock'=>0,'minimum_stock'=>0,'purchase_price'=>0];
}

function warehouse_set_variant_stock(PDO $db,int $warehouseId,int $productId,int $variantId,int $stock,float $purchasePrice=0,int $minimumStock=0): void
{
    warehouse_install($db);
    $db->prepare("INSERT INTO warehouse_variant_stocks(warehouse_id,product_id,variant_id,stock,minimum_stock,purchase_price,created_at) VALUES(?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE stock=VALUES(stock),minimum_stock=VALUES(minimum_stock),purchase_price=VALUES(purchase_price),updated_at=NOW()")
       ->execute([$warehouseId,$productId,$variantId,max(0,$stock),max(0,$minimumStock),max(0,$purchasePrice)]);
}
