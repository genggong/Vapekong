<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

Auth::requireLogin();
$pageTitle = 'Promo / Voucher';
$db = Database::connect();
ensure_promos_table($db);

$promos = $db->query("SELECT * FROM promos ORDER BY id DESC")->fetchAll();
$total = count($promos);
$active = count(array_filter($promos, fn($p) => ($p['status'] ?? '') === 'active'));
$vouchers = count(array_filter($promos, fn($p) => ($p['promo_type'] ?? '') === 'voucher'));
$specific = count(array_filter($promos, fn($p) => ($p['promo_scope'] ?? 'all') === 'specific_products'));

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/promos.css?v=' . time()); ?>">

<main class="admin-main promos-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Promo / Voucher</h1>
            <p>Kelola diskon otomatis, kode voucher, barcode promo, dan promo khusus produk tertentu.</p>
        </div>
        <div class="topbar-actions">
    <a href="<?= legacy_base_url('admin/promos/barcodes.php'); ?>" class="promos-btn soft">
        <i class="bi bi-upc-scan"></i> Semua Barcode
    </a>

    <a href="<?= legacy_base_url('admin/promos/create.php'); ?>" class="promos-btn primary">
        <i class="bi bi-plus-circle"></i> Tambah Promo
    </a>
</div>
    </header>

    <section class="promos-grid">
        <div class="promos-card"><div class="icon"><i class="bi bi-ticket-perforated"></i></div><span>Total Promo</span><h3><?= e($total); ?></h3><p>Semua promo/voucher</p></div>
        <div class="promos-card"><div class="icon"><i class="bi bi-check-circle"></i></div><span>Aktif</span><h3><?= e($active); ?></h3><p>Promo aktif</p></div>
        <div class="promos-card"><div class="icon"><i class="bi bi-upc-scan"></i></div><span>Voucher</span><h3><?= e($vouchers); ?></h3><p>Kode atau barcode</p></div>
        <div class="promos-card"><div class="icon"><i class="bi bi-box-seam"></i></div><span>Khusus Produk</span><h3><?= e($specific); ?></h3><p>Berlaku item tertentu</p></div>
    </section>

    <section class="promos-panel">
        <div class="promos-panel-head">
            <div>
                <h3>Daftar Promo</h3>
                <p>Promo bisa berlaku untuk semua produk atau hanya produk yang dipilih.</p>
            </div>
        </div>

        <div class="dt-hint-card">
            <div class="dt-hint-icon"><i class="bi bi-ticket-perforated"></i></div>
            <div class="dt-hint-content">
                <span class="dt-hint-title">Pencarian Promo</span>
                <p class="dt-hint-text">Cari promo berdasarkan nama, kode, barcode, tipe, produk khusus, periode, atau status.</p>
            </div>
        </div>

        <div class="promos-table-wrap">
            <table class="promos-table table">
                <thead>
                    <tr>
                        <th>Promo</th>
                        <th>Kode</th>
                        <th>Barcode</th>
                        <th>Berlaku</th>
                        <th>Tipe</th>
                        <th>Diskon</th>
                        <th>Min Belanja</th>
                        <th>Limit</th>
                        <th>Periode</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promos as $promo): ?>
                        <tr>
                            <td>
                                <div class="promo-main">
                                    <div class="promo-icon"><i class="bi bi-ticket-perforated"></i></div>
                                    <div><b><?= e($promo['name']); ?></b><small><?= e($promo['note'] ?: 'Tanpa catatan'); ?></small></div>
                                </div>
                            </td>
                            <td><span class="promo-badge blue"><?= e($promo['code'] ?: '-'); ?></span></td>
                            <td><span class="promo-badge gray"><?= e($promo['barcode'] ?: '-'); ?></span></td>
                            <td><span class="promo-badge <?= ($promo['promo_scope'] ?? 'all') === 'specific_products' ? 'orange' : 'green'; ?>"><?= e(promo_scope_text($promo)); ?></span></td>
                            <td><?= $promo['promo_type'] === 'voucher' ? '<span class="promo-badge orange">Voucher</span>' : '<span class="promo-badge green">Auto</span>'; ?></td>
                            <td><b><?= promo_discount_text($promo); ?></b></td>
                            <td><?= rupiah($promo['min_purchase']); ?></td>
                            <td><?= (int)$promo['usage_limit'] > 0 ? e($promo['used_count'] . ' / ' . $promo['usage_limit']) : 'Tanpa limit'; ?></td>
                            <td><?= e(($promo['start_date'] ?: '-') . ' s/d ' . ($promo['end_date'] ?: '-')); ?></td>
                            <td><?= promo_status_badge($promo); ?></td>
                            <td><div class="promo-actions">
    <a class="promo-icon-btn barcode" 
       href="<?= legacy_base_url('admin/promos/barcode.php?id=' . $promo['id']); ?>" 
       title="Lihat Barcode">
        <i class="bi bi-upc-scan"></i>
    </a>

    <a class="promo-icon-btn edit" 
       href="<?= legacy_base_url('admin/promos/edit.php?id=' . $promo['id']); ?>" 
       title="Edit Promo">
        <i class="bi bi-pencil"></i>
    </a>

    <a class="promo-icon-btn delete btn-delete" 
       href="<?= legacy_base_url('admin/promos/delete.php?id=' . $promo['id']); ?>" 
       title="Hapus Promo">
        <i class="bi bi-trash"></i>
    </a>
</div></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
