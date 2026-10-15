<?php
/**
 * Bulk catalog readers used by POS, purchases, request-stock and stock opname.
 * The legacy implementation queried variants/prices one product at a time (N+1).
 * These helpers intentionally perform a small, fixed number of queries.
 */

function catalog_fast_placeholders(int $count): string
{
    return implode(',', array_fill(0, max(1, $count), '?'));
}

function catalog_fast_variants_by_product(PDO $db, array $productIds, ?int $branchId = null, bool $activeOnly = true): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    if (!marketplace_table_exists($db, 'marketplace_product_variants')) return [];

    $branchId = $branchId ?: marketplace_branch_id($db);
    $hasStocks = marketplace_table_exists($db, 'marketplace_product_variant_stocks');
    $ph = catalog_fast_placeholders(count($ids));
    $params = [];

    if ($hasStocks) {
        $sql = "SELECT v.id,v.product_id,v.name,v.sku,v.barcode,v.selling_price,v.purchase_price,v.weight_gram,v.image_path,v.is_active,v.sort_order,
                       COALESCE(vs.stock,0) AS stock,COALESCE(vs.minimum_stock,0) AS minimum_stock
                FROM marketplace_product_variants v
                LEFT JOIN marketplace_product_variant_stocks vs ON vs.variant_id=v.id AND vs.branch_id=?
                WHERE v.product_id IN ({$ph})" . ($activeOnly ? " AND v.is_active=1" : "") . "
                ORDER BY v.product_id ASC,v.sort_order ASC,v.name ASC,v.id ASC";
        $params[] = $branchId;
        array_push($params, ...$ids);
    } else {
        $sql = "SELECT v.id,v.product_id,v.name,v.sku,v.barcode,v.selling_price,v.purchase_price,v.weight_gram,v.image_path,v.is_active,v.sort_order,
                       0 AS stock,0 AS minimum_stock
                FROM marketplace_product_variants v
                WHERE v.product_id IN ({$ph})" . ($activeOnly ? " AND v.is_active=1" : "") . "
                ORDER BY v.product_id ASC,v.sort_order ASC,v.name ASC,v.id ASC";
        $params = $ids;
    }

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $row['id'] = (int)$row['id'];
            $row['product_id'] = (int)$row['product_id'];
            $row['stock'] = max(0, (int)($row['stock'] ?? 0));
            $row['minimum_stock'] = max(0, (int)($row['minimum_stock'] ?? 0));
            // For fast catalog lists the stored variant name is enough. Full attribute
            // expansion remains available on the Marketplace variant editor.
            $row['display_name'] = trim((string)($row['name'] ?? '')) ?: ('Varian #' . $row['id']);
            $out[$row['product_id']][] = $row;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function catalog_fast_price_levels(PDO $db, array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn($v) => $v > 0)));
    if (!$ids || !price_table_exists($db, 'product_price_levels')) return [];
    $ph = catalog_fast_placeholders(count($ids));
    try {
        $stmt = $db->prepare("SELECT product_id,level_code,price,min_qty FROM product_price_levels WHERE product_id IN ({$ph})");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[(int)$row['product_id']][(string)$row['level_code']] = [
                'price' => (float)$row['price'],
                'min_qty' => max(1, (int)$row['min_qty']),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function catalog_fast_warehouse_variant_stocks(PDO $db, int $warehouseId, array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn($v) => $v > 0)));
    if (!$ids || $warehouseId <= 0 || !marketplace_table_exists($db, 'warehouse_variant_stocks')) return [];
    $ph = catalog_fast_placeholders(count($ids));
    try {
        $stmt = $db->prepare("SELECT product_id,variant_id,stock,minimum_stock,purchase_price FROM warehouse_variant_stocks WHERE warehouse_id=? AND product_id IN ({$ph})");
        $stmt->execute(array_merge([$warehouseId], $ids));
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[(int)$row['product_id']][(int)$row['variant_id']] = [
                'stock' => max(0, (int)$row['stock']),
                'minimum_stock' => max(0, (int)($row['minimum_stock'] ?? 0)),
                'purchase_price' => (float)($row['purchase_price'] ?? 0),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function catalog_fast_brand_names(PDO $db, array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn($v) => $v > 0)));
    if (!$ids || !marketplace_table_exists($db, 'marketplace_brands')) return [];
    $ph = catalog_fast_placeholders(count($ids));
    try {
        if (marketplace_column_exists($db, 'products', 'brand_id')) {
            $stmt = $db->prepare("SELECT p.id AS product_id,b.name FROM products p LEFT JOIN marketplace_brands b ON b.id=p.brand_id WHERE p.id IN ({$ph})");
            $stmt->execute($ids);
        } elseif (marketplace_table_exists($db, 'marketplace_product_meta')) {
            $stmt = $db->prepare("SELECT pm.product_id,b.name FROM marketplace_product_meta pm LEFT JOIN marketplace_brands b ON b.id=pm.brand_id WHERE pm.product_id IN ({$ph})");
            $stmt->execute($ids);
        } else {
            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) $out[(int)$row['product_id']] = (string)($row['name'] ?? '');
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
