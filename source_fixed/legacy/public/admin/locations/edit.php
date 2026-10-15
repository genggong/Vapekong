<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../branches/_helpers.php';
Auth::requireRole(['super_admin','owner','admin']);
$pageTitle='Atur Lokasi Barang'; $db=Database::connect(); branch_install($db); location_install($db);
$productId=(int)($_GET['product_id']??($_POST['product_id']??0)); $branchId=(int)($_GET['branch_id']??($_POST['branch_id']??0));
if(!branch_is_admin()) $branchId=branch_current_id($db);
$stmt=$db->prepare("SELECT * FROM products WHERE id=? LIMIT 1"); $stmt->execute([$productId]); $product=$stmt->fetch();
if(!$product){ flash('error','Produk tidak ditemukan.'); legacy_redirect('admin/locations/index.php'); }
$location=location_product($db,$productId,$branchId); $branches=location_branch_options($db);
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!CSRF::verify()){ flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/locations/edit.php?product_id='.$productId.'&branch_id='.$branchId); }
 $branchId=(int)($_POST['branch_id']??$branchId); if(!branch_is_admin())$branchId=branch_current_id($db);
 $area=trim($_POST['area']??''); $rack=trim($_POST['rack']??''); $shelf=trim($_POST['shelf']??''); $binBox=trim($_POST['bin_box']??''); $note=trim($_POST['note']??'');
 if($area===''&&$rack===''&&$shelf===''&&$binBox===''){ flash('error','Isi minimal salah satu: area, rak, shelf/baris, atau box.'); legacy_redirect('admin/locations/edit.php?product_id='.$productId.'&branch_id='.$branchId); }
 location_save($db,$productId,$branchId?:null,$area,$rack,$shelf,$binBox,$note);
 Auth::log('update_product_location','Update lokasi produk '.$product['name']); flash('success','Lokasi barang berhasil disimpan.'); legacy_redirect('admin/locations/index.php?branch_id='.$branchId);
}
require_once __DIR__.'/../../layouts/admin-header.php'; require_once __DIR__.'/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>"><link rel="stylesheet" href="<?= legacy_base_url('assets/css/locations.css?v=' . time()); ?>">
<main class="admin-main system-wrap"><header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Atur Lokasi Barang</h1><p><?= e($product['name']); ?> • <?= e($product['product_code']?:'-'); ?></p></div></header>
<form method="POST" class="system-form-card"><?= CSRF::input(); ?><input type="hidden" name="product_id" value="<?= e($productId); ?>">
<div class="location-edit-head"><span class="location-detail-thumb"><?php if(!empty($product['image'])): ?><img loading="lazy" src="<?= legacy_base_url($product['image']); ?>"><?php else: ?><i class="bi bi-box-seam"></i><?php endif; ?></span><div><h2><?= e($product['name']); ?></h2><p>Kode: <?= e($product['product_code']?:'-'); ?> • Barcode: <?= e($product['barcode']?:'-'); ?></p></div></div>
<div class="system-form-grid"><?php if(branch_is_admin()): ?><div class="system-field"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?= e($b['id']); ?>" <?= (int)$b['id']===$branchId?'selected':''; ?>><?= e($b['name']); ?> (<?= e($b['code']); ?>)</option><?php endforeach; ?></select><small>Lokasi bisa berbeda setiap cabang.</small></div><?php else: ?><input type="hidden" name="branch_id" value="<?= e($branchId); ?>"><?php endif; ?>
<div class="system-field"><label>Area</label><input name="area" value="<?= e($location['area']??''); ?>" placeholder="Contoh: Gudang Depan"></div>
<div class="system-field"><label>Rak</label><input name="rack" value="<?= e($location['rack']??''); ?>" placeholder="Contoh: Rak A"></div>
<div class="system-field"><label>Shelf / Baris</label><input name="shelf" value="<?= e($location['shelf']??''); ?>" placeholder="Contoh: Baris 2"></div>
<div class="system-field"><label>Box / Kolom</label><input name="bin_box" value="<?= e($location['bin_box']??''); ?>" placeholder="Contoh: Box 03"></div>
<div class="system-field system-wide"><label>Catatan</label><textarea name="note" rows="3" placeholder="Contoh: dekat kasir, bagian promo"><?= e($location['note']??''); ?></textarea></div></div>
<div class="system-form-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/locations/index.php?branch_id='.$branchId); ?>">Batal</a><button class="system-btn primary"><i class="bi bi-save"></i> Simpan Lokasi</button></div></form></main>
<?php require_once __DIR__.'/../../layouts/admin-footer.php'; ?>