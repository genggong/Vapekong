<?php
/**
 * Stock Branch Sync Helper
 * Menjaga stok POS/cabang tetap sinkron saat stok produk diupdate dari menu Produk/Stok.
 * Tampilan dan logic bisnis lain tidak diubah.
 */

function stock_sync_table_exists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function stock_sync_column_exists(PDO $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function stock_sync_current_branch_id(PDO $db): int
{
    if (function_exists('branch_current_id')) {
        try {
            return (int) branch_current_id($db);
        } catch (Throwable $e) {}
    }

    $id = 0;
    try {
        if (stock_sync_table_exists($db, 'branches')) {
            $id = (int)$db->query("SELECT id FROM branches WHERE is_main=1 ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($id <= 0) {
                $id = (int)$db->query("SELECT id FROM branches ORDER BY id ASC LIMIT 1")->fetchColumn();
            }
        }
    } catch (Throwable $e) {}

    return $id > 0 ? $id : 1;
}

function stock_sync_branch_row(PDO $db, int $branchId, int $productId, int $defaultStock = 0, int $minimumStock = 0): void
{
    if (!stock_sync_table_exists($db, 'product_branch_stocks')) return;

    $stmt = $db->prepare("INSERT IGNORE INTO product_branch_stocks (branch_id, product_id, stock, minimum_stock, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$branchId, $productId, $defaultStock, $minimumStock]);
}

function stock_sync_product_to_active_branch(PDO $db, int $productId, int $newStock, int $minimumStock = 0): void
{
    if (!stock_sync_table_exists($db, 'product_branch_stocks')) return;

    $branchId = stock_sync_current_branch_id($db);
    stock_sync_branch_row($db, $branchId, $productId, $newStock, $minimumStock);

    $stmt = $db->prepare("UPDATE product_branch_stocks SET stock=?, minimum_stock=?, updated_at=NOW() WHERE branch_id=? AND product_id=?");
    $stmt->execute([$newStock, $minimumStock, $branchId, $productId]);
}

function stock_sync_add_to_active_branch(PDO $db, int $productId, int $qty, int $minimumStock = 0): array
{
    if (!stock_sync_table_exists($db, 'product_branch_stocks')) {
        return ['branch_id' => 0, 'before' => 0, 'after' => 0];
    }

    $branchId = stock_sync_current_branch_id($db);
    stock_sync_branch_row($db, $branchId, $productId, 0, $minimumStock);

    $stmt = $db->prepare("SELECT stock FROM product_branch_stocks WHERE branch_id=? AND product_id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$branchId, $productId]);
    $before = (int)$stmt->fetchColumn();
    $after = $before + $qty;

    $upd = $db->prepare("UPDATE product_branch_stocks SET stock=?, minimum_stock=GREATEST(minimum_stock, ?), updated_at=NOW() WHERE branch_id=? AND product_id=?");
    $upd->execute([$after, $minimumStock, $branchId, $productId]);

    return ['branch_id' => $branchId, 'before' => $before, 'after' => $after];
}

function stock_sync_set_active_branch(PDO $db, int $productId, int $newStock, int $minimumStock = 0): array
{
    if (!stock_sync_table_exists($db, 'product_branch_stocks')) {
        return ['branch_id' => 0, 'before' => 0, 'after' => $newStock];
    }

    $branchId = stock_sync_current_branch_id($db);
    stock_sync_branch_row($db, $branchId, $productId, $newStock, $minimumStock);

    $stmt = $db->prepare("SELECT stock FROM product_branch_stocks WHERE branch_id=? AND product_id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$branchId, $productId]);
    $before = (int)$stmt->fetchColumn();

    $upd = $db->prepare("UPDATE product_branch_stocks SET stock=?, minimum_stock=?, updated_at=NOW() WHERE branch_id=? AND product_id=?");
    $upd->execute([$newStock, $minimumStock, $branchId, $productId]);

    return ['branch_id' => $branchId, 'before' => $before, 'after' => $newStock];
}

function stock_sync_recalculate_product_master(PDO $db, int $productId): void
{
    if (!stock_sync_table_exists($db, 'product_branch_stocks')) return;

    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(stock),0) FROM product_branch_stocks WHERE product_id=?");
        $stmt->execute([$productId]);
        $total = (int)$stmt->fetchColumn();
        $db->prepare("UPDATE products SET stock=?, updated_at=NOW() WHERE id=?")->execute([$total, $productId]);
    } catch (Throwable $e) {
        // Jangan hentikan proses utama.
    }
}
?>
