<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin','owner']);
$db = Database::connect();
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM categories WHERE id=?"); $stmt->execute([$id]); $cat = $stmt->fetch();
if (!$cat) { flash('error','Kategori tidak ditemukan.'); legacy_redirect('admin/categories/index.php'); }
$pageTitle = 'Edit Kategori';
if (is_post()) {
    if (!CSRF::verify()) { flash('error','Token tidak valid.'); legacy_redirect('admin/categories/edit.php?id='.$id); }
    $name = trim($_POST['name'] ?? ''); $slug = slugify($_POST['slug'] ?: $name); $icon = trim($_POST['icon'] ?? 'bi-box-seam'); $color = trim($_POST['color'] ?? '#2563eb'); $sort = (int)($_POST['sort_order'] ?? 0); $active = isset($_POST['is_active']) ? 1 : 0;
    if ($name === '') { flash('error','Nama kategori wajib diisi.'); legacy_redirect('admin/categories/edit.php?id='.$id); }
    try { $up = $db->prepare("UPDATE categories SET name=?, slug=?, icon=?, color=?, sort_order=?, is_active=? WHERE id=?"); $up->execute([$name,$slug,$icon,$color,$sort,$active,$id]); Auth::log('update_category','Mengubah kategori '.$name); flash('success','Kategori berhasil diperbarui.'); legacy_redirect('admin/categories/index.php'); } catch(Throwable $e){ flash('error','Gagal update: '.$e->getMessage()); }
}
require_once __DIR__ . '/../../layouts/admin-header.php'; require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<main class="admin-main"><header class="admin-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Edit Kategori</h1><p>Perbarui kategori produk.</p></div></header><section class="premium-card mt-4"><form method="POST" class="premium-form"><?= CSRF::input(); ?><div class="form-grid"><div class="form-group"><label>Nama Kategori</label><input name="name" value="<?= e($cat['name']); ?>" required></div><div class="form-group"><label>Slug</label><input name="slug" value="<?= e($cat['slug']); ?>"></div><div class="form-group"><label>Icon Bootstrap</label><input name="icon" value="<?= e($cat['icon']); ?>"></div><div class="form-group"><label>Warna</label><input type="color" name="color" value="<?= e($cat['color']); ?>"></div><div class="form-group"><label>Urutan</label><input type="number" name="sort_order" value="<?= e($cat['sort_order']); ?>"></div><div class="form-group switch-line"><label><input type="checkbox" name="is_active" <?= $cat['is_active'] ? 'checked' : ''; ?>> Kategori aktif</label></div></div><div class="form-actions"><a class="btn-soft" href="<?= legacy_base_url('admin/categories/index.php'); ?>">Batal</a><button class="btn-premium"><i class="bi bi-save"></i> Update</button></div></form></section></main><?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
