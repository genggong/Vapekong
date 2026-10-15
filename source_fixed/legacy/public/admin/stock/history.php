<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Riwayat Stok';
$db = Database::connect();
$rows = $db->query("SELECT sm.*, p.name AS product_name, p.product_code, p.unit, u.name AS user_name FROM stock_movements sm LEFT JOIN products p ON p.id=sm.product_id LEFT JOIN users u ON u.id=sm.user_id ORDER BY sm.id DESC LIMIT 1000")->fetchAll();
function stock_type_label($type) {
    $map = ['stock_in'=>'Stok Masuk','stock_out'=>'Stok Keluar','sale'=>'Penjualan','refund'=>'Refund','adjustment'=>'Adjustment','damaged'=>'Rusak/Hilang'];
    return $map[$type] ?? $type;
}
function stock_type_badge($type) {
    if (in_array($type, ['stock_in','refund'], true)) return 'success';
    if (in_array($type, ['stock_out','sale','damaged'], true)) return 'danger';
    return 'warning';
}
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link href="<?= legacy_base_url('assets/css/stock.css?v=' . time()); ?>" rel="stylesheet">
<main class="admin-main">
  <header class="admin-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Riwayat Stok</h1><p>Semua pergerakan stok: penjualan, stok masuk, stok keluar, adjustment, dan refund.</p></div><div class="topbar-actions"><a href="<?= legacy_base_url('admin/stock/index.php'); ?>" class="btn-soft"><i class="bi bi-arrow-left"></i> Stok</a></div></header>
  <section class="premium-card mt-4 stock-card"><div class="card-head"><div><h3>Log Pergerakan Stok</h3><p>Data terbaru ditampilkan paling atas.</p></div><span class="badge-soft danger"><?= count($rows); ?> Log</span></div>
  <div class="table-premium-card dt-premium-wrap"><table class="premium-table js-data-table nowrap" data-title="riwayat-stok"><thead><tr><th>Tanggal</th><th>Produk</th><th>Tipe</th><th>Qty</th><th>Sebelum</th><th>Sesudah</th><th>User</th><th>Catatan</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))); ?></td><td><b><?= e($r['product_name'] ?: 'Produk dihapus'); ?></b><br><small class="table-subtext"><?= e($r['product_code'] ?: '-'); ?></small></td><td><span class="badge-soft <?= stock_type_badge($r['type']); ?>"><?= e(stock_type_label($r['type'])); ?></span></td><td><strong><?= e($r['qty']); ?> <?= e($r['unit'] ?: ''); ?></strong></td><td><?= e($r['stock_before']); ?></td><td><?= e($r['stock_after']); ?></td><td><?= e($r['user_name'] ?: '-'); ?></td><td><?= e($r['note'] ?: '-'); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</main><?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
