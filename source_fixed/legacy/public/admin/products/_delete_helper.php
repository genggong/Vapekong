<?php

function product_delete_table_exists(PDO $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
}

function product_delete_column_exists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

function product_delete_usage(PDO $db, int $productId): array
{
    $checks = [
        'transaction_items' => 'transaksi penjualan',
        'purchase_items' => 'pembelian supplier',
        'sales_return_items' => 'retur penjualan',
        'supplier_return_items' => 'retur supplier',
        'stock_opname_items' => 'stock opname',
        'warehouse_quality_checks' => 'QC gudang',
        'product_bundle_items' => 'paket/bundle produk',
    ];

    $usage = [];
    foreach ($checks as $table => $label) {
        if (!product_delete_table_exists($db, $table) || !product_delete_column_exists($db, $table, 'product_id')) {
            continue;
        }
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE product_id = ?");
        $stmt->execute([$productId]);
        $count = (int)$stmt->fetchColumn();
        if ($count > 0) {
            $usage[] = $label . ' (' . $count . ')';
        }
    }
    return $usage;
}

function product_delete_one(PDO $db, int $productId): array
{
    if ($productId <= 0) {
        throw new Exception('ID produk tidak valid.');
    }

    $stmt = $db->prepare('SELECT id, name, image FROM products WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if (!$product) {
        throw new Exception('Produk tidak ditemukan atau sudah dihapus.');
    }

    $usage = product_delete_usage($db, $productId);
    if ($usage) {
        throw new Exception('Produk "' . $product['name'] . '" masih dipakai di ' . implode(', ', $usage) . '. Nonaktifkan produk jika histori harus tetap disimpan.');
    }

    // Data konfigurasi/non-historis boleh ikut dibersihkan agar tidak meninggalkan orphan row.
    $cleanupTables = [
        'promo_products',
        'product_price_levels',
        'product_branch_stocks',
        'product_locations',
        'stock_movements',
    ];
    foreach ($cleanupTables as $table) {
        if (!product_delete_table_exists($db, $table) || !product_delete_column_exists($db, $table, 'product_id')) {
            continue;
        }
        $stmt = $db->prepare("DELETE FROM {$table} WHERE product_id = ?");
        $stmt->execute([$productId]);
    }

    $stmt = $db->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    if ($stmt->rowCount() !== 1) {
        throw new Exception('Produk "' . $product['name'] . '" gagal dihapus.');
    }

    return $product;
}
