<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/../branches/_helpers.php';
$pageTitle = 'Laporan Laba Rugi';
$db = Database::connect();
branch_install($db);
$currentBranch = branch_current($db);
$currentBranchId = (int) $currentBranch['id'];

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');

$stmt = $db->prepare("
    SELECT 
        ti.product_id,
        ti.product_name,
        SUM(ti.qty) AS qty,
        SUM(ti.subtotal) AS sales,
        SUM(ti.purchase_price * ti.qty) AS cost,
        SUM(ti.subtotal - (ti.purchase_price * ti.qty)) AS profit
    FROM transaction_items ti
    JOIN transactions t ON t.id = ti.transaction_id
    WHERE DATE(t.created_at) BETWEEN ? AND ? AND t.status='success' AND t.branch_id = ?
    GROUP BY ti.product_id, ti.product_name
    ORDER BY profit DESC
");
$stmt->execute([$start, $end, $currentBranchId]);
$rows = $stmt->fetchAll();

$sales = array_sum(array_map(fn($r) => (float)$r['sales'], $rows));
$cost = array_sum(array_map(fn($r) => (float)$r['cost'], $rows));
$profit = $sales - $cost;

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/reports.css?v=' . time()); ?>">

<main class="admin-main reports-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Laporan Laba Rugi</h1>
            <p>Estimasi laba kotor cabang aktif: <b><?= e($currentBranch['name'] ?? 'Cabang'); ?></b>.</p>
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
            <div class="report-card"><div class="icon"><i class="bi bi-cash-stack"></i></div><span>Omzet</span><h3><?= rupiah($sales); ?></h3><p>Total penjualan</p></div>
            <div class="report-card"><div class="icon"><i class="bi bi-box"></i></div><span>Modal</span><h3><?= rupiah($cost); ?></h3><p>Harga beli produk</p></div>
            <div class="report-card"><div class="icon"><i class="bi bi-graph-up"></i></div><span>Laba Kotor</span><h3><?= rupiah($profit); ?></h3><p>Omzet - modal</p></div>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead><tr><th>Produk</th><th>Qty</th><th>Penjualan</th><th>Modal</th><th>Laba</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><b><?= e($r['product_name']); ?></b></td>
                            <td><?= e($r['qty']); ?></td>
                            <td><?= rupiah($r['sales']); ?></td>
                            <td><?= rupiah($r['cost']); ?></td>
                            <td><b><?= rupiah($r['profit']); ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="5" class="report-empty">Belum ada data laba rugi.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
