<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_delete_helper.php';
Auth::requireRole(['super_admin', 'owner']);

if (!is_post()) {
    flash('error', 'Penghapusan produk wajib melalui tombol Hapus agar terlindungi CSRF.');
    legacy_redirect('admin/products/index.php');
}
if (!CSRF::verify()) {
    flash('error', 'Token keamanan tidak valid. Silakan muat ulang halaman.');
    legacy_redirect('admin/products/index.php');
}

$id = (int)($_POST['id'] ?? 0);
$db = Database::connect();

try {
    $db->beginTransaction();
    $product = product_delete_one($db, $id);
    $db->commit();
    Auth::log('delete_product', 'Menghapus produk ' . ($product['name'] ?? ('#' . $id)));
    flash('success', 'Produk ' . ($product['name'] ?? '') . ' berhasil dihapus.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    flash('error', 'Produk tidak bisa dihapus: ' . $e->getMessage());
}

legacy_redirect('admin/products/index.php');
