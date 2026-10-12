<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/../branches/_helpers.php';
$pageTitle = 'Laporan';
$db = Database::connect();
branch_install($db);
$currentBranch = branch_current($db);
$currentBranchId = (int) $currentBranch['id'];

$today = date('Y-m-d');

function report_scalar(PDO $db, string $sql, array $params = []) {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: 0;
    } catch (Throwable $e) {
        return 0;
    }
}

$salesToday = report_scalar($db, "SELECT COALESCE(SUM(grand_total),0) FROM transactions WHERE DATE(created_at)=? AND status='success' AND branch_id=$currentBranchId", [$today]);
$countToday = report_scalar($db, "SELECT COUNT(*) FROM transactions WHERE DATE(created_at)=? AND branch_id=$currentBranchId", [$today]);
$grossProfit = report_scalar($db, "SELECT COALESCE(SUM(ti.subtotal - (ti.purchase_price * ti.qty)),0) FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id WHERE DATE(t.created_at)=? AND t.status='success' AND t.branch_id=$currentBranchId", [$today]);
$lowStock = report_scalar($db, "SELECT COUNT(*) FROM product_branch_stocks WHERE branch_id=$currentBranchId AND stock <= minimum_stock");

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/reports.css?v=' . time()); ?>">

<main class="admin-main reports-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Laporan</h1>
            <p>Ringkasan cabang aktif: <b><?= e($currentBranch['name'] ?? 'Cabang'); ?></b>.</p>
        </div>
        <div class="topbar-actions">
            <button onclick="window.print()" class="report-btn primary"><i class="bi bi-printer"></i> Print</button>
        </div>
    </header>

    <section class="report-hero">
        <div class="report-hero-badge"><i class="bi bi-graph-up-arrow"></i> Pusat Laporan UMKM</div>
        <h2>Data toko siap dipantau, dicetak, dan dianalisis.</h2>
        <p>Pilih jenis laporan untuk melihat data operasional toko lebih detail berdasarkan periode tertentu.</p>
    </section>

    <section class="report-grid">
        <div class="report-card">
            <div class="icon"><i class="bi bi-cash-stack"></i></div>
            <span>Omzet Hari Ini</span>
            <h3><?= rupiah($salesToday); ?></h3>
            <p>Total transaksi sukses hari ini</p>
        </div>
        <div class="report-card">
            <div class="icon"><i class="bi bi-receipt"></i></div>
            <span>Invoice Hari Ini</span>
            <h3><?= e($countToday); ?></h3>
            <p>Jumlah transaksi tercatat</p>
        </div>
        <div class="report-card">
            <div class="icon"><i class="bi bi-graph-up"></i></div>
            <span>Laba Kotor Hari Ini</span>
            <h3><?= rupiah($grossProfit); ?></h3>
            <p>Estimasi penjualan - modal</p>
        </div>
        <div class="report-card">
            <div class="icon"><i class="bi bi-exclamation-triangle"></i></div>
            <span>Stok Menipis</span>
            <h3><?= e($lowStock); ?></h3>
            <p>Produk perlu restock</p>
        </div>
    </section>

    <section class="report-panel">
        <div class="report-panel-head">
            <div>
                <h3>Pusat Laporan</h3>
                <p>Pilih laporan yang ingin dibuka.</p>
            </div>
        </div>

        <div class="report-menu-grid">
            <a class="report-menu-card" href="<?= legacy_base_url('admin/reports/sales.php'); ?>">
                <div class="report-menu-icon"><i class="bi bi-receipt-cutoff"></i></div>
                <h3>Penjualan</h3>
                <p>Daftar invoice, kasir, metode pembayaran, total, dan status transaksi.</p>
            </a>

            <a class="report-menu-card" href="<?= legacy_base_url('admin/reports/profit.php'); ?>">
                <div class="report-menu-icon"><i class="bi bi-graph-up-arrow"></i></div>
                <h3>Laba Rugi</h3>
                <p>Estimasi omzet, modal, dan laba kotor berdasarkan produk terjual.</p>
            </a>

            <a class="report-menu-card" href="<?= legacy_base_url('admin/reports/stock.php'); ?>">
                <div class="report-menu-icon"><i class="bi bi-boxes"></i></div>
                <h3>Stok Barang</h3>
                <p>Lihat stok akhir, stok minimum, status aman atau perlu restock.</p>
            </a>

            <a class="report-menu-card" href="<?= legacy_base_url('admin/reports/product.php'); ?>">
                <div class="report-menu-icon"><i class="bi bi-star"></i></div>
                <h3>Produk Terlaris</h3>
                <p>Produk paling banyak terjual berdasarkan periode laporan.</p>
            </a>

            <a class="report-menu-card" href="<?= legacy_base_url('admin/reports/payment.php'); ?>">
                <div class="report-menu-icon"><i class="bi bi-credit-card"></i></div>
                <h3>Pembayaran</h3>
                <p>Ringkasan metode pembayaran cash, transfer, QRIS, dan lainnya.</p>
            </a>

            <a class="report-menu-card" href="<?= legacy_base_url('admin/reports/cashier.php'); ?>">
                <div class="report-menu-icon"><i class="bi bi-person-badge"></i></div>
                <h3>Kasir</h3>
                <p>Performa transaksi dan penjualan per akun kasir.</p>
            </a>

            <a class="report-menu-card" href="<?= legacy_base_url('admin/finance/report.php'); ?>">
                <div class="report-menu-icon"><i class="bi bi-file-earmark-spreadsheet"></i></div>
                <h3>Keuangan Excel / PDF</h3>
                <p>Filter pemasukan dan pengeluaran, lalu unduh laporan Excel atau PDF.</p>
            </a>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
