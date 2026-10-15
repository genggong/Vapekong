<?php

function promo_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensure_promos_table(PDO $db): void
{
    $db->exec("\n        CREATE TABLE IF NOT EXISTS promos (\n            id BIGINT AUTO_INCREMENT PRIMARY KEY,\n            name VARCHAR(160) NOT NULL,\n            code VARCHAR(80) NULL,\n            barcode VARCHAR(120) NULL,\n            promo_type ENUM('voucher','auto_discount') NOT NULL DEFAULT 'voucher',\n            discount_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',\n            discount_value DECIMAL(15,2) NOT NULL DEFAULT 0,\n            max_discount DECIMAL(15,2) NOT NULL DEFAULT 0,\n            min_purchase DECIMAL(15,2) NOT NULL DEFAULT 0,\n            usage_limit INT NOT NULL DEFAULT 0,\n            used_count INT NOT NULL DEFAULT 0,\n            promo_scope ENUM('all','specific_products') NOT NULL DEFAULT 'all',\n            start_date DATE NULL,\n            end_date DATE NULL,\n            note TEXT NULL,\n            status ENUM('active','inactive') NOT NULL DEFAULT 'active',\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,\n            UNIQUE KEY promos_code_unique (code),\n            INDEX idx_promos_barcode (barcode),\n            INDEX idx_promos_status (status),\n            INDEX idx_promos_type (promo_type),\n            INDEX idx_promos_scope (promo_scope)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");

    if (!promo_column_exists($db, 'promos', 'barcode')) {
        $db->exec("ALTER TABLE promos ADD COLUMN barcode VARCHAR(120) NULL AFTER code");
        $db->exec("ALTER TABLE promos ADD INDEX idx_promos_barcode (barcode)");
    }

    if (!promo_column_exists($db, 'promos', 'promo_scope')) {
        $db->exec("ALTER TABLE promos ADD COLUMN promo_scope ENUM('all','specific_products') NOT NULL DEFAULT 'all' AFTER used_count");
        $db->exec("ALTER TABLE promos ADD INDEX idx_promos_scope (promo_scope)");
    }

    $db->exec("\n        CREATE TABLE IF NOT EXISTS promo_products (\n            id BIGINT AUTO_INCREMENT PRIMARY KEY,\n            promo_id BIGINT NOT NULL,\n            product_id BIGINT NOT NULL,\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            UNIQUE KEY promo_product_unique (promo_id, product_id),\n            INDEX idx_promo_products_promo (promo_id),\n            INDEX idx_promo_products_product (product_id),\n            CONSTRAINT fk_promo_products_promo FOREIGN KEY (promo_id) REFERENCES promos(id) ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");
}

function promo_status_badge(array $promo): string
{
    $today = date('Y-m-d');

    if (($promo['status'] ?? '') !== 'active') {
        return '<span class="promo-badge gray">Nonaktif</span>';
    }

    if (!empty($promo['start_date']) && $promo['start_date'] > $today) {
        return '<span class="promo-badge orange">Belum Mulai</span>';
    }

    if (!empty($promo['end_date']) && $promo['end_date'] < $today) {
        return '<span class="promo-badge red">Expired</span>';
    }

    if ((int)($promo['usage_limit'] ?? 0) > 0 && (int)($promo['used_count'] ?? 0) >= (int)$promo['usage_limit']) {
        return '<span class="promo-badge red">Limit Habis</span>';
    }

    return '<span class="promo-badge green">Aktif</span>';
}

function promo_discount_text(array $promo): string
{
    if (($promo['discount_type'] ?? '') === 'percent') {
        $text = rtrim(rtrim(number_format((float)$promo['discount_value'], 2, ',', '.'), '0'), ',') . '%';
        if ((float)($promo['max_discount'] ?? 0) > 0) {
            $text .= ' maks ' . rupiah($promo['max_discount']);
        }
        return $text;
    }

    return rupiah($promo['discount_value']);
}

function promo_calculate_discount(array $promo, float $eligibleSubtotal): float
{
    if ($eligibleSubtotal <= 0 || $eligibleSubtotal < (float)$promo['min_purchase']) {
        return 0;
    }

    if (($promo['discount_type'] ?? '') === 'percent') {
        $discount = $eligibleSubtotal * ((float)$promo['discount_value'] / 100);
        if ((float)$promo['max_discount'] > 0) {
            $discount = min($discount, (float)$promo['max_discount']);
        }
        return max(0, min($discount, $eligibleSubtotal));
    }

    return max(0, min((float)$promo['discount_value'], $eligibleSubtotal));
}

function promo_product_ids(PDO $db, int $promoId): array
{
    ensure_promos_table($db);
    $stmt = $db->prepare("SELECT product_id FROM promo_products WHERE promo_id=? ORDER BY product_id ASC");
    $stmt->execute([$promoId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function promo_scope_text(array $promo): string
{
    return ($promo['promo_scope'] ?? 'all') === 'specific_products' ? 'Produk tertentu' : 'Semua produk';
}
