<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/../stock/_sync_helper.php';
require_once __DIR__ . '/../prices/_helpers.php';
Auth::requireRole(['super_admin','owner']);
function product_multiple_uploads(array $files): array
{
    $out=[];
    if (empty($files['name']) || !is_array($files['name'])) return $out;
    $count=min(12,count($files['name']));
    for($i=0;$i<$count;$i++){
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $one=['name'=>$files['name'][$i]??'','type'=>$files['type'][$i]??'','tmp_name'=>$files['tmp_name'][$i]??'','error'=>$files['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$files['size'][$i]??0];
        $path=upload_product_image($one);
        if($path) $out[]=$path;
    }
    return $out;
}
function product_save_gallery_images(PDO $db,int $productId,array $paths,?string $primaryImage=null): void
{
    if(!$paths) return;
    marketplace_install($db);
    try{$q=$db->prepare("SELECT COALESCE(MAX(sort_order),0) FROM marketplace_product_images WHERE product_id=?");$q->execute([$productId]);$sort=(int)$q->fetchColumn();}catch(Throwable $e){$sort=0;}
    $stmt=$db->prepare("INSERT INTO marketplace_product_images(product_id,image_path,is_primary,sort_order,created_at) VALUES(?,?,?,?,NOW())");
    foreach($paths as $path){
        if($primaryImage && $path===$primaryImage) continue;
        $sort++;$stmt->execute([$productId,$path,0,$sort]);
    }
}

$db=Database::connect();
marketplace_install($db);
price_install($db);
$id=(int)($_GET['id']??0);
$stmt=$db->prepare('SELECT * FROM products WHERE id=?');
$stmt->execute([$id]);
$p=$stmt->fetch();
if($p){ $p['weight_gram']=marketplace_v15_product_weight($db,$id,marketplace_default_weight());$p['brand_id']=marketplace_v15_product_brand_id($db,$id); }
$hasVariants=$p?marketplace_v16_product_has_variants($db,$id,true):false;
if($hasVariants){$variantTotal=marketplace_v16_product_variant_stock_total($db,$id,branch_current_id($db),true);$p['stock']=$variantTotal;}
if(!$p){
    flash('error','Produk tidak ditemukan.');
    legacy_redirect('admin/products/index.php');
}
$activeBranchId=branch_current_id($db);

// Inline variant management: same data source as Marketplace product variants,
// but available directly from Edit Produk as requested.
if (is_post() && !empty($_POST['variant_action'])) {
    if(!CSRF::verify()){
        flash('error','Token tidak valid.');
        legacy_redirect('admin/products/edit.php?id='.$id);
    }
    try {
        $action=(string)$_POST['variant_action'];
        if($action==='save'){
            $variantId=max(0,(int)($_POST['variant_id']??0));
            $variantName=trim((string)($_POST['variant_name']??''));
            if($variantName==='') throw new InvalidArgumentException('Nama varian wajib diisi.');
            $sku=trim((string)($_POST['variant_sku']??''))?:null;
            $barcode=trim((string)($_POST['variant_barcode']??''))?:null;
            $selling=max(0,(float)($_POST['variant_selling_price']??$p['selling_price']));
            $purchase=max(0,(float)($_POST['variant_purchase_price']??$p['purchase_price']));
            $stock=max(0,(int)($_POST['variant_stock']??0));
            $minimum=max(0,(int)($_POST['variant_minimum_stock']??0));
            $active=isset($_POST['variant_is_active'])?1:0;
            $sort=(int)($_POST['variant_sort_order']??0);
            $weight=max(1,(int)($_POST['variant_weight_gram']??($p['weight_gram']??marketplace_default_weight())));
            if($variantId>0){
                $own=$db->prepare('SELECT id FROM marketplace_product_variants WHERE id=? AND product_id=? LIMIT 1');
                $own->execute([$variantId,$id]);
                if(!$own->fetchColumn()) throw new RuntimeException('Varian tidak ditemukan pada produk ini.');
                $db->prepare('UPDATE marketplace_product_variants SET name=?,sku=?,barcode=?,selling_price=?,purchase_price=?,weight_gram=?,is_active=?,sort_order=?,updated_at=NOW() WHERE id=? AND product_id=?')
                    ->execute([$variantName,$sku,$barcode,$selling,$purchase,$weight,$active,$sort,$variantId,$id]);
            }else{
                $db->prepare('INSERT INTO marketplace_product_variants(product_id,name,sku,barcode,selling_price,purchase_price,weight_gram,is_active,sort_order,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())')
                    ->execute([$id,$variantName,$sku,$barcode,$selling,$purchase,$weight,$active,$sort]);
                $variantId=(int)$db->lastInsertId();
            }
            marketplace_v16_set_variant_stock($db,$variantId,$activeBranchId,$stock,$minimum);
            marketplace_v16_sync_parent_stock($db,$id,$activeBranchId);
            flash('success','Varian berhasil disimpan dari halaman Edit Produk.');
            legacy_redirect('admin/products/edit.php?id='.$id.'#productVariants');
        }
        if($action==='delete'){
            $variantId=max(0,(int)($_POST['variant_id']??0));
            $own=$db->prepare('SELECT id,name FROM marketplace_product_variants WHERE id=? AND product_id=? LIMIT 1');
            $own->execute([$variantId,$id]);
            $row=$own->fetch();
            if(!$row) throw new RuntimeException('Varian tidak ditemukan.');
            // Soft-delete: historical transaction/purchase references keep pointing to
            // the same variant id. This is safer than deleting master data physically.
            $db->prepare('UPDATE marketplace_product_variants SET is_active=0,updated_at=NOW() WHERE id=? AND product_id=?')->execute([$variantId,$id]);
            marketplace_v16_sync_parent_stock($db,$id,$activeBranchId);
            flash('success','Varian '.(string)$row['name'].' dinonaktifkan. Riwayat transaksi tetap aman.');
            legacy_redirect('admin/products/edit.php?id='.$id.'#productVariants');
        }
    } catch(Throwable $e) {
        flash('error','Gagal memproses varian: '.$e->getMessage());
        legacy_redirect('admin/products/edit.php?id='.$id.'#productVariants');
    }
}

$inlineVariants=marketplace_v15_variants($db,$id,$activeBranchId,false);
$editVariantId=max(0,(int)($_GET['variant']??0));
$inlineEditVariant=null;
foreach($inlineVariants as $variantRow){if((int)$variantRow['id']===$editVariantId){$inlineEditVariant=$variantRow;break;}}
$pageTitle='Edit Produk';
$categories=$db->query('SELECT id,name FROM categories WHERE is_active=1 ORDER BY sort_order ASC,name ASC')->fetchAll();
$suppliers=$db->query('SELECT id,name FROM suppliers WHERE is_active=1 ORDER BY name ASC')->fetchAll();
$brands=marketplace_v15_brands($db,false);

if(is_post()){
    if(!CSRF::verify()){
        flash('error','Token tidak valid.');
        legacy_redirect('admin/products/edit.php?id='.$id);
    }
    $name=trim($_POST['name']??'');
    if($name===''){
        flash('error','Nama wajib diisi.');
        legacy_redirect('admin/products/edit.php?id='.$id);
    }

    try{
        $image=$p['image'];
        $uploadedImages=product_multiple_uploads($_FILES['images']??[]);
        $newImage=$uploadedImages[0]??upload_product_image($_FILES['image']??[]);
        if($newImage){$image=$newImage;}
        $oldStock=(int)$p['stock'];
        $newStock=$hasVariants?marketplace_v16_product_variant_stock_total($db,$id,branch_current_id($db),true):(int)($_POST['stock']??0);
        $sellingPrice=max(0, (float)($_POST['selling_price']??0));
        $code=trim($_POST['product_code']??'');
        if($code===''){
            $code='PRD'.date('YmdHis').random_int(10,99);
        }

        $weightGram = max(1, (int)($_POST['weight_gram'] ?? marketplace_v15_product_weight($db, $id, marketplace_default_weight())));
        // Keep the legacy POS update independent from optional marketplace columns.
        // Some subdomain databases do not have products.weight_gram yet.
        $up=$db->prepare("UPDATE products SET category_id=?,supplier_id=?,product_code=?,barcode=?,name=?,description=?,image=?,purchase_price=?,selling_price=?,stock=?,minimum_stock=?,unit=?,is_active=? WHERE id=?");
        $up->execute([
            ($_POST['category_id']??'')?:null,
            ($_POST['supplier_id']??'')?:null,
            $code,
            trim($_POST['barcode']??'')?:null,
            $name,
            trim($_POST['description']??''),
            $image,
            (float)($_POST['purchase_price']??0),
            $sellingPrice,
            $newStock,
            (int)($_POST['minimum_stock']??0),
            trim($_POST['unit']??'pcs'),
            isset($_POST['is_active'])?1:0,
            $id
        ]);
        marketplace_v15_set_product_weight($db, $id, $weightGram);
        marketplace_v15_set_product_brand($db, $id, max(0,(int)($_POST['brand_id']??0)));
        product_save_gallery_images($db,$id,$uploadedImages,$image);

        // Wajib sinkron: POS membaca harga ecer dari product_price_levels.
        // Tanpa ini, keranjang/nota transaksi baru masih bisa memakai harga lama.
        price_save($db, $id, 'retail', $sellingPrice, 1);
        price_ensure_product_levels($db, $id, $sellingPrice);

        $branchStockSync = stock_sync_set_active_branch($db, $id, $newStock, (int)($_POST['minimum_stock']??0));

        if($newStock !== $oldStock){
            $type=$newStock>$oldStock?'stock_in':'stock_out';
            $qty=abs($newStock-$oldStock);
            $st=$db->prepare("INSERT INTO stock_movements (product_id,user_id,type,qty,stock_before,stock_after,note) VALUES (?,?,?,?,?,?,?)");
            $st->execute([$id,Auth::id(),$type,$qty,$oldStock,$newStock,'Update stok dari edit produk']);
        }
        flash('success','Produk berhasil diperbarui.');
        legacy_redirect('admin/products/index.php');
    }catch(Throwable $e){
        flash('error','Gagal update produk: '.$e->getMessage());
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link href="<?= legacy_base_url('assets/css/product-barcode.css?v=' . time()); ?>" rel="stylesheet">

<style>
.product-price-priority{background:linear-gradient(135deg,#faf5ff,#fff1f2);border:1px solid #e9d5ff;border-radius:20px;padding:14px}.product-price-priority label{color:#6b21a8!important}.product-edit-quick-save{display:flex!important;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 0 2px}.product-edit-quick-save .btn-premium{min-height:50px}.product-edit-quick-save small{color:#64748b;font-weight:750}@media(max-width:720px){.product-edit-quick-save{position:sticky;top:8px;z-index:15;background:rgba(255,255,255,.94);backdrop-filter:blur(10px);border:1px solid #f3e8ff;border-radius:18px;padding:10px;box-shadow:0 12px 28px rgba(107,33,168,.12)}.product-edit-quick-save .btn-premium{width:100%;justify-content:center}.premium-form{padding-bottom:115px}}

.product-variant-panel{margin-top:18px}.product-variant-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);gap:16px}.product-variant-list{display:grid;gap:10px}.product-variant-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:13px;border:1px solid #e2e8f0;border-radius:16px;background:#fff}.product-variant-item h4{margin:0 0 4px}.product-variant-item p{margin:0;color:#64748b;font-size:12px}.product-variant-item-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.product-variant-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.product-variant-form .full{grid-column:1/-1}.variant-mini-btn{border:1px solid #e2e8f0;background:#fff;border-radius:10px;padding:8px 10px;font-weight:850;text-decoration:none;color:#334155;cursor:pointer}.variant-mini-btn.danger{color:#b91c1c;background:#fff1f2;border-color:#fecaca}@media(max-width:850px){.product-variant-grid{grid-template-columns:1fr}.product-variant-form{grid-template-columns:1fr}.product-variant-item{grid-template-columns:1fr}.product-variant-item-actions{justify-content:flex-start}}
</style>

<main class="admin-main">
    <header class="admin-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Edit Produk</h1>
            <p>Perbarui data produk, barcode, gambar, harga, dan stok.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/marketplace-products/detail.php?id='.$id); ?>" class="btn-soft"><i class="bi bi-sliders"></i> Varian & Galeri Marketplace</a>
            <a href="<?= legacy_base_url('admin/products/label.php?id='.$id); ?>" class="btn-premium" target="_blank"><i class="bi bi-upc-scan"></i> Cetak Label</a>
        </div>
    </header>

    <section class="premium-card mt-4">
        <form method="POST" enctype="multipart/form-data" class="premium-form">
            <?= CSRF::input(); ?>
            <div class="form-grid">
                <div class="form-group"><label>Nama Produk <small>• bisa diubah</small></label><input name="name" value="<?= e($p['name']); ?>" required></div>
                <div class="form-group product-price-priority"><label>Harga Jual / Harga POS <small>• bisa diubah</small></label><input id="sellingPriceInput" type="number" min="0" step="1" inputmode="numeric" name="selling_price" value="<?= e($p['selling_price']); ?>" required><small>Harga baru langsung disinkronkan ke Retail/Ecer untuk POS, keranjang, dan nota transaksi baru.</small></div>
                <div class="form-group full product-edit-quick-save"><button type="submit" class="btn-premium"><i class="bi bi-check2-circle"></i> Simpan Nama & Harga Sekarang</button><small>Tidak perlu scroll sampai bawah. Tombol ini menyimpan seluruh perubahan pada form.</small></div>

                <div class="form-group">
                    <label>Kode Produk</label>
                    <div class="input-action-wrap">
                        <input id="productCodeInput" name="product_code" value="<?= e($p['product_code']); ?>" required>
                        <button type="button" class="btn-mini-tool js-generate-code" data-target="#productCodeInput"><i class="bi bi-magic"></i> Generate</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Barcode</label>
                    <div class="input-action-wrap">
                        <input id="barcodeInput" name="barcode" value="<?= e($p['barcode']); ?>" placeholder="Scan kamera / scanner fisik / isi manual">
                        <button type="button" class="btn-mini-tool js-scan-barcode" data-target="#barcodeInput"><i class="bi bi-camera"></i> Scan</button>
                    </div>
                    <div class="input-mini-actions">
                        <button type="button" class="btn-mini-tool js-copy-code-to-barcode" data-from="#productCodeInput" data-to="#barcodeInput"><i class="bi bi-files"></i> Pakai kode produk sebagai barcode</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Kategori</label>
                    <select name="category_id"><option value="">Pilih kategori</option><?php foreach($categories as $c): ?><option value="<?= $c['id']; ?>" <?= (int)$p['category_id']===(int)$c['id']?'selected':''; ?>><?= e($c['name']); ?></option><?php endforeach; ?></select>
                </div>

                <div class="form-group">
                    <label>Brand / Merek <small>(opsional)</small></label>
                    <select name="brand_id"><option value="0">Tanpa brand / generik</option><?php foreach($brands as $b): ?><option value="<?= (int)$b['id']; ?>" <?= (int)($p['brand_id']??0)===(int)$b['id']?'selected':''; ?>><?= e($b['name']); ?><?= $b['is_active']?'':' (nonaktif)'; ?></option><?php endforeach; ?></select>
                    <small>Brand tidak wajib. Gunakan untuk merek/pabrikan agar filter marketplace lebih rapi.</small>
                </div>

                <div class="form-group">
                    <label>Supplier</label>
                    <select name="supplier_id"><option value="">Pilih supplier</option><?php foreach($suppliers as $s): ?><option value="<?= $s['id']; ?>" <?= (int)$p['supplier_id']===(int)$s['id']?'selected':''; ?>><?= e($s['name']); ?></option><?php endforeach; ?></select>
                </div>

                <div class="form-group"><label>Satuan</label><input name="unit" value="<?= e($p['unit']); ?>"></div>
                <div class="form-group"><label>Berat Marketplace (gram)</label><input type="number" min="1" max="50000" name="weight_gram" value="<?= e((string)($p['weight_gram'] ?? marketplace_default_weight())); ?>"><small>Dipakai untuk perhitungan ongkir marketplace.</small></div>
                <div class="form-group"><label>Harga Beli</label><input type="number" name="purchase_price" value="<?= e($p['purchase_price']); ?>"></div>
                
                <div class="form-group"><label>Stok</label><input type="number" name="stock" value="<?= e($p['stock']); ?>" <?= $hasVariants?'readonly':''; ?>><?php if($hasVariants): ?><small>Stok induk otomatis = total stok seluruh varian. Ubah stok langsung pada bagian Varian Produk di bawah.</small><?php endif; ?></div>
                <div class="form-group"><label>Stok Minimum</label><input type="number" name="minimum_stock" value="<?= e($p['minimum_stock']); ?>"></div>
                <div class="form-group"><label>Foto Produk Baru <small>(bisa multiple)</small></label><input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple><?php if($p['image']): ?><small>Foto pertama yang dipilih menjadi gambar utama baru; foto berikutnya masuk galeri Marketplace. Kosongkan bila tidak ingin mengganti foto.</small><?php endif; ?></div>
                <div class="form-group switch-line"><label><input type="checkbox" name="is_active" <?= $p['is_active']?'checked':''; ?>> Produk aktif</label></div>
                <div class="form-group full"><label>Deskripsi</label><textarea name="description"><?= e($p['description']); ?></textarea></div>
            </div>
            <div class="form-actions"><a class="btn-soft" href="<?= legacy_base_url('admin/products/index.php'); ?>">Batal</a><button class="btn-premium"><i class="bi bi-save"></i> Update Produk</button></div>
        </form>
    </section>

    <section class="premium-card product-variant-panel" id="productVariants">
        <div class="topbar-title" style="margin-bottom:14px"><h2 style="margin:0">Varian Produk</h2><p>Tambah, edit, stok, harga beli, dan harga jual varian langsung dari menu Produk. Data tetap sama dengan Varian Marketplace.</p></div>
        <div class="product-variant-grid">
            <div>
                <div class="product-variant-list">
                    <?php foreach($inlineVariants as $v): ?>
                    <article class="product-variant-item">
                        <div><h4><?= e($v['display_name']??$v['name']); ?></h4><p>SKU <?= e($v['sku']?:'-'); ?> • Barcode <?= e($v['barcode']?:'-'); ?> • Stok <?= (int)$v['stock']; ?> <?= e($p['unit']?:'pcs'); ?><br>Jual <?= rupiah($v['selling_price']); ?> • Beli <?= rupiah($v['purchase_price']); ?> • <?= !empty($v['is_active'])?'Aktif':'Nonaktif'; ?></p></div>
                        <div class="product-variant-item-actions">
                            <a class="variant-mini-btn" href="<?= legacy_base_url('admin/products/edit.php?id='.$id.'&variant='.(int)$v['id'].'#productVariants'); ?>"><i class="bi bi-pencil"></i> Edit</a>
                            <form method="POST" onsubmit="return confirm('Nonaktifkan varian ini? Riwayat transaksi tidak akan dihapus.')"><?= CSRF::input(); ?><input type="hidden" name="variant_action" value="delete"><input type="hidden" name="variant_id" value="<?= (int)$v['id']; ?>"><button type="submit" class="variant-mini-btn danger"><i class="bi bi-slash-circle"></i> Nonaktifkan</button></form>
                        </div>
                    </article>
                    <?php endforeach; ?>
                    <?php if(!$inlineVariants): ?><div style="padding:16px;border:1px dashed #cbd5e1;border-radius:16px;color:#64748b">Belum ada varian. Gunakan form di samping untuk menambahkan varian pertama.</div><?php endif; ?>
                </div>
            </div>
            <div>
                <form method="POST" class="product-variant-form">
                    <?= CSRF::input(); ?><input type="hidden" name="variant_action" value="save"><input type="hidden" name="variant_id" value="<?= (int)($inlineEditVariant['id']??0); ?>">
                    <div class="form-group full"><label>Nama Varian</label><input name="variant_name" required value="<?= e($inlineEditVariant['display_name']??$inlineEditVariant['name']??''); ?>" placeholder="Contoh: Strawberry 3mg / Black / 0.8Ω"></div>
                    <div class="form-group"><label>SKU Varian</label><input name="variant_sku" value="<?= e($inlineEditVariant['sku']??''); ?>"></div>
                    <div class="form-group"><label>Barcode Varian</label><input name="variant_barcode" value="<?= e($inlineEditVariant['barcode']??''); ?>"></div>
                    <div class="form-group"><label>Harga Jual</label><input type="number" min="0" name="variant_selling_price" value="<?= e($inlineEditVariant['selling_price']??$p['selling_price']); ?>"></div>
                    <div class="form-group"><label>Harga Beli</label><input type="number" min="0" name="variant_purchase_price" value="<?= e($inlineEditVariant['purchase_price']??$p['purchase_price']); ?>"></div>
                    <div class="form-group"><label>Stok Varian</label><input type="number" min="0" name="variant_stock" value="<?= e($inlineEditVariant['stock']??0); ?>"></div>
                    <div class="form-group"><label>Stok Minimum</label><input type="number" min="0" name="variant_minimum_stock" value="<?= e($inlineEditVariant['minimum_stock']??0); ?>"></div>
                    <div class="form-group"><label>Berat (gram)</label><input type="number" min="1" name="variant_weight_gram" value="<?= e($inlineEditVariant['weight_gram']??($p['weight_gram']??marketplace_default_weight())); ?>"></div>
                    <div class="form-group"><label>Urutan</label><input type="number" name="variant_sort_order" value="<?= e($inlineEditVariant['sort_order']??0); ?>"></div>
                    <div class="form-group full switch-line"><label><input type="checkbox" name="variant_is_active" <?= $inlineEditVariant&&!$inlineEditVariant['is_active']?'':'checked'; ?>> Varian aktif</label></div>
                    <div class="form-actions full"><button class="btn-premium"><i class="bi bi-save"></i> <?= $inlineEditVariant?'Update Varian':'Tambah Varian'; ?></button><?php if($inlineEditVariant): ?><a class="btn-soft" href="<?= legacy_base_url('admin/products/edit.php?id='.$id.'#productVariants'); ?>">Batal Edit</a><?php endif; ?></div>
                </form>
            </div>
        </div>
    </section>
</main>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
if (typeof window.Html5Qrcode === 'undefined') {
    document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"><\/script>');
}
</script>
<script src="<?= legacy_base_url('assets/js/product-barcode.js?v=' . time()); ?>"></script>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
