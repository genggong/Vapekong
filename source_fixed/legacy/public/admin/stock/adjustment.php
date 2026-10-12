<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/_sync_helper.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Adjustment Stok';
$db = Database::connect();
$productId = (int)($_GET['product_id'] ?? ($_POST['product_id'] ?? 0));
$currentBranchId = stock_sync_current_branch_id($db);
$stmtProducts = $db->prepare("SELECT p.id, p.name, p.product_code, COALESCE(pbs.stock, p.stock) AS stock, p.minimum_stock, p.unit FROM products p LEFT JOIN product_branch_stocks pbs ON pbs.product_id=p.id AND pbs.branch_id=? WHERE p.is_active=1 ORDER BY p.name ASC");
$stmtProducts->execute([$currentBranchId]);
$products = $stmtProducts->fetchAll();

if (is_post()) {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/stock/adjustment.php'); }
    $productId = (int)($_POST['product_id'] ?? 0);
    $newStock = (int)($_POST['new_stock'] ?? -1);
    $note = trim($_POST['note'] ?? '');
    if ($productId <= 0 || $newStock < 0) { flash('error','Produk dan stok akhir wajib diisi dengan benar.'); legacy_redirect('admin/stock/adjustment.php'); }
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT * FROM products WHERE id=? FOR UPDATE");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) { throw new Exception('Produk tidak ditemukan.'); }
        $branchSync = stock_sync_set_active_branch($db, $productId, $newStock, (int)($product['minimum_stock'] ?? 0));
        $before = (int)$branchSync['before'];
        $qtyDiff = abs($newStock - $before);
        stock_sync_recalculate_product_master($db, $productId);
        $db->prepare("INSERT INTO stock_movements (product_id,user_id,type,qty,stock_before,stock_after,reference_type,note) VALUES (?,?,?,?,?,?,?,?)")->execute([$productId, Auth::id(), 'adjustment', $qtyDiff, $before, $newStock, 'manual_adjustment', $note ?: 'Adjustment stok manual']);
        Auth::log('stock_adjustment', 'Adjustment stok '.$product['name'].' dari '.$before.' ke '.$newStock);
        $db->commit();
        flash('success','Adjustment stok berhasil disimpan.'); legacy_redirect('admin/stock/index.php');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error','Gagal adjustment stok: ' . $e->getMessage()); legacy_redirect('admin/stock/adjustment.php');
    }
}
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link href="<?= legacy_base_url('assets/css/stock.css?v=' . time()); ?>" rel="stylesheet">
<main class="admin-main">
<header class="admin-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Adjustment Stok</h1><p>Set stok akhir produk sesuai stok fisik di toko.</p></div></header>
<section class="premium-card mt-4 stock-form-card"><form method="POST" class="premium-form"><?= CSRF::input(); ?><div class="form-grid"><div class="form-group"><label>Produk</label><select name="product_id" required><option value="">Pilih produk</option><?php foreach($products as $p): ?><option value="<?= e($p['id']); ?>" <?= $productId===(int)$p['id']?'selected':''; ?>><?= e($p['name']); ?> — <?= e($p['product_code']); ?> | Stok sekarang <?= e($p['stock']); ?> <?= e($p['unit']); ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Stok Akhir</label><input type="number" min="0" name="new_stock" required placeholder="Contoh: 120"></div><div class="form-group form-wide"><label>Catatan</label><textarea name="note" rows="4" placeholder="Contoh: Penyesuaian setelah opname stok"></textarea></div></div><div class="form-actions"><a class="btn-soft" href="<?= legacy_base_url('admin/stock/index.php'); ?>">Batal</a><button class="btn-premium"><i class="bi bi-sliders"></i> Simpan Adjustment</button></div></form></section>
</main><?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
