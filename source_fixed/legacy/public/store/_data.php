<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/Modules/Marketplace/Marketplace.php';

/**
 * Storefront data bridge.
 * Read-only against the existing POS tables so the website catalog always
 * follows the product, category, stock and branch data managed in POS.
 */
function storefront_db(): ?PDO
{
    try {
        $db = Database::connect();
        marketplace_install($db);
        return $db;
    } catch (Throwable $e) {
        return null;
    }
}

function storefront_table_exists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function storefront_column_exists(PDO $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function storefront_settings(): array
{
    return [
        'app_name' => (string) setting('app_name', 'Vapekong'),
        'marketplace_enabled' => (string) setting('marketplace_enabled', 'on'),
        'store_name' => (string) setting('store_name', 'Vapekong Store'),
        'store_phone' => (string) setting('store_phone', ''),
        'store_email' => (string) setting('store_email', ''),
        'store_address' => (string) setting('store_address', ''),
        'receipt_footer' => (string) setting('receipt_footer', 'Terima kasih sudah berbelanja.'),
        'marketplace_site_title' => (string) setting('marketplace_site_title', ''),
        'marketplace_meta_description' => (string) setting('marketplace_meta_description', ''),
        'marketplace_hero_badge' => (string) setting('marketplace_hero_badge', 'VAPE STORE TERPERCAYA'),
        'marketplace_hero_title' => (string) setting('marketplace_hero_title', 'Belanja Vape & Aksesoris Lebih Mudah'),
        'marketplace_hero_subtitle' => (string) setting('marketplace_hero_subtitle', 'Terintegrasi dengan POS, pesanan online, ongkir, poin member, dan checkout dalam satu pengalaman.'),
        'marketplace_footer_description' => (string) setting('marketplace_footer_description', 'Toko modern dengan produk original, harga transparan, stok terhubung POS, dan pengalaman belanja yang cepat.'),
        'marketplace_opening_hours' => (string) setting('marketplace_opening_hours', '09:00 - 21:00 WIB'),
        'marketplace_instagram' => (string) setting('marketplace_instagram', ''),
        'marketplace_tiktok' => (string) setting('marketplace_tiktok', ''),
        'marketplace_facebook' => (string) setting('marketplace_facebook', ''),
        'marketplace_whatsapp' => (string) setting('marketplace_whatsapp', ''),
        'marketplace_logo' => (string) setting('marketplace_logo', ''),
        'marketplace_favicon' => (string) setting('marketplace_favicon', ''),
        'marketplace_hero_image' => (string) setting('marketplace_hero_image', ''),
        'marketplace_promo_image' => (string) setting('marketplace_promo_image', ''),
    ];
}

function storefront_asset_url(string $name): string
{
    return legacy_base_url('assets/storefront/' . ltrim($name, '/'));
}

function storefront_public_file_exists(string $path): bool
{
    $path = trim($path);
    if ($path === '' || preg_match('~^https?://~i', $path)) return $path !== '';
    $relative = ltrim($path, '/');
    if (strpos($relative, 'public/') === 0) $relative = substr($relative, 7);
    return is_file(__DIR__ . '/../' . $relative);
}

function storefront_visual_url(?string $path, string $fallbackAsset): string
{
    $path = trim((string) $path);
    if ($path !== '') {
        if (preg_match('~^https?://~i', $path)) return $path;
        if (storefront_public_file_exists($path)) return legacy_base_url(ltrim(preg_replace('~^public/~', '', $path), '/'));
    }
    return storefront_asset_url($fallbackAsset);
}


function storefront_external_url(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') return '';
    if (preg_match('~^https?://~i', $value)) return $value;
    return 'https://' . ltrim($value, '/');
}

function storefront_whatsapp_url(array $store): string
{
    $configured = trim((string) ($store['marketplace_whatsapp'] ?? ''));
    if ($configured !== '') {
        if (preg_match('~^https?://~i', $configured)) return $configured;
        $digits = preg_replace('/\D+/', '', $configured);
    } else {
        $digits = preg_replace('/\D+/', '', (string) ($store['store_phone'] ?? ''));
    }
    if (!$digits) return '';
    if (str_starts_with($digits, '0')) $digits = '62' . substr($digits, 1);
    return 'https://wa.me/' . $digits;
}

function storefront_category_key(?string $categoryName): string
{
    $name = strtolower(trim((string) $categoryName));
    if ($name === '') return 'default';
    if (strpos($name, 'liquid') !== false || strpos($name, 'juice') !== false || strpos($name, 'salt') !== false) return 'liquid';
    if (strpos($name, 'device') !== false || strpos($name, 'pod') !== false || strpos($name, 'mod') !== false) return 'device';
    if (strpos($name, 'cartridge') !== false || strpos($name, 'coil') !== false) return 'cartridge';
    if (strpos($name, 'cotton') !== false || strpos($name, 'kapas') !== false) return 'cotton';
    if (strpos($name, 'access') !== false || strpos($name, 'aksesor') !== false) return 'accessories';
    return 'default';
}

function storefront_category_image(?string $categoryName): string
{
    return storefront_asset_url('category-' . storefront_category_key($categoryName) . '.svg');
}

function storefront_product_fallback(array $product): string
{
    return storefront_category_image($product['category_name'] ?? '');
}

function storefront_product_image(array $product): string
{
    $path = trim((string) ($product['image'] ?? ''));
    if ($path !== '') {
        if (preg_match('~^https?://~i', $path)) return $path;
        if (storefront_public_file_exists($path)) return legacy_base_url(ltrim(preg_replace('~^public/~', '', $path), '/'));
    }
    return storefront_product_fallback($product);
}

function storefront_categories(int $limit = 8): array
{
    $db = storefront_db();
    if (!$db) return [];
    try {
        $stmt = $db->prepare("\n            SELECT c.id, c.name, c.slug, c.icon, c.description, COUNT(p.id) product_count\n            FROM categories c\n            LEFT JOIN products p ON p.category_id = c.id AND p.is_active = 1\n            WHERE c.is_active = 1\n            GROUP BY c.id, c.name, c.slug, c.icon, c.description, c.sort_order\n            ORDER BY c.sort_order ASC, c.name ASC\n            LIMIT " . max(1, min(20, $limit))
        );
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function storefront_products(array $options = []): array
{
    $db = storefront_db();
    if (!$db) return [];

    $limit = max(1, min(100, (int) ($options['limit'] ?? 12)));
    $offset = max(0, (int) ($options['offset'] ?? 0));
    $category = trim((string) ($options['category'] ?? ''));
    $search = trim((string) ($options['search'] ?? ''));
    $sort = (string) ($options['sort'] ?? 'name');
    $brand = max(0, (int) ($options['brand'] ?? 0));
    $branchId = marketplace_branch_id($db);

    $hasBrandColumn = storefront_column_exists($db, 'products', 'brand_id');
    $hasBrandTable = storefront_table_exists($db, 'marketplace_brands');
    $hasProductMeta = marketplace_v15_product_meta_install($db);
    $hasVariants = storefront_table_exists($db, 'marketplace_product_variants');
    $hasVariantStocks = $hasVariants && storefront_table_exists($db, 'marketplace_product_variant_stocks');

    $where = ['p.is_active = 1'];
    // First placeholder belongs to branches.id. Variant aggregate adds one more
    // branch placeholder before the normal filter parameters.
    $params = [$branchId];

    if ($category !== '') {
        if (ctype_digit($category)) {
            $where[] = 'p.category_id = ?';
            $filterParams[] = (int) $category;
        } else {
            $where[] = 'c.slug = ?';
            $filterParams[] = $category;
        }
    }

    if (!isset($filterParams)) $filterParams = [];

    if ($search !== '') {
        $where[] = '(p.name LIKE ? OR p.product_code LIKE ? OR p.description LIKE ? OR c.name LIKE ?)';
        $q = '%' . $search . '%';
        array_push($filterParams, $q, $q, $q, $q);
    }

    // Brand filtering supports both the legacy products.brand_id column and the
    // V15.7 metadata table used by older/subdomain database schemas.
    if ($brand > 0) {
        if ($hasBrandColumn) {
            $where[] = 'p.brand_id = ?';
            $filterParams[] = $brand;
        } elseif ($hasProductMeta) {
            $where[] = 'pm.brand_id = ?';
            $filterParams[] = $brand;
        }
    }

    switch ($sort) {
        case 'price_asc': $order = 'CASE WHEN stock_total <= 0 THEN 1 ELSE 0 END ASC, p.selling_price ASC, p.name ASC'; break;
        case 'price_desc': $order = 'CASE WHEN stock_total <= 0 THEN 1 ELSE 0 END ASC, p.selling_price DESC, p.name ASC'; break;
        case 'name': $order = 'CASE WHEN stock_total <= 0 THEN 1 ELSE 0 END ASC, p.name ASC, p.id ASC'; break;
        case 'stock': $order = 'CASE WHEN stock_total <= 0 THEN 1 ELSE 0 END ASC, stock_total DESC, p.name ASC'; break;
        default: $order = 'CASE WHEN stock_total <= 0 THEN 1 ELSE 0 END ASC, p.name ASC, p.id ASC';
    }

    try {
        $defaultWeight = (int) marketplace_default_weight();
        $hasWeightColumn = storefront_column_exists($db, 'products', 'weight_gram');
        $metaJoin = $hasProductMeta ? 'LEFT JOIN marketplace_product_meta pm ON pm.product_id=p.id' : '';
        if ($hasWeightColumn) $weightSelect = 'COALESCE(p.weight_gram,' . $defaultWeight . ') AS weight_gram';
        elseif ($hasProductMeta) $weightSelect = 'COALESCE(pm.weight_gram,' . $defaultWeight . ') AS weight_gram';
        else $weightSelect = $defaultWeight . ' AS weight_gram';

        $brandJoin = '';
        $brandSelect = 'NULL AS brand_name';
        if ($hasBrandTable) {
            if ($hasBrandColumn) {
                $brandJoin = 'LEFT JOIN marketplace_brands mb ON mb.id=p.brand_id AND mb.is_active=1';
                $brandSelect = 'mb.name AS brand_name';
            } elseif ($hasProductMeta) {
                $brandJoin = 'LEFT JOIN marketplace_brands mb ON mb.id=pm.brand_id AND mb.is_active=1';
                $brandSelect = 'mb.name AS brand_name';
            }
        }

        $variantJoin = '';
        $variantSelect = '0 AS variant_count';
        $stockSelect = 'COALESCE(pbs.stock, CASE WHEN b.is_main=1 THEN p.stock ELSE 0 END, 0) AS stock_total';
        if ($hasVariants && $hasVariantStocks) {
            $variantJoin = "LEFT JOIN (
                SELECT v.product_id,COUNT(*) AS variant_count,SUM(COALESCE(vs.stock,0)) AS variant_stock
                FROM marketplace_product_variants v
                LEFT JOIN marketplace_product_variant_stocks vs ON vs.variant_id=v.id AND vs.branch_id=?
                WHERE v.is_active=1
                GROUP BY v.product_id
            ) va ON va.product_id=p.id";
            $variantSelect = 'COALESCE(va.variant_count,0) AS variant_count';
            $stockSelect = 'CASE WHEN COALESCE(va.variant_count,0)>0 THEN COALESCE(va.variant_stock,0) ELSE COALESCE(pbs.stock, CASE WHEN b.is_main=1 THEN p.stock ELSE 0 END,0) END AS stock_total';
            $params[] = $branchId;
        } elseif ($hasVariants) {
            $variantJoin = "LEFT JOIN (
                SELECT v.product_id,COUNT(*) AS variant_count
                FROM marketplace_product_variants v
                WHERE v.is_active=1
                GROUP BY v.product_id
            ) va ON va.product_id=p.id";
            $variantSelect = 'COALESCE(va.variant_count,0) AS variant_count';
        }
        array_push($params, ...$filterParams);

        $sql = "
            SELECT p.id, p.category_id, p.product_code, p.barcode, p.name, p.description, p.image,
                   p.selling_price, p.stock, p.unit, {$weightSelect}, p.created_at,
                   c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon,
                   {$brandSelect},{$variantSelect},{$stockSelect}
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN branches b ON b.id = ?
            LEFT JOIN product_branch_stocks pbs ON pbs.product_id = p.id AND pbs.branch_id = b.id
            {$metaJoin}
            {$brandJoin}
            {$variantJoin}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$order}
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        // Legacy fallback: the catalog must still work even when branch-stock tables
        // or a V15 additive migration are not available on the hosting database yet.
        try {
            $fallbackWhere = ['p.is_active = 1'];
            $fallbackParams = [];
            if ($category !== '') {
                if (ctype_digit($category)) {
                    $fallbackWhere[] = 'p.category_id = ?';
                    $fallbackParams[] = (int) $category;
                } else {
                    $fallbackWhere[] = 'c.slug = ?';
                    $fallbackParams[] = $category;
                }
            }
            if ($search !== '') {
                $fallbackWhere[] = '(p.name LIKE ? OR p.product_code LIKE ? OR p.description LIKE ? OR c.name LIKE ?)';
                $q = '%' . $search . '%';
                array_push($fallbackParams, $q, $q, $q, $q);
            }
            if ($brand > 0) {
                if ($hasBrandColumn) {
                    $fallbackWhere[] = 'p.brand_id = ?';
                    $fallbackParams[] = $brand;
                } elseif ($hasProductMeta) {
                    $fallbackWhere[] = 'EXISTS (SELECT 1 FROM marketplace_product_meta pmf WHERE pmf.product_id=p.id AND pmf.brand_id=?)';
                    $fallbackParams[] = $brand;
                }
            }
            $defaultWeight = (int) marketplace_default_weight();
            $weightSelect = storefront_column_exists($db, 'products', 'weight_gram')
                ? 'COALESCE(p.weight_gram,' . $defaultWeight . ') AS weight_gram'
                : ($defaultWeight . ' AS weight_gram');
            $sql = "
                SELECT p.id, p.category_id, p.product_code, p.barcode, p.name, p.description, p.image,
                       p.selling_price, p.stock, p.unit, {$weightSelect}, p.created_at,
                       c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon,
                       NULL AS brand_name,0 AS variant_count,COALESCE(p.stock, 0) AS stock_total
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE " . implode(' AND ', $fallbackWhere) . "
                ORDER BY CASE WHEN COALESCE(p.stock,0) <= 0 THEN 1 ELSE 0 END ASC, p.name ASC, p.id ASC
                LIMIT {$limit} OFFSET {$offset}
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($fallbackParams);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable $ignored) {
            return [];
        }
    }

    // Price campaign calculation remains per returned page, but brand, variant
    // count, variant stock and product weight are now loaded by the main query.
    foreach ($rows as &$row) {
        try {
            $eff = marketplace_v15_effective_price($db, (int) $row['id'], (float) $row['selling_price']);
            $row['original_selling_price'] = $eff['normal_price'];
            $row['selling_price'] = $eff['price'];
            $row['flash_sale'] = $eff['flash'];
        } catch (Throwable $e) {
            $row['original_selling_price'] = (float) $row['selling_price'];
            $row['flash_sale'] = null;
        }
        $row['brand_name'] = $row['brand_name'] ?: null;
        $row['variant_count'] = (int)($row['variant_count'] ?? 0);
        $row['stock_total'] = max(0, (int)($row['stock_total'] ?? 0));
        $row['weight_gram'] = max(1, (int)($row['weight_gram'] ?? marketplace_default_weight()));
    }
    unset($row);

    return $rows;
}

