<?php
require_once __DIR__.'/../../../app/bootstrap.php';
require_once __DIR__.'/../../../app/Modules/Marketplace/Marketplace.php';
Auth::requireLogin();
$db=Database::connect();
marketplace_install($db);
$pageTitle='Brand Marketplace';
$err='';

// V15.10: an old partial table is upgraded before this page reads/writes it.
$brandStatus=marketplace_doctor_status($db)['product']['marketplace_brands']??null;
if(!$brandStatus || !$brandStatus['ok']) marketplace_doctor_repair($db);

function mp15_brand_upload(array $f):?string{
    if(empty($f['name'])||empty($f['tmp_name']))return null;
    if(($f['error']??0)!==UPLOAD_ERR_OK)throw new RuntimeException('Upload logo gagal.');
    if(($f['size']??0)>3*1024*1024)throw new RuntimeException('Logo maksimal 3MB.');
    $info=@getimagesize($f['tmp_name']);
    $ext=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp'][$info[2]??0]??null;
    if(!$ext)throw new RuntimeException('Logo harus JPG/PNG/WEBP.');
    $dir=__DIR__.'/../../uploads/storefront/brands';
    if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir))throw new RuntimeException('Folder logo tidak dapat dibuat.');
    $n='brand-'.date('YmdHis').'-'.bin2hex(random_bytes(3)).'.'.$ext;
    if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$n))throw new RuntimeException('Logo gagal disimpan.');
    return 'uploads/storefront/brands/'.$n;
}
function mp1510_brand_slug(PDO $db,string $name,int $excludeId=0):string{
    $base=slugify($name); if($base==='')$base='brand';
    $s=$db->prepare('SELECT id,name FROM marketplace_brands WHERE slug=? AND id<>? LIMIT 1');
    $s->execute([$base,$excludeId]);$r=$s->fetch();
    if(!$r)return $base;
    if(mb_strtolower(trim((string)$r['name']))===mb_strtolower(trim($name))){
        throw new RuntimeException('Brand "'.$name.'" sudah ada. Silakan edit brand yang sudah tersimpan, tidak perlu menambah ulang.');
    }
    for($i=2;$i<1000;$i++){
        $candidate=$base.'-'.$i;$s->execute([$candidate,$excludeId]);if(!$s->fetch())return $candidate;
    }
    return $base.'-'.time();
}

