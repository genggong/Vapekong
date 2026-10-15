<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireLogin();
$pageTitle = 'Retur / Refund';
$db = Database::connect();
sales_returns_install($db);

$returns = $db->query("SELECT sr.*, t.invoice_number, u.name AS user_name, c.name AS customer_name
    FROM sales_returns sr
    LEFT JOIN transactions t ON t.id = sr.transaction_id
    LEFT JOIN users u ON u.id = sr.user_id
    LEFT JOIN customers c ON c.id = sr.customer_id
    ORDER BY sr.id DESC")->fetchAll();

$totalReturns = count($returns);
$totalRefund = 0;
$completed = 0;
$cancelled = 0;
foreach ($returns as $r) {
    if (($r['status'] ?? '') === 'completed') {
        $completed++;
        $totalRefund += (float)$r['total_refund'];
    } else {
        $cancelled++;
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/returns.css?v=' . time()); ?>">

<main class="admin-main returns-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Retur / Refund</h1>
            <p>Kelola barang yang dikembalikan pelanggan, refund uang, dan stok otomatis masuk kembali.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/returns/create.php'); ?>" class="btn-premium"><i class="bi bi-arrow-counterclockwise"></i> Buat Retur</a>
        </div>
    </header>

    <section class="returns-hero">
        <div>
            <span class="return-badge-hero"><i class="bi bi-shield-check"></i> Return Center</span>
            <h2>Retur penjualan lebih aman, stok otomatis rapi.</h2>
            <p>Pilih transaksi, tentukan barang yang dikembalikan, lalu sistem akan membuat bukti retur dan mengembalikan stok jika diperlukan.</p>
        </div>
        <div class="returns-hero-icon"><i class="bi bi-arrow-counterclockwise"></i></div>
    </section>

    <section class="returns-grid">
        <div class="return-card"><div class="icon"><i class="bi bi-receipt"></i></div><span>Total Retur</span><h3><?= e($totalReturns); ?></h3><p>Semua data retur</p></div>
        <div class="return-card"><div class="icon"><i class="bi bi-check-circle"></i></div><span>Selesai</span><h3><?= e($completed); ?></h3><p>Retur selesai</p></div>
        <div class="return-card"><div class="icon"><i class="bi bi-x-circle"></i></div><span>Dibatalkan</span><h3><?= e($cancelled); ?></h3><p>Retur batal</p></div>
        <div class="return-card"><div class="icon"><i class="bi bi-cash-stack"></i></div><span>Total Refund</span><h3><?= rupiah($totalRefund); ?></h3><p>Retur selesai</p></div>
    </section>

    <section class="returns-panel">
        <div class="returns-panel-head">
            <div>
                <h3>Riwayat Retur</h3>
                <p>Lihat detail retur, cetak bukti, atau batalkan retur jika salah input.</p>
            </div>
        </div>

        <div class="dt-hint-card">
            <div class="dt-hint-icon"><i class="bi bi-search-heart"></i></div>
            <div class="dt-hint-content">
                <span class="dt-hint-title">Pencarian Retur</span>
                <p class="dt-hint-text">Cari berdasarkan nomor retur, invoice, pelanggan, kasir, status, tanggal, atau nominal refund.</p>
            </div>
        </div>

        <div class="returns-table-wrap">
            <table class="premium-table js-data-table nowrap return-table" data-title="retur">
                <thead>
                    <tr>
                        <th>No Retur</th>
                        <th>Invoice</th>
                        <th>Tanggal</th>
                        <th>Pelanggan</th>
                        <th>Admin</th>
                        <th>Total Refund</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($returns as $r): ?>
                        <tr>
                            <td><b><?= e($r['return_number']); ?></b><br><small>ID #<?= e($r['id']); ?></small></td>
                            <td><span class="return-badge blue"><?= e($r['invoice_number'] ?? '-'); ?></span></td>
                            <td><?= e(date('d/m/Y H:i', strtotime($r['return_date']))); ?></td>
                            <td><?= e($r['customer_name'] ?? '-'); ?></td>
                            <td><?= e($r['user_name'] ?? '-'); ?></td>
                            <td><b><?= rupiah($r['total_refund']); ?></b></td>
                            <td>
                                <?php if ($r['status'] === 'completed'): ?>
                                    <span class="return-badge green">Selesai</span>
                                <?php else: ?>
                                    <span class="return-badge red">Batal</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="return-actions">
                                    <a class="return-icon-btn" href="<?= legacy_base_url('admin/returns/detail.php?id=' . $r['id']); ?>" title="Detail"><i class="bi bi-eye"></i></a>
                                    <a class="return-icon-btn print" href="<?= legacy_base_url('admin/returns/print.php?id=' . $r['id']); ?>" target="_blank" title="Cetak"><i class="bi bi-printer"></i></a>
                                    <?php if ($r['status'] === 'completed'): ?>
                                        <a class="return-icon-btn danger btn-delete" href="<?= legacy_base_url('admin/returns/cancel.php?id=' . $r['id']); ?>" title="Batalkan"><i class="bi bi-x-lg"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
