<?php
/**
 * Marketplace V15.16
 * - Stok varian mandiri per cabang dan sinkron stok induk POS
 * - Foto tunggal per varian
 * - Tarif Kurir Toko per Kelurahan/Desa
 */

function marketplace_v16_table_exists(PDO $db, string $table): bool
{
    try {
        $s=$db->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
        $s->execute([$table]);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function marketplace_v16_column_exists(PDO $db, string $table, string $column): bool
{
    try {
        $s=$db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
        $s->execute([$table,$column]);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function marketplace_v16_index_exists(PDO $db, string $table, string $index): bool
{
    try {
        $s=$db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1");
        $s->execute([$table,$index]);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function marketplace_v16_install(PDO $db): void
{
    static $done=false;
    if($done) return;
    $done=true;

    try {
        if(marketplace_v16_table_exists($db,'marketplace_product_variants') && !marketplace_v16_column_exists($db,'marketplace_product_variants','image_path')) {
            $db->exec("ALTER TABLE marketplace_product_variants ADD COLUMN image_path VARCHAR(255) NULL AFTER weight_gram");
        }
    } catch(Throwable $e) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS marketplace_store_courier_rates (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            destination_id BIGINT NULL,
            province_id BIGINT NULL,
            city_id BIGINT NULL,
            district_id BIGINT NULL,
            subdistrict_id BIGINT NULL,
            province VARCHAR(120) NULL,
            city VARCHAR(120) NULL,
            district VARCHAR(120) NULL,
            subdistrict VARCHAR(120) NOT NULL,
            postal_code VARCHAR(20) NULL,
            fee DECIMAL(15,2) NOT NULL DEFAULT 0,
            eta VARCHAR(80) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mp_store_courier_destination(destination_id),
            INDEX idx_mp_store_courier_subdistrict(subdistrict),
            INDEX idx_mp_store_courier_active(is_active,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Throwable $e) {}

    // Transaksi POS dapat menyimpan varian yang dipilih kasir.
    try {
        if(marketplace_v16_table_exists($db,'transaction_items') && !marketplace_v16_column_exists($db,'transaction_items','variant_id')) $db->exec("ALTER TABLE transaction_items ADD COLUMN variant_id BIGINT NULL AFTER product_id");
        if(marketplace_v16_table_exists($db,'transaction_items') && !marketplace_v16_column_exists($db,'transaction_items','variant_name')) $db->exec("ALTER TABLE transaction_items ADD COLUMN variant_name VARCHAR(180) NULL AFTER product_name");
        if(marketplace_v16_table_exists($db,'transaction_items') && !marketplace_v16_column_exists($db,'transaction_items','variant_sku')) $db->exec("ALTER TABLE transaction_items ADD COLUMN variant_sku VARCHAR(120) NULL AFTER variant_name");
        if(marketplace_v16_table_exists($db,'transaction_items') && !marketplace_v16_index_exists($db,'transaction_items','idx_transaction_items_variant')) $db->exec("ALTER TABLE transaction_items ADD INDEX idx_transaction_items_variant(variant_id)");
    } catch(Throwable $e) {}

    // Stock-opname menjadi variant-aware. Semua ALTER dibungkus try agar aman pada schema lama.
    try {
        if(marketplace_v16_table_exists($db,'stock_opname_items') && !marketplace_v16_column_exists($db,'stock_opname_items','variant_id')) {
            $db->exec("ALTER TABLE stock_opname_items ADD COLUMN variant_id BIGINT NOT NULL DEFAULT 0 AFTER product_id");
        }
        if(marketplace_v16_table_exists($db,'stock_opname_items') && !marketplace_v16_column_exists($db,'stock_opname_items','variant_name')) {
            $db->exec("ALTER TABLE stock_opname_items ADD COLUMN variant_name VARCHAR(180) NULL AFTER product_name");
        }
        if(marketplace_v16_table_exists($db,'stock_opname_items') && marketplace_v16_index_exists($db,'stock_opname_items','uniq_opname_product')) {
            $db->exec("ALTER TABLE stock_opname_items DROP INDEX uniq_opname_product");
        }
        if(marketplace_v16_table_exists($db,'stock_opname_items') && !marketplace_v16_index_exists($db,'stock_opname_items','uniq_opname_product_variant')) {
            $db->exec("ALTER TABLE stock_opname_items ADD UNIQUE KEY uniq_opname_product_variant(opname_id,product_id,variant_id)");
        }
    } catch(Throwable $e) {}

    // Mode tarif per-kelurahan tersedia sebagai setting; tidak mengubah setting lama secara paksa.
    try {
        if(marketplace_v16_table_exists($db,'settings')) {
            $s=$db->prepare("INSERT IGNORE INTO settings(setting_key,setting_value) VALUES('marketplace_store_courier_rate_mode','flat')");
            $s->execute();
        }
    } catch(Throwable $e) {}
}

function marketplace_v16_variant_stock(PDO $db,int $variantId,int $branchId,bool $lock=false): int
{
    if($variantId<=0||$branchId<=0) return 0;
    marketplace_v16_install($db);
    try {
        $sql="SELECT COALESCE(stock,0) FROM marketplace_product_variant_stocks WHERE variant_id=? AND branch_id=? LIMIT 1".($lock?' FOR UPDATE':'');
        $s=$db->prepare($sql);$s->execute([$variantId,$branchId]);
        $v=$s->fetchColumn();
        return max(0,(int)($v===false?0:$v));
    } catch(Throwable $e) { return 0; }
}

function marketplace_v16_set_variant_stock(PDO $db,int $variantId,int $branchId,int $stock,int $minimumStock=0): void
{
    marketplace_v16_install($db);
    $stock=max(0,$stock);$minimumStock=max(0,$minimumStock);
    $s=$db->prepare("INSERT INTO marketplace_product_variant_stocks(variant_id,branch_id,stock,minimum_stock,created_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE stock=VALUES(stock),minimum_stock=VALUES(minimum_stock),updated_at=NOW()");
    $s->execute([$variantId,$branchId,$stock,$minimumStock]);
}

function marketplace_v16_product_has_variants(PDO $db,int $productId,bool $activeOnly=true): bool
{
    try {
        $sql="SELECT COUNT(*) FROM marketplace_product_variants WHERE product_id=?".($activeOnly?' AND is_active=1':'');
        $s=$db->prepare($sql);$s->execute([$productId]);return (int)$s->fetchColumn()>0;
    } catch(Throwable $e){ return false; }
}

function marketplace_v16_product_variant_stock_total(PDO $db,int $productId,int $branchId,bool $activeOnly=true): int
{
    marketplace_v16_install($db);
    try {
        $sql="SELECT COALESCE(SUM(vs.stock),0) FROM marketplace_product_variants v LEFT JOIN marketplace_product_variant_stocks vs ON vs.variant_id=v.id AND vs.branch_id=? WHERE v.product_id=?".($activeOnly?' AND v.is_active=1':'');
        $s=$db->prepare($sql);$s->execute([$branchId,$productId]);return max(0,(int)$s->fetchColumn());
    } catch(Throwable $e){ return 0; }
}

function marketplace_v16_sync_parent_stock(PDO $db,int $productId,int $branchId): int
{
    if($productId<=0||$branchId<=0) return 0;
    if(!marketplace_v16_product_has_variants($db,$productId,true)) {
        try { return marketplace_v15_pos_stock($db,$productId,$branchId,false); } catch(Throwable $e){ return 0; }
    }
    $total=marketplace_v16_product_variant_stock_total($db,$productId,$branchId,true);
    try {
        $min=0;
        $q=$db->prepare("SELECT COALESCE(MIN(NULLIF(vs.minimum_stock,0)),0) FROM marketplace_product_variants v LEFT JOIN marketplace_product_variant_stocks vs ON vs.variant_id=v.id AND vs.branch_id=? WHERE v.product_id=? AND v.is_active=1");
        $q->execute([$branchId,$productId]);$min=max(0,(int)$q->fetchColumn());
        $db->prepare("INSERT INTO product_branch_stocks(branch_id,product_id,stock,minimum_stock,created_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE stock=VALUES(stock),updated_at=NOW()")->execute([$branchId,$productId,$total,$min]);
        $m=$db->prepare("SELECT is_main FROM branches WHERE id=? LIMIT 1");$m->execute([$branchId]);
        if((int)$m->fetchColumn()===1) $db->prepare("UPDATE products SET stock=?,updated_at=NOW() WHERE id=?")->execute([$total,$productId]);
    } catch(Throwable $e) {}
    return $total;
}

function marketplace_v16_sync_all_variant_parents(PDO $db,?int $branchId=null): void
{
    marketplace_v16_install($db);
    try {
        $branchId=$branchId?:marketplace_branch_id($db);
        $ids=$db->query("SELECT DISTINCT product_id FROM marketplace_product_variants WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN)?:[];
        foreach($ids as $pid) marketplace_v16_sync_parent_stock($db,(int)$pid,$branchId);
    } catch(Throwable $e) {}
}

function marketplace_v16_norm(string $value): string
{
    $value=mb_strtoupper(trim($value),'UTF-8');
    $value=preg_replace('/[^\pL\pN]+/u',' ',$value)?:'';
    return trim(preg_replace('/\s+/u',' ',$value)?:'');
}

function marketplace_v16_store_courier_rate(PDO $db,int $destinationId,string $province,string $city,string $district,string $subdistrict): ?array
{
    marketplace_v16_install($db);
    try {
        if($destinationId>0){
            $s=$db->prepare("SELECT * FROM marketplace_store_courier_rates WHERE is_active=1 AND destination_id=? ORDER BY sort_order ASC,id DESC LIMIT 1");
            $s->execute([$destinationId]);$r=$s->fetch();if($r)return $r;
        }
        $wanted=[marketplace_v16_norm($province),marketplace_v16_norm($city),marketplace_v16_norm($district),marketplace_v16_norm($subdistrict)];
        if($wanted[3]==='') return null;
        $s=$db->query("SELECT * FROM marketplace_store_courier_rates WHERE is_active=1 ORDER BY sort_order ASC,id DESC");
        foreach($s->fetchAll()?:[] as $r){
            if(marketplace_v16_norm((string)$r['subdistrict'])!==$wanted[3]) continue;
            if(trim((string)$r['district'])!=='' && marketplace_v16_norm((string)$r['district'])!==$wanted[2]) continue;
            if(trim((string)$r['city'])!=='' && marketplace_v16_norm((string)$r['city'])!==$wanted[1]) continue;
            if(trim((string)$r['province'])!=='' && marketplace_v16_norm((string)$r['province'])!==$wanted[0]) continue;
            return $r;
        }
    } catch(Throwable $e) {}
    return null;
}

function marketplace_v16_store_courier_rate_rows(PDO $db): array
{
    marketplace_v16_install($db);
    try{return $db->query("SELECT * FROM marketplace_store_courier_rates ORDER BY sort_order ASC,province ASC,city ASC,district ASC,subdistrict ASC,id DESC")->fetchAll()?:[];}catch(Throwable $e){return [];}
}
