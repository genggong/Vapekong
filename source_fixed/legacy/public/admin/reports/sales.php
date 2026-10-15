<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/../branches/_helpers.php';
$pageTitle = 'Laporan Penjualan';
$db = Database::connect();
branch_install($db);
$currentBranch = branch_current($db);
$currentBranchId = (int) $currentBranch['id'];

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');

$stmt = $db->prepare("
    SELECT t.*, u.name AS cashier_name, b.name AS branch_name
    FROM transactions t
    LEFT JOIN users u ON u.id = t.cashier_id
    LEFT JOIN branches b ON b.id = t.branch_id
    WHERE DATE(t.created_at) BETWEEN ? AND ? AND t.branch_id = ?
    ORDER BY t.id DESC
");
$stmt->execute([$start, $end, $currentBranchId]);
$rows = $stmt->fetchAll();

$total = array_sum(array_map(fn($r) => (float)$r['grand_total'], $rows));
$success = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'success'));

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/reports.css?v=' . time()); ?>">

<main class="admin-main reports-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Laporan Penjualan</h1>
            <p>Filter invoice cabang aktif: <b><?= e($currentBranch['name'] ?? 'Cabang'); ?></b>.</p>
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
            <div class="report-card"><div class="icon"><i class="bi bi-cash"></i></div><span>Total Omzet</span><h3><?= rupiah($total); ?></h3><p>Periode laporan</p></div>
            <div class="report-card"><div class="icon"><i class="bi bi-receipt"></i></div><span>Total Invoice</span><h3><?= count($rows); ?></h3><p>Semua transaksi</p></div>
            <div class="report-card"><div class="icon"><i class="bi bi-check-circle"></i></div><span>Transaksi Sukses</span><h3><?= e($success); ?></h3><p>Status sukses</p></div>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead><tr><th>Invoice</th><th>Tanggal</th><th>Kasir</th><th>Cabang</th><th>Metode</th><th>Subtotal</th><th>Diskon</th><th>Total</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><b><?= e($r['invoice_number']); ?></b></td>
                            <td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))); ?></td>
                            <td><?= e($r['cashier_name'] ?? '-'); ?></td><td><?= e($r['branch_name'] ?? '-'); ?></td>
                            <td><span class="report-badge blue"><?= e(payment_method_label($r['payment_method'] ?? '-')); ?></span></td>
                            <td><?= rupiah($r['subtotal']); ?></td>
                            <td><?= rupiah($r['discount']); ?></td>
                            <td><b><?= rupiah($r['grand_total']); ?></b></td>
                            <td><span class="report-badge green"><?= e($r['status']); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="8" class="report-empty">Belum ada transaksi pada periode ini.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
