<?php
function price_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function price_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function price_levels(): array
{
    return [
        'retail' => 'Ecer',
        'grosir' => 'Grosir',
        'reseller' => 'Reseller',
        'member' => 'Member',
    ];
}

function price_level_label(?string $level): string
{
    $levels = price_levels();
    return $levels[$level ?: 'retail'] ?? 'Ecer';
}

function price_install(PDO $db): void
{
    static $done = [];
    $key = spl_object_id($db);
    if (!empty($done[$key])) return;
    $done[$key] = true;

    if (!price_table_exists($db, 'product_price_levels')) {
        $db->exec("CREATE TABLE IF NOT EXISTS product_price_levels (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT NOT NULL,
            level_code VARCHAR(40) NOT NULL,
            price DECIMAL(15,2) NOT NULL DEFAULT 0,
            min_qty INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_product_level (product_id, level_code),
            INDEX idx_product_price_levels_product (product_id),
            INDEX idx_product_price_levels_level (level_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (price_table_exists($db, 'customers') && !price_column_exists($db, 'customers', 'price_level')) {
        $db->exec("ALTER TABLE customers ADD COLUMN price_level VARCHAR(40) NOT NULL DEFAULT 'retail' AFTER member_level");
    }

    if (price_table_exists($db, 'transaction_items') && !price_column_exists($db, 'transaction_items', 'price_level')) {
        $db->exec("ALTER TABLE transaction_items ADD COLUMN price_level VARCHAR(40) NULL AFTER unit_price");
    }

    // Do not seed/update every product on every page load. The previous code did
    // four INSERT...SELECT scans plus one UPDATE scan whenever POS opened. Existing
    // rows remain valid and newly created products are seeded explicitly below.
}

function price_ensure_product_levels(PDO $db, int $productId, float $sellingPrice): void
{
    if ($productId <= 0) return;
    price_install($db);
    $stmt = $db->prepare("INSERT IGNORE INTO product_price_levels (product_id, level_code, price, min_qty, created_at) VALUES (?, ?, ?, 1, NOW())");
    foreach (array_keys(price_levels()) as $level) {
        $stmt->execute([$productId, $level, max(0, $sellingPrice)]);
    }
    // Retail always mirrors products.selling_price.
    $db->prepare("UPDATE product_price_levels SET price=?, updated_at=NOW() WHERE product_id=? AND level_code='retail'")
       ->execute([max(0, $sellingPrice), $productId]);
}

function price_get(PDO $db, int $productId, string $level = 'retail'): float
{
    $level = array_key_exists($level, price_levels()) ? $level : 'retail';

    $stmt = $db->prepare("SELECT price FROM product_price_levels WHERE product_id = ? AND level_code = ? LIMIT 1");
    $stmt->execute([$productId, $level]);
    $price = $stmt->fetchColumn();

    if ($price !== false && (float)$price > 0) {
        return (float)$price;
    }

    $stmt = $db->prepare("SELECT selling_price FROM products WHERE id = ? LIMIT 1");
    $stmt->execute([$productId]);
    return (float)($stmt->fetchColumn() ?: 0);
}

function price_all_for_product(PDO $db, int $productId): array
{
    $result = [];
    $stmt = $db->prepare("SELECT level_code, price, min_qty FROM product_price_levels WHERE product_id = ?");
    $stmt->execute([$productId]);
    foreach ($stmt->fetchAll() as $row) {
        $result[$row['level_code']] = $row;
    }
    return $result;
}

function price_save(PDO $db, int $productId, string $level, float $price, int $minQty = 1): void
{
    $level = array_key_exists($level, price_levels()) ? $level : 'retail';
    $price = max(0, $price);
    $minQty = max(1, $minQty);

    $stmt = $db->prepare("SELECT id FROM product_price_levels WHERE product_id = ? AND level_code = ? LIMIT 1");
    $stmt->execute([$productId, $level]);
    $id = (int)$stmt->fetchColumn();

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE product_price_levels SET price=?, min_qty=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$price, $minQty, $id]);
    } else {
        $stmt = $db->prepare("INSERT INTO product_price_levels (product_id, level_code, price, min_qty, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$productId, $level, $price, $minQty]);
    }

    if ($level === 'retail') {
        $stmt = $db->prepare("UPDATE products SET selling_price=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$price, $productId]);
    }
}

function price_customer_level(array $customer = null): string
{
    $level = $customer['price_level'] ?? 'retail';
    return array_key_exists($level, price_levels()) ? $level : 'retail';
}
?>