<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin', 'owner']);
$db = Database::connect();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="produk_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Nama Produk', 'Kode Produk', 'Barcode', 'Kategori', 'Supplier', 'Satuan', 'Harga Beli', 'Harga Jual', 'Stok', 'Stok Minimum', 'Status']);

$stmt = $db->query("
    SELECT p.*, c.name AS category_name, s.name AS supplier_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    ORDER BY p.name ASC
");

while ($row = $stmt->fetch()) {
    fputcsv($out, [
        $row['id'],
        $row['name'],
        $row['product_code'],
        $row['barcode'],
        $row['category_name'] ?? '',
        $row['supplier_name'] ?? '',
        $row['unit'],
        $row['purchase_price'],
        $row['selling_price'],
        $row['stock'],
        $row['minimum_stock'],
        ((int)$row['is_active'] === 1 ? 'Aktif' : 'Nonaktif')
    ]);
}

fclose($out);
exit;
