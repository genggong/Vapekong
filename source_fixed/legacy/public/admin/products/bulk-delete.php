<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_delete_helper.php';
Auth::requireRole(['super_admin', 'owner']);

if (!is_post()) {
    flash('error', 'Metode hapus massal tidak valid.');
    legacy_redirect('admin/products/index.php');
}
if (!CSRF::verify()) {
    flash('error', 'Token keamanan tidak valid. Silakan muat ulang halaman.');
    legacy_redirect('admin/products/index.php');
}

$ids = $_POST['product_ids'] ?? [];
if (!is_array($ids)) $ids = [];
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));

if (!$ids) {
    flash('warning', 'Pilih minimal satu produk yang ingin dihapus.');
    legacy_redirect('admin/products/index.php');
}
if (count($ids) > 300) {
    flash('error', 'Maksimal 300 produk dapat diproses sekaligus.');
    legacy_redirect('admin/products/index.php');
}

$db = Database::connect();
$deleted = [];
$failed = [];

try {
    $db->beginTransaction();

    foreach ($ids as $index => $id) {
        $savepoint = 'sp_product_' . $index;
        $db->exec('SAVEPOINT ' . $savepoint);
        try {
            $product = product_delete_one($db, $id);
            $deleted[] = (string)($product['name'] ?? ('#' . $id));
            $db->exec('RELEASE SAVEPOINT ' . $savepoint);
        } catch (Throwable $e) {
            $db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $db->exec('RELEASE SAVEPOINT ' . $savepoint);
            $failed[] = '#' . $id . ': ' . $e->getMessage();
        }
    }

    $db->commit();

    if ($deleted) {
        Auth::log('bulk_delete_products', 'Menghapus ' . count($deleted) . ' produk sekaligus');
    }

    if ($deleted && !$failed) {
        flash('success', count($deleted) . ' produk berhasil dihapus.');
    } elseif ($deleted && $failed) {
        flash('warning', count($deleted) . ' produk berhasil dihapus, ' . count($failed) . ' gagal karena masih punya histori/relasi.');
        flash('info', implode(' | ', array_slice($failed, 0, 3)) . (count($failed) > 3 ? ' | dan lainnya...' : ''));
    } else {
        flash('error', 'Tidak ada produk yang dapat dihapus. ' . ($failed ? $failed[0] : ''));
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    flash('error', 'Gagal memproses hapus massal: ' . $e->getMessage());
}

legacy_redirect('admin/products/index.php');
