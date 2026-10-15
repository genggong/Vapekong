<?php
/**
 * Marketplace Database Doctor V15.10
 * Non-destructive schema inspector / repair helper.
 * It never drops product/order data and avoids foreign keys on compatibility tables.
 */

function marketplace_doctor_table_columns(PDO $db, string $table): array
{
    try {
        $stmt = $db->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
        $stmt->execute([$table]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $name = (string)($r['COLUMN_NAME'] ?? '');
            if ($name === '') continue;
            // Keep Field for compatibility with the old SHOW COLUMNS shape.
            $r['Field'] = $name;
            $out[$name] = $r;
        }
        if ($out) return $out;
    } catch (Throwable $e) {
        // Fall back to SHOW COLUMNS using a validated identifier, never a bound SHOW placeholder.
    }
    try {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
        $rows = $db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $name = (string)($r['Field'] ?? '');
            if ($name !== '') $out[$name] = $r;
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

function marketplace_doctor_index_exists(PDO $db, string $table, string $index): bool
{
    try {
        $stmt = $db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
        $stmt->execute([$table, $index]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function marketplace_doctor_database_name(PDO $db): string
{
    try { return (string)($db->query('SELECT DATABASE()')->fetchColumn() ?: '-'); }
    catch (Throwable $e) { return '-'; }
}

function marketplace_doctor_connection_self_test(PDO $db): array
{
    $result = ['ok'=>false, 'products_readable'=>false, 'database'=>marketplace_doctor_database_name($db), 'error'=>''];
    try {
        $db->query('SELECT 1')->fetchColumn();
        $result['ok'] = true;
        try {
            $db->query('SELECT 1 FROM `products` LIMIT 1');
            $result['products_readable'] = true;
        } catch (Throwable $ignored) {
            // Empty/missing products is reported separately by the schema scanner.
        }
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }
    return $result;
}

function marketplace_doctor_specs(): array
{
    return [
        'marketplace_product_meta' => [
            'label'=>'Metadata Produk Marketplace',
            'columns'=>[
                'product_id'=>'INT NOT NULL', 'brand_id'=>'BIGINT NULL', 'weight_gram'=>'INT NULL',
                'created_at'=>'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'updated_at'=>'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
            ],
        ],
        'marketplace_brands' => [
            'label'=>'Brand / Merek',
            'columns'=>[
                'id'=>'BIGINT NOT NULL AUTO_INCREMENT', 'name'=>'VARCHAR(160) NOT NULL', 'slug'=>'VARCHAR(180) NOT NULL',
                'logo'=>'VARCHAR(255) NULL', 'description'=>'TEXT NULL', 'is_active'=>'TINYINT(1) NOT NULL DEFAULT 1',
                'sort_order'=>'INT NOT NULL DEFAULT 0', 'created_at'=>'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'updated_at'=>'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
            ],
        ],
        'marketplace_product_images' => [
            'label'=>'Galeri Produk',
            'columns'=>[
                'id'=>'BIGINT NOT NULL AUTO_INCREMENT','product_id'=>'BIGINT NOT NULL','image_path'=>'VARCHAR(255) NOT NULL',
                'is_primary'=>'TINYINT(1) NOT NULL DEFAULT 0','sort_order'=>'INT NOT NULL DEFAULT 0','created_at'=>'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            ],
        ],
        'marketplace_product_variants' => [
            'label'=>'Kombinasi Varian',
            'columns'=>[
                'id'=>'BIGINT NOT NULL AUTO_INCREMENT','product_id'=>'BIGINT NOT NULL','name'=>'VARCHAR(160) NOT NULL','sku'=>'VARCHAR(120) NULL',
                'barcode'=>'VARCHAR(120) NULL','selling_price'=>'DECIMAL(15,2) NOT NULL DEFAULT 0','purchase_price'=>'DECIMAL(15,2) NOT NULL DEFAULT 0',
                'weight_gram'=>'INT NOT NULL DEFAULT 100','image_path'=>'VARCHAR(255) NULL','is_active'=>'TINYINT(1) NOT NULL DEFAULT 1','sort_order'=>'INT NOT NULL DEFAULT 0',
                'created_at'=>'TIMESTAMP DEFAULT CURRENT_TIMESTAMP','updated_at'=>'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
            ],
        ],
        'marketplace_product_variant_stocks' => [
            'label'=>'Stok Varian per Cabang',
            'columns'=>[
                'id'=>'BIGINT NOT NULL AUTO_INCREMENT','variant_id'=>'BIGINT NOT NULL','branch_id'=>'BIGINT NOT NULL','stock'=>'INT NOT NULL DEFAULT 0',
                'minimum_stock'=>'INT NOT NULL DEFAULT 0','created_at'=>'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'updated_at'=>'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
            ],
        ],
        'marketplace_variant_options' => [
            'label'=>'Tipe Varian',
            'columns'=>[
                'id'=>'BIGINT NOT NULL AUTO_INCREMENT','product_id'=>'BIGINT NOT NULL','name'=>'VARCHAR(100) NOT NULL','sort_order'=>'INT NOT NULL DEFAULT 0',
                'is_active'=>'TINYINT(1) NOT NULL DEFAULT 1','created_at'=>'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'updated_at'=>'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
            ],
        ],
        'marketplace_variant_option_values' => [
            'label'=>'Nilai Varian',
            'columns'=>[
                'id'=>'BIGINT NOT NULL AUTO_INCREMENT','option_id'=>'BIGINT NOT NULL','value'=>'VARCHAR(120) NOT NULL','color_hex'=>'VARCHAR(16) NULL',
                'sort_order'=>'INT NOT NULL DEFAULT 0','is_active'=>'TINYINT(1) NOT NULL DEFAULT 1','created_at'=>'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'updated_at'=>'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
            ],
        ],
        'marketplace_variant_value_map' => [
            'label'=>'Mapping Nilai ke Varian',
            'columns'=>[
                'id'=>'BIGINT NOT NULL AUTO_INCREMENT','variant_id'=>'BIGINT NOT NULL','option_value_id'=>'BIGINT NOT NULL',
                'created_at'=>'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            ],
        ],
        'marketplace_store_courier_rates' => [
            'label'=>'Tarif Kurir Toko per Kelurahan',
            'columns'=>[
                'id'=>'BIGINT NOT NULL AUTO_INCREMENT','destination_id'=>'BIGINT NULL','province'=>'VARCHAR(120) NULL','city'=>'VARCHAR(120) NULL',
                'district'=>'VARCHAR(120) NULL','subdistrict'=>'VARCHAR(120) NOT NULL','fee'=>'DECIMAL(15,2) NOT NULL DEFAULT 0','eta'=>'VARCHAR(80) NULL',
                'is_active'=>'TINYINT(1) NOT NULL DEFAULT 1','sort_order'=>'INT NOT NULL DEFAULT 0','created_at'=>'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'updated_at'=>'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
            ],
        ],
    ];
}

function marketplace_doctor_create_sql(): array
{
    return [
        'marketplace_product_meta' => "CREATE TABLE IF NOT EXISTS marketplace_product_meta (
            product_id INT NOT NULL PRIMARY KEY, brand_id BIGINT NULL, weight_gram INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mp_product_meta_brand(brand_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'marketplace_brands' => "CREATE TABLE IF NOT EXISTS marketplace_brands (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, slug VARCHAR(180) NOT NULL, logo VARCHAR(255) NULL, description TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_marketplace_brand_slug(slug), INDEX idx_marketplace_brand_active(is_active,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'marketplace_product_images' => "CREATE TABLE IF NOT EXISTS marketplace_product_images (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, product_id BIGINT NOT NULL, image_path VARCHAR(255) NOT NULL, is_primary TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_mp_images_product(product_id,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'marketplace_product_variants' => "CREATE TABLE IF NOT EXISTS marketplace_product_variants (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, product_id BIGINT NOT NULL, name VARCHAR(160) NOT NULL, sku VARCHAR(120) NULL, barcode VARCHAR(120) NULL,
            selling_price DECIMAL(15,2) NOT NULL DEFAULT 0, purchase_price DECIMAL(15,2) NOT NULL DEFAULT 0, weight_gram INT NOT NULL DEFAULT 100, image_path VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, INDEX idx_mp_variants_product(product_id,is_active,sort_order),
            UNIQUE KEY uniq_mp_variant_sku(sku)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'marketplace_product_variant_stocks' => "CREATE TABLE IF NOT EXISTS marketplace_product_variant_stocks (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, variant_id BIGINT NOT NULL, branch_id BIGINT NOT NULL, stock INT NOT NULL DEFAULT 0,
            minimum_stock INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mp_variant_branch(variant_id,branch_id), INDEX idx_mp_variant_stock_branch(branch_id,stock)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'marketplace_variant_options' => "CREATE TABLE IF NOT EXISTS marketplace_variant_options (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, product_id BIGINT NOT NULL, name VARCHAR(100) NOT NULL, sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mp_variant_option(product_id,name), INDEX idx_mp_variant_option_product(product_id,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'marketplace_variant_option_values' => "CREATE TABLE IF NOT EXISTS marketplace_variant_option_values (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, option_id BIGINT NOT NULL, value VARCHAR(120) NOT NULL, color_hex VARCHAR(16) NULL,
            sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mp_variant_option_value(option_id,value), INDEX idx_mp_variant_option_values(option_id,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'marketplace_variant_value_map' => "CREATE TABLE IF NOT EXISTS marketplace_variant_value_map (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, variant_id BIGINT NOT NULL, option_value_id BIGINT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mp_variant_value_map(variant_id,option_value_id), INDEX idx_mp_variant_value_variant(variant_id),
            INDEX idx_mp_variant_value_value(option_value_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'marketplace_store_courier_rates' => "CREATE TABLE IF NOT EXISTS marketplace_store_courier_rates (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,destination_id BIGINT NULL,province_id BIGINT NULL,city_id BIGINT NULL,district_id BIGINT NULL,subdistrict_id BIGINT NULL,
            province VARCHAR(120) NULL,city VARCHAR(120) NULL,district VARCHAR(120) NULL,subdistrict VARCHAR(120) NOT NULL,postal_code VARCHAR(20) NULL,
            fee DECIMAL(15,2) NOT NULL DEFAULT 0,eta VARCHAR(80) NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mp_store_courier_destination(destination_id),INDEX idx_mp_store_courier_subdistrict(subdistrict),INDEX idx_mp_store_courier_active(is_active,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function marketplace_doctor_count(PDO $db, string $table): ?int
{
    try { return (int)$db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn(); }
    catch (Throwable $e) { return null; }
}

function marketplace_doctor_status(PDO $db): array
{
    $specs = marketplace_doctor_specs();
    $rows = [];
    foreach ($specs as $table=>$spec) {
        $exists = marketplace_table_exists($db, $table);
        $have = $exists ? marketplace_doctor_table_columns($db,$table) : [];
        $missing = [];
        foreach (array_keys($spec['columns']) as $col) if (!isset($have[$col])) $missing[] = $col;
        $rows[$table] = [
            'table'=>$table, 'label'=>$spec['label'], 'exists'=>$exists, 'missing_columns'=>$missing,
            'ok'=>$exists && !$missing, 'count'=>$exists ? marketplace_doctor_count($db,$table) : null,
        ];
    }
    $core = [];
    foreach (['products','branches','settings','transactions','transaction_items','purchases','purchase_items','warehouses','warehouse_stocks','warehouse_variant_stocks','branch_stock_requests','branch_stock_request_items','online_orders','online_order_items','marketplace_vouchers','marketplace_reviews','customer_notifications','marketplace_couriers','marketplace_courier_assignments','marketplace_store_courier_rates','stock_opnames','stock_opname_items'] as $t) {
        $core[$t] = ['exists'=>marketplace_table_exists($db,$t),'count'=>marketplace_table_exists($db,$t)?marketplace_doctor_count($db,$t):null];
    }
    return ['database'=>marketplace_doctor_database_name($db),'self_test'=>marketplace_doctor_connection_self_test($db),'product'=>$rows,'core'=>$core];
}

function marketplace_doctor_add_missing_columns(PDO $db, string $table, array $columns, array &$log): void
{
    if (!marketplace_table_exists($db,$table)) return;
    $have = marketplace_doctor_table_columns($db,$table);
    foreach ($columns as $name=>$def) {
        if (isset($have[$name])) continue;
        try {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$def}");
            $log[] = "Tambah kolom {$table}.{$name}: OK";
        } catch (Throwable $e) {
            $log[] = "Tambah kolom {$table}.{$name}: GAGAL - ".$e->getMessage();
        }
    }
}

function marketplace_doctor_repair(PDO $db): array
{
    $log = [];
    try { marketplace_install($db); $log[]='Installer marketplace utama dijalankan.'; }
    catch (Throwable $e) { $log[]='Installer marketplace utama gagal: '.$e->getMessage(); }

    foreach (marketplace_doctor_create_sql() as $table=>$sql) {
        try { $db->exec($sql); $log[]="Tabel {$table}: OK"; }
        catch (Throwable $e) { $log[]="Tabel {$table}: GAGAL - ".$e->getMessage(); }
    }
    $specs = marketplace_doctor_specs();
    foreach ($specs as $table=>$spec) marketplace_doctor_add_missing_columns($db,$table,$spec['columns'],$log);

    // Required indexes. Index creation is intentionally isolated so one old/bad
    // index never prevents the rest of the repair.
    $indexes = [
        ['marketplace_product_meta','PRIMARY','PRIMARY KEY (`product_id`)'],
        ['marketplace_brands','uniq_marketplace_brand_slug','UNIQUE INDEX uniq_marketplace_brand_slug (`slug`)'],
        ['marketplace_brands','idx_marketplace_brand_active','INDEX idx_marketplace_brand_active (`is_active`,`sort_order`)'],
        ['marketplace_product_images','idx_mp_images_product','INDEX idx_mp_images_product (`product_id`,`sort_order`)'],
        ['marketplace_product_variants','idx_mp_variants_product','INDEX idx_mp_variants_product (`product_id`,`is_active`,`sort_order`)'],
        ['marketplace_product_variant_stocks','uniq_mp_variant_branch','UNIQUE INDEX uniq_mp_variant_branch (`variant_id`,`branch_id`)'],
        ['marketplace_variant_options','uniq_mp_variant_option','UNIQUE INDEX uniq_mp_variant_option (`product_id`,`name`)'],
        ['marketplace_variant_option_values','uniq_mp_variant_option_value','UNIQUE INDEX uniq_mp_variant_option_value (`option_id`,`value`)'],
        ['marketplace_variant_value_map','uniq_mp_variant_value_map','UNIQUE INDEX uniq_mp_variant_value_map (`variant_id`,`option_value_id`)'],
    ];
    foreach ($indexes as [$table,$name,$definition]) {
        if (!marketplace_table_exists($db,$table) || marketplace_doctor_index_exists($db,$table,$name)) continue;
        try { $db->exec("ALTER TABLE `{$table}` ADD {$definition}"); $log[]="Index {$table}.{$name}: OK"; }
        catch (Throwable $e) { $log[]="Index {$table}.{$name}: dilewati - ".$e->getMessage(); }
    }

    // Synchronize old product columns into the compatibility metadata table.
    try {
        if (marketplace_table_exists($db,'marketplace_product_meta') && marketplace_table_exists($db,'products')) {
            $hasBrand = marketplace_column_exists($db,'products','brand_id');
            $hasWeight = marketplace_column_exists($db,'products','weight_gram');
            if ($hasBrand || $hasWeight) {
                $brandExpr = $hasBrand ? 'brand_id' : 'NULL';
                $weightExpr = $hasWeight ? 'weight_gram' : 'NULL';
                $db->exec("INSERT INTO marketplace_product_meta(product_id,brand_id,weight_gram,created_at)
                    SELECT id,{$brandExpr},{$weightExpr},NOW() FROM products
                    ON DUPLICATE KEY UPDATE
                    brand_id=COALESCE(marketplace_product_meta.brand_id,VALUES(brand_id)),
                    weight_gram=COALESCE(marketplace_product_meta.weight_gram,VALUES(weight_gram)),updated_at=NOW()");
                $log[]='Sinkron metadata produk lama: OK';
            }
        }
    } catch (Throwable $e) { $log[]='Sinkron metadata produk lama: dilewati - '.$e->getMessage(); }

    return $log;
}
