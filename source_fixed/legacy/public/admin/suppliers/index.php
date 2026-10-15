<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Supplier';
$db = Database::connect();
$suppliers = $db->query("SELECT * FROM suppliers ORDER BY id DESC")->fetchAll();
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<main class="admin-main">
  <header class="admin-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><h1>Supplier</h1><p>Kelola pemasok barang dan kontak pembelian stok.</p></div>
    <div class="topbar-actions"><a href="<?= legacy_base_url('admin/suppliers/create.php'); ?>" class="btn-premium"><i class="bi bi-plus-circle"></i> Tambah</a></div>
  </header>

  <section class="premium-card mt-4">
    <div class="card-head">
      <div>
        <h3>Data Supplier</h3>
        <p>Kontak supplier tersusun rapi dengan DataTables responsive.</p>
      </div>
      <span class="badge-soft danger"><?= count($suppliers); ?> Supplier</span>
    </div>

    <div class="table-toolbar-note">
      <div class="dt-hint-card">
    <div class="dt-hint-icon">
        <i class="bi bi-truck"></i>
    </div>
    <div class="dt-hint-content">
        <span class="dt-hint-title">Pencarian Supplier</span>
        <p class="dt-hint-text">
            Cari supplier berdasarkan nama, nomor HP, email, alamat, catatan, atau status.
        </p>
    </div>
</div>
    </div>

    <div class="table-premium-card dt-premium-wrap">
      <table class="premium-table js-data-table nowrap" data-title="supplier">
        <thead><tr><th>Supplier</th><th>Kontak</th><th>Alamat</th><th>Catatan</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
        <tbody>
        <?php foreach($suppliers as $s): ?>
          <tr>
            <td><div class="table-title"><span class="table-icon"><i class="bi bi-truck"></i></span><div><b><?= e($s['name']); ?></b><small class="table-subtext">Partner stok barang</small></div></div></td>
            <td><?= e($s['phone'] ?: '-'); ?><br><small class="table-subtext"><?= e($s['email'] ?: '-'); ?></small></td>
            <td><?= e($s['address'] ?: '-'); ?></td>
            <td><?= e($s['note'] ?: '-'); ?></td>
            <td><?= $s['is_active'] ? '<span class="badge-soft success">Aktif</span>' : '<span class="badge-soft danger">Nonaktif</span>'; ?></td>
            <td class="text-end table-actions"><a class="btn-icon edit" href="<?= legacy_base_url('admin/suppliers/edit.php?id='.$s['id']); ?>"><i class="bi bi-pencil"></i></a><a class="btn-icon delete btn-delete" href="<?= legacy_base_url('admin/suppliers/delete.php?id='.$s['id']); ?>"><i class="bi bi-trash"></i></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
