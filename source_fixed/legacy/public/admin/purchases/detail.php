<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin','owner']);
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/../warehouse/_helpers.php';
$pageTitle = 'Detail Pembelian';
$db = Database::connect();
branch_install($db);
purchases_install($db);
warehouse_install($db);
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT p.*, s.name supplier_name, s.phone supplier_phone, s.address supplier_address, u.name user_name, w.name warehouse_name, b.name branch_name FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id LEFT JOIN users u ON u.id=p.user_id LEFT JOIN warehouses w ON w.id=p.warehouse_id LEFT JOIN branches b ON b.id=p.branch_id WHERE p.id=? LIMIT 1");
$stmt->execute([$id]);
$purchase = $stmt->fetch();
if (!$purchase) { flash('error','Data pembelian tidak ditemukan.'); legacy_redirect('admin/purchases/index.php'); }
$stmt = $db->prepare("SELECT * FROM purchase_items WHERE purchase_id=? ORDER BY id ASC");
$stmt->execute([$id]);
$items = $stmt->fetchAll();
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/purchases.css?v=' . time()); ?>">
<main class="admin-main purchases-wrap">
<header class="admin-topbar page-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><h1>Detail Pembelian</h1><p><?= e($purchase['purchase_number']); ?> dari <?= e($purchase['supplier_name'] ?: 'Supplier Umum'); ?>.</p></div>
    <div class="topbar-actions"><a href="<?= legacy_base_url('admin/purchases/print.php?id='.$purchase['id']); ?>" target="_blank" class="btn-premium"><i class="bi bi-printer"></i> Cetak</a></div>
</header>

<section class="purchase-detail-grid">
    <div class="premium-card purchase-detail-card"><span>No Pembelian</span><h3><?= e($purchase['purchase_number']); ?></h3><p><?= e(date('d/m/Y', strtotime($purchase['purchase_date']))); ?></p></div>
    <div class="premium-card purchase-detail-card"><span>Supplier</span><h3><?= e($purchase['supplier_name'] ?: 'Supplier Umum'); ?></h3><p><?= e($purchase['supplier_phone'] ?: '-'); ?></p></div>
    <div class="premium-card purchase-detail-card"><span>Total</span><h3><?= purchases_money($purchase['grand_total']); ?></h3><p><?= purchases_status_label($purchase['payment_status']); ?></p></div>
    <div class="premium-card purchase-detail-card"><span>Tujuan Stok</span><h3><?= e(($purchase['stock_destination_type'] ?? 'branch') === 'warehouse' ? ($purchase['warehouse_name'] ?: 'Gudang') : ($purchase['branch_name'] ?: 'Cabang Utama')); ?></h3><p><?= ($purchase['stock_destination_type'] ?? 'branch') === 'warehouse' ? 'Stok Gudang' : 'Stok Cabang'; ?></p></div>
</section>

<section class="premium-card mt-4">
    <div class="card-head"><div><h3>Item Pembelian</h3><p>Daftar barang yang masuk stok dari pembelian ini.</p></div></div>
    <div class="table-premium-card dt-premium-wrap">
        <table class="premium-table js-data-table nowrap">
            <thead><tr><th>Produk</th><th>Kode</th><th>Qty</th><th>Harga Beli</th><th>Total</th><th>Stok</th></tr></thead>
            <tbody>
            <?php foreach($items as $it): ?>
                <tr><td><b><?= e($it['product_name']); ?><?= !empty($it['variant_name']) ? ' - '.e($it['variant_name']) : ''; ?></b><br><small><?= e($it['variant_sku'] ?: $it['unit']); ?></small></td><td><?= e($it['product_code']); ?></td><td><?= e($it['qty']); ?></td><td><?= purchases_money($it['purchase_price']); ?></td><td><b><?= purchases_money($it['line_total']); ?></b></td><td><?= e($it['stock_before']); ?> → <?= e($it['stock_after']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="premium-card mt-4 purchase-total-detail">
    <div><span>Subtotal</span><b><?= purchases_money($purchase['subtotal']); ?></b></div>
    <div><span>Diskon</span><b><?= purchases_money($purchase['discount']); ?></b></div>
    <div><span>Ongkir / Biaya</span><b><?= purchases_money($purchase['shipping_cost']); ?></b></div>
    <div class="grand"><span>Grand Total</span><b><?= purchases_money($purchase['grand_total']); ?></b></div>
    <div><span>Dibayar</span><b><?= purchases_money($purchase['paid_amount']); ?></b></div>
    <div><span>Sisa Hutang</span><b><?= purchases_money($purchase['debt_amount']); ?></b></div>
</section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
