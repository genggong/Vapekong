<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Tambah Kategori';
if (is_post()) {
    if (!CSRF::verify()) { flash('error','Token tidak valid.'); legacy_redirect('admin/categories/create.php'); }
    $name = trim($_POST['name'] ?? '');
    $slug = slugify($_POST['slug'] ?: $name);
    $icon = trim($_POST['icon'] ?? 'bi-box-seam');
    $color = trim($_POST['color'] ?? '#2563eb');
    $sort = (int)($_POST['sort_order'] ?? 0);
    $active = isset($_POST['is_active']) ? 1 : 0;
    if ($name === '') { flash('error','Nama kategori wajib diisi.'); legacy_redirect('admin/categories/create.php'); }
    try {
        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO categories (name, slug, icon, color, sort_order, is_active) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$name,$slug,$icon,$color,$sort,$active]);
        Auth::log('create_category','Menambah kategori ' . $name);
        flash('success','Kategori berhasil ditambahkan.'); legacy_redirect('admin/categories/index.php');
    } catch (Throwable $e) { flash('error','Gagal tambah kategori: ' . $e->getMessage()); }
}
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<main class="admin-main">
<header class="admin-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Tambah Kategori</h1><p>Buat kategori produk baru.</p></div></header>
<section class="premium-card mt-4"><form method="POST" class="premium-form"><?= CSRF::input(); ?><div class="form-grid"><div class="form-group"><label>Nama Kategori</label><input name="name" required placeholder="Contoh: Minuman"></div><div class="form-group"><label>Slug Optional</label><input name="slug" placeholder="otomatis dari nama"></div><div class="form-group"><label>Icon Bootstrap</label><input name="icon" value="bi-box-seam" placeholder="bi-box-seam"></div><div class="form-group"><label>Warna</label><input type="color" name="color" value="#2563eb"></div><div class="form-group"><label>Urutan</label><input type="number" name="sort_order" value="0"></div><div class="form-group switch-line"><label><input type="checkbox" name="is_active" checked> Kategori aktif</label></div></div><div class="form-actions"><a class="btn-soft" href="<?= legacy_base_url('admin/categories/index.php'); ?>">Batal</a><button class="btn-premium"><i class="bi bi-save"></i> Simpan</button></div></form></section>
</main><?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
