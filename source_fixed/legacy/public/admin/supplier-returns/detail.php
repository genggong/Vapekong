<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
if (file_exists(__DIR__ . '/../branches/_helpers.php')) require_once __DIR__ . '/../branches/_helpers.php';
Auth::requireRole(['super_admin','owner','admin']);
$pageTitle = 'Detail Retur Supplier';
$db = Database::connect();
supplier_returns_install($db);

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT sr.*, s.name AS supplier_name, s.phone AS supplier_phone, u.name AS user_name" .
    (supplier_return_table_exists($db, 'branches') ? ", b.name AS branch_name" : ", NULL AS branch_name") .
    " FROM supplier_returns sr
    LEFT JOIN suppliers s ON s.id=sr.supplier_id
    LEFT JOIN users u ON u.id=sr.user_id " .
    (supplier_return_table_exists($db, 'branches') ? "LEFT JOIN branches b ON b.id=sr.branch_id " : "") .
    " WHERE sr.id=? LIMIT 1");
$stmt->execute([$id]);
$retur = $stmt->fetch();
if (!$retur) { flash('error','Retur supplier tidak ditemukan.'); legacy_redirect('admin/supplier-returns/index.php'); }

$stmt = $db->prepare("SELECT * FROM supplier_return_items WHERE supplier_return_id=? ORDER BY id ASC");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/supplier-returns.css?v=' . time()); ?>">
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><h1>Detail Retur Supplier</h1><p><?= e($retur['return_number']); ?> • <?= e($retur['supplier_name'] ?: '-'); ?></p></div>
    <div class="topbar-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/supplier-returns/index.php'); ?>">Kembali</a><a class="system-btn primary" href="<?= legacy_base_url('admin/supplier-returns/print.php?id=' . $id); ?>" target="_blank"><i class="bi bi-printer"></i> Print</a></div>
</header>

<section class="sr-detail-grid">
    <div class="sr-detail-card"><h3>Ringkasan</h3>
        <div class="sr-line"><span>No. Retur</span><b><?= e($retur['return_number']); ?></b></div>
        <div class="sr-line"><span>Supplier</span><b><?= e($retur['supplier_name'] ?: '-'); ?></b></div>
        <div class="sr-line"><span>Cabang</span><b><?= e($retur['branch_name'] ?: '-'); ?></b></div>
        <div class="sr-line"><span>Tanggal</span><b><?= e(date('d/m/Y H:i', strtotime($retur['return_date']))); ?></b></div>
        <div class="sr-line"><span>Alasan</span><b><?= e(supplier_return_reason_label($retur['reason'])); ?></b></div>
        <div class="sr-line"><span>Total Qty</span><b><?= e($retur['total_qty']); ?></b></div>
        <div class="sr-line"><span>Nilai Modal</span><b><?= rupiah($retur['total_value']); ?></b></div>
        <div class="sr-line"><span>Status</span><b><span class="sr-status <?= e($retur['status']); ?>"><?= e(strtoupper($retur['status'])); ?></span></b></div>
    </div>
    <div class="sr-detail-card"><h3>Catatan</h3><p><?= nl2br(e($retur['note'] ?: '-')); ?></p></div>
</section>

<section class="system-form-card">
<h3>Item Retur</h3>
<div class="table-responsive">
<table class="premium-table">
<thead><tr><th>Produk</th><th>Qty</th><th>Modal</th><th>Subtotal</th><th>Alasan</th><th>Catatan</th></tr></thead>
<tbody>
<?php foreach($items as $it): ?>
<tr><td><b><?= e($it['product_name']); ?></b></td><td><?= e($it['qty']); ?></td><td><?= rupiah($it['unit_cost']); ?></td><td><b><?= rupiah($it['subtotal']); ?></b></td><td><?= e(supplier_return_reason_label($it['reason'])); ?></td><td><?= e($it['note'] ?: '-'); ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>