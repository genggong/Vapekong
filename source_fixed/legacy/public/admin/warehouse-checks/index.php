<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
whqc_require_view();
$pageTitle = 'Cek Gudang & QC';
$db = Database::connect();
whqc_ensure_tables($db);

$status = $_GET['status'] ?? '';
$q = trim((string)($_GET['q'] ?? ''));
$rows = whqc_fetch_rows($db, ['status' => $status, 'q' => $q]);
$summary = whqc_summary($db);

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/warehouse-qc.css?v=' . time()); ?>">
<main class="admin-main whqc-page">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Cek Gudang & Quality Control</h1>
            <p>Menu khusus cek barang gudang setelah Stock Opname, dengan hak akses Operator Gudang, Admin Gudang, dan Quality Control.</p>
        </div>
    </header>

    <section class="whqc-grid">
        <div class="whqc-stat"><div class="icon"><i class="bi bi-boxes"></i></div><span>Total Item SO</span><h3><?= e($summary['total']); ?></h3></div>
        <div class="whqc-stat"><div class="icon"><i class="bi bi-person-check"></i></div><span>Operator Check</span><h3><?= e($summary['operator']); ?></h3></div>
        <div class="whqc-stat"><div class="icon"><i class="bi bi-shield-check"></i></div><span>Admin Gudang OK</span><h3><?= e($summary['admin']); ?></h3></div>
        <div class="whqc-stat"><div class="icon"><i class="bi bi-patch-check"></i></div><span>QC Approved</span><h3><?= e($summary['approved']); ?></h3><small>Reject: <?= e($summary['rejected']); ?></small></div>
    </section>

    <section class="whqc-card">
        <div class="whqc-head">
            <div>
                <h3>List Barang Sudah SO</h3>
                <p>Data diambil dari sesi Stock Opname yang sudah final. Tidak mengubah stok, hanya checklist dan verifikasi gudang/QC.</p>
            </div>
        </div>
        <form class="whqc-filter" method="GET">
            <input name="q" value="<?= e($q); ?>" placeholder="Cari kode SO / produk / barcode">
            <select name="status">
                <option value="">Semua Status</option>
                <option value="pending" <?= $status==='pending'?'selected':''; ?>>Pending</option>
                <option value="operator_checked" <?= $status==='operator_checked'?'selected':''; ?>>Operator Checked</option>
                <option value="admin_checked" <?= $status==='admin_checked'?'selected':''; ?>>Admin Gudang OK</option>
                <option value="approved" <?= $status==='approved'?'selected':''; ?>>QC Approved</option>
                <option value="rejected" <?= $status==='rejected'?'selected':''; ?>>QC Rejected</option>
            </select>
            <button><i class="bi bi-search"></i> Filter</button>
        </form>

        <div class="whqc-table-wrap">
            <table class="premium-table whqc-table dataTable">
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th>SO</th>
                        <th>Stok</th>
                        <th>Status</th>
                        <th>Petugas</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <div class="whqc-product">
                                <span class="whqc-img">
                                    <?php if(!empty($row['image'])): ?><img loading="lazy" src="<?= legacy_base_url($row['image']); ?>" alt="produk"><?php else: ?><i class="bi bi-box-seam"></i><?php endif; ?>
                                </span>
                                <div>
                                    <b><?= e($row['product_name']); ?></b>
                                    <small><?= e($row['product_code'] ?: '-'); ?> • <?= e($row['unit'] ?: 'pcs'); ?></small>
                                </div>
                            </div>
                        </td>
                        <td><b><?= e($row['opname_code']); ?></b><br><small><?= e($row['opname_title']); ?></small></td>
                        <td>
                            <b>Sistem:</b> <?= e($row['system_stock']); ?><br>
                            <b>Fisik:</b> <?= $row['physical_stock'] !== null ? e($row['physical_stock']) : '-'; ?><br>
                            <small>Selisih: <?= e($row['difference']); ?></small>
                        </td>
                        <td><?= whqc_status_badge($row); ?></td>
                        <td>
                            <small>OP: <?= e($row['operator_name'] ?: '-'); ?></small><br>
                            <small>Admin: <?= e($row['admin_name'] ?: '-'); ?></small><br>
                            <small>QC: <?= e($row['qc_name'] ?: '-'); ?></small>
                        </td>
                        <td class="table-actions">
                            <a class="whqc-btn soft" href="<?= legacy_base_url('admin/warehouse-checks/detail.php?item_id=' . (int)$row['item_id']); ?>"><i class="bi bi-eye"></i> Cek</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="6" class="text-center py-4">Belum ada data barang SO yang cocok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