function storefront_active_product_count(): int
{
    $db = storefront_db();
    if (!$db) return 0;
    try { return (int) $db->query("SELECT COUNT(*) FROM products WHERE is_active=1")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

function storefront_effective_stock(array $product): int
{
    // V15.14: satu sumber stok marketplace = stok POS pada cabang fulfillment.
    return max(0, (int)($product['stock_total'] ?? $product['stock'] ?? 0));
}

function storefront_product(int $id): ?array
{
    if ($id <= 0) return null;
    $db = storefront_db();
    if (!$db) return null;

    $branchId = marketplace_branch_id($db);
    $weightExpr = storefront_column_exists($db, 'products', 'weight_gram')
        ? 'p.weight_gram'
        : (string) marketplace_default_weight();

    // Fetch the core POS product first. V15 extras must never make a valid POS
    // product look "missing" just because an additive migration is incomplete.
    try {
        $stmt = $db->prepare("SELECT p.id,p.category_id,p.product_code,p.barcode,p.name,p.description,p.image,p.selling_price,p.stock,p.unit,{$weightExpr} AS weight_gram,p.created_at,c.name category_name,c.slug category_slug,c.icon category_icon,COALESCE(pbs.stock,CASE WHEN b.is_main=1 THEN p.stock ELSE 0 END,0) stock_total FROM products p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN branches b ON b.id=? LEFT JOIN product_branch_stocks pbs ON pbs.product_id=p.id AND pbs.branch_id=b.id WHERE p.id=? AND p.is_active=1 LIMIT 1");
        $stmt->execute([$branchId, $id]);
        $row = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        try {
            $stmt = $db->prepare("SELECT p.id,p.category_id,p.product_code,p.barcode,p.name,p.description,p.image,p.selling_price,p.stock,p.unit,{$weightExpr} AS weight_gram,p.created_at,c.name category_name,c.slug category_slug,c.icon category_icon,COALESCE(p.stock,0) stock_total FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.is_active=1 LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch() ?: null;
        } catch (Throwable $ignored) {
            return null;
        }
    }

    if (!$row) return null;
    $row['weight_gram'] = marketplace_v15_product_weight($db, (int)$row['id'], (int)($row['weight_gram'] ?? marketplace_default_weight()));

    try {
        $eff = marketplace_v15_effective_price($db, (int) $row['id'], (float) $row['selling_price']);
        $row['original_selling_price'] = $eff['normal_price'];
        $row['selling_price'] = $eff['price'];
        $row['flash_sale'] = $eff['flash'];
    } catch (Throwable $e) {
        $row['original_selling_price'] = (float) $row['selling_price'];
        $row['flash_sale'] = null;
    }

    $row['brand_name'] = null;
    if (storefront_table_exists($db, 'marketplace_brands')) {
        try {
            $brandId = marketplace_v15_product_brand_id($db, (int)$row['id']);
            if ($brandId > 0) {
                $bs = $db->prepare("SELECT name FROM marketplace_brands WHERE id=? AND is_active=1 LIMIT 1");
                $bs->execute([$brandId]);
                $row['brand_name'] = $bs->fetchColumn() ?: null;
            }
        } catch (Throwable $e) {}
    }

    $row['variant_count'] = 0;
    if (storefront_table_exists($db, 'marketplace_product_variants')) {
        try {
            $vc = $db->prepare("SELECT COUNT(*) FROM marketplace_product_variants WHERE product_id=? AND is_active=1");
            $vc->execute([(int) $row['id']]);
            $row['variant_count'] = (int) $vc->fetchColumn();
            if($row['variant_count']>0) $row['stock_total']=marketplace_v16_product_variant_stock_total($db,(int)$row['id'],$branchId,true);
        } catch (Throwable $e) {}
    }

    return $row;
}


function storefront_customer(): ?array
{
    $db = storefront_db();
    return $db ? marketplace_customer($db) : null;
}

function storefront_wishlist_ids(): array
{
    $db = storefront_db();
    $cid = marketplace_customer_id();
    return ($db && $cid > 0) ? marketplace_wishlist_ids($db, $cid) : [];
}

function storefront_product_url(array $product): string
{
    return legacy_base_url('produk/' . (int)($product['id'] ?? 0) . '-' . slugify((string)($product['name'] ?? 'produk')));
}

function storefront_featured_promo(): ?array
{
    $db = storefront_db();
    if (!$db) return null;
    try {
        if (storefront_table_exists($db, 'marketplace_vouchers')) {
            $stmt = $db->query("SELECT id,name,code,discount_type,discount_value,max_discount,min_purchase,ends_at AS end_date FROM marketplace_vouchers WHERE is_active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) AND (usage_limit=0 OR used_count<usage_limit) ORDER BY id DESC LIMIT 1");
            $row = $stmt->fetch();
            if ($row) return $row;
        }
    } catch (Throwable $e) {}
    try {
        if (!storefront_table_exists($db, 'promos')) return null;
        $stmt = $db->query("SELECT id,name,code,discount_type,discount_value,max_discount,min_purchase,end_date FROM promos WHERE status='active' AND (start_date IS NULL OR start_date<=CURDATE()) AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY id DESC LIMIT 1");
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

function storefront_stats(): array
{
    $db = storefront_db();
    $stats = ['products' => 0, 'orders' => 0, 'customers' => 0, 'branches' => 0];
    if (!$db) return $stats;

    try { $stats['products'] = (int) $db->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn(); } catch (Throwable $e) {}
    try { $stats['customers'] = (int) $db->query("SELECT COUNT(*) FROM customers")->fetchColumn(); } catch (Throwable $e) {}
    try { $stats['branches'] = (int) $db->query("SELECT COUNT(*) FROM branches WHERE is_active = 1")->fetchColumn(); } catch (Throwable $e) {}
    try {
        if (storefront_table_exists($db, 'online_orders')) {
            $stats['orders'] = (int) $db->query("SELECT COUNT(*) FROM online_orders")->fetchColumn();
        }
    } catch (Throwable $e) {}
    return $stats;
}

function storefront_primary_branch(): ?array
{
    $db = storefront_db();
    if (!$db) return null;
    try {
        $branchId = marketplace_branch_id($db);
        $stmt = $db->prepare("SELECT id, name, phone, address FROM branches WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$branchId]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function storefront_track(string $code): ?array
{
    $code = trim($code);
    if ($code === '') return null;
    $db = storefront_db();
    if (!$db) return null;

    try {
        if (storefront_table_exists($db, 'online_orders')) {
            $stmt = $db->prepare("SELECT o.*,b.name AS branch_name,b.address AS branch_address,b.phone AS branch_phone FROM online_orders o LEFT JOIN branches b ON b.id=o.branch_id WHERE o.order_number = ? OR o.awb_number = ? ORDER BY o.id DESC LIMIT 1");
            $stmt->execute([$code, $code]);
            $online = $stmt->fetch();
            if ($online) {
                return ['type' => 'online_order', 'data' => $online];
            }
        }
    } catch (Throwable $e) {}

    // Keep compatibility with future shipment tables and legacy POS invoices.
    try {
        if (storefront_table_exists($db, 'shipments')) {
            $stmt = $db->prepare("SELECT * FROM shipments WHERE awb_number = ? OR shipping_order_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$code, $code]);
            $shipment = $stmt->fetch();
            if ($shipment) {
                return ['type' => 'shipment', 'data' => $shipment];
            }
        }
    } catch (Throwable $e) {}

    try {
        $stmt = $db->prepare("\n            SELECT t.id, t.invoice_number, t.grand_total, t.payment_method, t.status, t.transaction_date,\n                   c.name customer_name, b.name branch_name\n            FROM transactions t\n            LEFT JOIN customers c ON c.id = t.customer_id\n            LEFT JOIN branches b ON b.id = t.branch_id\n            WHERE t.invoice_number = ?\n            LIMIT 1\n        ");
        $stmt->execute([$code]);
        $transaction = $stmt->fetch();
        return $transaction ? ['type' => 'transaction', 'data' => $transaction] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function storefront_image(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    return legacy_base_url(ltrim($path, '/'));
}

function storefront_brands(int $limit=12): array
{
    $db=storefront_db();if(!$db)return [];try{$rows=marketplace_v15_brands($db,true);return array_slice($rows,0,max(1,$limit));}catch(Throwable $e){return [];}
}
function storefront_banners(string $position='hero'): array
{
    $db=storefront_db();if(!$db)return [];try{return marketplace_v15_banners($db,$position);}catch(Throwable $e){return [];}
}
function storefront_product_gallery(int $productId): array
{
    $db=storefront_db();if(!$db)return [];try{return marketplace_v15_product_images($db,$productId);}catch(Throwable $e){return [];}
}
function storefront_product_variants(int $productId): array
{
    $db=storefront_db();if(!$db)return [];try{$rows=marketplace_v15_variants($db,$productId,marketplace_branch_id($db),true);foreach($rows as &$v){$eff=marketplace_v15_effective_price($db,$productId,(float)$v['selling_price'],(int)$v['id']);$v['original_selling_price']=$eff['normal_price'];$v['selling_price']=$eff['price'];$v['flash_sale']=$eff['flash'];}unset($v);return $rows;}catch(Throwable $e){return [];}
}
function storefront_flash_products(int $limit=8): array
{
    $db=storefront_db();if(!$db)return [];$all=storefront_products(['limit'=>120,'sort'=>'latest']);$out=[];foreach($all as $p){
        if(empty($p['flash_sale']) && (int)($p['variant_count']??0)>0){
            $variants=storefront_product_variants((int)$p['id']);$active=array_values(array_filter($variants,static fn($v)=>!empty($v['flash_sale'])));
            if($active){usort($active,static fn($a,$b)=>(float)$a['selling_price']<=>(float)$b['selling_price']);$v=$active[0];$p['flash_sale']=$v['flash_sale'];$p['selling_price']=$v['selling_price'];$p['original_selling_price']=$v['original_selling_price'];}
        }
        if(!empty($p['flash_sale']))$out[]=$p;if(count($out)>=$limit)break;
    }return $out;
}

function storefront_product_json(array $product): string
{
    return htmlspecialchars(json_encode([
        'id' => (int) ($product['id'] ?? 0),
        'name' => (string) ($product['name'] ?? ''),
        'price' => (float) ($product['selling_price'] ?? 0),
        'original_price' => (float) ($product['original_selling_price'] ?? $product['selling_price'] ?? 0),
        'stock' => (int) ($product['stock_total'] ?? $product['stock'] ?? 0),
        'image' => storefront_product_image($product),
        'fallback_image' => storefront_product_fallback($product),
        'category' => (string) ($product['category_name'] ?? 'Produk'),
        'brand' => (string) ($product['brand_name'] ?? ''),
        'unit' => (string) ($product['unit'] ?? 'pcs'),
        'weight_gram' => (int) ($product['weight_gram'] ?? marketplace_default_weight()),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
}
