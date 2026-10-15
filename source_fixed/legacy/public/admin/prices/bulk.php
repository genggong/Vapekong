<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner','admin']);
$pageTitle = 'Edit Cepat Harga';
$db = Database::connect();
price_install($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/prices/bulk.php'); }
    $productIds = $_POST['product_id'] ?? [];
    foreach ($productIds as $idx=>$pid) {
        $pid = (int)$pid;
        if ($pid <= 0) continue;
        foreach (price_levels() as $code=>$label) {
            price_save($db, $pid, $code, (float)($_POST['price'][$code][$idx] ?? 0), 1);
        }
    }
    Auth::log('bulk_price_level','Edit cepat level harga');
    flash('success','Harga berhasil disimpan.');
    legacy_redirect('admin/prices/index.php');
}

$products = $db->query("SELECT * FROM products WHERE is_active=1 ORDER BY name ASC")->fetchAll();
$levels = price_levels();
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/prices.css?v=' . time()); ?>">
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Edit Cepat Harga</h1><p>Ubah harga ecer, grosir, reseller, dan member banyak produk sekaligus.</p></div><div class="topbar-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/prices/index.php'); ?>">Kembali</a></div></header>
<form method="POST" class="system-form-card"><?= CSRF::input(); ?><div class="table-responsive"><table class="premium-table price-bulk-table"><thead><tr><th>Produk</th><?php foreach($levels as $label): ?><th><?= e($label); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($products as $i=>$p): $prices=price_all_for_product($db,(int)$p['id']); ?><tr><td><input type="hidden" name="product_id[]" value="<?= e($p['id']); ?>"><b><?= e($p['name']); ?></b><br><small><?= e($p['product_code'] ?: '-'); ?></small></td><?php foreach($levels as $code=>$label): ?><td><input type="number" min="0" name="price[<?= e($code); ?>][]" value="<?= e((float)($prices[$code]['price'] ?? $p['selling_price'])); ?>"></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><div class="system-form-actions"><button class="system-btn primary"><i class="bi bi-save"></i> Simpan Semua Harga</button></div></form>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>