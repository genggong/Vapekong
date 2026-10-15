<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$db = Database::connect();
bundle_install($db);
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM product_bundles WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$bundle = $stmt->fetch();
if (!$bundle) { flash('error','Paket tidak ditemukan.'); legacy_redirect('admin/bundles/index.php'); }
try {
    $db->beginTransaction();
    $db->prepare("DELETE FROM product_bundle_items WHERE bundle_id=?")->execute([$id]);
    $db->prepare("DELETE FROM product_bundles WHERE id=?")->execute([$id]);
    Auth::log('delete_bundle','Hapus paket produk ' . $bundle['name']);
    $db->commit();
    flash('success','Paket berhasil dihapus.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    flash('error','Gagal hapus paket: ' . $e->getMessage());
}
legacy_redirect('admin/bundles/index.php');
?>