<?php
/**
 * Marketplace V15 professional operations layer.
 * Additive only: dashboard, homepage/banner, brand, variants/gallery, flash sale,
 * shipping vouchers, point redemption, fulfillment/picking, stock alert and logs.
 */

function marketplace_v15_install(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    // V15.7: do not force ALTER TABLE on shared hosting when the compatibility
    // metadata table is available. This prevents schema mismatch from breaking POS.
    $metaStorageReady = marketplace_v15_product_meta_install($db);
    if (!$metaStorageReady) {
        try {
            if (marketplace_table_exists($db,'products') && !marketplace_column_exists($db,'products','brand_id')) {
                $db->exec("ALTER TABLE products ADD COLUMN brand_id BIGINT NULL AFTER category_id");
            }
        } catch(Throwable $e){}
        try {
            if (marketplace_table_exists($db,'products') && marketplace_column_exists($db,'products','brand_id')) {
                $db->exec("ALTER TABLE products ADD INDEX idx_products_brand (brand_id)");
            }
        } catch(Throwable $e){}
    }

    try { $db->exec("CREATE TABLE IF NOT EXISTS marketplace_brands (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(160) NOT NULL,slug VARCHAR(180) NOT NULL,logo VARCHAR(255) NULL,description TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,sort_order INT NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uniq_marketplace_brand_slug(slug),INDEX idx_marketplace_brand_active(is_active,sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}


    try { $db->exec("CREATE TABLE IF NOT EXISTS marketplace_product_images (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,product_id BIGINT NOT NULL,image_path VARCHAR(255) NOT NULL,is_primary TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_mp_images_product(product_id,sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}

    try { $db->exec("CREATE TABLE IF NOT EXISTS marketplace_product_variants (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,product_id BIGINT NOT NULL,name VARCHAR(160) NOT NULL,sku VARCHAR(120) NULL,barcode VARCHAR(120) NULL,
        selling_price DECIMAL(15,2) NOT NULL DEFAULT 0,purchase_price DECIMAL(15,2) NOT NULL DEFAULT 0,weight_gram INT NOT NULL DEFAULT 100,
        is_active TINYINT(1) NOT NULL DEFAULT 1,sort_order INT NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,INDEX idx_mp_variants_product(product_id,is_active,sort_order),UNIQUE KEY uniq_mp_variant_sku(sku)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}

    try { $db->exec("CREATE TABLE IF NOT EXISTS marketplace_product_variant_stocks (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,variant_id BIGINT NOT NULL,branch_id BIGINT NOT NULL,stock INT NOT NULL DEFAULT 0,minimum_stock INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_mp_variant_branch(variant_id,branch_id),INDEX idx_mp_variant_stock_branch(branch_id,stock)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}

    // V15.9 structured variant attributes. A marketplace variant remains linked to
    // the same POS product; these tables only describe choices such as Color,
    // Flavor, Size, Nicotine, Resistance/Ohm, Capacity, etc. No duplicate product.
    try { $db->exec("CREATE TABLE IF NOT EXISTS marketplace_variant_options (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,product_id BIGINT NOT NULL,name VARCHAR(100) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_mp_variant_option(product_id,name),INDEX idx_mp_variant_option_product(product_id,sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}
    try { $db->exec("CREATE TABLE IF NOT EXISTS marketplace_variant_option_values (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,option_id BIGINT NOT NULL,value VARCHAR(120) NOT NULL,color_hex VARCHAR(16) NULL,
        sort_order INT NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_mp_variant_option_value(option_id,value),INDEX idx_mp_variant_option_values(option_id,sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}
    try { $db->exec("CREATE TABLE IF NOT EXISTS marketplace_variant_value_map (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,variant_id BIGINT NOT NULL,option_value_id BIGINT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_mp_variant_value_map(variant_id,option_value_id),INDEX idx_mp_variant_value_variant(variant_id),INDEX idx_mp_variant_value_value(option_value_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}

    try { $db->exec("CREATE TABLE IF NOT EXISTS marketplace_banners (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180) NOT NULL,subtitle VARCHAR(255) NULL,image_desktop VARCHAR(255) NOT NULL,image_mobile VARCHAR(255) NULL,
        link_url VARCHAR(255) NULL,position VARCHAR(40) NOT NULL DEFAULT 'hero',is_active TINYINT(1) NOT NULL DEFAULT 1,sort_order INT NOT NULL DEFAULT 0,
        starts_at DATETIME NULL,ends_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_mp_banner_active(position,is_active,sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}

    try { $db->exec("CREATE TABLE IF NOT EXISTS marketplace_flash_sales (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(180) NOT NULL,starts_at DATETIME NOT NULL,ends_at DATETIME NOT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,INDEX idx_mp_flash_active(is_active,starts_at,ends_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}
    try { $db->exec("CREATE TABLE IF NOT EXISTS marketplace_flash_sale_items (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,flash_sale_id BIGINT NOT NULL,product_id BIGINT NOT NULL,variant_id BIGINT NULL,sale_price DECIMAL(15,2) NOT NULL DEFAULT 0,
        stock_limit INT NOT NULL DEFAULT 0,sold_count INT NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_mp_flash_item(flash_sale_id,product_id,variant_id),INDEX idx_mp_flash_product(product_id,variant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}

    try { $db->exec("CREATE TABLE IF NOT EXISTS marketplace_activity_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT NULL,action VARCHAR(100) NOT NULL,entity_type VARCHAR(80) NULL,entity_id BIGINT NULL,description TEXT NULL,
        ip_address VARCHAR(64) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_mp_activity_created(created_at),INDEX idx_mp_activity_entity(entity_type,entity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}
    try { $db->exec("CREATE TABLE IF NOT EXISTS marketplace_integration_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,provider VARCHAR(60) NOT NULL,event VARCHAR(100) NULL,direction VARCHAR(20) NOT NULL DEFAULT 'out',http_status INT NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,request_excerpt MEDIUMTEXT NULL,response_excerpt MEDIUMTEXT NULL,error_message TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mp_integration_provider(provider,created_at),INDEX idx_mp_integration_success(success,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}
    try { $db->exec("CREATE TABLE IF NOT EXISTS marketplace_cron_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,job_name VARCHAR(100) NOT NULL,status VARCHAR(30) NOT NULL,processed INT NOT NULL DEFAULT 0,duration_ms INT NOT NULL DEFAULT 0,
        error_message TEXT NULL,started_at DATETIME NOT NULL,finished_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_mp_cron_job(job_name,started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Throwable $e){}

    $orderCols = [
        'shipping_discount' => "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER shipping_cost",
        'points_redeemed' => "INT NOT NULL DEFAULT 0 AFTER points_earned",
        'points_discount' => "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER points_redeemed",
        'points_redeemed_at' => "DATETIME NULL AFTER points_discount",
        'points_refunded_at' => "DATETIME NULL AFTER points_redeemed_at",
        'fulfillment_status' => "VARCHAR(40) NOT NULL DEFAULT 'waiting_payment' AFTER order_status",
        'picked_at' => "DATETIME NULL AFTER fulfillment_status",
        'packed_at' => "DATETIME NULL AFTER picked_at",
        'ready_ship_at' => "DATETIME NULL AFTER packed_at",
        'packing_proof' => "VARCHAR(255) NULL AFTER ready_ship_at",
    ];
    foreach($orderCols as $c=>$def){ try{ if(!marketplace_column_exists($db,'online_orders',$c)) $db->exec("ALTER TABLE online_orders ADD COLUMN `{$c}` {$def}"); }catch(Throwable $e){} }
    $itemCols = [
        'variant_id' => "BIGINT NULL AFTER product_id",
        'variant_name' => "VARCHAR(160) NULL AFTER product_name",
        'variant_sku' => "VARCHAR(120) NULL AFTER variant_name",
        'flash_sale_item_id' => "BIGINT NULL AFTER variant_sku",
        'picked_qty' => "INT NOT NULL DEFAULT 0 AFTER qty",
        'packed_qty' => "INT NOT NULL DEFAULT 0 AFTER picked_qty",
    ];
    foreach($itemCols as $c=>$def){ try{ if(!marketplace_column_exists($db,'online_order_items',$c)) $db->exec("ALTER TABLE online_order_items ADD COLUMN `{$c}` {$def}"); }catch(Throwable $e){} }
    try { if(!marketplace_column_exists($db,'marketplace_vouchers','benefit_type')) $db->exec("ALTER TABLE marketplace_vouchers ADD COLUMN benefit_type VARCHAR(30) NOT NULL DEFAULT 'order' AFTER name"); } catch(Throwable $e){}
    try { if(!marketplace_column_exists($db,'marketplace_vouchers','courier_codes')) $db->exec("ALTER TABLE marketplace_vouchers ADD COLUMN courier_codes TEXT NULL AFTER max_discount"); } catch(Throwable $e){}

    // Defaults are intentionally separated from POS settings where appropriate.
    $defaults = [
        'marketplace_home_show_flash'=>'on','marketplace_home_show_brands'=>'on','marketplace_home_show_latest'=>'on',
        'marketplace_home_show_banners'=>'on','marketplace_points_redeem_enabled'=>'on','marketplace_stock_alert_threshold'=>'5',
        'marketplace_auto_request_recommendation'=>'off'
    ];
    foreach($defaults as $k=>$v){ try{ $st=$db->prepare("INSERT IGNORE INTO settings(setting_key,setting_value) VALUES(?,?)");$st->execute([$k,$v]); }catch(Throwable $e){} }
}


/**
 * Marketplace product metadata compatibility layer (V15.7).
 *
 * The legacy POS products table is intentionally treated as immutable here. If
 * brand_id / weight_gram exist we keep using them for backward compatibility,
 * but marketplace_product_meta is the guaranteed fallback used on hosting where
 * ALTER TABLE is restricted or where the subdomain database schema is older.
 */
function marketplace_v15_product_meta_install(PDO $db): bool
{
    static $state = [];
    $key = spl_object_id($db);
    if (array_key_exists($key, $state)) return $state[$key];
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS marketplace_product_meta (
            product_id INT NOT NULL PRIMARY KEY,
            brand_id BIGINT NULL,
            weight_gram INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mp_product_meta_brand (brand_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $state[$key] = marketplace_table_exists($db, 'marketplace_product_meta');
    } catch (Throwable $e) {
        $state[$key] = false;
    }
    return $state[$key];
}

function marketplace_v15_product_meta(PDO $db, int $productId): array
{
    $meta = ['brand_id' => 0, 'weight_gram' => 0];
    if ($productId <= 0) return $meta;

    // Prefer legacy columns when they exist, but never require them.
    try {
        $cols = [];
        if (marketplace_column_exists($db, 'products', 'brand_id')) $cols[] = 'brand_id';
        if (marketplace_column_exists($db, 'products', 'weight_gram')) $cols[] = 'weight_gram';
        if ($cols) {
            $s = $db->prepare('SELECT '.implode(',', $cols).' FROM products WHERE id=? LIMIT 1');
            $s->execute([$productId]);
            $r = $s->fetch() ?: [];
            if (array_key_exists('brand_id', $r) && $r['brand_id'] !== null) $meta['brand_id'] = (int)$r['brand_id'];
            if (array_key_exists('weight_gram', $r) && (int)$r['weight_gram'] > 0) $meta['weight_gram'] = (int)$r['weight_gram'];
        }
    } catch (Throwable $e) {}

    if (marketplace_v15_product_meta_install($db)) {
        try {
            $s = $db->prepare('SELECT brand_id,weight_gram FROM marketplace_product_meta WHERE product_id=? LIMIT 1');
            $s->execute([$productId]);
            $r = $s->fetch();
            if ($r) {
                if ($meta['brand_id'] <= 0 && $r['brand_id'] !== null) $meta['brand_id'] = (int)$r['brand_id'];
                if ($meta['weight_gram'] <= 0 && (int)$r['weight_gram'] > 0) $meta['weight_gram'] = (int)$r['weight_gram'];
            }
        } catch (Throwable $e) {}
    }

    // Last-resort settings fallback for ultra-restricted hosting accounts.
    if ($meta['brand_id'] <= 0) $meta['brand_id'] = max(0, (int)setting('marketplace_product_'.$productId.'_brand_id', '0'));
    if ($meta['weight_gram'] <= 0) $meta['weight_gram'] = max(1, (int)setting('marketplace_product_'.$productId.'_weight_gram', (string)marketplace_default_weight()));
    return $meta;
}

function marketplace_v15_product_brand_id(PDO $db, int $productId): int
{
    return (int)(marketplace_v15_product_meta($db, $productId)['brand_id'] ?? 0);
}

function marketplace_v15_product_weight(PDO $db, int $productId, ?int $fallback = null): int
{
    $fallback = max(1, $fallback ?: marketplace_default_weight());
    $v = (int)(marketplace_v15_product_meta($db, $productId)['weight_gram'] ?? 0);
    return $v > 0 ? $v : $fallback;
}

function marketplace_v15_set_product_brand(PDO $db, int $productId, int $brandId): void
{
    $brandId = max(0, $brandId);
    $saved = false;
    if (marketplace_v15_product_meta_install($db)) {
        try {
            $s = $db->prepare("INSERT INTO marketplace_product_meta(product_id,brand_id,created_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE brand_id=VALUES(brand_id),updated_at=NOW()");
            $s->execute([$productId, $brandId > 0 ? $brandId : null]);
            $saved = true;
        } catch (Throwable $e) {}
    }
    try {
        if (marketplace_column_exists($db, 'products', 'brand_id')) {
            $db->prepare('UPDATE products SET brand_id=? WHERE id=?')->execute([$brandId > 0 ? $brandId : null, $productId]);
            $saved = true;
        }
    } catch (Throwable $e) {}
    try { marketplace_setting_set($db, 'marketplace_product_'.$productId.'_brand_id', (string)$brandId); $saved = true; } catch (Throwable $e) {}
    if (!$saved) throw new RuntimeException('Brand belum dapat disimpan. Hak akses database tidak mengizinkan penyimpanan metadata marketplace.');
}

function marketplace_v15_set_product_weight(PDO $db, int $productId, int $weightGram): void
{
    $weightGram = max(1, min(50000, $weightGram));
    $saved = false;
    if (marketplace_v15_product_meta_install($db)) {
        try {
            $s = $db->prepare("INSERT INTO marketplace_product_meta(product_id,weight_gram,created_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE weight_gram=VALUES(weight_gram),updated_at=NOW()");
            $s->execute([$productId, $weightGram]);
            $saved = true;
        } catch (Throwable $e) {}
    }
    try {
        if (marketplace_column_exists($db, 'products', 'weight_gram')) {
            $db->prepare('UPDATE products SET weight_gram=? WHERE id=?')->execute([$weightGram, $productId]);
            $saved = true;
        }
    } catch (Throwable $e) {}
    try { marketplace_setting_set($db, 'marketplace_product_'.$productId.'_weight_gram', (string)$weightGram); $saved = true; } catch (Throwable $e) {}
    if (!$saved) throw new RuntimeException('Berat marketplace belum dapat disimpan. Hak akses database tidak mengizinkan penyimpanan metadata marketplace.');
}

function marketplace_v15_schema_status(PDO $db): array
{
    return [
        'products_brand_id' => marketplace_column_exists($db, 'products', 'brand_id'),
        'products_weight_gram' => marketplace_column_exists($db, 'products', 'weight_gram'),
        'product_meta' => marketplace_v15_product_meta_install($db),
        'brands' => marketplace_table_exists($db, 'marketplace_brands'),
        'images' => marketplace_table_exists($db, 'marketplace_product_images'),
        'variants' => marketplace_table_exists($db, 'marketplace_product_variants'),
        'variant_options' => marketplace_table_exists($db, 'marketplace_variant_options') && marketplace_table_exists($db, 'marketplace_variant_option_values') && marketplace_table_exists($db, 'marketplace_variant_value_map'),
    ];
}

function marketplace_v15_log(PDO $db,string $action,string $entityType='',?int $entityId=null,string $description=''):void{
    try{ marketplace_v15_install($db);$s=$db->prepare("INSERT INTO marketplace_activity_logs(user_id,action,entity_type,entity_id,description,ip_address,created_at) VALUES(?,?,?,?,?,?,NOW())");$s->execute([class_exists('Auth')?Auth::id():null,$action,$entityType?:null,$entityId,$description,$_SERVER['REMOTE_ADDR']??null]); }catch(Throwable $e){}
}
function marketplace_v15_integration_log(PDO $db,string $provider,string $event,bool $success,?int $status=null,string $req='',string $res='',string $err=''):void{
    try{ marketplace_v15_install($db);$trim=fn($v)=>mb_substr($v,0,4000);$s=$db->prepare("INSERT INTO marketplace_integration_logs(provider,event,direction,http_status,success,request_excerpt,response_excerpt,error_message,created_at) VALUES(?,?,'out',?,?,?,?,?,NOW())");$s->execute([$provider,$event,$status,$success?1:0,$trim($req),$trim($res),$trim($err)]); }catch(Throwable $e){}
}
function marketplace_v15_brand(PDO $db,int $id):?array{
    try{ marketplace_v15_install($db); if(!marketplace_table_exists($db,'marketplace_brands')) return null; $s=$db->prepare("SELECT * FROM marketplace_brands WHERE id=? LIMIT 1");$s->execute([$id]);return $s->fetch()?:null; }catch(Throwable $e){ return null; }
}
function marketplace_v15_brands(PDO $db,bool $activeOnly=true):array{
    try{
        marketplace_v15_install($db);
        if(!marketplace_table_exists($db,'marketplace_brands')) return [];
        $cols=marketplace_doctor_table_columns($db,'marketplace_brands');
        $where=$activeOnly&&isset($cols['is_active'])?' WHERE is_active=1':'';
        $order=[];if(isset($cols['sort_order']))$order[]='sort_order ASC';if(isset($cols['name']))$order[]='name ASC';$order[]='id ASC';
        return $db->query('SELECT * FROM marketplace_brands'.$where.' ORDER BY '.implode(',',$order))->fetchAll()?:[];
    }catch(Throwable $e){ return []; }
}
function marketplace_v15_product_images(PDO $db,int $productId):array{
    try{ marketplace_v15_install($db); if(!marketplace_table_exists($db,'marketplace_product_images')) return []; $s=$db->prepare("SELECT * FROM marketplace_product_images WHERE product_id=? ORDER BY is_primary DESC,sort_order ASC,id ASC");$s->execute([$productId]);return $s->fetchAll()?:[]; }catch(Throwable $e){ return []; }
}
function marketplace_v159_variant_options(PDO $db,int $productId,bool $activeOnly=false):array{
    try{
        marketplace_v15_install($db);
        if(!marketplace_table_exists($db,'marketplace_variant_options')) return [];
        $optCols=marketplace_doctor_table_columns($db,'marketplace_variant_options');
        $where=' WHERE product_id=?'.($activeOnly&&isset($optCols['is_active'])?' AND is_active=1':'');
        $order=(isset($optCols['sort_order'])?'sort_order ASC,':'').'id ASC';
        $s=$db->prepare('SELECT * FROM marketplace_variant_options'.$where.' ORDER BY '.$order);$s->execute([$productId]);$rows=$s->fetchAll()?:[];
        if(!marketplace_table_exists($db,'marketplace_variant_option_values')){foreach($rows as &$r)$r['values']=[];unset($r);return $rows;}
        $valCols=marketplace_doctor_table_columns($db,'marketplace_variant_option_values');
        $valWhere=' WHERE option_id=?'.($activeOnly&&isset($valCols['is_active'])?' AND is_active=1':'');
        $valOrder=(isset($valCols['sort_order'])?'sort_order ASC,':'').'id ASC';
        $vs=$db->prepare('SELECT * FROM marketplace_variant_option_values'.$valWhere.' ORDER BY '.$valOrder);
        foreach($rows as &$r){try{$vs->execute([(int)$r['id']]);$r['values']=$vs->fetchAll()?:[];}catch(Throwable $e){$r['values']=[];}}unset($r);
        return $rows;
    }catch(Throwable $e){return [];}
}
function marketplace_v159_variant_attributes(PDO $db,int $variantId):array{
    try{
        marketplace_v15_install($db);
        if(!marketplace_table_exists($db,'marketplace_variant_value_map')) return [];
        $s=$db->prepare("SELECT o.id option_id,o.name option_name,v.id value_id,v.value,v.color_hex FROM marketplace_variant_value_map m JOIN marketplace_variant_option_values v ON v.id=m.option_value_id JOIN marketplace_variant_options o ON o.id=v.option_id WHERE m.variant_id=? ORDER BY o.sort_order ASC,o.id ASC,v.sort_order ASC,v.id ASC");
        $s->execute([$variantId]);return $s->fetchAll()?:[];
    }catch(Throwable $e){return [];}
}
function marketplace_v159_variant_label(PDO $db,int $variantId,string $fallback=''):string{
    $attrs=marketplace_v159_variant_attributes($db,$variantId);$parts=[];foreach($attrs as $a){$v=trim((string)($a['value']??''));if($v!=='')$parts[]=$v;}return $parts?implode(' / ',$parts):$fallback;
}
function marketplace_v159_variant_selected_values(PDO $db,int $variantId):array{
    $out=[];foreach(marketplace_v159_variant_attributes($db,$variantId) as $a)$out[(int)$a['option_id']]=(int)$a['value_id'];return $out;
}
function marketplace_v159_save_variant_values(PDO $db,int $variantId,int $productId,array $valueIds):string{
    if(!marketplace_table_exists($db,'marketplace_variant_value_map')){marketplace_doctor_repair($db);}
    if(!marketplace_table_exists($db,'marketplace_variant_value_map')) throw new RuntimeException('Tabel mapping varian belum tersedia. Jalankan Cek & Upgrade Database Marketplace.');
    if(!marketplace_table_exists($db,'marketplace_variant_option_values')||!marketplace_table_exists($db,'marketplace_variant_options')) throw new RuntimeException('Tabel opsi varian belum lengkap. Jalankan Cek & Upgrade Database Marketplace.');
    $clean=[];
    foreach($valueIds as $optionId=>$valueId){$optionId=(int)$optionId;$valueId=(int)$valueId;if($optionId<=0||$valueId<=0)continue;$q=$db->prepare("SELECT v.id FROM marketplace_variant_option_values v JOIN marketplace_variant_options o ON o.id=v.option_id WHERE v.id=? AND o.id=? AND o.product_id=? LIMIT 1");$q->execute([$valueId,$optionId,$productId]);if($q->fetchColumn())$clean[$optionId]=$valueId;}
    $db->prepare("DELETE FROM marketplace_variant_value_map WHERE variant_id=?")->execute([$variantId]);
    if($clean){$i=$db->prepare("INSERT INTO marketplace_variant_value_map(variant_id,option_value_id,created_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE option_value_id=VALUES(option_value_id)");foreach($clean as $valueId)$i->execute([$variantId,$valueId]);}
    return marketplace_v159_variant_label($db,$variantId,'');
}
function marketplace_v15_pos_stock(PDO $db,int $productId,int $branchId,bool $lock=false):int{
    if($productId<=0||$branchId<=0)return 0;
    try{
        $sql="SELECT COALESCE(pbs.stock,CASE WHEN COALESCE(b.is_main,0)=1 THEN p.stock ELSE 0 END,0) stock FROM products p LEFT JOIN branches b ON b.id=? LEFT JOIN product_branch_stocks pbs ON pbs.product_id=p.id AND pbs.branch_id=? WHERE p.id=? LIMIT 1".($lock?" FOR UPDATE":"");
        $s=$db->prepare($sql);$s->execute([$branchId,$branchId,$productId]);$v=$s->fetchColumn();return max(0,(int)($v===false?0:$v));
    }catch(Throwable $e){
        try{$sql="SELECT COALESCE(stock,0) FROM products WHERE id=? LIMIT 1".($lock?" FOR UPDATE":"");$s=$db->prepare($sql);$s->execute([$productId]);return max(0,(int)$s->fetchColumn());}catch(Throwable $ignored){return 0;}
    }
}
function marketplace_v15_variants(PDO $db,int $productId,?int $branchId=null,bool $activeOnly=true):array{
    try{
        marketplace_v15_install($db);
        if(!marketplace_table_exists($db,'marketplace_product_variants')) return [];
        $branchId=$branchId?:marketplace_branch_id($db);
        $vcols=marketplace_doctor_table_columns($db,'marketplace_product_variants');
        $where=' WHERE v.product_id=?'.($activeOnly&&isset($vcols['is_active'])?' AND v.is_active=1':'');
        $order=(isset($vcols['sort_order'])?'v.sort_order ASC,':'').'v.id ASC';
        $rows=[];
        if(marketplace_table_exists($db,'marketplace_product_variant_stocks')){
            try{
                $scols=marketplace_doctor_table_columns($db,'marketplace_product_variant_stocks');
                $stockExpr=isset($scols['stock'])?'COALESCE(vs.stock,0)':'0';
                $minExpr=isset($scols['minimum_stock'])?'COALESCE(vs.minimum_stock,0)':'0';
                $sql="SELECT v.*,{$stockExpr} allocated_stock,{$minExpr} minimum_stock FROM marketplace_product_variants v LEFT JOIN marketplace_product_variant_stocks vs ON vs.variant_id=v.id AND vs.branch_id=?{$where} ORDER BY {$order}";
                $st=$db->prepare($sql);$st->execute([$branchId,$productId]);$rows=$st->fetchAll()?:[];
            }catch(Throwable $e){$rows=[];}
        }
        if(!$rows){
            $st=$db->prepare("SELECT v.*,0 allocated_stock,0 minimum_stock FROM marketplace_product_variants v{$where} ORDER BY {$order}");$st->execute([$productId]);$rows=$st->fetchAll()?:[];
        }
        // V15.16: stok tiap varian mandiri per cabang. Stok induk POS disinkronkan dari jumlah varian.
        foreach($rows as &$v){
            $v['stock']=max(0,(int)($v['allocated_stock']??0));
            $v['stock_source']='variant';
            $v['attributes']=marketplace_v159_variant_attributes($db,(int)$v['id']);
            $v['display_name']=marketplace_v159_variant_label($db,(int)$v['id'],(string)$v['name']);
        }unset($v);
        return $rows;
    }catch(Throwable $e){ return []; }
}
function marketplace_v15_variant(PDO $db,int $variantId,int $branchId,bool $lock=false):?array{
    try{
        $s=$db->prepare("SELECT v.*,COALESCE(vs.stock,0) allocated_stock,COALESCE(vs.minimum_stock,0) minimum_stock FROM marketplace_product_variants v LEFT JOIN marketplace_product_variant_stocks vs ON vs.variant_id=v.id AND vs.branch_id=? WHERE v.id=? LIMIT 1");
        $s->execute([$branchId,$variantId]);$r=$s->fetch()?:null;
        if($r){
            if($lock){
                $lockStmt=$db->prepare("SELECT stock,minimum_stock FROM marketplace_product_variant_stocks WHERE variant_id=? AND branch_id=? FOR UPDATE");
                $lockStmt->execute([$variantId,$branchId]);
                $lockedRow=$lockStmt->fetch();
                $r['stock']=max(0,(int)($lockedRow['stock']??0));
                $r['minimum_stock']=max(0,(int)($lockedRow['minimum_stock']??0));
            } else {
                $r['stock']=max(0,(int)($r['allocated_stock']??0));
            }
            $r['stock_source']='variant';
            $r['attributes']=marketplace_v159_variant_attributes($db,(int)$r['id']);
            $r['display_name']=marketplace_v159_variant_label($db,(int)$r['id'],(string)$r['name']);
        }
        return $r;
    }catch(Throwable $e){return null;}
}
function marketplace_v15_active_flash_price(PDO $db,int $productId,?int $variantId=null):?array{
    try{
        marketplace_v15_install($db);
        if(!marketplace_table_exists($db,'marketplace_flash_sale_items')||!marketplace_table_exists($db,'marketplace_flash_sales')) return null;
        $sql="SELECT i.id flash_sale_item_id,i.sale_price,i.stock_limit,i.sold_count,f.id flash_sale_id,f.name,f.ends_at FROM marketplace_flash_sale_items i JOIN marketplace_flash_sales f ON f.id=i.flash_sale_id WHERE i.product_id=? AND ".($variantId!==null?"i.variant_id=?":"i.variant_id IS NULL")." AND f.is_active=1 AND f.starts_at<=NOW() AND f.ends_at>=NOW() AND (i.stock_limit=0 OR i.sold_count<i.stock_limit) ORDER BY f.id DESC LIMIT 1";$s=$db->prepare($sql);$s->execute($variantId!==null?[$productId,$variantId]:[$productId]);$r=$s->fetch();return $r?:null;
    }catch(Throwable $e){ return null; }
}
function marketplace_v15_effective_price(PDO $db,int $productId,float $normal,?int $variantId=null):array{
    $flash=marketplace_v15_active_flash_price($db,$productId,$variantId);$price=$flash?max(0,(float)$flash['sale_price']):max(0,$normal);return ['price'=>$price,'normal_price'=>max(0,$normal),'flash'=>$flash];
}
function marketplace_v15_banners(PDO $db,string $position='hero'):array{
    try{ marketplace_v15_install($db); if(!marketplace_table_exists($db,'marketplace_banners')) return []; $s=$db->prepare("SELECT * FROM marketplace_banners WHERE position=? AND is_active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) ORDER BY sort_order ASC,id DESC");$s->execute([$position]);return $s->fetchAll()?:[]; }catch(Throwable $e){ return []; }
}
function marketplace_v15_shipping_discount(array $voucher,float $shippingCost,string $courierCode):float{
    if(($voucher['benefit_type']??'order')!=='shipping') return 0.0;$allowed=array_filter(array_map('trim',explode(',',strtolower((string)($voucher['courier_codes']??'')))));if($allowed&&!in_array(strtolower($courierCode),$allowed,true)) return 0.0;$value=(float)($voucher['discount_value']??0);$disc=($voucher['discount_type']??'fixed')==='percent'?$shippingCost*($value/100):$value;if((float)($voucher['max_discount']??0)>0)$disc=min($disc,(float)$voucher['max_discount']);return max(0,min($shippingCost,$disc));
}
function marketplace_v15_points_preview(PDO $db,int $customerId,int $requested,float $maxDiscountBase,bool $lock=false):array{
    if($customerId<=0||setting('marketplace_points_redeem_enabled','on')!=='on'||$requested<=0)return ['points'=>0,'discount'=>0.0,'available'=>0];$sql="SELECT points FROM customers WHERE id=? LIMIT 1".($lock?" FOR UPDATE":"");$s=$db->prepare($sql);$s->execute([$customerId]);$available=max(0,(int)$s->fetchColumn());$min=max(1,(int)setting('member_point_min_redeem','1'));$value=max(1,(float)setting('member_point_value','100'));$points=min($requested,$available,(int)floor(max(0,$maxDiscountBase)/$value));if($points<$min)$points=0;return ['points'=>$points,'discount'=>$points*$value,'available'=>$available,'point_value'=>$value,'min'=>$min];
}
function marketplace_v15_redeem_points(PDO $db,int $customerId,int $requested,float $maxDiscountBase,string $orderNumber):array{
    $r=marketplace_v15_points_preview($db,$customerId,$requested,$maxDiscountBase,true);if($r['points']<=0)return $r;$db->prepare("UPDATE customers SET points=points-?,updated_at=NOW() WHERE id=? AND points>=?")->execute([$r['points'],$customerId,$r['points']]);if($db->query("SELECT ROW_COUNT()")->fetchColumn()==0)throw new RuntimeException('Poin member berubah. Silakan ulangi checkout.');$db->exec("CREATE TABLE IF NOT EXISTS customer_points (id BIGINT AUTO_INCREMENT PRIMARY KEY,customer_id BIGINT NOT NULL,transaction_id BIGINT NULL,type ENUM('in','out') NOT NULL DEFAULT 'in',points INT NOT NULL DEFAULT 0,description TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_customer_points_customer(customer_id),INDEX idx_customer_points_transaction(transaction_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");$db->prepare("INSERT INTO customer_points(customer_id,transaction_id,type,points,description,created_at) VALUES(?,NULL,'out',?,?,NOW())")->execute([$customerId,$r['points'],'Tukar poin marketplace '.$orderNumber.' senilai '.rupiah($r['discount'])]);return $r;
}
function marketplace_v15_refund_points(PDO $db,int $orderId):void{
    try{$s=$db->prepare("SELECT customer_id,order_number,points_redeemed,points_refunded_at FROM online_orders WHERE id=? FOR UPDATE");$s->execute([$orderId]);$o=$s->fetch();if(!$o||(int)$o['customer_id']<=0||(int)$o['points_redeemed']<=0||!empty($o['points_refunded_at']))return;$p=(int)$o['points_redeemed'];$db->prepare("UPDATE customers SET points=points+?,updated_at=NOW() WHERE id=?")->execute([$p,(int)$o['customer_id']]);$db->prepare("INSERT INTO customer_points(customer_id,transaction_id,type,points,description,created_at) VALUES(?,NULL,'in',?,?,NOW())")->execute([(int)$o['customer_id'],$p,'Pengembalian poin marketplace '.$o['order_number']]);$db->prepare("UPDATE online_orders SET points_refunded_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$orderId]);}catch(Throwable $e){}
}
function marketplace_v15_dashboard(PDO $db):array{
    marketplace_v15_install($db);$out=['today_orders'=>0,'today_revenue'=>0,'month_orders'=>0,'month_revenue'=>0,'pending'=>0,'processing'=>0,'profit_month'=>0,'new_customers'=>0,'active_complaints'=>0];
    try{$r=$db->query("SELECT COUNT(*) c,COALESCE(SUM(grand_total),0) v FROM online_orders WHERE payment_status='paid' AND DATE(COALESCE(paid_at,created_at))=CURDATE()")->fetch();$out['today_orders']=(int)$r['c'];$out['today_revenue']=(float)$r['v'];}catch(Throwable $e){}
    try{$r=$db->query("SELECT COUNT(*) c,COALESCE(SUM(grand_total),0) v FROM online_orders WHERE payment_status='paid' AND YEAR(COALESCE(paid_at,created_at))=YEAR(CURDATE()) AND MONTH(COALESCE(paid_at,created_at))=MONTH(CURDATE())")->fetch();$out['month_orders']=(int)$r['c'];$out['month_revenue']=(float)$r['v'];}catch(Throwable $e){}
    try{$out['pending']=(int)$db->query("SELECT COUNT(*) FROM online_orders WHERE payment_status='pending'")->fetchColumn();$out['processing']=(int)$db->query("SELECT COUNT(*) FROM online_orders WHERE payment_status='paid' AND order_status IN('paid','processing')")->fetchColumn();}catch(Throwable $e){}
    try{$out['profit_month']=(float)$db->query("SELECT COALESCE(SUM((i.unit_price-i.purchase_price)*i.qty),0) FROM online_order_items i JOIN online_orders o ON o.id=i.order_id WHERE o.payment_status='paid' AND YEAR(COALESCE(o.paid_at,o.created_at))=YEAR(CURDATE()) AND MONTH(COALESCE(o.paid_at,o.created_at))=MONTH(CURDATE())")->fetchColumn();}catch(Throwable $e){}
    try{$out['new_customers']=(int)$db->query("SELECT COUNT(*) FROM customers WHERE marketplace_registered_at IS NOT NULL AND YEAR(marketplace_registered_at)=YEAR(CURDATE()) AND MONTH(marketplace_registered_at)=MONTH(CURDATE())")->fetchColumn();}catch(Throwable $e){}
    try{if(marketplace_table_exists($db,'marketplace_service_cases'))$out['active_complaints']=(int)$db->query("SELECT COUNT(*) FROM marketplace_service_cases WHERE status NOT IN('resolved','closed','rejected')")->fetchColumn();}catch(Throwable $e){}
    return $out;
}
function marketplace_v15_sales_chart(PDO $db,int $days=30):array{
    $days=max(7,min(90,$days));$sql="SELECT DATE(COALESCE(paid_at,created_at)) d,COUNT(*) orders,COALESCE(SUM(grand_total),0) revenue FROM online_orders WHERE payment_status='paid' AND COALESCE(paid_at,created_at)>=DATE_SUB(CURDATE(),INTERVAL {$days} DAY) GROUP BY DATE(COALESCE(paid_at,created_at)) ORDER BY d ASC";return $db->query($sql)->fetchAll()?:[];
}
function marketplace_v15_stock_alerts(PDO $db):array{
    marketplace_v15_install($db);$branch=marketplace_branch_id($db);$threshold=max(0,(int)setting('marketplace_stock_alert_threshold','5'));$warehouseJoin=marketplace_table_exists($db,'warehouse_stocks')?"LEFT JOIN warehouse_stocks ws ON ws.product_id=p.id AND ws.warehouse_id=(SELECT id FROM warehouses WHERE is_main=1 ORDER BY id LIMIT 1)":"LEFT JOIN (SELECT NULL product_id,0 stock,0 purchase_price) ws ON 1=0";$sql="SELECT p.id,p.product_code,p.name,p.unit,p.minimum_stock,COALESCE(pbs.stock,CASE WHEN b.is_main=1 THEN p.stock ELSE 0 END,0) branch_stock,COALESCE(ws.stock,0) warehouse_stock,COALESCE(NULLIF(ws.purchase_price,0),p.purchase_price,0) unit_cost FROM products p JOIN branches b ON b.id=? LEFT JOIN product_branch_stocks pbs ON pbs.product_id=p.id AND pbs.branch_id=b.id {$warehouseJoin} WHERE p.is_active=1 AND COALESCE(pbs.stock,CASE WHEN b.is_main=1 THEN p.stock ELSE 0 END,0)<=GREATEST(COALESCE(NULLIF(p.minimum_stock,0),?),?) ORDER BY branch_stock ASC,p.name ASC LIMIT 300";$s=$db->prepare($sql);$s->execute([$branch,$threshold,$threshold]);return $s->fetchAll()?:[];
}
function marketplace_v15_create_stock_request(PDO $db,array $qtyByProduct,string $note='Rekomendasi stok Marketplace V15'):?string{
    if(!marketplace_table_exists($db,'branch_stock_requests')||!function_exists('warehouse_main_id')) return null;$branch=marketplace_branch_id($db);$wid=warehouse_main_id($db);$items=[];$total=0;foreach($qtyByProduct as $pid=>$qty){$pid=(int)$pid;$qty=max(0,(int)$qty);if($pid<=0||$qty<=0)continue;$s=$db->prepare("SELECT p.id,p.name,p.product_code,p.unit,COALESCE(ws.stock,0) warehouse_stock,COALESCE(NULLIF(ws.purchase_price,0),p.purchase_price,0) unit_cost FROM products p LEFT JOIN warehouse_stocks ws ON ws.product_id=p.id AND ws.warehouse_id=? WHERE p.id=? LIMIT 1");$s->execute([$wid,$pid]);$p=$s->fetch();if(!$p)continue;$qty=min($qty,(int)$p['warehouse_stock']);if($qty<=0)continue;$line=$qty*(float)$p['unit_cost'];$items[]=[$p,$qty,$line];$total+=$line;}if(!$items)return null;$num=function_exists('warehouse_request_number')?warehouse_request_number($db):('RST-MP-'.date('YmdHis'));
    $db->beginTransaction();try{$s=$db->prepare("INSERT INTO branch_stock_requests(request_number,branch_id,warehouse_id,requested_by,status,total_cost,note,created_at) VALUES(?,?,?,?, 'pending',?,?,NOW())");$s->execute([$num,$branch,$wid,class_exists('Auth')?Auth::id():null,$total,$note]);$rid=(int)$db->lastInsertId();$i=$db->prepare("INSERT INTO branch_stock_request_items(request_id,product_id,product_name,product_code,unit,qty_requested,qty_fulfilled,unit_cost,line_total,created_at) VALUES(?,?,?,?,?,?,0,?,?,NOW())");foreach($items as [$p,$qty,$line])$i->execute([$rid,$p['id'],$p['name'],$p['product_code'],$p['unit']?:'pcs',$qty,$p['unit_cost'],$line]);$db->commit();marketplace_v15_log($db,'stock_request_auto','branch_stock_request',$rid,'Request Stock dari Stock Alert Marketplace '.$num);return $num;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function marketplace_v15_build_invoice_pdf(PDO $db,array $order)
{
    require_once __DIR__ . '/../DataExchange/Pdf.php';
    $items=marketplace_order_items($db,(int)$order['id']);
    $pdf=new KpPdfDocument('P');$pdf->setTitle('Invoice '.$order['order_number']);$pdf->addPage();
    $pdf->text(32,36,(string)setting('marketplace_site_title',setting('store_name','Marketplace')),18,true);
    $pdf->text(32,58,'INVOICE MARKETPLACE',11,true);$pdf->text(360,36,(string)$order['order_number'],12,true,'right',200);
    $pdf->text(360,56,'Tanggal: '.date('d/m/Y H:i',strtotime((string)$order['created_at'])),8,false,'right',200);
    $pdf->line(32,72,563,72);
    $pdf->text(32,92,'Penerima',9,true);$pdf->text(32,110,(string)$order['recipient_name'],9,true);$pdf->text(32,126,(string)$order['recipient_phone'],8);$pdf->text(32,142,mb_substr((string)$order['address'],0,85),8);
    if((string)($order['courier_code']??'')==='pickup'){$bs=$db->prepare("SELECT name,address FROM branches WHERE id=? LIMIT 1");$bs->execute([(int)$order['branch_id']]);$br=$bs->fetch()?:[];$pdf->text(330,92,'Pengambilan di Toko',9,true);$pdf->text(330,110,(string)($br['name']??'Toko'),8,true);$pdf->text(330,126,mb_substr((string)($br['address']??$order['address']),0,55),7);$pdf->text(330,142,'Status: '.marketplace_store_pickup_status_label($order),8);}else{$pdf->text(330,92,'Pengiriman',9,true);$pdf->text(330,110,strtoupper((string)$order['courier_code']).' '.(string)$order['courier_service'],8,true);$pdf->text(330,126,'Resi: '.((string)$order['awb_number']?:'-'),8);$pdf->text(330,142,'Status: '.marketplace_order_status_label((string)$order['order_status']),8);}
    $rows=[];foreach($items as $i){$name=(string)$i['product_name'].(!empty($i['variant_name'])?' - '.$i['variant_name']:'');$rows[]=[mb_substr($name,0,42),(string)$i['qty'],rupiah((float)$i['unit_price']),rupiah((float)$i['line_total'])];}
    $pdf->table(['Produk','Qty','Harga','Subtotal'],$rows,[285,45,95,105],['x'=>32,'y'=>172,'header_height'=>24,'row_height'=>22,'font_size'=>8,'page_title'=>'Invoice '.$order['order_number']]);
    $y=min(700,205+(count($rows)*22));
    $pdf->text(320,$y,'Subtotal',8);$pdf->text(430,$y,rupiah((float)$order['subtotal']),8,true,'right',130);$y+=18;
    if((float)$order['discount']>0){$pdf->text(320,$y,'Voucher',8);$pdf->text(430,$y,'- '.rupiah((float)$order['discount']),8,true,'right',130);$y+=18;}
    if((float)($order['points_discount']??0)>0){$pdf->text(320,$y,'Poin ('.(int)$order['points_redeemed'].')',8);$pdf->text(430,$y,'- '.rupiah((float)$order['points_discount']),8,true,'right',130);$y+=18;}
    $pdf->text(320,$y,'Ongkir',8);$pdf->text(430,$y,rupiah((float)$order['shipping_cost']),8,true,'right',130);$y+=18;
    if((float)($order['shipping_discount']??0)>0){$pdf->text(320,$y,'Gratis Ongkir',8);$pdf->text(430,$y,'- '.rupiah((float)$order['shipping_discount']),8,true,'right',130);$y+=18;}
    $pdf->line(320,$y,563,$y);$y+=20;$pdf->text(320,$y,'TOTAL',10,true);$pdf->text(430,$y,rupiah((float)$order['grand_total']),11,true,'right',130);$y+=28;
    $method=(string)($order['payment_method']??'');
    $methodLabel=marketplace_pakasir_methods()[$method]??($method==='qris'?'QRIS':'Pembayaran');
    $pdf->text(32,$y,'Pembayaran: '.$methodLabel.' • '.marketplace_payment_status_label((string)$order['payment_status']),8,true);
    if($method==='qris'){
        $pdf->text(32,$y+18,'Instruksi: scan QRIS pada halaman pesanan.',7);
        $y+=18;
    }elseif(!empty($order['payment_va_number'])){
        $pdf->text(32,$y+18,'Nomor Virtual Account: '.(string)$order['payment_va_number'],9,true);
        $y+=18;
    }
    if((float)($order['payment_total']??0)>0){$pdf->text(32,$y+18,'Total pembayaran: '.rupiah((float)$order['payment_total']),8);$y+=18;}
    $pdf->text(32,$y+18,'Terima kasih telah berbelanja. Invoice ini dibuat otomatis oleh sistem marketplace.',7);
    return $pdf;
}
