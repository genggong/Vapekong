<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$pageTitle = 'Laporan Pembayaran';
$db = Database::connect();

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');

$stmt = $db->prepare("
    SELECT 
        payment_method,
        COUNT(*) AS total_trx,
        COALESCE(SUM(grand_total),0) AS total_sales
    FROM transactions
    WHERE DATE(created_at) BETWEEN ? AND ? AND status='success'
    GROUP BY payment_method
    ORDER BY total_sales DESC
");
$stmt->execute([$start, $end]);
$rows = $stmt->fetchAll();

$totalSales = array_sum(array_map(fn($r) => (float)$r['total_sales'], $rows));
$totalTrx = array_sum(array_map(fn($r) => (int)$r['total_trx'], $rows));

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/reports.css?v=' . time()); ?>">

<main class="admin-main reports-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Laporan Pembayaran</h1>
            <p>Ringkasan transaksi berdasarkan metode pembayaran.</p>
        </div>
        <div class="topbar-actions"><button onclick="window.print()" class="report-btn primary"><i class="bi bi-printer"></i> Print</button></div>
    </header>

    <section class="report-panel mt-4">
        <form class="report-filter" method="GET">
        <div>
            <label>Dari Tanggal</label>
            <input type="date" name="start" value="<?= e($start); ?>">
        </div>
        <div>
            <label>Sampai Tanggal</label>
            <input type="date" name="end" value="<?= e($end); ?>">
        </div>
        <button class="report-btn primary" type="submit"><i class="bi bi-funnel"></i> Filter</button>
        <a class="report-btn soft" href="<?= legacy_base_url('admin/reports/index.php'); ?>">Kembali</a>
    </form>

        <div class="report-grid">
            <div class="report-card"><div class="icon"><i class="bi bi-credit-card"></i></div><span>Metode Aktif</span><h3><?= count($rows); ?></h3><p>Metode pembayaran</p></div>
            <div class="report-card"><div class="icon"><i class="bi bi-receipt"></i></div><span>Total Transaksi</span><h3><?= e($totalTrx); ?></h3><p>Invoice sukses</p></div>
            <div class="report-card"><div class="icon"><i class="bi bi-cash-stack"></i></div><span>Total Omzet</span><h3><?= rupiah($totalSales); ?></h3><p>Periode laporan</p></div>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead><tr><th>Metode</th><th>Jumlah Transaksi</th><th>Total Penjualan</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><span class="report-badge blue"><?= e(payment_method_label($r['payment_method'] ?? '-')); ?></span></td>
                            <td><b><?= e($r['total_trx']); ?></b></td>
                            <td><b><?= rupiah($r['total_sales']); ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="3" class="report-empty">Belum ada data pembayaran.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
