<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Edit Cabang';
$db = Database::connect();
branch_install($db);
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$stmt = $db->prepare("SELECT * FROM branches WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$branch = $stmt->fetch();
if (!$branch) { flash('error','Cabang tidak ditemukan.'); legacy_redirect('admin/branches/index.php'); }
if (is_post()) {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/branches/edit.php?id=' . $id); }
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $isMain = !empty($_POST['is_main']) ? 1 : 0;
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    if ($code === '' || $name === '') { flash('error','Kode dan nama cabang wajib diisi.'); legacy_redirect('admin/branches/edit.php?id=' . $id); }
    try {
        $db->beginTransaction();
        if ($isMain) $db->prepare("UPDATE branches SET is_main=0 WHERE id<>?")->execute([$id]);
        if ((int)$branch['is_main']) $isActive = 1;
        $stmt = $db->prepare("UPDATE branches SET code=?, name=?, phone=?, address=?, is_main=?, is_active=?, note=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$code,$name,$phone ?: null,$address ?: null,$isMain,$isActive,$note ?: null,$id]);
        $stmt = $db->prepare("INSERT IGNORE INTO product_branch_stocks (branch_id,product_id,stock,minimum_stock,created_at) SELECT ?, id, 0, minimum_stock, NOW() FROM products");
        $stmt->execute([$id]);
        Auth::log('update_branch','Update cabang ' . $name);
        $db->commit();
        flash('success','Cabang berhasil diperbarui.');
        legacy_redirect('admin/branches/index.php');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error','Gagal update cabang: ' . $e->getMessage());
        legacy_redirect('admin/branches/edit.php?id=' . $id);
    }
}
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<main class="admin-main system-wrap"><header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Edit Cabang</h1><p><?= e($branch['name']); ?></p></div></header><form method="POST" class="system-form-card"><?= CSRF::input(); ?><input type="hidden" name="id" value="<?= e($id); ?>"><div class="system-form-grid"><div class="system-field"><label>Kode Cabang</label><input name="code" required value="<?= e($branch['code']); ?>"></div><div class="system-field"><label>Nama Cabang</label><input name="name" required value="<?= e($branch['name']); ?>"></div><div class="system-field"><label>No. HP</label><input name="phone" value="<?= e($branch['phone']); ?>"></div><div class="system-field"><label>Cabang Utama</label><select name="is_main"><option value="0">Tidak</option><option value="1" <?= (int)$branch['is_main']?'selected':''; ?>>Ya</option></select></div><div class="system-field"><label>Status</label><select name="is_active" <?= (int)$branch['is_main']?'disabled':''; ?>><option value="1" <?= (int)$branch['is_active']?'selected':''; ?>>Aktif</option><option value="0" <?= !(int)$branch['is_active']?'selected':''; ?>>Nonaktif</option></select><?php if((int)$branch['is_main']): ?><small>Cabang utama tidak bisa dinonaktifkan.</small><?php endif; ?></div><div class="system-field system-wide"><label>Alamat</label><textarea name="address" rows="3"><?= e($branch['address']); ?></textarea></div><div class="system-field system-wide"><label>Catatan</label><textarea name="note" rows="3"><?= e($branch['note']); ?></textarea></div></div><div class="system-form-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/branches/index.php'); ?>">Batal</a><button class="system-btn primary"><i class="bi bi-save"></i> Update Cabang</button></div></form></main><?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
