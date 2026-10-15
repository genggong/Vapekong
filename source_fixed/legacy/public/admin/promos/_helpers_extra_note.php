<?php
/*
Tambahan penting untuk public/admin/promos/_helpers.php

Kalau fungsi ensure_promos_table() kamu belum punya kolom barcode_code dan scope,
tambahkan query ALTER ini setelah CREATE TABLE promos.
Jangan buat file ini dipanggil langsung. Ini hanya catatan patch.
*/

try {
    $db->exec("ALTER TABLE promos ADD COLUMN barcode_code VARCHAR(80) NULL AFTER code");
} catch (Throwable $e) {}

try {
    $db->exec("ALTER TABLE promos ADD COLUMN scope ENUM('all','specific_products') NOT NULL DEFAULT 'all' AFTER promo_type");
} catch (Throwable $e) {}

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS promo_products (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            promo_id BIGINT NOT NULL,
            product_id BIGINT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY promo_product_unique (promo_id, product_id),
            INDEX idx_promo_products_promo (promo_id),
            INDEX idx_promo_products_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {}
