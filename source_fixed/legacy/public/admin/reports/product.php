<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$pageTitle = 'Laporan Produk Terlaris';
$db = Database::connect();

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');

$stmt = $db->prepare("
    SELECT 
        ti.product_id,
        ti.product_name,
        SUM(ti.qty) AS qty,
        SUM(ti.subtotal) AS total
    FROM transaction_items ti
    JOIN transactions t ON t.id = ti.transaction_id
    WHERE DATE(t.created_at) BETWEEN ? AND ? AND t.status='success'
    GROUP BY ti.product_id, ti.product_name
    ORDER BY qty DESC
    LIMIT 100
");
$stmt->execute([$start, $end]);
$rows = $stmt->fetchAll();

$totalQty = array_sum(array_map(fn($r) => (int)$r['qty'], $rows));
$totalSales = array_sum(array_map(fn($r) => (float)$r['total'], $rows));

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/reports.css?v=' . time()); ?>">

<main class="admin-main reports-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Produk Terlaris</h1>
            <p>Lihat produk paling laku berdasarkan jumlah terjual dan omzet.</p>
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
            <div class="report-card"><div class="icon"><i class="bi bi-star"></i></div><span>Produk Terjual</span><h3><?= count($rows); ?></h3><p>Jenis produk</p></div>
            <div class="report-card"><div class="icon"><i class="bi bi-bag-check"></i></div><span>Total Qty</span><h3><?= e($totalQty); ?></h3><p>Jumlah unit terjual</p></div>
            <div class="report-card"><div class="icon"><i class="bi bi-cash"></i></div><span>Total Omzet</span><h3><?= rupiah($totalSales); ?></h3><p>Dari produk terlaris</p></div>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead><tr><th>Rank</th><th>Produk</th><th>Qty Terjual</th><th>Total Omzet</th></tr></thead>
                <tbody>
                    <?php $no=1; foreach ($rows as $r): ?>
                        <tr>
                            <td><span class="report-badge orange">#<?= $no++; ?></span></td>
                            <td><b><?= e($r['product_name']); ?></b></td>
                            <td><b><?= e($r['qty']); ?></b></td>
                            <td><b><?= rupiah($r['total']); ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="4" class="report-empty">Belum ada produk terjual.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
