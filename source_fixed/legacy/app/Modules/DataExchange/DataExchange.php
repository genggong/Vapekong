<?php
require_once __DIR__ . '/Xlsx.php';
require_once __DIR__ . '/Pdf.php';

function kp_exchange_safe_date(?string $date, string $fallback): string
{
    $date = trim((string)$date);
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $time = strtotime($date . ' 00:00:00');
        if ($time !== false && date('Y-m-d', $time) === $date) return $date;
    }
    return $fallback;
}

function kp_exchange_rupiah_number($number): string
{
    return 'Rp ' . number_format((float)$number, 0, ',', '.');
}

function kp_exchange_product_headers(): array
{
    // Urutan dibuat ramah operator: master produk -> brand/kategori -> varian -> identitas -> harga/stok.
    return [
        'name', 'brand', 'category', 'variant', 'variant_sku', 'variant_barcode',
        'product_code', 'barcode', 'supplier', 'unit', 'purchase_price', 'selling_price',
        'stock', 'minimum_stock', 'rack_location', 'is_active', 'description'
    ];
}

function kp_exchange_product_labels(): array
{
    return [
        'name' => 'Nama Produk',
        'brand' => 'Brand',
        'category' => 'Kategori',
        'variant' => 'Varian',
        'variant_sku' => 'SKU Varian',
        'variant_barcode' => 'Barcode Varian',
        'product_code' => 'Kode Produk',
        'barcode' => 'Barcode Produk',
        'supplier' => 'Supplier',
        'unit' => 'Satuan',
        'purchase_price' => 'Harga Beli',
        'selling_price' => 'Harga Jual',
        'stock' => 'Stok',
        'minimum_stock' => 'Stok Minimum',
        'rack_location' => 'Lokasi Rak',
        'is_active' => 'Status',
        'description' => 'Deskripsi',
    ];
}

function kp_exchange_product_label_row(): array
{
    $labels = kp_exchange_product_labels();
    return array_map(static fn(string $key): string => $labels[$key] ?? $key, kp_exchange_product_headers());
}
