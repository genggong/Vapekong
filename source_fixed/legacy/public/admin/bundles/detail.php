<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../branches/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Detail Paket Produk';
$db = Database::connect();
bundle_install($db);
branch_install($db);
$currentBranch = branch_current($db);
$currentBranchId = (int)$currentBranch['id'];
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM product_bundles WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$bundle = $stmt->fetch();
if (!$bundle) { flash('error','Paket tidak ditemukan.'); legacy_redirect('admin/bundles/index.php'); }
$items = bundle_items($db, $id);
$modal = bundle_cost($db, $id);
$available = bundle_available_stock($db, $id, $currentBranchId);
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/bundles.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/sweetalert-theme.css?v=' . time()); ?>">
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Detail Paket Produk</h1><p><?= e($bundle['code']); ?> • <?= e($bundle['name']); ?></p></div><div class="topbar-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/bundles/index.php'); ?>">Kembali</a><a class="system-btn primary" href="<?= legacy_base_url('admin/bundles/edit.php?id=' . $id); ?>">Edit</a></div></header>
<section class="system-form-card">
<div class="bundle-detail-head"><div class="bundle-detail-img"><?php if(!empty($bundle['image']) && bundle_image_exists($bundle['image'])): ?><img loading="lazy" src="<?= bundle_asset_url($bundle['image']); ?>"><?php else: ?><i class="bi bi-box2-heart"></i><?php endif; ?></div><div><h2><?= e($bundle['name']); ?></h2><p><?= e($bundle['note'] ?: '-'); ?></p><div class="bundle-pill-row"><span class="badge-soft danger">Harga <?= rupiah($bundle['selling_price']); ?></span><span class="badge-soft">Modal <?= rupiah($modal); ?></span><span class="badge-soft">Laba <?= rupiah((float)$bundle['selling_price'] - $modal); ?></span><span class="badge-soft">Bisa dibuat <?= e($available); ?> paket di <?= e($currentBranch['name']); ?></span></div></div></div>
</section>
<section class="system-form-card"><div class="system-card-head"><div><h3>Isi Paket</h3><p>Stok komponen otomatis berkurang saat paket dijual di POS.</p></div></div><div class="table-responsive"><table class="premium-table"><thead><tr><th>Produk</th><th>Qty Paket</th><th>Modal Satuan</th><th>Total Modal</th><th>Stok Cabang</th></tr></thead><tbody><?php foreach($items as $it): $stock=(int)$it['stock']; if(bundle_table_exists($db,'product_branch_stocks')){ $s=$db->prepare("SELECT stock FROM product_branch_stocks WHERE branch_id=? AND product_id=?"); $s->execute([$currentBranchId,$it['product_id']]); $v=$s->fetchColumn(); if($v!==false)$stock=(int)$v; } ?><tr><td><b><?= e($it['product_name']); ?></b><br><small><?= e($it['product_code']); ?> • <?= e($it['unit']); ?></small></td><td><?= e($it['qty']); ?></td><td><?= rupiah($it['purchase_price']); ?></td><td><?= rupiah((float)$it['purchase_price']*(int)$it['qty']); ?></td><td><?= e($stock); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>