if(is_post()){
    if(!CSRF::verify())$err='Token keamanan tidak valid.';
    else try{
        $a=$_POST['action']??'save';$id=(int)($_POST['id']??0);
        if($a==='delete'){
            if($id){
                // Do not leave products pointing to a deleted brand.
                try{$db->prepare('UPDATE marketplace_product_meta SET brand_id=NULL WHERE brand_id=?')->execute([$id]);}catch(Throwable $ignore){}
                if(marketplace_column_exists($db,'products','brand_id'))try{$db->prepare('UPDATE products SET brand_id=NULL WHERE brand_id=?')->execute([$id]);}catch(Throwable $ignore){}
                $db->prepare('DELETE FROM marketplace_brands WHERE id=?')->execute([$id]);
            }
            flash('success','Brand dihapus.');legacy_redirect('admin/marketplace-brands/index.php');
        }
        $name=trim((string)($_POST['name']??''));if($name==='')throw new InvalidArgumentException('Nama brand wajib diisi.');
        $slug=mp1510_brand_slug($db,$name,$id);
        $logo=mp15_brand_upload($_FILES['logo']??[]);$desc=trim((string)($_POST['description']??''));$active=isset($_POST['is_active'])?1:0;$sort=(int)($_POST['sort_order']??0);
        if($id){
            $old=marketplace_v15_brand($db,$id);if(!$old)throw new RuntimeException('Brand yang akan diedit tidak ditemukan. Jalankan Cek Database Marketplace.');
            $logo=$logo?:($old['logo']??null);
            $s=$db->prepare('UPDATE marketplace_brands SET name=?,slug=?,logo=?,description=?,is_active=?,sort_order=?,updated_at=NOW() WHERE id=?');
            $s->execute([$name,$slug,$logo,$desc?:null,$active,$sort,$id]);
        }else{
            $s=$db->prepare('INSERT INTO marketplace_brands(name,slug,logo,description,is_active,sort_order,created_at) VALUES(?,?,?,?,?,?,NOW())');
            $s->execute([$name,$slug,$logo,$desc?:null,$active,$sort]);$id=(int)$db->lastInsertId();
        }
        $saved=marketplace_v15_brand($db,$id);if(!$saved)throw new RuntimeException('Brand belum terbaca kembali dari database setelah disimpan. Buka Cek Database Marketplace lalu jalankan upgrade.');
        marketplace_v15_log($db,'brand.save','brand',$id,$name);
        flash('success','Brand marketplace disimpan dan sudah diverifikasi di database.');legacy_redirect('admin/marketplace-brands/index.php');
    }catch(Throwable $e){$err=$e->getMessage();}
}
$edit=isset($_GET['edit'])?marketplace_v15_brand($db,(int)$_GET['edit']):null;$rows=marketplace_v15_brands($db,false);$ok=flash('success');
require __DIR__.'/../../layouts/admin-header.php';require __DIR__.'/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?=legacy_base_url('assets/css/marketplace-customer-admin.css?v=15.10')?>">
<main class="admin-main mp-admin"><header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Brand / Merek</h1><p>Master merek marketplace terhubung ke produk POS. Brand bersifat opsional.</p></div><div class="topbar-actions"><a class="mp-btn soft" href="<?=legacy_base_url('admin/marketplace-database/index.php')?>"><i class="bi bi-database-check"></i> Cek Database</a></div></header>
<?php if($ok):?><div class="mp-alert success"><?=e($ok)?></div><?php endif;?><?php if($err):?><div class="mp-alert error"><?=e($err)?></div><?php endif;?>
<section class="mp-admin-grid two"><section class="mp-panel"><div class="mp-panel-head"><h3><?=$edit?'Edit Brand':'Brand Baru'?></h3></div><form method="post" enctype="multipart/form-data" class="mp-form"><?=CSRF::input()?><input type="hidden" name="id" value="<?=e($edit['id']??0)?>"><label>Nama<input name="name" value="<?=e($edit['name']??'')?>" required placeholder="Contoh: JNC / Vaporesso / OXVA"></label><label>Logo<input type="file" name="logo" accept="image/jpeg,image/png,image/webp"></label><label>Deskripsi<textarea name="description" rows="3"><?=e($edit['description']??'')?></textarea></label><label>Urutan<input type="number" name="sort_order" value="<?=e($edit['sort_order']??0)?>"></label><label class="mp-check"><input type="checkbox" name="is_active" <?=$edit&&!$edit['is_active']?'':'checked'?>> Aktif</label><button class="mp-btn" type="submit"><i class="bi bi-save"></i> Simpan</button></form></section>
<section class="mp-panel"><div class="mp-panel-head"><div><h3>Daftar Brand</h3><p><?=count($rows)?> brand terbaca dari database aktif.</p></div></div><div class="table-premium-card"><table class="premium-table"><thead><tr><th>Brand</th><th>Status</th><th>Urutan</th><th>Aksi</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><b><?=e($r['name'])?></b><br><small><?=e($r['slug'])?></small></td><td><?=$r['is_active']?'Aktif':'Off'?></td><td><?=e($r['sort_order'])?></td><td><a class="mp-icon" href="?edit=<?=$r['id']?>"><i class="bi bi-pencil"></i></a><form method="post" style="display:inline" onsubmit="return confirm('Hapus brand? Produk yang memakai brand ini akan kembali Tanpa brand.')"><?=CSRF::input()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="mp-icon danger"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="4"><div class="empty">Belum ada brand. Produk tetap dapat digunakan sebagai Tanpa brand.</div></td></tr><?php endif;?></tbody></table></div></section></section></main>
<?php require __DIR__.'/../../layouts/admin-footer.php';?>
