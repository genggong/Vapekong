<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/_sync_helper.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Stok Keluar';
$db = Database::connect();
$productId = (int)($_GET['product_id'] ?? ($_POST['product_id'] ?? 0));
$currentBranchId = stock_sync_current_branch_id($db);
$stmtProducts = $db->prepare("SELECT p.id, p.name, p.product_code, COALESCE(pbs.stock, p.stock) AS stock, p.minimum_stock, p.unit FROM products p LEFT JOIN product_branch_stocks pbs ON pbs.product_id=p.id AND pbs.branch_id=? WHERE p.is_active=1 ORDER BY p.name ASC");
$stmtProducts->execute([$currentBranchId]);
$products = $stmtProducts->fetchAll();

if (is_post()) {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/stock/stock-out.php'); }
    $productId = (int)($_POST['product_id'] ?? 0);
    $qty = (int)($_POST['qty'] ?? 0);
    $reason = trim($_POST['reason'] ?? 'stock_out');
    $note = trim($_POST['note'] ?? '');
    if ($productId <= 0 || $qty <= 0) { flash('error','Produk dan jumlah stok wajib diisi dengan benar.'); legacy_redirect('admin/stock/stock-out.php'); }
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT * FROM products WHERE id=? FOR UPDATE");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) { throw new Exception('Produk tidak ditemukan.'); }
        $before = (int)$product['stock'];
        if ($qty > $before) { throw new Exception('Jumlah keluar melebihi stok saat ini.'); }
        $after = $before - $qty;
        $type = $reason === 'damaged' ? 'damaged' : 'stock_out';
        $db->prepare("UPDATE products SET stock=?, updated_at=NOW() WHERE id=?")->execute([$after, $productId]);
        $db->prepare("INSERT INTO stock_movements (product_id,user_id,type,qty,stock_before,stock_after,reference_type,note) VALUES (?,?,?,?,?,?,?,?)")->execute([$productId, Auth::id(), $type, $qty, $before, $after, 'manual_stock_out', $note ?: 'Stok keluar manual']);
        Auth::log('stock_out', 'Stok keluar '.$product['name'].' sebanyak '.$qty);
        $db->commit();
        flash('success','Stok keluar berhasil disimpan.'); legacy_redirect('admin/stock/index.php');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error','Gagal simpan stok keluar: ' . $e->getMessage()); legacy_redirect('admin/stock/stock-out.php');
    }
}
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link href="<?= legacy_base_url('assets/css/stock.css?v=' . time()); ?>" rel="stylesheet">
<main class="admin-main">
<header class="admin-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Stok Keluar</h1><p>Kurangi stok untuk barang rusak, hilang, retur supplier, atau koreksi manual.</p></div></header>
<section class="premium-card mt-4 stock-form-card"><form method="POST" class="premium-form"><?= CSRF::input(); ?><div class="form-grid"><div class="form-group"><label>Produk</label><select name="product_id" required><option value="">Pilih produk</option><?php foreach($products as $p): ?><option value="<?= e($p['id']); ?>" <?= $productId===(int)$p['id']?'selected':''; ?>><?= e($p['name']); ?> — <?= e($p['product_code']); ?> | Stok <?= e($p['stock']); ?> <?= e($p['unit']); ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Jumlah Keluar</label><input type="number" min="1" name="qty" required placeholder="Contoh: 5"></div><div class="form-group"><label>Alasan</label><select name="reason"><option value="stock_out">Stok keluar manual</option><option value="damaged">Barang rusak/hilang</option></select></div><div class="form-group form-wide"><label>Catatan</label><textarea name="note" rows="4" placeholder="Contoh: Barang rusak / koreksi stok / retur supplier"></textarea></div></div><div class="form-actions"><a class="btn-soft" href="<?= legacy_base_url('admin/stock/index.php'); ?>">Batal</a><button class="btn-premium"><i class="bi bi-box-arrow-up"></i> Simpan Stok Keluar</button></div></form></section>
</main><?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
