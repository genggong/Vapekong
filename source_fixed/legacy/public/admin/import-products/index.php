<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../prices/_helpers.php';
Auth::requireRole(['super_admin', 'owner']);

$pageTitle = 'Import Produk';
$db = Database::connect();
marketplace_install($db);
price_install($db);
branch_install($db);
$currentBranchId = branch_current_id($db);
$hasRackLocation = branch_column_exists($db, 'products', 'rack_location');
$preview = $_SESSION['import_products_preview'] ?? null;

if (is_post()) {
    if (!CSRF::verify()) {
        flash('error', 'Token keamanan tidak valid.');
        legacy_redirect('admin/import-products/index.php');
    }

    $action = $_POST['action'] ?? 'preview';

    if ($action === 'clear') {
        unset($_SESSION['import_products_preview']);
        flash('success', 'Preview import dibersihkan.');
        legacy_redirect('admin/import-products/index.php');
    }

    if ($action === 'preview') {
        try {
            $mode = $_POST['import_mode'] ?? 'upsert';
            if (!in_array($mode, ['add_only', 'upsert'], true)) $mode = 'upsert';

            $matrix = import_products_read_uploaded_file($_FILES['data_file'] ?? [], 1001);
            $headerIndex = null;
            $headers = [];
            foreach (array_slice($matrix, 0, 10, true) as $index => $candidate) {
                $normalized = array_map('import_products_normalize_header', (array)$candidate);
                if (in_array('name', $normalized, true) || in_array('nama_produk', $normalized, true)) {
                    $headerIndex = (int)$index;
                    $headers = $normalized;
                    break;
                }
            }
            if ($headerIndex === null) {
                throw new Exception('Header kolom "name" tidak ditemukan. Gunakan template Excel KasirPro.');
            }

            $map = array_flip($headers);
            $rows = [];
            $errors = [];
            $seenCode = [];
            $seenBarcode = [];
            $seenVariantSku = [];
            $seenVariantBarcode = [];
            $rowIndexByIdentity = [];
            $duplicateRowsMerged = 0;
            $limit = 1000;
            $dataRows = array_slice($matrix, $headerIndex + 1);

            foreach ($dataRows as $offset => $data) {
                if (count($rows) >= $limit) {
                    $errors[] = 'Maksimal import ' . $limit . ' baris sekali upload.';
                    break;
                }

                $isEmpty = true;
                foreach ((array)$data as $cell) {
                    if (trim((string)$cell) !== '') { $isEmpty = false; break; }
                }
                if ($isEmpty) continue;

                $get = function (string $key, $default = '') use ($map, $data) {
                    if (!isset($map[$key])) return $default;
                    return trim((string)($data[$map[$key]] ?? $default));
                };

                $row = [
                    'line' => $headerIndex + $offset + 2,
                    'name' => $get('name', $get('nama_produk')),
                    'variant' => $get('variant', $get('varian')),
                    'variant_sku' => $get('variant_sku', $get('sku_varian')),
                    'variant_barcode' => $get('variant_barcode', $get('barcode_varian')),
                    'brand' => $get('brand', $get('brand_merek', $get('merek'))),
                    'product_code' => $get('product_code', $get('kode_produk')),
                    'barcode' => $get('barcode', $get('barcode_produk')),
                    'category' => $get('category', $get('kategori')),
                    'supplier' => $get('supplier'),
                    'unit' => $get('unit', $get('satuan', 'pcs')) ?: 'pcs',
                    'purchase_price' => import_products_parse_number($get('purchase_price', $get('harga_beli'))),
                    'selling_price' => import_products_parse_number($get('selling_price', $get('harga_jual'))),
                    'stock' => (int)import_products_parse_number($get('stock', $get('stok'))),
                    'minimum_stock' => (int)import_products_parse_number($get('minimum_stock', $get('stok_minimum'))),
                    'rack_location' => $get('rack_location', $get('lokasi_rak')),
                    'is_active' => import_products_parse_bool($get('is_active', $get('status', '1'))),
                    'description' => $get('description', $get('deskripsi')),
                    'operation' => 'create',
                    'existing_id' => null,
                    'errors' => [],
                    'warnings' => [],
                ];

                if ($row['variant'] !== '' && str_ends_with(mb_strtolower($row['name']), mb_strtolower(' - '.$row['variant']))) {
                    $row['name'] = trim(mb_substr($row['name'],0,mb_strlen($row['name'])-mb_strlen(' - '.$row['variant'])));
                }
                if ($row['name'] === '') $row['errors'][] = 'Nama produk kosong';
                if ($row['selling_price'] < 0 || $row['purchase_price'] < 0) $row['errors'][] = 'Harga tidak boleh minus';
                if ($row['stock'] < 0 || $row['minimum_stock'] < 0) $row['errors'][] = 'Stok tidak boleh minus';

                // V15.18: produk induk boleh berulang untuk baris varian. Identitas varian
                // diprioritaskan dari SKU -> Barcode -> Nama Varian. Ini membuat file hasil
                // Export bisa di-upload ulang tanpa false-positive duplikat.
                $parentIdentity = mb_strtolower(trim((string)$row['name']));
                $variantNameIdentity = mb_strtolower(trim((string)$row['variant']));
                $variantIdentity = $row['variant_sku'] !== ''
                    ? 'sku:'.mb_strtolower($row['variant_sku'])
                    : ($row['variant_barcode'] !== ''
                        ? 'barcode:'.mb_strtolower($row['variant_barcode'])
                        : ($variantNameIdentity !== '' ? 'name:'.$variantNameIdentity : 'no-variant'));
                $parentKey = $row['product_code'] !== '' ? 'code:'.mb_strtolower($row['product_code'])
                    : ($row['barcode'] !== '' ? 'barcode:'.mb_strtolower($row['barcode']) : 'name:'.$parentIdentity);
                $rowIdentity = $parentKey.'|'.$variantIdentity;

                // Jika source lama sudah memiliki varian duplikat identik, konsolidasikan ke
                // satu baris preview. Stok memakai nilai terbesar agar tidak menggandakan stok
                // hanya karena baris duplikat lama.
                if (isset($rowIndexByIdentity[$rowIdentity])) {
                    $idx = $rowIndexByIdentity[$rowIdentity];
                    if (isset($rows[$idx])) {
                        $rows[$idx]['stock'] = max((int)$rows[$idx]['stock'], (int)$row['stock']);
                        $rows[$idx]['minimum_stock'] = max((int)$rows[$idx]['minimum_stock'], (int)$row['minimum_stock']);
                        foreach (['brand','category','supplier','unit','rack_location','description','variant_sku','variant_barcode'] as $mergeKey) {
                            if (($rows[$idx][$mergeKey] ?? '') === '' && ($row[$mergeKey] ?? '') !== '') $rows[$idx][$mergeKey] = $row[$mergeKey];
                        }
                        if ((float)$rows[$idx]['purchase_price'] <= 0 && (float)$row['purchase_price'] > 0) $rows[$idx]['purchase_price'] = $row['purchase_price'];
                        if ((float)$rows[$idx]['selling_price'] <= 0 && (float)$row['selling_price'] > 0) $rows[$idx]['selling_price'] = $row['selling_price'];
                        $rows[$idx]['duplicate_source_lines'][] = (int)$row['line'];
                        $duplicateRowsMerged++;
                        continue;
                    }
                }
                $rowIndexByIdentity[$rowIdentity] = count($rows);

                if ($row['product_code'] !== '') {
                    $key = mb_strtolower($row['product_code']);
                    if (isset($seenCode[$key]) && ($seenCode[$key]['parent'] ?? '') !== $parentIdentity) {
                        $row['errors'][] = 'Kode produk dipakai oleh produk induk berbeda di file';
                    }
                    $seenCode[$key]['parent'] = $parentIdentity;
                    $seenCode[$key]['variants'][$variantIdentity] = true;
                }
                if ($row['barcode'] !== '') {
                    $key = mb_strtolower($row['barcode']);
                    if (isset($seenBarcode[$key]) && ($seenBarcode[$key]['parent'] ?? '') !== $parentIdentity) {
                        $row['errors'][] = 'Barcode produk dipakai oleh produk induk berbeda di file';
                    }
                    $seenBarcode[$key]['parent'] = $parentIdentity;
                    $seenBarcode[$key]['variants'][$variantIdentity] = true;
                }
                if ($row['variant_sku'] !== '') {
                    $key=mb_strtolower($row['variant_sku']);
                    if(isset($seenVariantSku[$key]) && $seenVariantSku[$key] !== $rowIdentity){
                        $row['warnings'][]='SKU varian duplikat di file dikosongkan otomatis. Isi SKU unik bila memang diperlukan.';
                        $row['variant_sku']='';
                    } else $seenVariantSku[$key]=$rowIdentity;
                }
                if ($row['variant_barcode'] !== '') {
                    $key=mb_strtolower($row['variant_barcode']);
                    if(isset($seenVariantBarcode[$key]) && $seenVariantBarcode[$key] !== $rowIdentity){
                        $row['warnings'][]='Barcode varian duplikat di file dikosongkan otomatis. Isi barcode unik bila memang diperlukan.';
                        $row['variant_barcode']='';
                    } else $seenVariantBarcode[$key]=$rowIdentity;
                }

                $existingInfo = import_products_find_existing($db, $row['product_code'], $row['barcode'], $row['name']);
                $parentDbId = !empty($existingInfo['row']['id']) ? (int)$existingInfo['row']['id'] : 0;
                // SKU/Barcode varian bersifat opsional. Jika bentrok dengan varian produk lain
                // di database, kosongkan otomatis agar import Brand/Kategori/Varian/Stok tetap bisa diproses.
                if ($row['variant_sku'] !== '') {
                    try { $q=$db->prepare('SELECT id,product_id FROM marketplace_product_variants WHERE sku=? ORDER BY id ASC LIMIT 1');$q->execute([$row['variant_sku']]);$vr=$q->fetch();
                        if($vr && ($parentDbId<=0 || (int)$vr['product_id']!==$parentDbId)){$row['warnings'][]='SKU varian sudah dipakai produk lain di database; SKU dikosongkan otomatis.';$row['variant_sku']='';}
                    } catch(Throwable $ignore) {}
                }
                if ($row['variant_barcode'] !== '') {
                    try { $q=$db->prepare('SELECT id,product_id FROM marketplace_product_variants WHERE barcode=? ORDER BY id ASC LIMIT 1');$q->execute([$row['variant_barcode']]);$vr=$q->fetch();
                        if($vr && ($parentDbId<=0 || (int)$vr['product_id']!==$parentDbId)){$row['warnings'][]='Barcode varian sudah dipakai produk lain di database; barcode varian dikosongkan otomatis.';$row['variant_barcode']='';}
                    } catch(Throwable $ignore) {}
                }
                if ($existingInfo['conflict']) {
                    $row['errors'][] = 'Data cocok ke lebih dari satu produk. Pastikan nama produk unik atau gunakan file lama yang memiliki kode.';
                } elseif ($existingInfo['row']) {
                    if ($mode === 'add_only') {
                        $row['errors'][] = 'Produk sudah ada di database';
                    } else {
                        $row['operation'] = 'update';
                        $row['existing_id'] = (int)$existingInfo['row']['id'];
                    }
                }

                $rows[] = $row;
            }

            if (!$rows) throw new Exception('Tidak ada data produk yang bisa dipreview.');

            $_SESSION['import_products_preview'] = [
                'rows' => $rows,
                'errors' => $errors,
                'mode' => $mode,
                'filename' => $_FILES['data_file']['name'] ?? 'file-produk',
                'created_at' => now_datetime(),
                'duplicate_rows_merged' => $duplicateRowsMerged,
            ];

            $dupMsg = $duplicateRowsMerged > 0 ? ' '.$duplicateRowsMerged.' baris duplikat identik dikonsolidasikan otomatis.' : '';
            flash('success', 'Preview berhasil dibuat. Periksa produk baru, pembaruan, dan data error sebelum diproses.'.$dupMsg);
            legacy_redirect('admin/import-products/index.php');
        } catch (Throwable $e) {
            flash('error', 'Preview gagal: ' . $e->getMessage());
            legacy_redirect('admin/import-products/index.php');
        }
    }

    if ($action === 'import') {
        try {
            $preview = $_SESSION['import_products_preview'] ?? null;
            if (!$preview || empty($preview['rows'])) throw new Exception('Preview import tidak ditemukan. Upload file produk dulu.');

            $mode = $preview['mode'] ?? 'upsert';
            $createCategory = isset($_POST['create_category']);
            $createSupplier = isset($_POST['create_supplier']);
            $created = 0;
            $createdParentIds = [];
            $updated = 0;
            $skipped = 0;

            $db->beginTransaction();
            foreach ($preview['rows'] as $i => $row) {
                if (!empty($row['errors'])) { $skipped++; continue; }

                $existingInfo = import_products_find_existing($db, trim($row['product_code']), trim($row['barcode']), trim($row['name']));
                if ($existingInfo['conflict']) { $skipped++; continue; }
                $existing = $existingInfo['row'];
                $existingCreatedThisRun = $existing && in_array((int)$existing['id'],$createdParentIds,true);
                if ($existing && $mode === 'add_only' && !$existingCreatedThisRun) { $skipped++; continue; }

                $categoryId = import_products_find_category($db, $row['category'], $createCategory);
                $supplierId = import_products_find_supplier($db, $row['supplier'], $createSupplier);
                $brandId = import_products_find_brand($db, $row['brand'] ?? '');
                $barcode = trim($row['barcode']) ?: null;
                $rack = trim($row['rack_location'] ?? '') ?: null;

                if ($existing && ($mode === 'upsert' || $existingCreatedThisRun)) {
                    $productId = (int)$existing['id'];
                    $productCode = trim($row['product_code']) ?: (string)$existing['product_code'];
                    if ($barcode === null && !empty($existing['barcode'])) $barcode = (string)$existing['barcode'];
                    $oldStock = (int)$existing['stock'];
                    $parentStockForSave = trim((string)($row['variant']??''))!=='' ? $oldStock : (int)$row['stock'];

                    if ($hasRackLocation) {
                        $stmt = $db->prepare('UPDATE products SET category_id=?,supplier_id=?,product_code=?,barcode=?,name=?,description=?,purchase_price=?,selling_price=?,stock=?,minimum_stock=?,unit=?,rack_location=?,is_active=? WHERE id=?');
                        $stmt->execute([$categoryId, $supplierId, $productCode, $barcode, $row['name'], $row['description'], $row['purchase_price'], $row['selling_price'], $parentStockForSave, $row['minimum_stock'], $row['unit'], $rack, $row['is_active'], $productId]);
                    } else {
                        $stmt = $db->prepare('UPDATE products SET category_id=?,supplier_id=?,product_code=?,barcode=?,name=?,description=?,purchase_price=?,selling_price=?,stock=?,minimum_stock=?,unit=?,is_active=? WHERE id=?');
                        $stmt->execute([$categoryId, $supplierId, $productCode, $barcode, $row['name'], $row['description'], $row['purchase_price'], $row['selling_price'], $parentStockForSave, $row['minimum_stock'], $row['unit'], $row['is_active'], $productId]);
                    }

                    price_save($db, $productId, 'retail', max(0, (float)$row['selling_price']), 1);
                    if($brandId>0) marketplace_v15_set_product_brand($db,$productId,$brandId);
                    if(trim((string)($row['variant']??''))!==''){
                        import_products_upsert_variant($db,$productId,$currentBranchId,$row);
                    }else{
                        import_products_sync_branch_stock($db, $currentBranchId, $productId, (int)$row['stock'], (int)$row['minimum_stock']);
                        import_products_record_stock_movement($db, $productId, $oldStock, (int)$row['stock'], 'Update stok dari import produk', $currentBranchId);
                    }
                    if (!$existingCreatedThisRun) $updated++;
                    continue;
                }

                $productCode = trim($row['product_code']);
                if ($productCode === '') $productCode = import_products_generate_code($db, $i);
                $parentStockForSave = trim((string)($row['variant']??''))!=='' ? 0 : (int)$row['stock'];
                if (import_products_existing_value($db, 'product_code', $productCode)) { $skipped++; continue; }
                if ($barcode && import_products_existing_value($db, 'barcode', $barcode)) { $skipped++; continue; }

                if ($hasRackLocation) {
                    $stmt = $db->prepare('INSERT INTO products (category_id,supplier_id,product_code,barcode,name,description,image,purchase_price,selling_price,stock,minimum_stock,unit,rack_location,is_active) VALUES (?,?,?,?,?,?,NULL,?,?,?,?,?,?,?)');
                    $stmt->execute([$categoryId, $supplierId, $productCode, $barcode, $row['name'], $row['description'], $row['purchase_price'], $row['selling_price'], $parentStockForSave, $row['minimum_stock'], $row['unit'], $rack, $row['is_active']]);
                } else {
                    $stmt = $db->prepare('INSERT INTO products (category_id,supplier_id,product_code,barcode,name,description,image,purchase_price,selling_price,stock,minimum_stock,unit,is_active) VALUES (?,?,?,?,?,?,NULL,?,?,?,?,?,?)');
                    $stmt->execute([$categoryId, $supplierId, $productCode, $barcode, $row['name'], $row['description'], $row['purchase_price'], $row['selling_price'], $parentStockForSave, $row['minimum_stock'], $row['unit'], $row['is_active']]);
                }

                $productId = (int)$db->lastInsertId();
                $createdParentIds[]=$productId;
                price_save($db, $productId, 'retail', max(0, (float)$row['selling_price']), 1);
                if($brandId>0) marketplace_v15_set_product_brand($db,$productId,$brandId);
                if(trim((string)($row['variant']??''))!==''){
                    import_products_upsert_variant($db,$productId,$currentBranchId,$row);
                }else{
                    import_products_sync_branch_stock($db, $currentBranchId, $productId, (int)$row['stock'], (int)$row['minimum_stock']);
                    import_products_record_stock_movement($db, $productId, 0, (int)$row['stock'], 'Stok awal dari import produk', $currentBranchId);
                }
                $created++;
            }
            $db->commit();

            unset($_SESSION['import_products_preview']);
            Auth::log('import_products', 'Import produk: ' . $created . ' baru, ' . $updated . ' diperbarui, ' . $skipped . ' dilewati');
            flash('success', 'Import selesai. Baru: ' . $created . ', diperbarui: ' . $updated . ', dilewati: ' . $skipped . '.');
            legacy_redirect('admin/products/index.php');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            flash('error', 'Import gagal: ' . $e->getMessage());
            legacy_redirect('admin/import-products/index.php');
        }
    }
}

