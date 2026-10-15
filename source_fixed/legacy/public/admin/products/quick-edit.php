<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../prices/_helpers.php';
Auth::requireRole(['super_admin','owner']);

if (!is_post()) {
    flash('error', 'Perubahan produk wajib dikirim dari menu Produk.');
    legacy_redirect('admin/products/index.php');
}
if (!CSRF::verify()) {
    flash('error', 'Token keamanan tidak valid. Silakan muat ulang halaman Produk.');
    legacy_redirect('admin/products/index.php');
}

$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$sellingPriceRaw = str_replace([',', ' '], ['', ''], (string)($_POST['selling_price'] ?? '0'));
$sellingPrice = max(0, (float)$sellingPriceRaw);

if ($id <= 0) {
    flash('error', 'Produk tidak valid.');
    legacy_redirect('admin/products/index.php');
}
if ($name === '') {
    flash('error', 'Nama produk wajib diisi.');
    legacy_redirect('admin/products/index.php');
}

$db = Database::connect();
price_install($db);

try {
    $stmt = $db->prepare('SELECT id,name,selling_price FROM products WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $old = $stmt->fetch();
    if (!$old) {
        throw new RuntimeException('Produk tidak ditemukan.');
    }

    $db->beginTransaction();
    $up = $db->prepare('UPDATE products SET name=?, selling_price=?, updated_at=NOW() WHERE id=?');
    $up->execute([$name, $sellingPrice, $id]);

    // POS, keranjang, dan transaksi baru membaca level retail.
    // Simpan lewat helper agar products.selling_price dan product_price_levels selalu identik.
    price_save($db, $id, 'retail', $sellingPrice, 1);
    $db->commit();

    Auth::log('quick_edit_product', 'Edit cepat produk #' . $id . ': ' . $old['name'] . ' -> ' . $name . ', harga ' . $old['selling_price'] . ' -> ' . $sellingPrice);
    flash('success', 'Nama dan harga jual produk berhasil diperbarui. Harga baru langsung berlaku untuk transaksi baru.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    flash('error', 'Gagal mengubah produk: ' . $e->getMessage());
}

legacy_redirect('admin/products/index.php');
