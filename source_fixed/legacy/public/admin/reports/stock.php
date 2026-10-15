<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$pageTitle = 'Laporan Stok';
$db = Database::connect();

$rows = $db->query("
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    ORDER BY p.stock ASC, p.name ASC
")->fetchAll();

$totalStock = array_sum(array_map(fn($r) => (int)$r['stock'], $rows));
$stockValue = array_sum(array_map(fn($r) => (float)$r['stock'] * (float)$r['purchase_price'], $rows));
$lowStock = count(array_filter($rows, fn($r) => (int)$r['stock'] <= (int)$r['minimum_stock']));

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/reports.css?v=' . time()); ?>">

<main class="admin-main reports-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Laporan Stok</h1>
            <p>Pantau stok akhir, nilai modal stok, dan produk yang perlu restock.</p>
        </div>
        <div class="topbar-actions"><button onclick="window.print()" class="report-btn primary"><i class="bi bi-printer"></i> Print</button></div>
    </header>

    <section class="report-panel mt-4">
        <div class="report-grid">
            <div class="report-card"><div class="icon"><i class="bi bi-boxes"></i></div><span>Total Produk</span><h3><?= count($rows); ?></h3><p>Produk tercatat</p></div>
            <div class="report-card"><div class="icon"><i class="bi bi-stack"></i></div><span>Total Stok</span><h3><?= e($totalStock); ?></h3><p>Semua satuan</p></div>
            <div class="report-card"><div class="icon"><i class="bi bi-wallet2"></i></div><span>Nilai Modal</span><h3><?= rupiah($stockValue); ?></h3><p>Stok x harga beli</p></div>
            <div class="report-card"><div class="icon"><i class="bi bi-exclamation-triangle"></i></div><span>Stok Menipis</span><h3><?= e($lowStock); ?></h3><p>Perlu restock</p></div>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead><tr><th>Produk</th><th>Kategori</th><th>Stok</th><th>Minimum</th><th>Harga Beli</th><th>Nilai Modal</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): 
                        $isLow = (int)$r['stock'] <= (int)$r['minimum_stock'];
                    ?>
                        <tr>
                            <td><b><?= e($r['name']); ?></b><br><small><?= e($r['product_code']); ?></small></td>
                            <td><?= e($r['category_name'] ?? '-'); ?></td>
                            <td><b><?= e($r['stock']); ?> <?= e($r['unit']); ?></b></td>
                            <td><?= e($r['minimum_stock']); ?></td>
                            <td><?= rupiah($r['purchase_price']); ?></td>
                            <td><b><?= rupiah($r['stock'] * $r['purchase_price']); ?></b></td>
                            <td><span class="report-badge <?= $isLow ? 'red' : 'green'; ?>"><?= $isLow ? 'Restock' : 'Aman'; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="7" class="report-empty">Belum ada produk.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
