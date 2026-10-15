<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
Auth::requirePermission('settings.view');
$pageTitle='Kurir Toko';
$db=Database::connect();marketplace_install($db);
$editId=max(0,(int)($_GET['edit']??0));$edit=$editId?marketplace_store_courier_get($db,$editId):null;
$branches=[];try{$branches=$db->query("SELECT id,name,is_main FROM branches WHERE is_active=1 ORDER BY is_main DESC,name ASC")->fetchAll()?:[];}catch(Throwable $e){}
$rows=$db->query("SELECT c.*,b.name branch_name,(SELECT COUNT(*) FROM marketplace_courier_assignments a WHERE a.courier_id=c.id) total_jobs,(SELECT COUNT(*) FROM marketplace_courier_assignments a WHERE a.courier_id=c.id AND a.status IN('assigned','accepted','picked_up','on_the_way','failed')) active_jobs,(SELECT COUNT(*) FROM marketplace_courier_assignments a WHERE a.courier_id=c.id AND a.status='delivered') delivered_jobs FROM marketplace_couriers c LEFT JOIN branches b ON b.id=c.branch_id ORDER BY c.status='active' DESC,c.name ASC")->fetchAll()?:[];
require_once __DIR__.'/../../layouts/admin-header.php';require_once __DIR__.'/../../layouts/admin-sidebar.php';
?>
<style>
.courier-admin-grid{display:grid;grid-template-columns:minmax(300px,.75fr) minmax(0,1.45fr);gap:18px;margin-top:18px}.courier-form{display:grid;gap:12px}.courier-form label{display:grid;gap:6px;font-weight:800;color:#334155}.courier-form input,.courier-form select{width:100%}.courier-stats{display:flex;gap:8px;flex-wrap:wrap}.courier-stats span{padding:7px 10px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;font-size:12px;font-weight:800}.portal-copy{padding:14px;border:1px solid #ddd6fe;border-radius:16px;background:#faf5ff;overflow-wrap:anywhere}.portal-copy b{display:block;color:#6d28d9;margin-bottom:4px}@media(max-width:960px){.courier-admin-grid{grid-template-columns:1fr}}
</style>
<main class="admin-main">
<header class="admin-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Kurir Toko</h1><p>Kelola akun kurir internal yang mengantar pesanan marketplace.</p></div><div class="topbar-actions"><a class="btn-soft" href="<?= legacy_base_url('admin/marketplace-courier-deliveries/index.php'); ?>"><i class="bi bi-truck"></i> Monitor Paket</a><a class="btn-premium" target="_blank" rel="noopener" href="<?= legacy_base_url('kurir/login'); ?>"><i class="bi bi-box-arrow-up-right"></i> Portal Kurir</a></div></header>
<div class="courier-admin-grid">
<section class="premium-card"><div class="card-head"><div><h3><?= $edit?'Edit Kurir':'Tambah Kurir'; ?></h3><p>Akun ini khusus portal kurir dan terpisah dari login admin/POS.</p></div></div>
<form class="courier-form" method="post" action="<?= legacy_base_url('admin/marketplace-couriers/action.php'); ?>"><?= CSRF::input(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id']??0); ?>">
<label>Nama Kurir<input name="name" required maxlength="160" value="<?= e($edit['name']??''); ?>" placeholder="Nama lengkap"></label>
<label>Username<input name="username" required maxlength="100" value="<?= e($edit['username']??''); ?>" placeholder="contoh: kurir01" autocomplete="off"></label>
<label>Nomor WhatsApp<input name="phone" required maxlength="30" value="<?= e($edit['phone']??''); ?>" placeholder="08xxxxxxxxxx"></label>
<label>Email <small>(opsional)</small><input type="email" name="email" maxlength="190" value="<?= e($edit['email']??''); ?>"></label>
<label>Cabang<select name="branch_id"><option value="0">Semua Cabang</option><?php foreach($branches as $b): ?><option value="<?= (int)$b['id']; ?>" <?= (int)($edit['branch_id']??0)===(int)$b['id']?'selected':''; ?>><?= e($b['name'].((int)$b['is_main']===1?' • Utama':'')); ?></option><?php endforeach; ?></select></label>
<label>Kendaraan<select name="vehicle_type"><?php foreach(['Motor','Mobil','Mobil Box','Sepeda','Lainnya'] as $vehicle): ?><option <?= ($edit['vehicle_type']??'Motor')===$vehicle?'selected':''; ?>><?= e($vehicle); ?></option><?php endforeach; ?></select></label>
<label>Nomor Kendaraan<input name="vehicle_number" maxlength="80" value="<?= e($edit['vehicle_number']??''); ?>" placeholder="BE 1234 XX / opsional"></label>
<label>Status<select name="status"><option value="active" <?= ($edit['status']??'active')==='active'?'selected':''; ?>>Aktif</option><option value="inactive" <?= ($edit['status']??'')==='inactive'?'selected':''; ?>>Nonaktif</option></select></label>
<label>Password <?= $edit?'<small>(kosongkan jika tidak diganti)</small>':''; ?><input type="password" name="password" <?= $edit?'':'required'; ?> minlength="6" maxlength="120" autocomplete="new-password" placeholder="Minimal 6 karakter"></label>
<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn-premium"><i class="bi bi-save"></i> <?= $edit?'Simpan Perubahan':'Buat Akun Kurir'; ?></button><?php if($edit): ?><a class="btn-soft" href="<?= legacy_base_url('admin/marketplace-couriers/index.php'); ?>">Batal</a><?php endif; ?></div>
</form>
<div class="portal-copy" style="margin-top:16px"><b>Alamat Portal Kurir</b><span><?= e(legacy_base_url('kurir/login')); ?></span></div>
</section>
<section class="premium-card"><div class="card-head"><div><h3>Daftar Kurir</h3><p>Kurir hanya dapat membuka paket yang ditugaskan kepadanya.</p></div><span class="badge-soft info"><?= count($rows); ?> kurir</span></div>
<div class="table-premium-card dt-premium-wrap"><table class="premium-table js-data-table nowrap" data-title="kurir-toko" data-table-width="1150"><thead><tr><th>Kurir</th><th>Cabang</th><th>Kendaraan</th><th>Tugas</th><th>Status</th><th>Login Terakhir</th><th class="text-end">Aksi</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><b><?= e($r['name']); ?></b><br><small>@<?= e($r['username']); ?> • <?= e($r['phone']); ?></small></td><td><?= e($r['branch_name']?:'Semua Cabang'); ?></td><td><?= e($r['vehicle_type']); ?><br><small><?= e($r['vehicle_number']?:'-'); ?></small></td><td><div class="courier-stats"><span>Aktif <?= (int)$r['active_jobs']; ?></span><span>Selesai <?= (int)$r['delivered_jobs']; ?></span><span>Total <?= (int)$r['total_jobs']; ?></span></div></td><td><span class="badge-soft <?= $r['status']==='active'?'success':'danger'; ?>"><?= $r['status']==='active'?'Aktif':'Nonaktif'; ?></span></td><td><?= $r['last_login_at']?date('d/m/Y H:i',strtotime($r['last_login_at'])):'Belum pernah'; ?></td><td class="text-end"><a class="btn-soft" href="<?= legacy_base_url('admin/marketplace-couriers/index.php'); ?>?edit=<?= (int)$r['id']; ?>"><i class="bi bi-pencil"></i> Edit</a></td></tr><?php endforeach; ?>
</tbody></table></div></section>
</div></main>
<?php require_once __DIR__.'/../../layouts/admin-footer.php'; ?>
