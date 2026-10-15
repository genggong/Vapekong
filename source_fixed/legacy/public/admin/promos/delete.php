<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireLogin();
$db = Database::connect();
ensure_promos_table($db);
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { flash('error', 'Promo tidak valid.'); legacy_redirect('admin/promos/index.php'); }
try {
    $db->beginTransaction();
    $db->prepare("DELETE FROM promo_products WHERE promo_id=?")->execute([$id]);
    $db->prepare("DELETE FROM promos WHERE id=?")->execute([$id]);
    $db->commit();
    Auth::log('delete_promo', 'Hapus promo ID ' . $id);
    flash('success', 'Promo berhasil dihapus.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    flash('error', 'Gagal menghapus promo: ' . $e->getMessage());
}
legacy_redirect('admin/promos/index.php');
