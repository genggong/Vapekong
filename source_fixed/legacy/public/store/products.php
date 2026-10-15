<?php
require_once __DIR__ . '/_data.php';
$pageTitle = 'Produk';
$activePage = 'products';
$q = trim((string) ($_GET['q'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$sort = trim((string) ($_GET['sort'] ?? 'name'));
$brand = max(0,(int)($_GET['brand'] ?? 0));
$products = storefront_products(['limit' => 100, 'search' => $q, 'category' => $category, 'brand'=>$brand, 'sort' => $sort]);
$categories = storefront_categories(20);
$brands = storefront_brands(50);
$wishlistIds = storefront_wishlist_ids();
require __DIR__ . '/_header.php';
?>
<main class="inner-page">
    <section class="store-container page-hero small">
        <div><span class="hero-label"><i class="bi bi-grid-fill"></i> KATALOG ONLINE</span><h1>Temukan Produk <span>Favoritmu</span></h1><p>Data produk, harga, dan stok dibaca langsung dari POS agar selalu konsisten.</p></div>
        <div class="page-hero-icon"><i class="bi bi-bag-heart-fill"></i></div>
    </section>
    <section class="store-container catalog-toolbar">
        <form method="get" class="catalog-filter-form">
            <div class="filter-search"><i class="bi bi-search"></i><input name="q" value="<?= e($q); ?>" placeholder="Cari nama produk, kode, kategori..."></div>
            <select name="category"><option value="">Semua Kategori</option><?php foreach ($categories as $cat): ?><option value="<?= e($cat['slug']); ?>" <?= $category === $cat['slug'] ? 'selected' : ''; ?>><?= e($cat['name']); ?></option><?php endforeach; ?></select>
            <select name="brand"><option value="0">Semua Brand</option><?php foreach($brands as $b):?><option value="<?=$b['id']?>" <?=$brand===(int)$b['id']?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select>
            <select name="sort"><option value="name" <?= $sort === 'name' ? 'selected' : ''; ?>>Nama A-Z • stok habis terakhir</option><option value="latest" <?= $sort === 'latest' ? 'selected' : ''; ?>>Terbaru</option><option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : ''; ?>>Harga Terendah</option><option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : ''; ?>>Harga Tertinggi</option><option value="stock" <?= $sort === 'stock' ? 'selected' : ''; ?>>Stok Terbanyak</option></select>
            <button type="submit" class="btn-primary-store"><i class="bi bi-funnel"></i> Terapkan</button>
            <?php if ($q !== '' || $category !== '' || $brand>0 || $sort !== 'latest'): ?><a class="btn-soft" href="<?= legacy_base_url('produk'); ?>"><i class="bi bi-arrow-counterclockwise"></i> Reset</a><?php endif; ?>
        </form>
    </section>
    <section class="store-container catalog-content">
        <div class="catalog-result-head"><div><span class="eyebrow">HASIL KATALOG</span><h2><?= count($products); ?> Produk ditemukan</h2></div><span class="catalog-sync"><i class="bi bi-arrow-repeat"></i> Sinkron POS</span></div>
        <div class="product-grid catalog-grid">
            <?php if ($products): foreach ($products as $i => $product): $img = storefront_product_image($product); $fallbackImg = storefront_product_fallback($product); $stock = storefront_effective_stock($product); ?>
                <article class="product-card">
                    <div class="product-media"><button class="wish-btn <?= in_array((int)$product['id'], $wishlistIds, true) ? 'active' : ''; ?>" type="button" data-product-id="<?= (int)$product['id']; ?>"><i class="bi <?= in_array((int)$product['id'], $wishlistIds, true) ? 'bi-heart-fill' : 'bi-heart'; ?>"></i></button><a href="<?= e(storefront_product_url($product)); ?>"><img src="<?= e($img); ?>" data-fallback="<?= e($fallbackImg); ?>" onerror="this.onerror=null;this.src=this.dataset.fallback;" alt="<?= e($product['name']); ?>" loading="lazy"></a></div>
                    <div class="product-info"><span class="product-category"><?= e($product['category_name'] ?: 'Produk'); ?></span><h3><a class="product-title-link" href="<?= e(storefront_product_url($product)); ?>"><?= e($product['name']); ?></a></h3><div class="rating"><i class="bi bi-star-fill"></i><span>Detail</span><small>Lihat produk</small></div><div class="product-bottom"><div><strong><?= rupiah($product['selling_price']); ?></strong><?php if((float)($product['original_selling_price']??0)>(float)$product['selling_price']):?><span class="flash-old-price"><?=rupiah($product['original_selling_price'])?></span><?php endif;?><span class="stock-badge <?= $stock <= 0 ? 'out' : ''; ?>"><?= $stock > 0 ? 'Stok ' . $stock : 'Habis'; ?></span></div><?php if((int)($product['variant_count']??0)>0):?><a class="add-cart-btn" href="<?=e(storefront_product_url($product))?>" title="Pilih varian"><i class="bi bi-sliders"></i><span>Pilih Varian</span></a><?php else:?><button class="add-cart-btn" type="button" data-product="<?= storefront_product_json($product); ?>" <?= $stock <= 0 ? 'disabled' : ''; ?>><i class="bi bi-plus-lg"></i><span>Keranjang</span></button><?php endif;?></div></div>
                </article>
            <?php endforeach; else: ?><div class="empty-products full-span"><i class="bi bi-search"></i><h3>Produk tidak ditemukan</h3><p>Coba kata kunci atau kategori yang berbeda.</p><a class="btn-primary-store" href="<?= legacy_base_url('produk'); ?>">Lihat semua produk</a></div><?php endif; ?>
        </div>
    </section>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
