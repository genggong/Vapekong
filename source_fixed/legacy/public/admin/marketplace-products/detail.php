<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
Auth::requireLogin();
$db = Database::connect();
marketplace_install($db);
$doctorBefore = marketplace_doctor_status($db);
$doctorNeedsRepair = false;
foreach (($doctorBefore['product'] ?? []) as $ds) { if (empty($ds['ok'])) { $doctorNeedsRepair = true; break; } }
if ($doctorNeedsRepair) marketplace_doctor_repair($db);
$id = (int)($_GET['id'] ?? 0);
$s = $db->prepare("SELECT p.*,c.name category_name,s.name supplier_name FROM products p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE p.id=? LIMIT 1");
$s->execute([$id]);
$product = $s->fetch();
if (!$product) { http_response_code(404); exit('Produk tidak ditemukan'); }
$productMeta = marketplace_v15_product_meta($db, $id);
$product['brand_id'] = (int)($productMeta['brand_id'] ?? 0);
$product['weight_gram'] = marketplace_v15_product_weight($db, $id, marketplace_default_weight());
$pageTitle = 'Kelola Marketplace - ' . $product['name'];
$err = '';

function mp159_product_image_upload(array $f): ?string {
    if (empty($f['name']) || empty($f['tmp_name'])) return null;
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload gambar gagal.');
    if (($f['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('Gambar maksimal 5MB.');
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_JPEG=>'jpg', IMAGETYPE_PNG=>'png', IMAGETYPE_WEBP=>'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) throw new RuntimeException('Gambar harus JPG/PNG/WEBP.');
    $dir = __DIR__ . '/../../uploads/storefront/products';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Folder upload tidak dapat dibuat.');
    $n = 'product-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $n)) throw new RuntimeException('Gambar gagal disimpan.');
    return 'uploads/storefront/products/' . $n;
}


function mp159_multi_files(array $files): array {
    if (!isset($files['name']) || !is_array($files['name'])) return [];
    $out=[];
    foreach($files['name'] as $i=>$name){
        if(trim((string)$name)==='') continue;
        $out[]=[
            'name'=>$name,
            'type'=>$files['type'][$i]??'',
            'tmp_name'=>$files['tmp_name'][$i]??'',
            'error'=>$files['error'][$i]??UPLOAD_ERR_NO_FILE,
            'size'=>$files['size'][$i]??0,
        ];
    }
    return $out;
}

function mp159_parent_stock(PDO $db, int $productId, int $branchId): int {
    try {
        $q = $db->prepare("SELECT COALESCE(pbs.stock,CASE WHEN b.is_main=1 THEN p.stock ELSE 0 END,0) FROM products p JOIN branches b ON b.id=? LEFT JOIN product_branch_stocks pbs ON pbs.product_id=p.id AND pbs.branch_id=b.id WHERE p.id=? LIMIT 1");
        $q->execute([$branchId,$productId]);
        return max(0,(int)$q->fetchColumn());
    } catch (Throwable $e) {
        $q = $db->prepare("SELECT COALESCE(stock,0) FROM products WHERE id=? LIMIT 1");
        $q->execute([$productId]);
        return max(0,(int)$q->fetchColumn());
    }
}

if (is_post()) {
    if (!CSRF::verify()) $err = 'Token keamanan tidak valid.';
    else try {
        $a = (string)($_POST['action'] ?? '');
        $successMessage = 'Perubahan marketplace produk disimpan.';
        if ($a === 'brand') {
            $brand = max(0,(int)($_POST['brand_id'] ?? 0));
            if ($brand > 0 && !marketplace_v15_brand($db,$brand)) throw new InvalidArgumentException('Brand yang dipilih tidak ditemukan. Buka Cek Database Marketplace bila brand baru tidak muncul.');
            marketplace_v15_set_product_brand($db,$id,$brand);
            $verifyBrand=marketplace_v15_product_brand_id($db,$id);
            if($verifyBrand!==$brand) throw new RuntimeException('Brand belum terbaca kembali setelah disimpan. Jalankan Cek & Upgrade Database Marketplace.');
            marketplace_v15_log($db,'product.brand','product',$id,'Brand produk diubah');
            $successMessage='Brand produk disimpan dan diverifikasi.';
        } elseif ($a === 'image_add') {
            $files=mp159_multi_files($_FILES['images'] ?? []);
            if(!$files && !empty($_FILES['image']['name'])) $files=[($_FILES['image'] ?? [])];
            if(!$files) throw new InvalidArgumentException('Pilih minimal satu gambar.');
            if(count($files)>12) throw new InvalidArgumentException('Maksimal 12 foto dalam sekali upload.');
            $primary = isset($_POST['is_primary']) ? 1 : 0;
            $sort=(int)($_POST['sort_order'] ?? 0);$saved=0;
            if ($primary) $db->prepare("UPDATE marketplace_product_images SET is_primary=0 WHERE product_id=?")->execute([$id]);
            foreach($files as $idx=>$f){
                $path = mp159_product_image_upload($f);
                if(!$path) continue;
                $isPrimary=($primary && $idx===0)?1:0;
                $db->prepare("INSERT INTO marketplace_product_images(product_id,image_path,is_primary,sort_order,created_at) VALUES(?,?,?,?,NOW())")->execute([$id,$path,$isPrimary,$sort+$idx]);
                $saved++;
                marketplace_v15_log($db,'product.image.add','product',$id,$path);
            }
            if($saved<1) throw new RuntimeException('Tidak ada foto yang berhasil disimpan.');
            $successMessage=$saved.' foto galeri berhasil disimpan.';
        } elseif ($a === 'image_delete') {
            $img=(int)($_POST['image_id']??0);
            $db->prepare("DELETE FROM marketplace_product_images WHERE id=? AND product_id=?")->execute([$img,$id]);
            $successMessage='Foto galeri dihapus.';
        } elseif ($a === 'image_primary') {
            $img=(int)($_POST['image_id']??0);
            $db->prepare("UPDATE marketplace_product_images SET is_primary=0 WHERE product_id=?")->execute([$id]);
            $db->prepare("UPDATE marketplace_product_images SET is_primary=1 WHERE id=? AND product_id=?")->execute([$img,$id]);
            $successMessage='Gambar utama marketplace diperbarui.';
        } elseif ($a === 'option_add') {
            $name = trim((string)($_POST['option_name'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('Nama tipe varian wajib diisi.');
            if (mb_strlen($name) > 100) throw new InvalidArgumentException('Nama tipe varian terlalu panjang.');
            $db->prepare("INSERT INTO marketplace_variant_options(product_id,name,sort_order,is_active,created_at) VALUES(?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order),is_active=1,updated_at=NOW()")->execute([$id,$name,(int)($_POST['sort_order']??0)]);
            $verify=$db->prepare('SELECT id FROM marketplace_variant_options WHERE product_id=? AND name=? LIMIT 1');$verify->execute([$id,$name]);$optionId=(int)$verify->fetchColumn();
            if($optionId<=0)throw new RuntimeException('Tipe varian belum tersimpan ke database. Jalankan Cek & Upgrade Database Marketplace.');
            marketplace_v15_log($db,'variant.option.add','product',$id,'Tipe varian: '.$name);
            $successMessage='Tipe varian "'.$name.'" berhasil disimpan.';
        } elseif ($a === 'option_delete') {
            $oid=(int)($_POST['option_id']??0);
            $chk=$db->prepare("SELECT id FROM marketplace_variant_options WHERE id=? AND product_id=? LIMIT 1");$chk->execute([$oid,$id]);
            if($chk->fetchColumn()){
                $vals=$db->prepare("SELECT id FROM marketplace_variant_option_values WHERE option_id=?");$vals->execute([$oid]);$ids=$vals->fetchAll(PDO::FETCH_COLUMN)?:[];
                if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$db->prepare("DELETE FROM marketplace_variant_value_map WHERE option_value_id IN ($ph)")->execute($ids);}
                $db->prepare("DELETE FROM marketplace_variant_option_values WHERE option_id=?")->execute([$oid]);
                $db->prepare("DELETE FROM marketplace_variant_options WHERE id=? AND product_id=?")->execute([$oid,$id]);
            }
            $successMessage='Tipe varian dihapus.';
        } elseif ($a === 'option_value_add') {
            $oid=(int)($_POST['option_id']??0);$value=trim((string)($_POST['option_value']??''));
            $chk=$db->prepare("SELECT name FROM marketplace_variant_options WHERE id=? AND product_id=? LIMIT 1");$chk->execute([$oid,$id]);$optionName=(string)($chk->fetchColumn()?:'');
            if($optionName===''||$value==='') throw new InvalidArgumentException('Tipe dan nilai varian wajib diisi.');
            $color=trim((string)($_POST['color_hex']??''));if($color!==''&&!preg_match('/^#[0-9A-Fa-f]{6}$/',$color))$color=null;
            $db->prepare("INSERT INTO marketplace_variant_option_values(option_id,value,color_hex,sort_order,is_active,created_at) VALUES(?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE color_hex=VALUES(color_hex),sort_order=VALUES(sort_order),is_active=1,updated_at=NOW()")->execute([$oid,$value,$color?:null,(int)($_POST['sort_order']??0)]);
            $verify=$db->prepare('SELECT id FROM marketplace_variant_option_values WHERE option_id=? AND value=? LIMIT 1');$verify->execute([$oid,$value]);
            if(!(int)$verify->fetchColumn())throw new RuntimeException('Nilai varian belum tersimpan ke database. Jalankan Cek & Upgrade Database Marketplace.');
            marketplace_v15_log($db,'variant.value.add','product',$id,$optionName.': '.$value);
            $successMessage=$optionName.' = '.$value.' berhasil disimpan.';
        } elseif ($a === 'option_value_delete') {
            $vid=(int)($_POST['value_id']??0);
            $chk=$db->prepare("SELECT v.id FROM marketplace_variant_option_values v JOIN marketplace_variant_options o ON o.id=v.option_id WHERE v.id=? AND o.product_id=? LIMIT 1");$chk->execute([$vid,$id]);
            if($chk->fetchColumn()){$db->prepare("DELETE FROM marketplace_variant_value_map WHERE option_value_id=?")->execute([$vid]);$db->prepare("DELETE FROM marketplace_variant_option_values WHERE id=?")->execute([$vid]);}
            $successMessage='Nilai varian dihapus.';
        } elseif ($a === 'variant_save') {
            $db->beginTransaction();
            try{
                $variantId=(int)($_POST['variant_id']??0);
                $manual=trim((string)($_POST['variant_name']??''));
                $sku=trim((string)($_POST['sku']??''))?:null;$barcode=trim((string)($_POST['barcode']??''))?:null;
                $sellRaw=(string)($_POST['selling_price']??'');$buyRaw=(string)($_POST['purchase_price']??'');
                $sell=$sellRaw===''?(float)$product['selling_price']:max(0,(float)$sellRaw);
                $buy=$buyRaw===''?(float)$product['purchase_price']:max(0,(float)$buyRaw);
                $weight=max(1,(int)($_POST['weight_gram']??$product['weight_gram']));$active=isset($_POST['is_active'])?1:0;$sort=(int)($_POST['sort_order']??0);
                $variantStock=max(0,(int)($_POST['variant_stock']??0));
                $variantImage=null;
                if(!empty($_FILES['variant_image']['name'])) $variantImage=mp159_product_image_upload($_FILES['variant_image']);
                $selected=is_array($_POST['option_value_ids']??null)?$_POST['option_value_ids']:[];
                $hasSelected=false;foreach($selected as $vv){if((int)$vv>0){$hasSelected=true;break;}}
                if(!$variantId && $manual==='' && !$hasSelected) throw new InvalidArgumentException('Pilih nilai opsi varian atau isi Nama Varian manual.');
                $isNewVariant=$variantId<=0;
                if(!$variantId && $manual==='') $manual='Varian';
                if($variantId){
                    $own=$db->prepare("SELECT id FROM marketplace_product_variants WHERE id=? AND product_id=? LIMIT 1");$own->execute([$variantId,$id]);if(!$own->fetchColumn())throw new RuntimeException('Varian tidak ditemukan.');
                    if($variantImage){
                        $db->prepare("UPDATE marketplace_product_variants SET name=?,sku=?,barcode=?,selling_price=?,purchase_price=?,weight_gram=?,image_path=?,is_active=?,sort_order=?,updated_at=NOW() WHERE id=? AND product_id=?")->execute([$manual?:'Varian',$sku,$barcode,$sell,$buy,$weight,$variantImage,$active,$sort,$variantId,$id]);
                    } else {
                        $db->prepare("UPDATE marketplace_product_variants SET name=?,sku=?,barcode=?,selling_price=?,purchase_price=?,weight_gram=?,is_active=?,sort_order=?,updated_at=NOW() WHERE id=? AND product_id=?")->execute([$manual?:'Varian',$sku,$barcode,$sell,$buy,$weight,$active,$sort,$variantId,$id]);
                    }
                } else {
                    $db->prepare("INSERT INTO marketplace_product_variants(product_id,name,sku,barcode,selling_price,purchase_price,weight_gram,image_path,is_active,sort_order,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW())")->execute([$id,$manual?:'Varian',$sku,$barcode,$sell,$buy,$weight,$variantImage,$active,$sort]);
                    $variantId=(int)$db->lastInsertId();
                }
                if($variantId<=0)throw new RuntimeException('ID varian tidak terbentuk setelah penyimpanan.');
                $derived=marketplace_v159_save_variant_values($db,$variantId,$id,$selected);
                $finalName=$derived!==''?$derived:$manual;
                if(trim($finalName)===''||$finalName==='Varian')throw new InvalidArgumentException('Pilih nilai opsi varian yang valid atau isi Nama Varian manual.');
                $db->prepare("UPDATE marketplace_product_variants SET name=?,updated_at=NOW() WHERE id=? AND product_id=?")->execute([$finalName,$variantId,$id]);
                $branch=marketplace_branch_id($db);$min=max(0,(int)($_POST['minimum_stock']??0));
                marketplace_v16_set_variant_stock($db,$variantId,$branch,$variantStock,$min);
                marketplace_v16_sync_parent_stock($db,$id,$branch);
                $verify=$db->prepare('SELECT v.id FROM marketplace_product_variants v LEFT JOIN marketplace_product_variant_stocks vs ON vs.variant_id=v.id AND vs.branch_id=? WHERE v.id=? AND v.product_id=? LIMIT 1');$verify->execute([$branch,$variantId,$id]);
                if(!(int)$verify->fetchColumn())throw new RuntimeException('Varian belum terbaca kembali setelah disimpan.');
                $db->commit();
                marketplace_v15_log($db,'variant.save','variant',$variantId,$product['name'].' - '.$finalName);
                $successMessage='Varian "'.$finalName.'" berhasil disimpan dan diverifikasi.';
            }catch(Throwable $ve){if($db->inTransaction())$db->rollBack();throw $ve;}
        } elseif ($a === 'variant_delete') {
            $variantId=(int)($_POST['variant_id']??0);
            if($variantId<=0) throw new InvalidArgumentException('Varian tidak valid.');
            $own=$db->prepare("SELECT id,name FROM marketplace_product_variants WHERE id=? AND product_id=? LIMIT 1");
            $own->execute([$variantId,$id]);
            $variantRow=$own->fetch(PDO::FETCH_ASSOC);
            if(!$variantRow) throw new RuntimeException('Varian tidak ditemukan pada produk ini.');
            try{
                $db->beginTransaction();
                $db->prepare("DELETE FROM marketplace_variant_value_map WHERE variant_id=?")->execute([$variantId]);
                $db->prepare("DELETE FROM marketplace_product_variant_stocks WHERE variant_id=?")->execute([$variantId]);
                $del=$db->prepare("DELETE FROM marketplace_product_variants WHERE id=? AND product_id=?");
                $del->execute([$variantId,$id]);
                if($del->rowCount()<1) throw new RuntimeException('Varian gagal dihapus dari database.');
                $db->commit();
                marketplace_v16_sync_parent_stock($db,$id,marketplace_branch_id($db));
                marketplace_v15_log($db,'variant.delete','variant',$variantId,$product['name'].' - '.($variantRow['name']??''));
                $successMessage='Varian "'.($variantRow['name']??'').'" berhasil dihapus.';
            }catch(Throwable $de){if($db->inTransaction())$db->rollBack();throw $de;}
        } else {
            throw new InvalidArgumentException('Aksi produk marketplace tidak dikenali.');
        }
        flash('success',$successMessage);
        legacy_redirect('admin/marketplace-products/detail.php?id='.$id);
    } catch(Throwable $e) { $err=$e->getMessage(); }
}

$productMeta=marketplace_v15_product_meta($db,$id);$product['brand_id']=(int)($productMeta['brand_id']??0);$product['weight_gram']=marketplace_v15_product_weight($db,$id,marketplace_default_weight());
$brands=marketplace_v15_brands($db,false);$schemaStatus=marketplace_v15_schema_status($db);$compatMode=!$schemaStatus['products_brand_id']||!$schemaStatus['products_weight_gram'];$doctorAfter=marketplace_doctor_status($db);$doctorBad=0;foreach(($doctorAfter['product']??[]) as $ds)if(empty($ds['ok']))$doctorBad++;
$images=marketplace_v15_product_images($db,$id);$options=marketplace_v159_variant_options($db,$id,false);$variants=marketplace_v15_variants($db,$id,marketplace_branch_id($db),false);
$editVariant=null;if(isset($_GET['variant']))foreach($variants as $v)if((int)$v['id']===(int)$_GET['variant'])$editVariant=$v;
$selectedValues=$editVariant?marketplace_v159_variant_selected_values($db,(int)$editVariant['id']):[];$ok=flash('success');
require __DIR__.'/../../layouts/admin-header.php';require __DIR__.'/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?=legacy_base_url('assets/css/marketplace-customer-admin.css?v=15.9')?>">
<style>
.mp-master-grid{display:grid;grid-template-columns:100px 1fr;gap:16px;align-items:center}.mp-master-grid img{width:100px;height:100px;border-radius:18px;object-fit:cover;background:#f8fafc}.mp-master-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}.mp-master-meta div{padding:10px 12px;border:1px solid #e2e8f0;border-radius:14px;background:#fff}.mp-master-meta span{display:block;font-size:11px;color:#64748b;font-weight:800}.mp-master-meta b{display:block;margin-top:3px}.mp-option-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.mp-option-card{border:1px solid #e2e8f0;border-radius:18px;padding:14px}.mp-option-values{display:flex;gap:7px;flex-wrap:wrap;margin:10px 0}.mp-option-chip{display:inline-flex;align-items:center;gap:6px;padding:7px 9px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:800;font-size:12px}.mp-swatch{width:15px;height:15px;border-radius:50%;border:1px solid rgba(15,23,42,.18)}.mp-inline-form{display:grid;grid-template-columns:1fr 64px auto;gap:8px;align-items:end}.mp-variant-choice{border:1px solid #e2e8f0;border-radius:16px;padding:12px;background:#fafafa}.mp-variant-choice strong{display:block;margin-bottom:8px}.mp-attr-list{display:flex;gap:5px;flex-wrap:wrap;margin-top:5px}.mp-attr-list span{font-size:11px;padding:4px 7px;border-radius:999px;background:#f1f5f9;color:#475569;font-weight:800}.mp-variant-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.mp-variant-actions form{margin:0}.mp-action-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:38px;padding:8px 11px;border-radius:11px;border:1px solid #e2e8f0;background:#fff;color:#334155;text-decoration:none;font-weight:800;font-size:12px;cursor:pointer}.mp-action-btn i{font-size:14px}.mp-action-btn.danger{border-color:#fecaca;background:#fff1f2;color:#be123c}.mp-action-btn:hover{filter:brightness(.98)}@media(max-width:720px){.mp-master-grid{grid-template-columns:72px 1fr}.mp-master-grid img{width:72px;height:72px}.mp-master-meta,.mp-option-grid{grid-template-columns:1fr}.mp-inline-form{grid-template-columns:1fr 58px}.mp-inline-form button{grid-column:1/-1}.mp-variant-table thead{display:none}.mp-variant-table,.mp-variant-table tbody,.mp-variant-table tr,.mp-variant-table td{display:block;width:100%}.mp-variant-table tbody{display:grid;gap:12px}.mp-variant-table tr{border:1px solid #eadff0!important;border-radius:16px;background:#fff;overflow:hidden;padding:4px 0;box-shadow:0 6px 18px rgba(15,23,42,.035)}.mp-variant-table td{border:0!important;padding:10px 14px!important;display:grid;grid-template-columns:92px minmax(0,1fr);gap:10px;align-items:start}.mp-variant-table td::before{content:attr(data-label);font-size:11px;font-weight:900;color:#7c3aed;text-transform:uppercase;letter-spacing:.03em}.mp-variant-table td:last-child{border-top:1px solid #f1eaf4!important;grid-template-columns:92px minmax(0,1fr)}.mp-variant-actions{justify-content:flex-start}.mp-action-btn{min-width:92px}.mp-variant-table td[colspan]{display:block;text-align:center}.mp-variant-table td[colspan]::before{display:none}}
</style>
<main class="admin-main mp-admin">
<header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1><?=e($product['name'])?></h1><p><?=e($product['product_code'])?> • Master produk POS; marketplace tidak membuat produk duplikat.</p></div><div class="topbar-actions"><a class="mp-btn soft" href="<?=legacy_base_url('admin/marketplace-database/index.php')?>"><i class="bi bi-database-check"></i> Cek DB</a><a class="mp-btn soft" href="<?=legacy_base_url('admin/products/edit.php?id='.$id)?>"><i class="bi bi-pencil"></i> Edit Master POS</a></div></header>
<?php if($ok):?><div class="mp-alert success"><?=e($ok)?></div><?php endif;?><?php if($err):?><div class="mp-alert error"><?=e($err)?></div><?php endif;?>
<?php if($compatMode):?><div class="mp-alert success">Mode kompatibilitas aktif. Brand dan berat marketplace disimpan sebagai metadata tanpa mengubah master produk POS.</div><?php endif;?><?php if($doctorBad>0):?><div class="mp-alert error">Ditemukan <?=$doctorBad?> bagian schema marketplace yang belum lengkap. <a href="<?=legacy_base_url('admin/marketplace-database/index.php')?>"><b>Buka Cek Database Marketplace</b></a> lalu jalankan upgrade.</div><?php endif;?>
<section class="mp-panel"><div class="mp-panel-head"><div><h3>Master Produk POS</h3><p>Nama, kode, harga dasar, gambar utama, kategori, stok dan status berasal langsung dari produk POS ini.</p></div><span class="badge-soft <?=!empty($product['is_active'])?'success':'danger'?>"><?=!empty($product['is_active'])?'Aktif / tampil':'Nonaktif / tidak tampil'?></span></div><div class="mp-master-grid"><?php if(!empty($product['image'])):?><img src="<?=legacy_base_url($product['image'])?>" alt=""><?php else:?><div></div><?php endif;?><div><b><?=e($product['name'])?></b><div class="mp-master-meta"><div><span>Harga POS</span><b><?=rupiah($product['selling_price'])?></b></div><div><span>Stok POS</span><b><?=e($product['stock'])?> <?=e($product['unit']?:'pcs')?></b></div><div><span>Kategori</span><b><?=e($product['category_name']?:'-')?></b></div></div></div></div></section>
<section class="mp-admin-grid two">
<section class="mp-panel"><div class="mp-panel-head"><div><h3>Brand / Merek <small>(opsional)</small></h3><p>Brand adalah merek/pabrikan seperti JNC, Vaporesso, Voopoo, OXVA. Produk generik boleh Tanpa brand.</p></div><a class="mp-icon" href="<?=legacy_base_url('admin/marketplace-brands/index.php')?>" title="Kelola brand"><i class="bi bi-tags"></i></a></div><form method="post" class="mp-form"><?=CSRF::input()?><input type="hidden" name="action" value="brand"><label>Merek<select name="brand_id"><option value="0">Tanpa brand</option><?php foreach($brands as $b):?><option value="<?=$b['id']?>" <?=(int)$product['brand_id']===(int)$b['id']?'selected':''?>><?=e($b['name'])?><?=$b['is_active']?'':' (nonaktif)'?></option><?php endforeach;?></select></label><button class="mp-btn">Simpan Brand</button></form></section>
<section class="mp-panel"><div class="mp-panel-head"><div><h3>Tambah Foto Galeri</h3><p>Bisa pilih banyak foto sekaligus. Gambar utama POS tetap menjadi foto master; galeri ini menjadi foto tambahan marketplace.</p></div></div><form method="post" enctype="multipart/form-data" class="mp-form"><?=CSRF::input()?><input type="hidden" name="action" value="image_add"><label>Gambar Produk <small>(multiple, maks. 12 foto)</small><input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required></label><label>Urutan Awal<input type="number" name="sort_order" value="0"></label><label class="mp-check"><input type="checkbox" name="is_primary"> Foto pertama jadi gambar utama marketplace</label><button class="mp-btn">Upload Foto</button></form></section>
</section>
<section class="mp-panel"><div class="mp-panel-head"><div><h3>Galeri Produk</h3><p>Foto tambahan marketplace untuk produk POS yang sama.</p></div></div><div class="mp-gallery-admin"><?php foreach($images as $im):?><article><img src="<?=legacy_base_url($im['image_path'])?>" alt=""><span><?=$im['is_primary']?'Utama Marketplace':'Galeri'?></span><div><form method="post"><?=CSRF::input()?><input type="hidden" name="action" value="image_primary"><input type="hidden" name="image_id" value="<?=$im['id']?>"><button class="mp-icon"><i class="bi bi-star"></i></button></form><form method="post" onsubmit="return confirm('Hapus gambar?')"><?=CSRF::input()?><input type="hidden" name="action" value="image_delete"><input type="hidden" name="image_id" value="<?=$im['id']?>"><button class="mp-icon danger"><i class="bi bi-trash"></i></button></form></div></article><?php endforeach;?><?php if(!$images):?><div class="empty">Belum ada galeri tambahan.</div><?php endif;?></div></section>

<section class="mp-panel"><div class="mp-panel-head"><div><h3>Tipe Varian / Opsi</h3><p>Buat atribut seperti Warna, Rasa, Ukuran, Kadar Nikotin, Resistance/Ohm, Kapasitas, atau nama custom. Setelah itu isi nilainya.</p></div></div>
<form method="post" class="mp-form" style="margin-bottom:16px"><?=CSRF::input()?><input type="hidden" name="action" value="option_add"><div class="mp-inline-form"><label>Tipe varian<input name="option_name" list="variantOptionSuggestions" placeholder="Contoh: Warna" required></label><label>Urut<input type="number" name="sort_order" value="0"></label><button class="mp-btn"><i class="bi bi-plus"></i> Tambah Tipe</button></div><datalist id="variantOptionSuggestions"><option value="Warna"><option value="Rasa"><option value="Ukuran"><option value="Kadar Nikotin"><option value="Resistance / Ohm"><option value="Kapasitas"><option value="Model"></datalist></form>
<div class="mp-option-grid"><?php foreach($options as $op):?><article class="mp-option-card"><div class="mp-panel-head"><div><h4><?=e($op['name'])?></h4><small><?=count($op['values'])?> nilai</small></div><form method="post" onsubmit="return confirm('Hapus tipe varian dan hubungan nilainya?')"><?=CSRF::input()?><input type="hidden" name="action" value="option_delete"><input type="hidden" name="option_id" value="<?=$op['id']?>"><button class="mp-icon danger"><i class="bi bi-trash"></i></button></form></div><div class="mp-option-values"><?php foreach($op['values'] as $val):?><span class="mp-option-chip"><?php if(!empty($val['color_hex'])):?><i class="mp-swatch" style="background:<?=e($val['color_hex'])?>"></i><?php endif;?><?=e($val['value'])?><form method="post" style="display:inline" onsubmit="return confirm('Hapus nilai ini?')"><?=CSRF::input()?><input type="hidden" name="action" value="option_value_delete"><input type="hidden" name="value_id" value="<?=$val['id']?>"><button class="mp-icon danger" style="width:24px;height:24px"><i class="bi bi-x"></i></button></form></span><?php endforeach;?><?php if(!$op['values']):?><small>Belum ada nilai.</small><?php endif;?></div><form method="post" class="mp-inline-form"><?=CSRF::input()?><input type="hidden" name="action" value="option_value_add"><input type="hidden" name="option_id" value="<?=$op['id']?>"><label>Nilai<input name="option_value" placeholder="Contoh: Black / Strawberry / 0.8Ω" required></label><label>Kode warna<input name="color_hex" placeholder="#000000" maxlength="7" title="Opsional, terutama untuk tipe Warna"></label><button class="mp-btn soft"><i class="bi bi-plus"></i> Tambah Nilai</button></form></article><?php endforeach;?><?php if(!$options):?><div class="empty">Belum ada tipe varian. Jika produk tidak memiliki pilihan seperti warna/rasa/ukuran, bagian ini boleh kosong.</div><?php endif;?></div>
</section>

<section class="mp-admin-grid two"><section class="mp-panel"><div class="mp-panel-head"><div><h3><?=$editVariant?'Edit Kombinasi Varian':'Kombinasi Varian Baru'?></h3><p>Pilih satu nilai dari setiap tipe yang relevan. Setiap varian dapat memiliki stok dan 1 foto sendiri.</p></div></div><form method="post" enctype="multipart/form-data" class="mp-form"><?=CSRF::input()?><input type="hidden" name="action" value="variant_save"><input type="hidden" name="variant_id" value="<?=e($editVariant['id']??0)?>">
<?php foreach($options as $op):?><div class="mp-variant-choice"><strong><?=e($op['name'])?></strong><select name="option_value_ids[<?=$op['id']?>]"><option value="0">Tidak dipakai pada kombinasi ini</option><?php foreach($op['values'] as $val):?><option value="<?=$val['id']?>" <?=((int)($selectedValues[$op['id']]??0)===(int)$val['id'])?'selected':''?>><?=e($val['value'])?></option><?php endforeach;?></select></div><?php endforeach;?>
<label>Nama Varian Manual <small>(opsional jika memakai opsi di atas)</small><input name="variant_name" value="<?=e($editVariant['display_name']??$editVariant['name']??'')?>" placeholder="Contoh manual: Paket Special"></label><label>SKU Varian<input name="sku" value="<?=e($editVariant['sku']??'')?>"></label><label>Barcode Varian<input name="barcode" value="<?=e($editVariant['barcode']??'')?>"></label><label>Harga Jual Varian<input type="number" name="selling_price" min="0" value="<?=e($editVariant['selling_price']??$product['selling_price'])?>"><small>Boleh sama atau berbeda dari harga POS master.</small></label><label>Harga Beli / HPP<input type="number" name="purchase_price" min="0" value="<?=e($editVariant['purchase_price']??$product['purchase_price'])?>"></label><label>Berat Gram<input type="number" name="weight_gram" min="1" value="<?=e($editVariant['weight_gram']??($product['weight_gram']??100))?>"></label><label>Foto Varian <small>(1 foto)</small><input type="file" name="variant_image" accept="image/jpeg,image/png,image/webp"><?php if(!empty($editVariant['image_path'])):?><small><a href="<?=e(legacy_base_url($editVariant['image_path']))?>" target="_blank">Lihat foto varian saat ini</a></small><?php endif;?></label><label>Stok Varian<input type="number" name="variant_stock" min="0" value="<?=e($editVariant['stock']??0)?>"><small>Stok diisi manual per varian dan per cabang. Stok induk POS otomatis menjadi total semua varian aktif.</small></label><label>Minimum Stok<input type="number" name="minimum_stock" min="0" value="<?=e($editVariant['minimum_stock']??0)?>"></label><label>Urutan<input type="number" name="sort_order" value="<?=e($editVariant['sort_order']??0)?>"></label><label class="mp-check"><input type="checkbox" name="is_active" <?=$editVariant&&!$editVariant['is_active']?'':'checked'?>> Aktif</label><button class="mp-btn"><i class="bi bi-save"></i> Simpan Varian</button><?php if($editVariant):?><a class="mp-btn soft" href="?id=<?=$id?>">Batal Edit</a><?php endif;?></form></section>
<section class="mp-panel"><div class="mp-panel-head"><div><h3>Daftar Varian</h3><p>Semua varian tetap anak dari produk POS yang sama. Gunakan tombol Hapus jika varian sudah tidak dipakai.</p></div></div><div class="table-premium-card"><table class="premium-table mp-variant-table"><thead><tr><th>Varian</th><th>Harga</th><th>Stok Varian</th><th>Aksi</th></tr></thead><tbody><?php foreach($variants as $v):?><tr><td data-label="Varian"><div><b><?=e($v['display_name']??$v['name'])?></b><br><small><?=e($v['sku']?:'-')?></small><?php if(!empty($v['attributes'])):?><div class="mp-attr-list"><?php foreach($v['attributes'] as $a):?><span><?=e($a['option_name'])?>: <?=e($a['value'])?></span><?php endforeach;?></div><?php endif;?></div></td><td data-label="Harga"><div><?=rupiah($v['selling_price'])?><br><small>HPP <?=rupiah($v['purchase_price'])?></small></div></td><td data-label="Stok Varian"><div><b><?=e($v['stock'])?></b> <small><?=e($product['unit']?:'pcs')?></small><br><small>Stok manual varian</small></div></td><td data-label="Aksi"><div class="mp-variant-actions"><a class="mp-action-btn" href="?id=<?=$id?>&variant=<?=$v['id']?>"><i class="bi bi-pencil"></i> Edit</a><form method="post" onsubmit="return confirm('Hapus varian <?=e(addslashes($v['display_name']??$v['name']))?>? Data opsi varian ini juga akan dihapus. Produk POS utama tidak ikut terhapus.')"><?=CSRF::input()?><input type="hidden" name="action" value="variant_delete"><input type="hidden" name="variant_id" value="<?=$v['id']?>"><button class="mp-action-btn danger" type="submit"><i class="bi bi-trash"></i> Hapus</button></form></div></td></tr><?php endforeach;?><?php if(!$variants):?><tr><td colspan="4"><div class="empty">Belum ada varian. Produk tetap bisa dijual sebagai satu item biasa.</div></td></tr><?php endif;?></tbody></table></div></section></section>
</main>
<?php require __DIR__.'/../../layouts/admin-footer.php'; ?>
