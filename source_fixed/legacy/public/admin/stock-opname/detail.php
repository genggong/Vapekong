<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../locations/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle = 'Detail Stock Opname';
$db = Database::connect();
location_install($db);
opname_ensure_tables($db);
$id = (int) ($_GET['id'] ?? 0);
$opname = opname_get($db, $id);
if (!$opname) { flash('error','Sesi opname tidak ditemukan.'); legacy_redirect('admin/stock-opname/index.php'); }
$items = opname_items($db, $id);
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/stock-opname.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/locations.css?v=' . time()); ?>">
<main class="admin-main stock-opname-page">
    <header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Detail Stock Opname</h1><p><?= e($opname['code']); ?> • <?= e($opname['title']); ?></p></div><div class="topbar-actions"><a class="opname-btn soft" href="<?= legacy_base_url('admin/stock-opname/index.php'); ?>"><i class="bi bi-arrow-left"></i> Kembali</a></div></header>
    <section class="opname-grid">
        <div class="opname-stat"><div class="icon"><i class="bi bi-boxes"></i></div><span>Produk</span><h3><?= e($opname['total_items']); ?></h3></div>
        <div class="opname-stat"><div class="icon"><i class="bi bi-check2-square"></i></div><span>Dicek</span><h3><?= e($opname['total_checked']); ?></h3></div>
        <div class="opname-stat"><div class="icon"><i class="bi bi-arrow-up-circle"></i></div><span>Selisih Plus</span><h3>+<?= e($opname['total_plus']); ?></h3><small><?= rupiah($opname['total_plus_value'] ?? 0); ?></small></div>
        <div class="opname-stat"><div class="icon"><i class="bi bi-arrow-down-circle"></i></div><span>Selisih Minus</span><h3>-<?= e($opname['total_minus']); ?></h3><small><?= rupiah($opname['total_minus_value'] ?? 0); ?></small></div>
    </section>
    <section class="opname-card">
        <div class="opname-card-head"><div><h3><?= e($opname['title']); ?></h3><p>Status: <?= opname_status_badge($opname['status']); ?> • Petugas: <?= e($opname['user_name'] ?: '-'); ?> • Dibuat: <?= e(date('d/m/Y H:i', strtotime($opname['created_at']))); ?></p></div><div class="opname-actions"><?php if($opname['status']==='draft'): ?><a class="opname-btn primary" href="<?= legacy_base_url('admin/stock-opname/edit.php?id=' . $id); ?>"><i class="bi bi-pencil"></i> Lanjut Input</a><?php endif; ?><a class="opname-btn dark" href="<?= legacy_base_url('admin/stock-opname/print.php?id=' . $id); ?>" target="_blank"><i class="bi bi-printer"></i> Cetak</a></div></div>
        <?php if (!empty($opname['note'])): ?><div class="opname-note"><i class="bi bi-journal-text"></i><span><?= e($opname['note']); ?></span></div><?php endif; ?>
        <div class="opname-table-wrap"><table class="opname-table"><thead><tr><th>Produk</th><th>Stok Sistem</th><th>Stok Fisik</th><th>Selisih Qty</th><th>Nilai Selisih</th><th>Alasan</th><th>Catatan</th></tr></thead><tbody><?php foreach($items as $it): $diff=(int)$it['difference']; ?><tr><td><div class="opname-product"><span class="opname-product-img"><?php if(!empty($it['image'])): ?><img loading="lazy" src="<?= legacy_base_url($it['image']); ?>" alt="produk"><?php else: ?><i class="bi bi-box-seam"></i><?php endif; ?></span><div><b><?= e($it['product_name']); ?></b><small><?= e($it['product_code']); ?> • <?= e($it['unit']); ?></small></div></div></td><td><?= e($it['system_stock']); ?></td><td><?= $it['physical_stock']===null ? '-' : e($it['physical_stock']); ?></td><td class="<?= $diff>0?'diff-plus':($diff<0?'diff-minus':'diff-zero'); ?>"><?= $it['physical_stock']===null ? '-' : e($diff); ?></td><td class="<?= $diff>0?'diff-plus':($diff<0?'diff-minus':'diff-zero'); ?>"><?= $it['physical_stock']===null ? '-' : (($diff>0?'+':($diff<0?'-':'')) . rupiah(abs((float)($it['difference_value'] ?? 0)))); ?></td><td><?= e(opname_reason_label($it['reason'])); ?></td><td><?= e($it['note'] ?: '-'); ?></td></tr><?php endforeach; ?></tbody></table></div>
    </section>
</main>
<?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?><script>document.addEventListener('DOMContentLoaded',function(){if(window.Swal){Swal.fire({icon:'success',title:'Stock Opname Final',text:'Stok produk sudah disesuaikan dari hasil opname.',confirmButtonColor:'#991b1b'});} });</script><?php endif; ?>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
