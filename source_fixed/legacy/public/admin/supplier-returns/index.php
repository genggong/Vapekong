<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
if (file_exists(__DIR__ . '/../branches/_helpers.php')) require_once __DIR__ . '/../branches/_helpers.php';
Auth::requireRole(['super_admin','owner','admin']);
$pageTitle = 'Retur Supplier';
$db = Database::connect();
supplier_returns_install($db);
if (function_exists('branch_install')) branch_install($db);

$rows = $db->query("SELECT sr.*, s.name AS supplier_name, u.name AS user_name" .
    (supplier_return_table_exists($db, 'branches') ? ", b.name AS branch_name" : ", NULL AS branch_name") .
    " FROM supplier_returns sr
    LEFT JOIN suppliers s ON s.id=sr.supplier_id
    LEFT JOIN users u ON u.id=sr.user_id " .
    (supplier_return_table_exists($db, 'branches') ? "LEFT JOIN branches b ON b.id=sr.branch_id " : "") .
    " ORDER BY sr.id DESC")->fetchAll();

$totalValue = array_sum(array_map(fn($r)=>(float)$r['total_value'], $rows));
$totalQty = array_sum(array_map(fn($r)=>(int)$r['total_qty'], $rows));
$totalCompleted = count(array_filter($rows, fn($r)=>($r['status'] ?? '') === 'completed'));

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/supplier-returns.css?v=' . time()); ?>">
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title">
        <h1>Retur Supplier</h1>
        <p>Catat barang rusak, expired, salah kirim, atau barang yang dikembalikan ke supplier.</p>
    </div>
    <div class="topbar-actions">
        <a href="<?= legacy_base_url('admin/supplier-returns/create.php'); ?>" class="system-btn primary"><i class="bi bi-plus-circle"></i> Buat Retur</a>
    </div>
</header>

<section class="sr-stats">
    <div class="sr-stat"><div class="sr-stat-icon"><i class="bi bi-arrow-return-left"></i></div><span>Total Retur</span><h3><?= e(count($rows)); ?></h3></div>
    <div class="sr-stat"><div class="sr-stat-icon"><i class="bi bi-check-circle"></i></div><span>Selesai</span><h3><?= e($totalCompleted); ?></h3></div>
    <div class="sr-stat"><div class="sr-stat-icon"><i class="bi bi-box-seam"></i></div><span>Total Qty</span><h3><?= e($totalQty); ?></h3></div>
    <div class="sr-stat"><div class="sr-stat-icon"><i class="bi bi-cash-stack"></i></div><span>Nilai Modal</span><h3><?= rupiah($totalValue); ?></h3></div>
</section>

<section class="system-form-card">
<div class="table-responsive">
<table class="premium-table">
<thead><tr><th>No. Retur</th><th>Supplier</th><th>Tanggal</th><th>Qty</th><th>Nilai</th><th>Alasan</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
<tbody>
<?php foreach($rows as $r): ?>
<tr>
<td><b><?= e($r['return_number']); ?></b><br><small><?= e($r['branch_name'] ?: '-'); ?> • <?= e($r['user_name'] ?: '-'); ?></small></td>
<td><?= e($r['supplier_name'] ?: '-'); ?></td>
<td><?= e(date('d/m/Y H:i', strtotime($r['return_date']))); ?></td>
<td><b><?= e($r['total_qty']); ?></b> item</td>
<td><?= rupiah($r['total_value']); ?></td>
<td><?= e(supplier_return_reason_label($r['reason'])); ?></td>
<td><span class="sr-status <?= e($r['status']); ?>"><?= e(strtoupper($r['status'])); ?></span></td>
<td class="text-end table-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/supplier-returns/detail.php?id=' . $r['id']); ?>">Detail</a></td>
</tr>
<?php endforeach; if(!$rows): ?><tr><td colspan="8" class="text-center">Belum ada retur supplier.</td></tr><?php endif; ?>
</tbody>
</table>
</div>
</section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>