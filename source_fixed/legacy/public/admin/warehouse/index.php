<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/_helpers.php';
warehouse_require_manage();
$pageTitle = 'Gudang';
$db = Database::connect();
branch_install($db);
warehouse_install($db);
$warehouseId = warehouse_main_id($db);
$stmtWarehouse = $db->prepare("SELECT * FROM warehouses WHERE id=? LIMIT 1");
$stmtWarehouse->execute([$warehouseId]);
$warehouse = $stmtWarehouse->fetch();

$stmt = $db->prepare("SELECT p.id,p.name,p.product_code,p.barcode,p.unit,p.image,p.minimum_stock,
    ws.stock,ws.minimum_stock warehouse_minimum,ws.purchase_price,ws.updated_at,
    c.name category_name,s.name supplier_name
    FROM products p
    LEFT JOIN warehouse_stocks ws ON ws.product_id=p.id AND ws.warehouse_id=?
    LEFT JOIN categories c ON c.id=p.category_id
    LEFT JOIN suppliers s ON s.id=p.supplier_id
    WHERE p.is_active=1
    ORDER BY COALESCE(ws.stock,0) ASC,p.name ASC");
$stmt->execute([$warehouseId]);
$products = $stmt->fetchAll();

$totalSku = count($products);
$totalUnits = 0;
$totalValue = 0;
$lowStock = 0;
foreach ($products as $p) {
    $stock = (int)($p['stock'] ?? 0);
    $min = (int)($p['warehouse_minimum'] ?? $p['minimum_stock'] ?? 0);
    $cost = (float)($p['purchase_price'] ?? 0);
    $totalUnits += $stock;
    $totalValue += $stock * $cost;
    if ($stock <= $min) $lowStock++;
}

$pendingRequests = (int)$db->query("SELECT COUNT(*) FROM branch_stock_requests WHERE status IN ('pending','approved')")->fetchColumn();
$recent = $db->prepare("SELECT m.*,p.name product_name,p.product_code,u.name user_name
    FROM warehouse_stock_movements m
    LEFT JOIN products p ON p.id=m.product_id
    LEFT JOIN users u ON u.id=m.user_id
    WHERE m.warehouse_id=? ORDER BY m.id DESC LIMIT 20");
$recent->execute([$warehouseId]);
$movements = $recent->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/warehouse.css?v=' . time()); ?>">
<main class="admin-main warehouse-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title"><h1>Gudang</h1><p>Stok pusat supplier terpisah dari stok cabang dan terhubung ke produk POS.</p></div>
        <div class="topbar-actions">
            <a class="btn-premium outline" href="<?= legacy_base_url('admin/stock-requests/index.php'); ?>"><i class="bi bi-arrow-down-up"></i> Request Cabang</a>
            <a class="btn-premium" href="<?= legacy_base_url('admin/purchases/create.php?destination=warehouse'); ?>"><i class="bi bi-truck"></i> Beli dari Supplier</a>
        </div>
    </header>

    <section class="warehouse-head-card">
        <div class="warehouse-icon"><i class="bi bi-buildings-fill"></i></div>
        <div><span>Gudang Aktif</span><h2><?= e($warehouse['name'] ?? 'Gudang Utama'); ?></h2><p><?= e($warehouse['address'] ?? setting('store_address','-')); ?></p></div>
        <div class="warehouse-code"><?= e($warehouse['code'] ?? 'GDG-UTAMA'); ?></div>
    </section>

    <section class="warehouse-stats">
        <article><span><i class="bi bi-box-seam"></i></span><div><small>SKU Produk</small><h3><?= number_format($totalSku,0,',','.'); ?></h3><p>Produk aktif</p></div></article>
        <article><span><i class="bi bi-boxes"></i></span><div><small>Total Unit</small><h3><?= number_format($totalUnits,0,',','.'); ?></h3><p>Stok gudang</p></div></article>
        <article><span><i class="bi bi-cash-stack"></i></span><div><small>Nilai Modal</small><h3><?= rupiah($totalValue); ?></h3><p>Tanpa margin cabang</p></div></article>
        <article><span><i class="bi bi-hourglass-split"></i></span><div><small>Request Aktif</small><h3><?= number_format($pendingRequests,0,',','.'); ?></h3><p><?= $lowStock; ?> SKU minimum/habis</p></div></article>
    </section>

    <section class="premium-card mt-4">
        <div class="card-head"><div><h3>Stok Gudang</h3><p>Harga modal adalah harga beli gudang dari supplier dan menjadi harga transfer cabang tanpa margin.</p></div><span class="badge-soft"><?= e($totalSku); ?> produk</span></div>
        <div class="table-premium-card dt-premium-wrap">
            <table class="premium-table js-data-table nowrap" data-title="warehouse-stock">
                <thead><tr><th>Produk</th><th>Kategori</th><th>Supplier</th><th>Stok Gudang</th><th>Minimum</th><th>Harga Modal</th><th>Nilai Stok</th><th>Update</th></tr></thead>
                <tbody>
                <?php foreach ($products as $p): $stock=(int)($p['stock']??0); $min=(int)($p['warehouse_minimum']??$p['minimum_stock']??0); $cost=(float)($p['purchase_price']??0); ?>
                    <tr>
                        <td><b><?= e($p['name']); ?></b><br><small><?= e($p['product_code']); ?><?= $p['barcode'] ? ' • '.e($p['barcode']) : ''; ?></small></td>
                        <td><?= e($p['category_name'] ?: '-'); ?></td>
                        <td><?= e($p['supplier_name'] ?: '-'); ?></td>
                        <td data-order="<?= $stock; ?>"><span class="stock-pill <?= $stock <= 0 ? 'out' : ($stock <= $min ? 'low' : 'ok'); ?>"><?= e($stock); ?> <?= e($p['unit']); ?></span></td>
                        <td><?= e($min); ?> <?= e($p['unit']); ?></td>
                        <td><?= rupiah($cost); ?></td>
                        <td data-order="<?= $stock*$cost; ?>"><b><?= rupiah($stock*$cost); ?></b></td>
                        <td><?= !empty($p['updated_at']) ? e(date('d/m/Y H:i',strtotime($p['updated_at']))) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="premium-card mt-4">
        <div class="card-head"><div><h3>Pergerakan Stok Gudang Terbaru</h3><p>Audit pembelian supplier dan pengiriman stok ke cabang.</p></div></div>
        <div class="table-premium-card dt-premium-wrap">
            <table class="premium-table nowrap">
                <thead><tr><th>Waktu</th><th>Produk</th><th>Tipe</th><th>Qty</th><th>Stok</th><th>Modal</th><th>User</th><th>Catatan</th></tr></thead>
                <tbody>
                <?php if (!$movements): ?><tr><td colspan="8" class="text-center">Belum ada pergerakan stok gudang.</td></tr><?php endif; ?>
                <?php foreach ($movements as $m): ?>
                    <tr><td><?= e(date('d/m/Y H:i',strtotime($m['created_at']))); ?></td><td><b><?= e($m['product_name'] ?: '-'); ?></b><br><small><?= e($m['product_code'] ?: ''); ?></small></td><td><span class="badge-soft"><?= e(str_replace('_',' ',ucwords($m['type'],'_'))); ?></span></td><td><?= e($m['qty']); ?></td><td><?= e($m['stock_before']); ?> → <?= e($m['stock_after']); ?></td><td><?= rupiah($m['unit_cost']); ?></td><td><?= e($m['user_name'] ?: '-'); ?></td><td><?= e($m['note'] ?: '-'); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
