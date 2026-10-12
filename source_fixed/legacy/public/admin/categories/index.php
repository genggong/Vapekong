<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Kategori Produk';
$db = Database::connect();
$categories = $db->query("SELECT * FROM categories ORDER BY sort_order ASC, id DESC")->fetchAll();
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<main class="admin-main">
  <header class="admin-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><h1>Kategori Produk</h1><p>Kelola kategori, icon, warna, dan status tampil produk.</p></div>
    <div class="topbar-actions"><a href="<?= legacy_base_url('admin/categories/create.php'); ?>" class="btn-premium"><i class="bi bi-plus-circle"></i> Tambah</a></div>
  </header>

  <section class="premium-card mt-4">
    <div class="card-head">
      <div>
        <h3>Data Kategori</h3>
        <p>Tabel sudah memakai Bootstrap DataTables: search, pagination, responsive, dan sorting otomatis.</p>
      </div>
      <span class="badge-soft danger"><?= count($categories); ?> Data</span>
    </div>

    <div class="table-toolbar-note">
      <div class="dt-hint-card">
    <div class="dt-hint-icon">
        <i class="bi bi-stars"></i>
    </div>
    <div class="dt-hint-content">
        <span class="dt-hint-title">Pencarian Kategori</span>
        <p class="dt-hint-text">
            Gunakan kolom pencarian DataTables untuk mencari nama kategori, slug, status, atau urutan.
        </p>
    </div>
</div>
    </div>

    <div class="table-premium-card dt-premium-wrap">
      <table class="premium-table js-data-table nowrap" data-title="kategori">
        <thead>
          <tr><th>Kategori</th><th>Slug</th><th>Warna</th><th>Urutan</th><th>Status</th><th class="text-end">Aksi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $cat): ?>
          <tr>
            <td><div class="table-title"><span class="table-icon" style="background:<?= e($cat['color']); ?>20;color:<?= e($cat['color']); ?>"><i class="bi <?= e($cat['icon']); ?>"></i></span><div><b><?= e($cat['name']); ?></b><small class="table-subtext">Kategori produk toko</small></div></div></td>
            <td><code><?= e($cat['slug']); ?></code></td>
            <td><span class="color-dot" style="background:<?= e($cat['color']); ?>"></span> <?= e($cat['color']); ?></td>
            <td><?= e($cat['sort_order']); ?></td>
            <td><?= $cat['is_active'] ? '<span class="badge-soft success">Aktif</span>' : '<span class="badge-soft danger">Nonaktif</span>'; ?></td>
            <td class="text-end table-actions">
              <a href="<?= legacy_base_url('admin/categories/edit.php?id=' . $cat['id']); ?>" class="btn-icon edit"><i class="bi bi-pencil"></i></a>
              <a href="<?= legacy_base_url('admin/categories/delete.php?id=' . $cat['id']); ?>" class="btn-icon delete btn-delete"><i class="bi bi-trash"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
