<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Tambah Cabang';
$db = Database::connect();
branch_install($db);
if (is_post()) {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/branches/create.php'); }
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $isMain = !empty($_POST['is_main']) ? 1 : 0;
    if ($code === '' || $name === '') { flash('error','Kode dan nama cabang wajib diisi.'); legacy_redirect('admin/branches/create.php'); }
    try {
        $db->beginTransaction();
        if ($isMain) $db->exec("UPDATE branches SET is_main = 0");
        $stmt = $db->prepare("INSERT INTO branches (code,name,phone,address,is_main,is_active,note,created_at) VALUES (?,?,?,?,?,1,?,NOW())");
        $stmt->execute([$code,$name,$phone ?: null,$address ?: null,$isMain,$note ?: null]);
        $branchId = (int)$db->lastInsertId();
        $stmt = $db->prepare("INSERT IGNORE INTO product_branch_stocks (branch_id,product_id,stock,minimum_stock,created_at) SELECT ?, id, 0, minimum_stock, NOW() FROM products");
        $stmt->execute([$branchId]);
        Auth::log('create_branch','Tambah cabang ' . $name);
        $db->commit();
        flash('success','Cabang berhasil ditambahkan.');
        legacy_redirect('admin/branches/index.php');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error','Gagal tambah cabang: ' . $e->getMessage());
        legacy_redirect('admin/branches/create.php');
    }
}
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<main class="admin-main system-wrap"><header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Tambah Cabang</h1><p>Buat outlet/cabang baru.</p></div></header><form method="POST" class="system-form-card"><?= CSRF::input(); ?><div class="system-form-grid"><div class="system-field"><label>Kode Cabang</label><input name="code" required placeholder="CABANG-01"></div><div class="system-field"><label>Nama Cabang</label><input name="name" required placeholder="Cabang Pasar"></div><div class="system-field"><label>No. HP</label><input name="phone" placeholder="08xxxx"></div><div class="system-field"><label>Jadikan Cabang Utama?</label><select name="is_main"><option value="0">Tidak</option><option value="1">Ya</option></select></div><div class="system-field system-wide"><label>Alamat</label><textarea name="address" rows="3"></textarea></div><div class="system-field system-wide"><label>Catatan</label><textarea name="note" rows="3"></textarea></div></div><div class="system-form-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/branches/index.php'); ?>">Batal</a><button class="system-btn primary"><i class="bi bi-save"></i> Simpan Cabang</button></div></form></main><?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
