<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin','owner']);
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/../warehouse/_helpers.php';
$pageTitle = 'Pembelian Supplier';
$db = Database::connect();
branch_install($db);
purchases_install($db);
warehouse_install($db);

$rows = $db->query("SELECT p.*, s.name AS supplier_name, u.name AS user_name, w.name AS warehouse_name, b.name AS branch_name,
    (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id=p.id) AS item_count
    FROM purchases p
    LEFT JOIN suppliers s ON s.id=p.supplier_id
    LEFT JOIN users u ON u.id=p.user_id
    LEFT JOIN warehouses w ON w.id=p.warehouse_id
    LEFT JOIN branches b ON b.id=p.branch_id
    ORDER BY p.id DESC")->fetchAll();

$totalPurchase = $db->query("SELECT COALESCE(SUM(grand_total),0) total FROM purchases WHERE status='completed'")->fetch()['total'] ?? 0;
$totalDebt = $db->query("SELECT COALESCE(SUM(debt_amount),0) total FROM purchases WHERE status='completed' AND payment_status='debt'")->fetch()['total'] ?? 0;
$totalRows = count($rows);

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/purchases.css?v=' . time()); ?>">

<main class="admin-main purchases-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Pembelian Supplier</h1>
            <p>Buat pembelian barang, tambah stok otomatis, catat modal, lunas, atau hutang supplier.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/supplier-returns/index.php'); ?>" class="btn-premium outline"><i class="bi bi-arrow-return-left"></i> Retur Supplier</a>
            <a href="<?= legacy_base_url('admin/purchases/create.php'); ?>" class="btn-premium"><i class="bi bi-plus-circle"></i> Buat Pembelian</a>
        </div>
    </header>

    <section class="purchase-summary-grid">
        <div class="purchase-mini-card"><div class="purchase-mini-icon"><i class="bi bi-bag-check"></i></div><div><span>Total Pembelian</span><h3><?= e($totalRows); ?></h3><p>Invoice pembelian</p></div></div>
        <div class="purchase-mini-card"><div class="purchase-mini-icon green"><i class="bi bi-cash-stack"></i></div><div><span>Nilai Pembelian</span><h3><?= purchases_money($totalPurchase); ?></h3><p>Semua pembelian selesai</p></div></div>
        <div class="purchase-mini-card"><div class="purchase-mini-icon orange"><i class="bi bi-journal-minus"></i></div><div><span>Hutang Supplier</span><h3><?= purchases_money($totalDebt); ?></h3><p>Pembelian belum lunas</p></div></div>
    </section>

    <section class="premium-card mt-4">
        <div class="card-head">
            <div>
                <h3>Data Pembelian</h3>
                <p>Riwayat invoice pembelian supplier, status pembayaran, total modal, dan aksi cetak.</p>
            </div>
            <span class="badge-soft danger"><?= e($totalRows); ?> Pembelian</span>
        </div>

        <div class="dt-hint-card">
            <div class="dt-hint-icon"><i class="bi bi-bag-check"></i></div>
            <div class="dt-hint-content">
                <span class="dt-hint-title">Pencarian Pembelian</span>
                <p class="dt-hint-text">Cari pembelian berdasarkan nomor PO, supplier, tanggal, status pembayaran, total, atau admin pembuat.</p>
            </div>
        </div>

        <div class="table-premium-card dt-premium-wrap">
            <table class="premium-table js-data-table nowrap" data-title="purchases">
                <thead>
                    <tr>
                        <th>No. Pembelian</th>
                        <th>Tanggal</th>
                        <th>Supplier</th>
                        <th>Tujuan Stok</th>
                        <th>Item</th>
                        <th>Total</th>
                        <th>Pembayaran</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><div class="purchase-main-cell"><b><?= e($row['purchase_number']); ?></b><small>Invoice: <?= e($row['supplier_invoice'] ?: '-'); ?></small></div></td>
                        <td><?= e(date('d/m/Y', strtotime($row['purchase_date']))); ?><br><small class="table-subtext"><?= e($row['user_name'] ?: '-'); ?></small></td>
                        <td><?= e($row['supplier_name'] ?: 'Supplier Umum'); ?></td>
                        <td><?php if (($row['stock_destination_type'] ?? 'branch') === 'warehouse'): ?><span class="badge-soft warning"><i class="bi bi-buildings"></i> <?= e($row['warehouse_name'] ?: 'Gudang'); ?></span><?php else: ?><span class="badge-soft"><i class="bi bi-shop"></i> <?= e($row['branch_name'] ?: 'Cabang Utama'); ?></span><?php endif; ?></td>
                        <td><span class="badge-soft"><?= e($row['item_count']); ?> item</span></td>
                        <td><b><?= purchases_money($row['grand_total']); ?></b></td>
                        <td><?= $row['payment_status']==='paid' ? '<span class="badge-soft success">Lunas</span>' : '<span class="badge-soft warning">Hutang</span>'; ?><br><small><?= purchases_money($row['paid_amount']); ?> dibayar</small></td>
                        <td><?= $row['status']==='completed' ? '<span class="badge-soft success">Selesai</span>' : '<span class="badge-soft danger">Batal</span>'; ?></td>
                        <td class="text-end table-actions">
                            <a class="btn-icon" title="Detail" href="<?= legacy_base_url('admin/purchases/detail.php?id=' . $row['id']); ?>"><i class="bi bi-eye"></i></a>
                            <a class="btn-icon" title="Cetak" target="_blank" href="<?= legacy_base_url('admin/purchases/print.php?id=' . $row['id']); ?>"><i class="bi bi-printer"></i></a>
                            <?php if ($row['status'] === 'completed'): ?>
                                <a class="btn-icon delete btn-delete" title="Batalkan" href="<?= legacy_base_url('admin/purchases/cancel.php?id=' . $row['id']); ?>"><i class="bi bi-x-circle"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
