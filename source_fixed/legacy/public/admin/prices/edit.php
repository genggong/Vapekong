<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner','admin']);
$pageTitle = 'Edit Level Harga';
$db = Database::connect();
price_install($db);

$productId = (int)($_GET['product_id'] ?? ($_POST['product_id'] ?? 0));
$stmt = $db->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
$stmt->execute([$productId]);
$product = $stmt->fetch();
if (!$product) { flash('error','Produk tidak ditemukan.'); legacy_redirect('admin/prices/index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/prices/edit.php?product_id='.$productId); }
    foreach (price_levels() as $code=>$label) {
        price_save($db, $productId, $code, (float)($_POST['price'][$code] ?? 0), (int)($_POST['min_qty'][$code] ?? 1));
    }
    Auth::log('update_price_level','Update level harga produk '.$product['name']);
    flash('success','Level harga berhasil disimpan.');
    legacy_redirect('admin/prices/index.php');
}

$prices = price_all_for_product($db, $productId);
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/prices.css?v=' . time()); ?>">
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Edit Level Harga</h1><p><?= e($product['name']); ?> • <?= e($product['product_code'] ?: '-'); ?></p></div></header>
<form method="POST" class="system-form-card"><?= CSRF::input(); ?><input type="hidden" name="product_id" value="<?= e($productId); ?>">
<div class="table-responsive"><table class="premium-table"><thead><tr><th>Level</th><th>Harga</th><th>Minimal Qty</th></tr></thead><tbody>
<?php foreach(price_levels() as $code=>$label): ?><tr><td><b><?= e($label); ?></b><br><small><?= e($code); ?></small></td><td><input type="number" min="0" name="price[<?= e($code); ?>]" value="<?= e((float)($prices[$code]['price'] ?? $product['selling_price'])); ?>" class="pos-input"></td><td><input type="number" min="1" name="min_qty[<?= e($code); ?>]" value="<?= e((int)($prices[$code]['min_qty'] ?? 1)); ?>" class="pos-input"></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="system-form-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/prices/index.php'); ?>">Batal</a><button class="system-btn primary"><i class="bi bi-save"></i> Simpan Harga</button></div>
</form>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>