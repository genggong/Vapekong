<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
Auth::requireLogin();

$db = Database::connect();
marketplace_install($db);
$pageTitle = 'Produk Marketplace';

$q = trim((string) ($_GET['q'] ?? ''));
$brand = max(0, (int) ($_GET['brand'] ?? 0));
$schemaWarning = '';

$hasBrandColumn = marketplace_column_exists($db, 'products', 'brand_id');
$hasBrandTable = marketplace_table_exists($db, 'marketplace_brands');
$hasProductMeta = marketplace_v15_product_meta_install($db);
$hasVariants = marketplace_table_exists($db, 'marketplace_product_variants');
$hasImages = marketplace_table_exists($db, 'marketplace_product_images');

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.product_code LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($brand > 0) {
    if ($hasBrandColumn) {
        $where[] = 'p.brand_id=?';
        $params[] = $brand;
    } elseif ($hasProductMeta) {
        $where[] = 'EXISTS (SELECT 1 FROM marketplace_product_meta pmf WHERE pmf.product_id=p.id AND pmf.brand_id=?)';
        $params[] = $brand;
    }
}

$brandIdSelect = $hasBrandColumn ? 'p.brand_id' : ($hasProductMeta ? 'pm.brand_id' : 'NULL');
$brandJoin = $hasProductMeta ? 'LEFT JOIN marketplace_product_meta pm ON pm.product_id=p.id ' : '';
if ($hasBrandTable) $brandJoin .= 'LEFT JOIN marketplace_brands b ON b.id=' . ($hasBrandColumn ? 'p.brand_id' : ($hasProductMeta ? 'pm.brand_id' : 'NULL')) . ' ';
$brandSelect = $hasBrandTable ? 'b.name' : 'NULL';
$variantSelect = $hasVariants ? 'COALESCE(vx.variant_count,0)' : '0';
$imageSelect = $hasImages ? 'COALESCE(ix.image_count,0)' : '0';
$aggregateJoin = '';
if ($hasVariants) $aggregateJoin .= 'LEFT JOIN (SELECT product_id,COUNT(*) variant_count FROM marketplace_product_variants GROUP BY product_id) vx ON vx.product_id=p.id ';
if ($hasImages) $aggregateJoin .= 'LEFT JOIN (SELECT product_id,COUNT(*) image_count FROM marketplace_product_images GROUP BY product_id) ix ON ix.product_id=p.id ';

$rows = [];
try {
    $sql = "SELECT p.id,p.name,p.product_code,p.image,p.selling_price,p.stock,p.is_active,"
         . $brandIdSelect . " AS brand_id,"
         . "{$brandSelect} AS brand_name,c.name category_name,"
         . "{$variantSelect} AS variant_count,{$imageSelect} AS image_count "
         . "FROM products p {$brandJoin}{$aggregateJoin}LEFT JOIN categories c ON c.id=p.category_id "
         . "WHERE " . implode(' AND ', $where) . " ORDER BY CASE WHEN COALESCE(p.stock,0)<=0 THEN 1 ELSE 0 END ASC,p.name ASC,p.id ASC";
    $s = $db->prepare($sql);
    $s->execute($params);
    $rows = $s->fetchAll() ?: [];
} catch (Throwable $e) {
    // Keep the admin page usable even if a hosting DB blocked an additive V15 ALTER.
    $schemaWarning = 'Sebagian struktur Marketplace V15 belum terpasang. Daftar produk tetap ditampilkan dalam mode kompatibilitas.';
    try {
        $fallbackWhere = ['1=1'];
        $fallbackParams = [];
        if ($q !== '') {
            $fallbackWhere[] = '(p.name LIKE ? OR p.product_code LIKE ?)';
            $fallbackParams[] = '%' . $q . '%';
            $fallbackParams[] = '%' . $q . '%';
        }
        $s = $db->prepare("SELECT p.id,p.name,p.product_code,p.image,p.selling_price,p.stock,p.is_active,NULL AS brand_id,NULL AS brand_name,c.name category_name,0 AS variant_count,0 AS image_count FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE " . implode(' AND ', $fallbackWhere) . " ORDER BY CASE WHEN COALESCE(p.stock,0)<=0 THEN 1 ELSE 0 END ASC,p.name ASC,p.id ASC");
        $s->execute($fallbackParams);
        $rows = $s->fetchAll() ?: [];
    } catch (Throwable $ignored) {
        $rows = [];
        $schemaWarning = 'Data produk belum dapat dibaca. Jalankan patch database Marketplace Repair V15.1 atau buka halaman ini kembali setelah overwrite patch.';
    }
}

$brands = $hasBrandTable ? marketplace_v15_brands($db, false) : [];
if (!$hasBrandColumn && !$hasProductMeta) $schemaWarning = $schemaWarning ?: 'Metadata marketplace belum dapat dibuat. Brand belum bisa dipasang ke produk, tetapi produk POS tetap aman.';
require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/marketplace-customer-admin.css?v=15.9') ?>">
<main class="admin-main mp-admin">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Produk Marketplace</h1>
            <p>Master produk sama dengan POS. Marketplace hanya menambahkan brand, galeri, varian, berat kirim, dan data online.</p>
        </div>
        <div class="topbar-actions">
            <a class="mp-btn soft" href="<?= legacy_base_url('admin/products/create.php') ?>"><i class="bi bi-plus"></i> Tambah Produk POS</a>
        </div>
    </header>

    <?php if ($schemaWarning): ?>
        <div class="mp-alert error"><?= e($schemaWarning) ?></div>
    <?php endif; ?>

    <section class="mp-panel">
        <div class="mp-panel-head"><div><h3>Sinkron Otomatis dengan POS</h3><p>Produk dibuat dan diedit dari menu Produk POS. Produk aktif otomatis tersedia untuk marketplace. Produk nonaktif tetap terlihat di halaman admin ini agar daftar master sama dengan POS, tetapi tidak tampil ke pembeli.</p></div><span class="badge-soft success"><?= count($rows) ?> master produk</span></div>
    </section>

    <section class="mp-panel">
        <form method="get" class="mp-filter-row">
            <input name="q" value="<?= e($q) ?>" placeholder="Cari nama / SKU">
            <select name="brand" <?= (!$hasBrandColumn && !$hasProductMeta) ? 'disabled' : '' ?>>
                <option value="0">Semua brand</option>
                <?php foreach ($brands as $b): ?>
                    <option value="<?= (int) $b['id'] ?>" <?= $brand === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="mp-btn soft"><i class="bi bi-search"></i> Filter</button>
        </form>

        <div class="table-premium-card">
            <table class="premium-table js-data-table">
                <thead><tr><th>Produk</th><th>Kategori</th><th>Status POS</th><th>Brand</th><th>Harga POS</th><th>Varian</th><th>Galeri</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><b><?= e($r['name']) ?></b><br><small><?= e($r['product_code'] ?: '-') ?></small></td>
                        <td><?= e($r['category_name'] ?: '-') ?></td>
                        <td><?= !empty($r['is_active']) ? '<span class="badge-soft success">Aktif / tampil</span>' : '<span class="badge-soft danger">Nonaktif / disembunyikan</span>' ?></td>
                        <td><?= e($r['brand_name'] ?: 'Tanpa brand') ?></td>
                        <td><?= rupiah($r['selling_price']) ?></td>
                        <td><?= (int) $r['variant_count'] ?></td>
                        <td><?= (int) $r['image_count'] ?></td>
                        <td><a class="mp-btn soft" href="<?= legacy_base_url('admin/marketplace-products/detail.php?id=' . (int) $r['id']) ?>"><i class="bi bi-sliders"></i> Kelola</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
