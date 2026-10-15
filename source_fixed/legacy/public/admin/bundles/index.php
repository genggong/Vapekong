<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Paket Produk';
$db = Database::connect();
bundle_install($db);

$rows = $db->query("SELECT pb.*,
    COUNT(bi.id) AS total_items,
    COALESCE(SUM(bi.qty),0) AS total_qty,
    COALESCE(SUM(bi.qty * p.purchase_price),0) AS modal_paket
    FROM product_bundles pb
    LEFT JOIN product_bundle_items bi ON bi.bundle_id = pb.id
    LEFT JOIN products p ON p.id = bi.product_id
    GROUP BY pb.id
    ORDER BY pb.id DESC")->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/bundles.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/sweetalert-theme.css?v=' . time()); ?>">
<main class="admin-main system-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title"><h1>Paket Produk</h1><p>Buat bundling/paket hemat dari beberapa produk. Stok komponen otomatis berkurang saat paket terjual.</p></div>
        <div class="topbar-actions"><a href="<?= legacy_base_url('admin/bundles/create.php'); ?>" class="system-btn primary"><i class="bi bi-plus-circle"></i> Tambah Paket</a></div>
    </header>

    <section class="bundle-stats">
        <div class="bundle-stat"><div class="bundle-stat-icon"><i class="bi bi-box2-heart"></i></div><span>Total Paket</span><h3><?= count($rows); ?></h3></div>
        <div class="bundle-stat"><div class="bundle-stat-icon"><i class="bi bi-check-circle"></i></div><span>Paket Aktif</span><h3><?= count(array_filter($rows, fn($r)=>(int)$r['is_active']===1)); ?></h3></div>
        <div class="bundle-stat"><div class="bundle-stat-icon"><i class="bi bi-basket"></i></div><span>Total Item Komponen</span><h3><?= e(array_sum(array_map(fn($r)=>(int)$r['total_qty'],$rows))); ?></h3></div>
        <div class="bundle-stat"><div class="bundle-stat-icon"><i class="bi bi-wallet2"></i></div><span>Modal Semua Paket</span><h3><?= rupiah(array_sum(array_map(fn($r)=>(float)$r['modal_paket'],$rows))); ?></h3></div>
    </section>

    <section class="system-form-card">
        <div class="table-responsive">
            <table class="premium-table">
                <thead><tr><th>Paket</th><th>Kode</th><th>Komponen</th><th>Modal</th><th>Harga Jual</th><th>Estimasi Laba</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                    <?php foreach($rows as $row): $modal=(float)$row['modal_paket']; $harga=(float)$row['selling_price']; ?>
                    <tr>
                        <td><div class="bundle-product-cell"><span class="bundle-thumb"><?php if(!empty($row['image']) && bundle_image_exists($row['image'])): ?><img loading="lazy" src="<?= bundle_asset_url($row['image']); ?>"><?php else: ?><i class="bi bi-box2-heart"></i><?php endif; ?></span><div><b><?= e($row['name']); ?></b><br><small><?= e($row['note'] ?: '-'); ?></small></div></div></td>
                        <td><b><?= e($row['code']); ?></b></td>
                        <td><?= e($row['total_items']); ?> produk<br><small><?= e($row['total_qty']); ?> qty komponen</small></td>
                        <td><?= rupiah($modal); ?></td>
                        <td><b><?= rupiah($harga); ?></b></td>
                        <td class="<?= ($harga-$modal)>=0?'text-success':'text-danger'; ?>"><b><?= rupiah($harga-$modal); ?></b></td>
                        <td><?= (int)$row['is_active'] ? '<span class="bundle-status on">Aktif</span>' : '<span class="bundle-status off">Nonaktif</span>'; ?></td>
                        <td class="text-end"><div class="bundle-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/bundles/detail.php?id=' . $row['id']); ?>">Detail</a><a class="system-btn soft" href="<?= legacy_base_url('admin/bundles/edit.php?id=' . $row['id']); ?>">Edit</a><a class="system-btn danger" onclick="return confirm('Hapus paket ini?')" href="<?= legacy_base_url('admin/bundles/delete.php?id=' . $row['id']); ?>">Hapus</a></div></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(!$rows): ?><tr><td colspan="8" class="text-center">Belum ada paket produk.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>