<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Edit Paket Produk';
$db = Database::connect();
bundle_install($db);
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$stmt = $db->prepare("SELECT * FROM product_bundles WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$bundle = $stmt->fetch();
if (!$bundle) { flash('error','Paket tidak ditemukan.'); legacy_redirect('admin/bundles/index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/bundles/edit.php?id=' . $id); }
    $code = strtoupper(trim($_POST['code'] ?? '')) ?: $bundle['code'];
    $name = trim($_POST['name'] ?? '');
    $sellingPrice = max(0, (float)($_POST['selling_price'] ?? 0));
    $isActive = (int)($_POST['is_active'] ?? 1);
    $note = trim($_POST['note'] ?? '');
    $productIds = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];

    if ($name === '' || $sellingPrice <= 0) { flash('error','Nama dan harga paket wajib diisi.'); legacy_redirect('admin/bundles/edit.php?id=' . $id); }

    try {
        $db->beginTransaction();
        $image = bundle_upload_image('image') ?: $bundle['image'];

        $stmt = $db->prepare("UPDATE product_bundles SET code=?, name=?, image=?, selling_price=?, note=?, is_active=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$code,$name,$image,$sellingPrice,$note ?: null,$isActive ? 1 : 0,$id]);

        $db->prepare("DELETE FROM product_bundle_items WHERE bundle_id=?")->execute([$id]);
        $ins = $db->prepare("INSERT INTO product_bundle_items (bundle_id,product_id,qty,created_at) VALUES (?,?,?,NOW())");
        $seen = [];
        foreach ($productIds as $i => $pid) {
            $pid = (int)$pid;
            $qty = max(1, (int)($qtys[$i] ?? 1));
            if ($pid <= 0 || isset($seen[$pid])) continue;
            $seen[$pid] = true;
            $ins->execute([$id,$pid,$qty]);
        }
        if (!$seen) throw new Exception('Minimal isi paket harus 1 produk.');

        Auth::log('update_bundle','Update paket produk ' . $name);
        $db->commit();
        flash('success','Paket produk berhasil diperbarui.');
        legacy_redirect('admin/bundles/index.php');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error','Gagal update paket: ' . $e->getMessage());
        legacy_redirect('admin/bundles/edit.php?id=' . $id);
    }
}

$items = bundle_items($db, $id);
$products = $db->query("SELECT id,name,product_code,purchase_price,selling_price FROM products WHERE is_active=1 ORDER BY name ASC")->fetchAll();
$isEdit = true;
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/bundles.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/sweetalert-theme.css?v=' . time()); ?>">
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Edit Paket Produk</h1><p><?= e($bundle['name']); ?></p></div></header>
<?php require __DIR__ . '/_form.php'; ?>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>