$preview = $_SESSION['import_products_preview'] ?? null;
$validRows = 0;
$invalidRows = 0;
$newRows = 0;
$updateRows = 0;
if ($preview) {
    foreach ($preview['rows'] as $row) {
        if (!empty($row['errors'])) {
            $invalidRows++;
        } else {
            $validRows++;
            if (($row['operation'] ?? 'create') === 'update') $updateRows++; else $newRows++;
        }
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/import-products.css?v=' . time()); ?>">

<main class="admin-main import-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Import Produk</h1>
            <p>Upload produk dari Excel, CSV, atau PDF hasil export KasirPro, lalu validasi sebelum disimpan.</p>
        </div>
        <div class="topbar-actions">
            <a class="import-btn soft" href="<?= legacy_base_url('admin/products/index.php'); ?>"><i class="bi bi-box-seam"></i> Produk</a>
            <a class="import-btn primary" href="<?= legacy_base_url('admin/import-products/template.php?format=xlsx'); ?>"><i class="bi bi-file-earmark-excel"></i> Template Excel</a>
        </div>
    </header>

    <section class="import-hero">
        <span class="import-hero-badge"><i class="bi bi-file-earmark-spreadsheet"></i> Excel & PDF Data Exchange</span>
        <h2>Tambah atau perbarui banyak produk tanpa input satu per satu.</h2>
        <p>Sistem membaca file XLSX asli, CSV, dan PDF yang diekspor dari KasirPro. Semua data diperiksa lebih dulu, termasuk kode, barcode, harga, stok, kategori, supplier, dan sinkronisasi stok cabang.</p>
    </section>

    <?php if (!$preview): ?>
        <section class="import-grid">
            <div class="import-panel">
                <div class="import-panel-head">
                    <div>
                        <h3>Upload Data Produk</h3>
                        <p>Gunakan template Excel atau file hasil export agar struktur kolom tetap sesuai.</p>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" class="import-form">
                    <?= CSRF::input(); ?>
                    <input type="hidden" name="action" value="preview">
                    <div class="import-field">
                        <label>File Produk</label>
                        <input type="file" name="data_file" accept=".xlsx,.csv,.txt,.pdf" required>
                    </div>
                    <div class="import-field">
                        <label>Mode Import</label>
                        <select name="import_mode">
                            <option value="upsert">Tambah produk baru + perbarui produk yang sudah ada</option>
                            <option value="add_only">Hanya tambah produk baru</option>
                        </select>
                    </div>
                    <div class="import-note">
                        <b>Kolom utama:</b> Nama Produk, Brand / Merek, Kategori, Varian. File juga mendukung SKU Varian, Barcode Varian, Kode Produk, Barcode Produk, Supplier, Satuan, Harga Beli, Harga Jual, Stok, Stok Minimum, Lokasi Rak, Status, dan Deskripsi. Produk dengan beberapa varian memakai beberapa baris dengan Nama Produk + Kode Produk induk yang sama.<br>
                        <b>Catatan PDF:</b> hanya PDF data produk yang diunduh dari KasirPro yang dapat diunggah kembali.
                    </div>
                    <div class="import-actions">
                        <a class="import-btn soft" href="<?= legacy_base_url('admin/import-products/template.php?format=csv'); ?>"><i class="bi bi-filetype-csv"></i> Template CSV</a>
                        <a class="import-btn soft" href="<?= legacy_base_url('admin/import-products/template.php?format=xlsx'); ?>"><i class="bi bi-file-earmark-excel"></i> Template Excel</a>
                        <button class="import-btn primary" type="submit"><i class="bi bi-eye"></i> Preview Data</button>
                    </div>
                </form>
            </div>

            <div class="import-panel">
                <div class="import-panel-head"><div><h3>Cara Pakai</h3><p>Ikuti urutan agar data tetap aman.</p></div></div>
                <div class="import-steps">
                    <div class="import-step"><span>1</span><div><b>Download Template</b><p>Isi atau edit Nama Produk, Brand, Kategori, Varian, harga dan stok tanpa mengubah nama header. File export Produk juga bisa di-upload kembali.</p></div></div>
                    <div class="import-step"><span>2</span><div><b>Pilih Mode</b><p>Gunakan mode Tambah & Perbarui untuk meng-apply file kembali ke POS. Sistem mencocokkan Kode Produk/Barcode lalu Nama Produk sebagai fallback.</p></div></div>
                    <div class="import-step"><span>3</span><div><b>Preview & Validasi</b><p>Cek produk baru, produk yang diperbarui, duplikat, dan data yang error.</p></div></div>
                    <div class="import-step"><span>4</span><div><b>Proses Import</b><p>Produk POS, Brand, Kategori, Varian dan stok per varian/cabang diterapkan dalam satu proses.</p></div></div>
                </div>
            </div>
        </section>
    <?php else: ?>
        <section class="import-panel">
            <div class="import-panel-head">
                <div>
                    <h3>Preview Import</h3>
                    <p>File: <?= e($preview['filename']); ?> • Mode: <?= ($preview['mode'] ?? 'upsert') === 'upsert' ? 'Tambah & Perbarui' : 'Tambah Baru'; ?> • Dibuat: <?= e($preview['created_at']); ?></p>
                </div>
                <form method="POST">
                    <?= CSRF::input(); ?>
                    <input type="hidden" name="action" value="clear">
                    <button class="import-btn danger" type="submit"><i class="bi bi-x-circle"></i> Bersihkan Preview</button>
                </form>
            </div>

            <div class="import-summary">
                <div class="import-summary-card"><span>Total Baris</span><h3><?= count($preview['rows']); ?></h3></div>
                <div class="import-summary-card"><span>Produk Baru</span><h3><?= $newRows; ?></h3></div>
                <div class="import-summary-card"><span>Diperbarui</span><h3><?= $updateRows; ?></h3></div>
                <div class="import-summary-card"><span>Error</span><h3><?= $invalidRows; ?></h3></div>
            </div>

            <?php if (!empty($preview['errors'])): ?>
                <div class="import-note mb-3"><b>Catatan:</b><ul class="import-error-list"><?php foreach ($preview['errors'] as $err): ?><li><?= e($err); ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <form method="POST" class="import-form">
                <?= CSRF::input(); ?>
                <input type="hidden" name="action" value="import">
                <div class="import-actions">
                    <label class="switch-box"><input type="checkbox" name="create_category" checked> Buat kategori otomatis jika belum ada</label>
                    <label class="switch-box"><input type="checkbox" name="create_supplier" checked> Buat supplier otomatis jika belum ada</label>
                    <button class="import-btn primary" type="submit" <?= $validRows <= 0 ? 'disabled' : ''; ?>><i class="bi bi-cloud-upload"></i> Proses <?= $validRows; ?> Produk Valid</button>
                </div>
            </form>

            <div class="import-table-wrap mt-3">
                <table class="import-table">
                    <thead><tr><th>Baris</th><th>Nama Produk</th><th>Brand</th><th>Kategori</th><th>Varian</th><th>Kode/Barcode</th><th>Harga</th><th>Stok</th><th>Hasil</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview['rows'] as $row): ?>
                        <tr>
                            <td>#<?= e($row['line']); ?></td>
                            <td class="cell-main"><b><?= e($row['name']); ?></b><small><?= e($row['unit']); ?> • <?= e($row['description'] ?: 'Tanpa deskripsi'); ?></small></td>
                            <td><?= e($row['brand'] ?: 'Tanpa Brand'); ?></td>
                            <td><?= e($row['category'] ?: '-'); ?></td>
                            <td><b><?= e($row['variant'] ?: '-'); ?></b><?php if(!empty($row['variant_sku']) || !empty($row['variant_barcode'])):?><br><small><?= e($row['variant_sku'] ?: '-') ?><?= !empty($row['variant_barcode']) ? ' • '.e($row['variant_barcode']) : '' ?></small><?php endif;?></td>
                            <td><code><?= e($row['product_code'] ?: 'Auto'); ?></code><br><small><?= e($row['barcode'] ?: 'Tanpa barcode'); ?></small></td>
                            <td><?= rupiah($row['purchase_price']); ?><br><b><?= rupiah($row['selling_price']); ?></b></td>
                            <td><?= e($row['stock']); ?><br><small>Min <?= e($row['minimum_stock']); ?><?= !empty($row['rack_location']) ? ' • Rak ' . e($row['rack_location']) : ''; ?></small></td>
                            <td>
                                <?php if (!empty($row['errors'])): ?>
                                    <span class="import-badge red"><i class="bi bi-x-circle"></i> Error</span>
                                    <ul class="import-error-list"><?php foreach ($row['errors'] as $err): ?><li><?= e($err); ?></li><?php endforeach; ?></ul>
                                <?php else: ?>
                                    <?php if (!empty($row['warnings'])): ?><span class="import-badge orange"><i class="bi bi-exclamation-circle"></i> Catatan</span><ul class="import-error-list"><?php foreach ($row['warnings'] as $wr): ?><li><?= e($wr); ?></li><?php endforeach; ?></ul><?php endif; ?>
                                    <?php if (($row['operation'] ?? 'create') === 'update'): ?>
                                        <span class="import-badge blue"><i class="bi bi-arrow-repeat"></i> Perbarui</span>
                                    <?php else: ?>
                                        <span class="import-badge green"><i class="bi bi-plus-circle"></i> Produk Baru</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
