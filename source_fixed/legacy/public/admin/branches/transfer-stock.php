<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Transfer Stok Cabang';
$db = Database::connect();
branch_install($db);
if (is_post()) {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/branches/transfer-stock.php'); }
    $from = (int)($_POST['from_branch_id'] ?? 0);
    $to = (int)($_POST['to_branch_id'] ?? 0);
    $productId = (int)($_POST['product_id'] ?? 0);
    $qty = max(1, (int)($_POST['qty'] ?? 0));
    $note = trim($_POST['note'] ?? '');
    if ($from <= 0 || $to <= 0 || $from === $to || $productId <= 0 || $qty <= 0) { flash('error','Cabang asal, tujuan, produk, dan qty wajib valid.'); legacy_redirect('admin/branches/transfer-stock.php'); }
    try {
        $db->beginTransaction();
        $product = $db->prepare("SELECT * FROM products WHERE id=? LIMIT 1 FOR UPDATE");
        $product->execute([$productId]);
        $p = $product->fetch();
        if (!$p) throw new Exception('Produk tidak ditemukan.');
        branch_sync_product($db, $productId);
        $fromStock = branch_stock($db, $from, $productId);
        $toStock = branch_stock($db, $to, $productId);
        if ((int)$fromStock['stock'] < $qty) throw new Exception('Stok cabang asal tidak cukup. Stok tersedia: ' . (int)$fromStock['stock']);
        $beforeFrom = (int)$fromStock['stock']; $afterFrom = $beforeFrom - $qty; $beforeTo = (int)$toStock['stock']; $afterTo = $beforeTo + $qty;
        $db->prepare("UPDATE product_branch_stocks SET stock=?, updated_at=NOW() WHERE branch_id=? AND product_id=?")->execute([$afterFrom,$from,$productId]);
        $db->prepare("UPDATE product_branch_stocks SET stock=?, updated_at=NOW() WHERE branch_id=? AND product_id=?")->execute([$afterTo,$to,$productId]);
        $desc = 'Transfer stok cabang: ' . branch_name($db,$from) . ' ke ' . branch_name($db,$to) . ($note ? ' - ' . $note : '');
        $stmt = $db->prepare("INSERT INTO stock_movements (product_id,user_id,branch_id,type,qty,stock_before,stock_after,reference_type,reference_id,note,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->execute([$productId, Auth::id(), $from, 'adjustment', $qty, $beforeFrom, $afterFrom, 'branch_transfer', $to, $desc]);
        $stmt->execute([$productId, Auth::id(), $to, 'adjustment', $qty, $beforeTo, $afterTo, 'branch_transfer', $from, $desc]);
        Auth::log('branch_transfer_stock',$desc);
        $db->commit();
        flash('success','Transfer stok berhasil.');
        legacy_redirect('admin/branches/transfer-stock.php');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error','Gagal transfer stok: ' . $e->getMessage());
        legacy_redirect('admin/branches/transfer-stock.php');
    }
}
$branches = branch_all($db, true);
$products = $db->query("SELECT id,name,product_code,unit FROM products WHERE is_active=1 ORDER BY name ASC")->fetchAll();
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<main class="admin-main system-wrap"><header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Transfer Stok Cabang</h1><p>Pindahkan stok produk antar cabang.</p></div><div class="topbar-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/branches/index.php'); ?>">Kembali</a></div></header><form method="POST" class="system-form-card"><?= CSRF::input(); ?><div class="system-form-grid"><div class="system-field"><label>Dari Cabang</label><select name="from_branch_id" required><option value="">Pilih</option><?php foreach($branches as $b): ?><option value="<?= e($b['id']); ?>"><?= e($b['name']); ?> (<?= e($b['code']); ?>)</option><?php endforeach; ?></select></div><div class="system-field"><label>Ke Cabang</label><select name="to_branch_id" required><option value="">Pilih</option><?php foreach($branches as $b): ?><option value="<?= e($b['id']); ?>"><?= e($b['name']); ?> (<?= e($b['code']); ?>)</option><?php endforeach; ?></select></div><div class="system-field"><label>Produk</label><select name="product_id" required><option value="">Pilih produk</option><?php foreach($products as $p): ?><option value="<?= e($p['id']); ?>"><?= e($p['name']); ?> — <?= e($p['product_code']); ?> | <?= e($p['unit']); ?></option><?php endforeach; ?></select></div><div class="system-field"><label>Qty Transfer</label><input type="number" min="1" name="qty" value="1" required></div><div class="system-field system-wide"><label>Catatan</label><textarea name="note" rows="3"></textarea></div></div><div class="system-form-actions"><button class="system-btn primary"><i class="bi bi-arrow-left-right"></i> Transfer Stok</button></div></form></main><?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
