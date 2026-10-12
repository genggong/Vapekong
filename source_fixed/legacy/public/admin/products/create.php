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

$pageTitle='Tambah Produk';
$db=Database::connect();
marketplace_install($db);
price_install($db);
$categories=$db->query('SELECT id,name FROM categories WHERE is_active=1 ORDER BY sort_order ASC,name ASC')->fetchAll();
$suppliers=$db->query('SELECT id,name FROM suppliers WHERE is_active=1 ORDER BY name ASC')->fetchAll();
$brands=marketplace_v15_brands($db,false);

if(is_post()){
    if(!CSRF::verify()){
        flash('error','Token tidak valid.');
        legacy_redirect('admin/products/create.php');
    }

    $name=trim($_POST['name']??'');
    if($name===''){
        flash('error','Nama produk wajib diisi.');
        legacy_redirect('admin/products/create.php');
    }

    $code=trim($_POST['product_code']??'');
    if($code===''){
        $code='PRD'.date('YmdHis').random_int(10,99);
    }

    try{
        $uploadedImages=product_multiple_uploads($_FILES['images']??[]);
        $image=$uploadedImages[0]??upload_product_image($_FILES['image']??[]);
        $weightGram = max(1, (int)($_POST['weight_gram'] ?? marketplace_default_weight()));
        // Do not require the optional products.weight_gram column to create a POS product.
        $stmt=$db->prepare("INSERT INTO products (category_id,supplier_id,product_code,barcode,name,description,image,purchase_price,selling_price,stock,minimum_stock,unit,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            ($_POST['category_id']??'')?:null,
            ($_POST['supplier_id']??'')?:null,
            $code,
            trim($_POST['barcode']??'')?:null,
            $name,
            trim($_POST['description']??''),
            $image,
            (float)($_POST['purchase_price']??0),
            (float)($_POST['selling_price']??0),
            (int)($_POST['stock']??0),
            (int)($_POST['minimum_stock']??0),
            trim($_POST['unit']??'pcs'),
            isset($_POST['is_active'])?1:0
        ]);

        $productId = (int)$db->lastInsertId();
        marketplace_v15_set_product_weight($db, $productId, $weightGram);
        marketplace_v15_set_product_brand($db, $productId, max(0,(int)($_POST['brand_id']??0)));
        product_save_gallery_images($db,$productId,$uploadedImages,$image);
        $variantNames=$_POST['variant_name']??[];$variantSkus=$_POST['variant_sku']??[];$variantBarcodes=$_POST['variant_barcode']??[];$variantPrices=$_POST['variant_selling_price']??[];$variantCosts=$_POST['variant_purchase_price']??[];$variantStocks=$_POST['variant_stock']??[];
        $branchId=branch_current_id($db);$createdVariant=false;
        foreach($variantNames as $i=>$variantName){$variantName=trim((string)$variantName);if($variantName==='')continue;$sku=trim((string)($variantSkus[$i]??''))?:null;$barcode=trim((string)($variantBarcodes[$i]??''))?:null;$vp=max(0,(float)($variantPrices[$i]??($_POST['selling_price']??0)));$vc=max(0,(float)($variantCosts[$i]??($_POST['purchase_price']??0)));$vs=max(0,(int)($variantStocks[$i]??0));$ins=$db->prepare("INSERT INTO marketplace_product_variants(product_id,name,sku,barcode,selling_price,purchase_price,weight_gram,is_active,sort_order,created_at) VALUES(?,?,?,?,?,?,?,1,?,NOW())");$ins->execute([$productId,$variantName,$sku,$barcode,$vp,$vc,$weightGram,$i]);$vid=(int)$db->lastInsertId();marketplace_v16_set_variant_stock($db,$vid,$branchId,$vs,0);$createdVariant=true;}
        $initialStock=(int)($_POST['stock']??0);
        if($createdVariant){
            // Parent stock must equal the sum of active variant stocks. Do not overwrite
            // it again with the generic stock input after variants were created.
            $initialStock=marketplace_v16_sync_parent_stock($db,$productId,$branchId);
        }
        price_save($db, $productId, 'retail', max(0, (float)($_POST['selling_price']??0)), 1);
        price_ensure_product_levels($db, $productId, max(0, (float)($_POST['selling_price']??0)));
        if(!$createdVariant){
            stock_sync_product_to_active_branch($db, $productId, $initialStock, (int)($_POST['minimum_stock']??0));
        }
        if ($initialStock > 0) {
            $st=$db->prepare("INSERT INTO stock_movements (product_id,user_id,type,qty,stock_before,stock_after,note) VALUES (?,?,?,?,?,?,?)");
            $st->execute([$productId, Auth::id(), 'stock_in', $initialStock, 0, $initialStock, $createdVariant?'Stok awal total varian':'Stok awal produk']);
        }

        flash('success','Produk berhasil ditambahkan.');
        legacy_redirect('admin/products/index.php');
    }catch(Throwable $e){
        flash('error','Gagal tambah produk: '.$e->getMessage());
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link href="<?= legacy_base_url('assets/css/product-barcode.css?v=' . time()); ?>" rel="stylesheet">
<style>.variant-create-row{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr 1fr .8fr auto;gap:8px;align-items:end}.variant-create-row input{width:100%}@media(max-width:900px){.variant-create-row{grid-template-columns:1fr 1fr}}@media(max-width:560px){.variant-create-row{grid-template-columns:1fr}.variant-create-row .btn-soft{width:100%}}</style>
<main class="admin-main">
    <header class="admin-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Tambah Produk</h1>
            <p>Input produk, barcode, harga, stok awal, dan gambar produk.</p>
        </div>
    </header>

    <section class="premium-card mt-4">
        <form method="POST" enctype="multipart/form-data" class="premium-form">
            <?= CSRF::input(); ?>
            <div class="form-grid">
                <div class="form-group">
                    <label>Nama Produk</label>
                    <input name="name" required placeholder="Contoh: Aqua 600ml">
                </div>

                <div class="form-group">
                    <label>Kode Produk</label>
                    <div class="input-action-wrap">
                        <input id="productCodeInput" name="product_code" placeholder="Otomatis jika kosong">
                        <button type="button" class="btn-mini-tool js-generate-code" data-target="#productCodeInput">
                            <i class="bi bi-magic"></i> Generate
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Barcode</label>
                    <div class="input-action-wrap">
                        <input id="barcodeInput" name="barcode" placeholder="Scan kamera / scanner fisik / isi manual">
                        <button type="button" class="btn-mini-tool js-scan-barcode" data-target="#barcodeInput">
                            <i class="bi bi-camera"></i> Scan
                        </button>
                    </div>
                    <div class="input-mini-actions">
                        <button type="button" class="btn-mini-tool js-copy-code-to-barcode" data-from="#productCodeInput" data-to="#barcodeInput">
                            <i class="bi bi-files"></i> Pakai kode produk sebagai barcode
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Kategori</label>
                    <select name="category_id">
                        <option value="">Pilih kategori</option>
                        <?php foreach($categories as $c): ?>
                            <option value="<?= $c['id']; ?>"><?= e($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Brand / Merek <small>(opsional)</small></label>
                    <select name="brand_id"><option value="0">Tanpa brand / generik</option><?php foreach($brands as $b): ?><option value="<?= (int)$b['id']; ?>"><?= e($b['name']); ?><?= $b['is_active']?'':' (nonaktif)'; ?></option><?php endforeach; ?></select>
                    <small>Merek/pabrikan, misalnya JNC, Vaporesso, Voopoo. Tidak wajib untuk membuat produk.</small>
                </div>

                <div class="form-group">
                    <label>Supplier</label>
                    <select name="supplier_id">
                        <option value="">Pilih supplier</option>
                        <?php foreach($suppliers as $s): ?>
                            <option value="<?= $s['id']; ?>"><?= e($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group"><label>Satuan</label><input name="unit" value="pcs"></div>
                <div class="form-group"><label>Berat Marketplace (gram)</label><input type="number" min="1" max="50000" name="weight_gram" value="<?= e((string) marketplace_default_weight()); ?>"><small>Dipakai untuk hitung ongkir live. Contoh liquid 60ml + kemasan dapat diisi sesuai berat kirim sebenarnya.</small></div>
                <div class="form-group"><label>Harga Beli</label><input type="number" name="purchase_price" value="0"></div>
                <div class="form-group"><label>Harga Jual</label><input type="number" name="selling_price" value="0"></div>
                <div class="form-group"><label>Stok Awal</label><input type="number" name="stock" value="0"></div>
                <div class="form-group"><label>Stok Minimum</label><input type="number" name="minimum_stock" value="0"></div>
                <div class="form-group"><label>Foto Produk <small>(bisa multiple)</small></label><input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple><small>Maksimal 12 foto. Foto pertama menjadi gambar utama POS; sisanya otomatis masuk galeri Marketplace.</small></div>
                <div class="form-group switch-line"><label><input type="checkbox" name="is_active" checked> Produk aktif</label></div>
                <div class="form-group full"><label>Deskripsi</label><textarea name="description" placeholder="Deskripsi singkat produk"></textarea></div>
            </div>

            <section class="premium-card" style="margin:14px 0;padding:14px"><div style="display:flex;justify-content:space-between;gap:12px;align-items:center"><div><b>Tambah Varian <small>(opsional)</small></b><p style="margin:5px 0 0">Contoh: White GT, Beige, 3mg, 6mg. Stok setiap varian dapat berbeda.</p></div><button type="button" class="btn-soft" id="addVariantRow"><i class="bi bi-plus-circle"></i> Tambah Varian</button></div><div id="variantRows" style="display:grid;gap:10px;margin-top:12px"></div></section>
            <div class="premium-card" style="margin:14px 0;padding:14px"><b>Produk otomatis masuk master Marketplace</b><p style="margin:5px 0 0">Jika Produk aktif dicentang, produk ini otomatis tersedia di katalog marketplace. Varian yang diisi di atas langsung ikut tersimpan ke POS & Marketplace.</p></div>
            <div class="form-actions">
                <a class="btn-soft" href="<?= legacy_base_url('admin/products/index.php'); ?>">Batal</a>
                <button class="btn-premium"><i class="bi bi-save"></i> Simpan Produk</button>
            </div>
        </form>
    </section>
</main>
<script>
(function(){const box=document.getElementById('variantRows'),btn=document.getElementById('addVariantRow');if(!box||!btn)return;function add(){const row=document.createElement('div');row.className='variant-create-row';row.innerHTML='<div><label>Nama Varian</label><input name="variant_name[]" placeholder="White GT"></div><div><label>SKU</label><input name="variant_sku[]"></div><div><label>Barcode</label><input name="variant_barcode[]"></div><div><label>Harga Beli</label><input type="number" min="0" name="variant_purchase_price[]"></div><div><label>Harga Jual</label><input type="number" min="0" name="variant_selling_price[]"></div><div><label>Stok</label><input type="number" min="0" name="variant_stock[]" value="0"></div><button type="button" class="btn-soft variant-remove"><i class="bi bi-trash"></i></button>';row.querySelector('.variant-remove').onclick=()=>row.remove();box.appendChild(row);}btn.addEventListener('click',add);})();
</script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
if (typeof window.Html5Qrcode === 'undefined') {
    document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"><\/script>');
}
</script>
<script src="<?= legacy_base_url('assets/js/product-barcode.js?v=' . time()); ?>"></script>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
