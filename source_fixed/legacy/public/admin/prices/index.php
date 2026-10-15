<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner','admin']);
$pageTitle = 'Harga Grosir / Level Harga';
$db = Database::connect();
price_install($db);

$q = trim($_GET['q'] ?? '');
$where = "WHERE p.is_active = 1";
$params = [];
if ($q !== '') {
    $where .= " AND (p.name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}

$stmt = $db->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id $where ORDER BY p.name ASC");
$stmt->execute($params);
$products = $stmt->fetchAll();

$levels = price_levels();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/prices.css?v=' . time()); ?>">
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><h1>Harga Grosir / Level Harga</h1><p>Atur harga ecer, grosir, reseller, dan member untuk POS.</p></div>
    <div class="topbar-actions"><a class="system-btn primary" href="<?= legacy_base_url('admin/prices/bulk.php'); ?>"><i class="bi bi-pencil-square"></i> Edit Cepat</a></div>
</header>

<section class="price-stats">
    <div class="price-stat"><div class="price-stat-icon"><i class="bi bi-box-seam"></i></div><span>Total Produk</span><h3><?= e(count($products)); ?></h3></div>
    <div class="price-stat"><div class="price-stat-icon"><i class="bi bi-tags"></i></div><span>Level Harga</span><h3><?= e(count($levels)); ?></h3></div>
    <div class="price-stat"><div class="price-stat-icon"><i class="bi bi-cash-stack"></i></div><span>Harga Ecer</span><h3>Default</h3></div>
    <div class="price-stat"><div class="price-stat-icon"><i class="bi bi-people"></i></div><span>POS</span><h3>Pilih Level</h3></div>
</section>

<section class="system-form-card">
    <form method="GET" class="price-filter">
        <div class="system-field"><label>Cari Produk</label><input name="q" value="<?= e($q); ?>" placeholder="Nama, kode, barcode..."></div>
        <div class="system-field"><label>&nbsp;</label><button class="system-btn primary"><i class="bi bi-search"></i> Cari</button></div>
    </form>
</section>

<section class="system-form-card">
<div class="table-responsive">
<table class="premium-table">
<thead><tr><th>Produk</th><?php foreach($levels as $label): ?><th><?= e($label); ?></th><?php endforeach; ?><th class="text-end">Aksi</th></tr></thead>
<tbody>
<?php foreach($products as $p): $prices = price_all_for_product($db, (int)$p['id']); ?>
<tr>
<td><div class="price-product-cell"><span class="price-thumb"><?php if(!empty($p['image'])): ?><img loading="lazy" src="<?= legacy_base_url($p['image']); ?>"><?php else: ?><i class="bi bi-box-seam"></i><?php endif; ?></span><div><b><?= e($p['name']); ?></b><br><small><?= e($p['product_code'] ?: '-'); ?> • <?= e($p['category_name'] ?: '-'); ?></small></div></div></td>
<?php foreach($levels as $code=>$label): ?><td><span class="price-level-pill"><?= rupiah($prices[$code]['price'] ?? $p['selling_price']); ?></span></td><?php endforeach; ?>
<td class="text-end table-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/prices/edit.php?product_id=' . $p['id']); ?>">Edit</a></td>
</tr>
<?php endforeach; if(!$products): ?><tr><td colspan="<?= e(count($levels)+2); ?>" class="text-center">Produk tidak ditemukan.</td></tr><?php endif; ?>
</tbody>
</table>
</div>
</section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>