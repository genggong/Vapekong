<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Tambah Paket Produk';
$db = Database::connect();
bundle_install($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/bundles/create.php'); }
    $code = strtoupper(trim($_POST['code'] ?? '')) ?: bundle_next_code($db);
    $name = trim($_POST['name'] ?? '');
    $sellingPrice = max(0, (float)($_POST['selling_price'] ?? 0));
    $isActive = (int)($_POST['is_active'] ?? 1);
    $note = trim($_POST['note'] ?? '');
    $productIds = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];

    if ($name === '' || $sellingPrice <= 0) { flash('error','Nama dan harga paket wajib diisi.'); legacy_redirect('admin/bundles/create.php'); }

    try {
        $db->beginTransaction();
        $image = bundle_upload_image('image');

        $stmt = $db->prepare("INSERT INTO product_bundles (code,name,image,selling_price,note,is_active,created_at) VALUES (?,?,?,?,?,?,NOW())");
        $stmt->execute([$code,$name,$image,$sellingPrice,$note ?: null,$isActive ? 1 : 0]);
        $bundleId = (int)$db->lastInsertId();

        $ins = $db->prepare("INSERT INTO product_bundle_items (bundle_id,product_id,qty,created_at) VALUES (?,?,?,NOW())");
        $seen = [];
        foreach ($productIds as $i => $pid) {
            $pid = (int)$pid;
            $qty = max(1, (int)($qtys[$i] ?? 1));
            if ($pid <= 0 || isset($seen[$pid])) continue;
            $seen[$pid] = true;
            $ins->execute([$bundleId,$pid,$qty]);
        }
        if (!$seen) throw new Exception('Minimal isi paket harus 1 produk.');

        Auth::log('create_bundle','Tambah paket produk ' . $name);
        $db->commit();
        flash('success','Paket produk berhasil ditambahkan.');
        legacy_redirect('admin/bundles/index.php');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error','Gagal simpan paket: ' . $e->getMessage());
        legacy_redirect('admin/bundles/create.php');
    }
}

$bundle = [];
$items = [];
$products = $db->query("SELECT id,name,product_code,purchase_price,selling_price FROM products WHERE is_active=1 ORDER BY name ASC")->fetchAll();
$isEdit = false;
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/bundles.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/sweetalert-theme.css?v=' . time()); ?>">
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Tambah Paket Produk</h1><p>Buat paket dari beberapa produk satuan.</p></div></header>
<?php require __DIR__ . '/_form.php'; ?>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>