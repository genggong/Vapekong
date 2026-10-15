<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/../warehouse/_helpers.php';
warehouse_require_request_access();
$pageTitle = 'Request Stock';
$db = Database::connect();
branch_install($db);
warehouse_install($db);
$currentBranch = branch_current($db);
$currentBranchId = (int)$currentBranch['id'];
$isManager = warehouse_can_manage();

$where = '';
$params = [];
if (!$isManager) {
    $where = 'WHERE r.branch_id=?';
    $params[] = $currentBranchId;
}
$stmt = $db->prepare("SELECT r.*,b.name branch_name,b.code branch_code,w.name warehouse_name,u.name requester_name,
    (SELECT COUNT(*) FROM branch_stock_request_items i WHERE i.request_id=r.id) item_count
    FROM branch_stock_requests r
    LEFT JOIN branches b ON b.id=r.branch_id
    LEFT JOIN warehouses w ON w.id=r.warehouse_id
    LEFT JOIN users u ON u.id=r.requested_by
    {$where}
    ORDER BY r.id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stats = ['pending'=>0,'approved'=>0,'fulfilled'=>0,'rejected'=>0];
foreach ($rows as $r) if (isset($stats[$r['status']])) $stats[$r['status']]++;

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/warehouse.css?v=' . time()); ?>">
<main class="admin-main warehouse-wrap">
<header class="admin-topbar page-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><h1>Request Stock</h1><p>Cabang meminta stok dari gudang dengan harga modal supplier tanpa margin.</p></div>
    <div class="topbar-actions"><?php if ($isManager): ?><a class="btn-premium outline" href="<?= legacy_base_url('admin/warehouse/index.php'); ?>"><i class="bi bi-buildings"></i> Gudang</a><?php endif; ?><a class="btn-premium" href="<?= legacy_base_url('admin/stock-requests/create.php'); ?>"><i class="bi bi-plus-circle"></i> Buat Request</a></div>
</header>

<section class="request-branch-banner"><span><i class="bi bi-shop"></i></span><div><small>Cabang Aktif</small><h3><?= e($currentBranch['name']); ?></h3><p><?= e($currentBranch['code']); ?><?= $isManager ? ' • Admin dapat melihat semua cabang' : ''; ?></p></div></section>

<section class="warehouse-stats compact">
    <article><span><i class="bi bi-hourglass-split"></i></span><div><small>Menunggu</small><h3><?= $stats['pending']; ?></h3><p>Belum diproses</p></div></article>
    <article><span><i class="bi bi-check2-circle"></i></span><div><small>Disetujui</small><h3><?= $stats['approved']; ?></h3><p>Siap dikirim</p></div></article>
    <article><span><i class="bi bi-truck"></i></span><div><small>Selesai</small><h3><?= $stats['fulfilled']; ?></h3><p>Stok masuk cabang</p></div></article>
    <article><span><i class="bi bi-x-circle"></i></span><div><small>Ditolak</small><h3><?= $stats['rejected']; ?></h3><p>Tidak dipenuhi</p></div></article>
</section>

<section class="premium-card mt-4">
<div class="card-head"><div><h3>Riwayat Request Stock</h3><p>Setiap request menyimpan harga modal saat diminta dan jejak approval gudang.</p></div></div>
<div class="table-premium-card dt-premium-wrap"><table class="premium-table js-data-table nowrap" data-title="stock-requests"><thead><tr><th>No Request</th><th>Tanggal</th><th>Cabang</th><th>Gudang</th><th>Item</th><th>Total Modal</th><th>Status</th><th>Peminta</th><th class="text-end">Aksi</th></tr></thead><tbody>
<?php foreach($rows as $r): ?>
<tr><td><b><?= e($r['request_number']); ?></b></td><td><?= e(date('d/m/Y H:i',strtotime($r['created_at']))); ?></td><td><b><?= e($r['branch_name'] ?: '-'); ?></b><br><small><?= e($r['branch_code'] ?: ''); ?></small></td><td><?= e($r['warehouse_name'] ?: 'Gudang'); ?></td><td><span class="badge-soft"><?= e($r['item_count']); ?> item</span></td><td data-order="<?= e((float)$r['total_cost']); ?>"><b><?= rupiah($r['total_cost']); ?></b></td><td><span class="request-status <?= e(warehouse_request_status_class($r['status'])); ?>"><?= e(warehouse_request_status_label($r['status'])); ?></span></td><td><?= e($r['requester_name'] ?: '-'); ?></td><td class="text-end"><a class="btn-icon" href="<?= legacy_base_url('admin/stock-requests/detail.php?id='.$r['id']); ?>" title="Detail"><i class="bi bi-eye"></i></a></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
