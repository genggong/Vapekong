<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../branches/_helpers.php';
Auth::requireRole(['super_admin','owner','admin']);
$pageTitle='Rak / Lokasi Barang';
$db=Database::connect(); branch_install($db); location_install($db);
$currentBranch=branch_current($db); $currentBranchId=(int)($currentBranch['id']??0);
$branchId=isset($_GET['branch_id'])?(int)$_GET['branch_id']:$currentBranchId;
if(!branch_is_admin()) $branchId=$currentBranchId;
$keyword=trim($_GET['q']??'');
$where="WHERE p.is_active=1"; $params=[];
if($keyword!==''){ $where.=" AND (p.name LIKE ? OR p.product_code LIKE ? OR p.barcode LIKE ? OR pl.area LIKE ? OR pl.rack LIKE ? OR pl.shelf LIKE ? OR pl.bin_box LIKE ?)"; $like='%'.$keyword.'%'; $params=[$like,$like,$like,$like,$like,$like,$like];}
$sql="SELECT p.id,p.name,p.product_code,p.barcode,p.unit,p.image,p.rack_location,COALESCE(pbs.stock,p.stock) AS stock,c.name AS category_name,pl.area,pl.rack,pl.shelf,pl.bin_box,pl.note
FROM products p
LEFT JOIN categories c ON c.id=p.category_id
LEFT JOIN product_branch_stocks pbs ON pbs.product_id=p.id AND pbs.branch_id=?
LEFT JOIN product_locations pl ON pl.product_id=p.id AND ".($branchId>0?"pl.branch_id=?":"pl.branch_id IS NULL")."
$where ORDER BY COALESCE(pl.area,''),COALESCE(pl.rack,''),COALESCE(pl.shelf,''),p.name ASC";
$exec=[$branchId]; if($branchId>0)$exec[]=$branchId; $exec=array_merge($exec,$params);
$stmt=$db->prepare($sql); $stmt->execute($exec); $rows=$stmt->fetchAll(); $branches=location_branch_options($db);
require_once __DIR__.'/../../layouts/admin-header.php'; require_once __DIR__.'/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/locations.css?v=' . time()); ?>">
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Rak / Lokasi Barang</h1><p>Atur posisi barang supaya stok opname dan pencarian produk lebih cepat.</p></div><div class="topbar-actions"><a href="<?= legacy_base_url('admin/locations/bulk.php?branch_id='.$branchId); ?>" class="system-btn soft"><i class="bi bi-pencil-square"></i> Input Cepat</a></div></header>
<section class="location-stats">
<div class="location-stat"><div class="location-stat-icon"><i class="bi bi-box-seam"></i></div><span>Total Produk</span><h3><?= e(count($rows)); ?></h3></div>
<div class="location-stat"><div class="location-stat-icon"><i class="bi bi-geo-alt-fill"></i></div><span>Sudah Ada Lokasi</span><h3><?= e(count(array_filter($rows,fn($r)=>location_label($r)!=='-' || !empty($r['rack_location'])))); ?></h3></div>
<div class="location-stat"><div class="location-stat-icon"><i class="bi bi-exclamation-circle"></i></div><span>Belum Ada Lokasi</span><h3><?= e(count(array_filter($rows,fn($r)=>location_label($r)==='-' && empty($r['rack_location'])))); ?></h3></div>
<div class="location-stat"><div class="location-stat-icon"><i class="bi bi-shop-window"></i></div><span>Cabang</span><h3><?= e($branchId?branch_name($db,$branchId):'Global'); ?></h3></div>
</section>
<section class="system-form-card"><form method="GET" class="location-filter"><div class="system-field"><label>Cari Produk / Rak</label><input name="q" value="<?= e($keyword); ?>" placeholder="Cari nama produk, kode, area, rak..."></div><?php if(branch_is_admin()): ?><div class="system-field"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?= e($b['id']); ?>" <?= (int)$b['id']===$branchId?'selected':''; ?>><?= e($b['name']); ?> (<?= e($b['code']); ?>)</option><?php endforeach; ?></select></div><?php endif; ?><div class="system-field location-filter-actions"><label>&nbsp;</label><button class="system-btn primary"><i class="bi bi-search"></i> Filter</button></div></form></section>
<section class="system-form-card"><div class="table-responsive"><table class="premium-table"><thead><tr><th>Produk</th><th>Kategori</th><th>Stok</th><th>Lokasi</th><th>Catatan</th><th class="text-end">Aksi</th></tr></thead><tbody>
<?php foreach($rows as $row): $loc=location_label($row); if($loc==='-'&&!empty($row['rack_location']))$loc=$row['rack_location']; ?>
<tr><td><div class="location-product-cell"><span class="location-thumb"><?php if(!empty($row['image'])): ?><img loading="lazy" src="<?= legacy_base_url($row['image']); ?>"><?php else: ?><i class="bi bi-box-seam"></i><?php endif; ?></span><div><b><?= e($row['name']); ?></b><br><small><?= e($row['product_code']?:'-'); ?> • <?= e($row['unit']?:'pcs'); ?></small></div></div></td><td><?= e($row['category_name']?:'-'); ?></td><td><b><?= e((int)$row['stock']); ?></b></td><td><?= $loc!=='-'?'<span class="location-badge"><i class="bi bi-geo-alt-fill"></i> '.e($loc).'</span>':'<span class="location-empty">Belum diatur</span>'; ?></td><td><?= e($row['note']?:'-'); ?></td><td class="text-end table-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/locations/edit.php?product_id='.$row['id'].'&branch_id='.$branchId); ?>"><i class="bi bi-pencil"></i> Atur</a></td></tr>
<?php endforeach; if(!$rows): ?><tr><td colspan="6" class="text-center">Data tidak ditemukan.</td></tr><?php endif; ?>
</tbody></table></div></section></main>
<?php require_once __DIR__.'/../../layouts/admin-footer.php'; ?>