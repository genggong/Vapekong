<?php
require_once __DIR__ . '/_data.php';
$pageTitle = 'Belanja Online';
$activePage = 'home';
$homePage = max(1, (int)($_GET['page'] ?? 1));
$homePerPage = 8;
$homeTotalProducts = storefront_active_product_count();
$homeLastPage = max(1, (int)ceil($homeTotalProducts / $homePerPage));
if ($homePage > $homeLastPage) $homePage = $homeLastPage;
$products = storefront_products(['limit' => $homePerPage, 'offset' => ($homePage - 1) * $homePerPage, 'sort' => 'name']);
$heroProducts = storefront_products(['limit' => 3, 'sort' => 'name']);
$categories = storefront_categories(5);
$promo = storefront_featured_promo();
$stats = storefront_stats();
$branch = storefront_primary_branch();
$store = storefront_settings();
$wishlistIds = storefront_wishlist_ids();
$banners = setting('marketplace_home_show_banners','on')==='on' ? storefront_banners('hero') : [];
$promoBanners = setting('marketplace_home_show_banners','on')==='on' ? storefront_banners('promo') : [];
$customBanners = setting('marketplace_home_show_banners','on')==='on' ? storefront_banners('custom') : [];
$brands = setting('marketplace_home_show_brands','on')==='on' ? storefront_brands(12) : [];
$flashProducts = setting('marketplace_home_show_flash','on')==='on' ? storefront_flash_products(8) : [];
$heroVisual = storefront_visual_url($store['marketplace_hero_image'] ?? '', 'hero-fallback.svg');
$promoVisual = storefront_visual_url($store['marketplace_promo_image'] ?? '', 'promo-fallback.svg');
require __DIR__ . '/_header.php';

$promoTitle = $promo['name'] ?? 'Promo spesial hari ini';
$promoBig = 'Potongan hingga 35%';
if ($promo) {
    if (($promo['discount_type'] ?? '') === 'percent') {
        $promoBig = 'Potongan ' . rtrim(rtrim(number_format((float) $promo['discount_value'], 2, '.', ''), '0'), '.') . '%';
    } else {
        $promoBig = 'Hemat ' . rupiah((float) ($promo['discount_value'] ?? 0));
    }
}
?>
<main>
    <section class="hero-section">
        <div class="store-container hero-grid">
            <div class="hero-copy reveal">
                <span class="hero-label"><i class="bi bi-patch-check-fill"></i> <?= e($store['marketplace_hero_badge']); ?></span>
                <h1><?= e($store['marketplace_hero_title']); ?></h1>
                <p><?= e($store['marketplace_hero_subtitle']); ?></p>
                <div class="hero-actions">
                    <a href="<?= legacy_base_url('produk'); ?>" class="btn-primary-store"><i class="bi bi-bag-fill"></i> Belanja Sekarang</a>
                    <a href="<?= legacy_base_url('tracking'); ?>" class="btn-outline-store"><i class="bi bi-truck"></i> Lacak Pesanan</a>
                </div>
                <div class="hero-benefits">
                    <span><i class="bi bi-shield-check"></i>100% Original</span>
                    <span><i class="bi bi-truck"></i>Pengiriman Cepat</span>
                    <span><i class="bi bi-lightning-charge"></i>Checkout Instan</span>
                    <span><i class="bi bi-geo-alt"></i>Multi Cabang</span>
                </div>
            </div>
            <div class="hero-visual reveal delay-1">
                <div class="hero-blob blob-one"></div><div class="hero-blob blob-two"></div>
                <div class="hero-art-card">
                    <img class="hero-main-art" src="<?= e($heroVisual); ?>" alt="Produk pilihan <?= e($store['store_name']); ?>" onerror="this.onerror=null;this.src='<?= e(storefront_asset_url('hero-fallback.svg')); ?>';">
                </div>
                <?php if ($heroProducts): ?>
                    <div class="hero-live-products" aria-label="Produk terbaru">
                        <?php foreach ($heroProducts as $hp): $hpImg = storefront_product_image($hp); $hpFallback = storefront_product_fallback($hp); ?>
                            <span class="hero-live-item"><img src="<?= e($hpImg); ?>" alt="<?= e($hp['name']); ?>" onerror="this.onerror=null;this.src='<?= e($hpFallback); ?>';"></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="float-bubble bubble-a"></div><div class="float-bubble bubble-b"></div><div class="float-bubble bubble-c"></div>
            </div>
        </div>
    </section>

    <?php if($banners): ?><section class="store-container content-section reveal"><div class="marketplace-banner-grid"><?php foreach($banners as $bn):$href=trim((string)$bn['link_url']);if($href!==''&&!preg_match('~^https?://~i',$href))$href=legacy_base_url(ltrim($href,'/'));?><a class="marketplace-banner-card" href="<?=e($href?:legacy_base_url('produk'))?>"><picture><?php if($bn['image_mobile']):?><source media="(max-width:760px)" srcset="<?=e(legacy_base_url($bn['image_mobile']))?>"><?php endif;?><img src="<?=e(legacy_base_url($bn['image_desktop']))?>" alt="<?=e($bn['title'])?>"></picture><div class="marketplace-banner-copy"><h3><?=e($bn['title'])?></h3><?php if($bn['subtitle']):?><p><?=e($bn['subtitle'])?></p><?php endif;?></div></a><?php endforeach;?></div></section><?php endif; ?>

    <section class="store-container promo-banner reveal" id="promo">
        <div class="promo-copy"><span class="eyebrow">DISKON SPESIAL HARI INI</span><h2><?= e($promoBig); ?></h2><p><?= e($promoTitle); ?><?= !empty($promo['code']) ? ' • Kode: ' . e($promo['code']) : ''; ?></p></div>
        <div class="promo-art promo-image-art"><img src="<?= e($promoVisual); ?>" alt="Promo <?= e($store['store_name']); ?>" onerror="this.onerror=null;this.src='<?= e(storefront_asset_url('promo-fallback.svg')); ?>';"></div>
        <a href="<?= legacy_base_url('produk'); ?>" class="promo-btn">Lihat Promo <i class="bi bi-arrow-right"></i></a>
    </section>

    <?php if($promoBanners): ?><section class="store-container content-section reveal"><div class="marketplace-banner-grid"><?php foreach($promoBanners as $bn):$href=trim((string)$bn['link_url']);if($href!==''&&!preg_match('~^https?://~i',$href))$href=legacy_base_url(ltrim($href,'/'));?><a class="marketplace-banner-card" href="<?=e($href?:legacy_base_url('produk'))?>"><picture><?php if($bn['image_mobile']):?><source media="(max-width:760px)" srcset="<?=e(legacy_base_url($bn['image_mobile']))?>"><?php endif;?><img src="<?=e(legacy_base_url($bn['image_desktop']))?>" alt="<?=e($bn['title'])?>"></picture><div class="marketplace-banner-copy"><h3><?=e($bn['title'])?></h3><?php if($bn['subtitle']):?><p><?=e($bn['subtitle'])?></p><?php endif;?></div></a><?php endforeach;?></div></section><?php endif; ?>

    <?php if($brands): ?><section class="store-container content-section"><div class="section-heading"><div><span class="eyebrow">MEREK PILIHAN</span><h2>Brand Marketplace</h2></div></div><div class="store-brand-grid"><?php foreach($brands as $b):?><a class="store-brand-card" href="<?=legacy_base_url('produk?brand='.(int)$b['id'])?>"><span class="store-brand-card-media"><?php if($b['logo']):?><img src="<?=e(legacy_base_url($b['logo']))?>" alt="<?=e($b['name'])?>" onerror="this.style.display='none';this.nextElementSibling.style.display='grid';"><span class="category-icon store-brand-fallback" style="display:none"><i class="bi bi-patch-check"></i></span><?php else:?><span class="category-icon store-brand-fallback"><i class="bi bi-patch-check"></i></span><?php endif;?></span><span class="store-brand-card-copy"><b><?=e($b['name'])?></b><small>Lihat produk brand ini</small></span></a><?php endforeach;?></div></section><?php endif; ?>

    <section class="store-container content-section" id="categories">
        <div class="section-heading reveal"><div><span class="eyebrow">TEMUKAN FAVORITMU</span><h2>Kategori Populer</h2></div><a href="<?= legacy_base_url('produk'); ?>">Lihat Semua <i class="bi bi-arrow-right"></i></a></div>
        <div class="category-grid reveal delay-1">
            <?php if ($categories): foreach ($categories as $cat): ?>
                <a class="category-card" href="<?= legacy_base_url('produk?category=' . urlencode($cat['slug'])); ?>">
                    <span class="category-icon category-image"><img src="<?= e(storefront_category_image($cat['name'])); ?>" alt="<?= e($cat['name']); ?>"></span>
                    <strong><?= e($cat['name']); ?></strong><small><?= (int) $cat['product_count']; ?>+ Produk</small>
                    <span class="category-arrow"><i class="bi bi-arrow-up-right"></i></span>
                </a>
            <?php endforeach; else: ?>
                <?php foreach (['Liquid','Device','Cartridge','Cotton','Accessories'] as $fallback): ?>
                    <a class="category-card" href="<?= legacy_base_url('produk'); ?>"><span class="category-icon category-image"><img src="<?= e(storefront_category_image($fallback)); ?>" alt="<?= e($fallback); ?>"></span><strong><?= e($fallback); ?></strong><small>Produk</small><span class="category-arrow"><i class="bi bi-arrow-up-right"></i></span></a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <?php if($customBanners): ?><section class="store-container content-section reveal"><div class="marketplace-banner-grid"><?php foreach($customBanners as $bn):$href=trim((string)$bn['link_url']);if($href!==''&&!preg_match('~^https?://~i',$href))$href=legacy_base_url(ltrim($href,'/'));?><a class="marketplace-banner-card" href="<?=e($href?:legacy_base_url('produk'))?>"><picture><?php if($bn['image_mobile']):?><source media="(max-width:760px)" srcset="<?=e(legacy_base_url($bn['image_mobile']))?>"><?php endif;?><img src="<?=e(legacy_base_url($bn['image_desktop']))?>" alt="<?=e($bn['title'])?>"></picture><div class="marketplace-banner-copy"><h3><?=e($bn['title'])?></h3><?php if($bn['subtitle']):?><p><?=e($bn['subtitle'])?></p><?php endif;?></div></a><?php endforeach;?></div></section><?php endif; ?>

    <?php if($flashProducts): ?><section class="store-container content-section product-showcase"><div class="section-heading"><div><span class="eyebrow">WAKTU TERBATAS</span><h2>Flash Sale</h2></div><a href="<?=legacy_base_url('produk')?>">Lihat Semua <i class="bi bi-arrow-right"></i></a></div><div class="product-grid"><?php foreach($flashProducts as $fp):$fs=storefront_effective_stock($fp);?><article class="product-card"><div class="product-media"><span class="product-badge purple">FLASH</span><a href="<?=e(storefront_product_url($fp))?>"><img src="<?=e(storefront_product_image($fp))?>" data-fallback="<?=e(storefront_product_fallback($fp))?>" onerror="this.onerror=null;this.src=this.dataset.fallback" alt="<?=e($fp['name'])?>"></a></div><div class="product-info"><span class="product-category"><?=e($fp['category_name']?:'Produk')?></span><h3><a class="product-title-link" href="<?=e(storefront_product_url($fp))?>"><?=e($fp['name'])?></a></h3><div class="product-bottom"><div><strong><?=rupiah($fp['selling_price'])?></strong><?php if((float)$fp['original_selling_price']>(float)$fp['selling_price']):?><span class="flash-old-price"><?=rupiah($fp['original_selling_price'])?></span><?php endif;?><span class="stock-badge <?=$fs<=0?'out':''?>"><?=$fs>0?'Stok '.$fs:'Habis'?></span></div><?php if((int)($fp['variant_count']??0)>0):?><a class="add-cart-btn" href="<?=e(storefront_product_url($fp))?>" aria-label="Pilih varian"><i class="bi bi-sliders"></i></a><?php else:?><button class="add-cart-btn" data-product="<?=storefront_product_json($fp)?>" <?=$fs<=0?'disabled':''?>><i class="bi bi-plus-lg"></i></button><?php endif;?></div></div></article><?php endforeach;?></div></section><?php endif; ?>

    <?php if(setting('marketplace_home_show_latest','on')==='on'): ?>
    <section class="store-container content-section product-showcase" id="produk-terbaru">
        <div class="section-heading reveal"><div><span class="eyebrow">PILIHAN TERBAIK</span><h2>Produk Terbaru</h2></div><a href="<?= legacy_base_url('produk'); ?>">Lihat Semua <i class="bi bi-arrow-right"></i></a></div>
        <div class="shop-layout">
            <div class="product-grid reveal delay-1">
                <?php if ($products): foreach ($products as $i => $product):
                    $img = storefront_product_image($product);
                    $fallbackImg = storefront_product_fallback($product);
                    $stock = storefront_effective_stock($product);
                ?>
                    <article class="product-card">
                        <div class="product-media">
                            <?php if ($i === 0): ?><span class="product-badge purple">Best Seller</span><?php elseif ($i === 1): ?><span class="product-badge">New</span><?php endif; ?>
                            <button class="wish-btn <?= in_array((int)$product['id'], $wishlistIds, true) ? 'active' : ''; ?>" type="button" data-product-id="<?= (int)$product['id']; ?>" aria-label="Favorit"><i class="bi <?= in_array((int)$product['id'], $wishlistIds, true) ? 'bi-heart-fill' : 'bi-heart'; ?>"></i></button>
                            <a href="<?= e(storefront_product_url($product)); ?>"><img src="<?= e($img); ?>" data-fallback="<?= e($fallbackImg); ?>" onerror="this.onerror=null;this.src=this.dataset.fallback;" alt="<?= e($product['name']); ?>" loading="lazy"></a>
                        </div>
                        <div class="product-info">
                            <span class="product-category"><?= e($product['category_name'] ?: 'Produk'); ?></span>
                            <h3><a class="product-title-link" href="<?= e(storefront_product_url($product)); ?>"><?= e($product['name']); ?></a></h3>
                            <div class="rating"><i class="bi bi-star-fill"></i><span>Detail</span><small>Lihat produk</small></div>
                            <div class="product-bottom"><div><strong><?= rupiah($product['selling_price']); ?></strong><?php if((float)($product['original_selling_price']??0)>(float)$product['selling_price']):?><span class="flash-old-price"><?=rupiah($product['original_selling_price'])?></span><?php endif;?><span class="stock-badge <?= $stock <= 0 ? 'out' : ''; ?>"><?= $stock > 0 ? 'Stok ' . $stock : 'Habis'; ?></span></div>
                                <?php if((int)($product['variant_count']??0)>0):?><a class="add-cart-btn" href="<?=e(storefront_product_url($product))?>"><i class="bi bi-sliders"></i><span>Varian</span></a><?php else:?><button class="add-cart-btn" type="button" data-product="<?= storefront_product_json($product); ?>" <?= $stock <= 0 ? 'disabled' : ''; ?>><i class="bi bi-plus-lg"></i><span>Keranjang</span></button><?php endif;?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; else: ?>
                    <div class="empty-products"><i class="bi bi-box-seam"></i><h3>Belum ada produk aktif</h3><p>Produk yang aktif di POS akan otomatis tampil di sini.</p></div>
                <?php endif; ?>
            </div>
            <?php if($homeLastPage > 1): ?>
            <nav class="store-pagination home-product-pagination" aria-label="Halaman produk terbaru">
                <?php if($homePage > 1): ?><a href="?page=<?=$homePage-1?>#produk-terbaru" aria-label="Sebelumnya"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
                <?php $startPage=max(1,$homePage-2);$endPage=min($homeLastPage,$homePage+2);for($i=$startPage;$i<=$endPage;$i++): ?><a class="<?=$i===$homePage?'active':''?>" href="?page=<?=$i?>#produk-terbaru"><?=$i?></a><?php endfor; ?>
                <?php if($homePage < $homeLastPage): ?><a href="?page=<?=$homePage+1?>#produk-terbaru" aria-label="Berikutnya"><i class="bi bi-chevron-right"></i></a><?php endif; ?>
            </nav>
            <?php endif; ?>

            <aside class="checkout-preview reveal delay-2">
                <div class="preview-head"><div><span class="preview-icon"><i class="bi bi-bag-check-fill"></i></span><div><small>KERANJANG AKTIF</small><h3>Ringkasan Checkout</h3></div></div><span class="preview-count"><b data-cart-count>0</b> item</span></div>
                <div class="checkout-lines"><div><span>Subtotal</span><b data-cart-subtotal>Rp 0</b></div><div><span>Ongkir</span><b data-shipping-preview>Rp 0</b></div><div><span>Poin Digunakan</span><b class="purple-text">- Rp 0</b></div><div class="checkout-total"><span>Total</span><strong data-cart-total>Rp 0</strong></div></div>
                <div class="preview-section"><div class="preview-label"><b>Pengiriman</b><a href="<?= legacy_base_url('checkout'); ?>">Cek Ongkir</a></div><div class="preview-live-note"><i class="bi bi-truck"></i><span><b>Tarif RajaOngkir real-time</b><small>Kurir dan ongkir muncul setelah alamat tujuan dipilih saat checkout.</small></span></div></div>
                <div class="preview-section"><div class="preview-label"><b>Metode Pembayaran</b><a href="<?= legacy_base_url('checkout'); ?>">Lihat Semua</a></div><div class="payment-pills"><button type="button" class="active">QRIS</button><button type="button">Virtual Account</button></div></div>
                <a href="<?= legacy_base_url('checkout'); ?>" class="btn-primary-store full"><i class="bi bi-lock-fill"></i> Checkout Sekarang</a>
                <small class="integration-note"><i class="bi bi-shield-check"></i> Harga, stok, ongkir, voucher, dan pembayaran diverifikasi ulang oleh server.</small>
            </aside>
        </div>
    </section>

    <?php endif; ?>



    <section class="store-container content-section howto-section reveal" id="cara-belanja">
        <div class="section-heading"><div><span class="eyebrow">BELANJA TANPA RIBET</span><h2>Cara Belanja</h2></div><a href="<?= legacy_base_url('produk'); ?>">Mulai Belanja <i class="bi bi-arrow-right"></i></a></div>
        <div class="howto-grid">
            <div><span>01</span><i class="bi bi-search"></i><h3>Pilih Produk</h3><p>Cari produk, kategori, dan stok yang tersedia langsung dari data POS.</p></div>
            <div><span>02</span><i class="bi bi-bag-plus"></i><h3>Masuk Keranjang</h3><p>Atur jumlah belanja dan periksa kembali produk sebelum checkout.</p></div>
            <div><span>03</span><i class="bi bi-geo-alt"></i><h3>Isi Pengiriman</h3><p>Lengkapi alamat penerima dan pilih layanan pengiriman saat checkout.</p></div>
            <div><span>04</span><i class="bi bi-check2-circle"></i><h3>Pantau Pesanan</h3><p>Gunakan nomor invoice atau resi untuk melihat status pesanan.</p></div>
        </div>
    </section>

    <section class="store-container faq-section reveal" id="faq">
        <div class="faq-heading"><span class="eyebrow">PUSAT BANTUAN</span><h2>Pertanyaan yang sering ditanyakan</h2><p>Informasi dasar sebelum checkout. Detail pembayaran dan pengiriman dapat disesuaikan dari sistem.</p></div>
        <div class="faq-list">
            <details open><summary>Apakah stok marketplace sama dengan stok POS?<i class="bi bi-plus-lg"></i></summary><p>Ya. Katalog membaca produk dan stok dari database POS sehingga perubahan stok di sistem utama ikut tercermin di marketplace.</p></details>
            <details><summary>Bagaimana cara melacak pesanan?<i class="bi bi-plus-lg"></i></summary><p>Buka menu Tracking lalu masukkan nomor invoice atau nomor resi yang diterima.</p></details>
            <details><summary>Apakah harga marketplace mengikuti harga POS?<i class="bi bi-plus-lg"></i></summary><p>Saat ini harga katalog mengambil harga jual produk dari POS. Pengaturan harga online terpisah dapat ditambahkan pada tahap berikutnya.</p></details>
            <details><summary>Apakah bisa checkout dari HP?<i class="bi bi-plus-lg"></i></summary><p>Bisa. Halaman marketplace, keranjang, checkout, dan tracking dibuat responsif untuk ponsel, tablet, dan desktop.</p></details>
        </div>
    </section>

    <section class="store-container tracking-card reveal" id="tracking-preview">
        <div class="track-search-block"><span class="eyebrow">LACAK PESANAN</span><h2>Pantau pesanan tanpa ribet</h2><p>Masukkan nomor invoice/resi dan lihat status terakhir pesanan Anda.</p>
            <form action="<?= legacy_base_url('tracking'); ?>" method="get" class="tracking-inline"><input name="code" placeholder="INV-2026... atau nomor resi" required><button>Lacak Pesanan</button></form>
        </div>
        <div class="shipping-progress">
            <div class="progress-title"><span>Status Pengiriman</span><small>Contoh alur otomatis</small></div>
            <div class="progress-line"><span class="filled"></span></div>
            <div class="progress-steps"><div class="done"><i class="bi bi-check-lg"></i><b>Pesanan Dibuat</b><small>Order masuk</small></div><div class="done"><i class="bi bi-check-lg"></i><b>Dikemas</b><small>Siap kirim</small></div><div class="active"><i class="bi bi-truck"></i><b>Dikirim</b><small>Kurir pickup</small></div><div><i class="bi bi-geo"></i><b>Dalam Perjalanan</b><small>Tracking</small></div><div><i class="bi bi-house-check"></i><b>Selesai</b><small>Diterima</small></div></div>
        </div>
    </section>

    <section class="store-container stats-strip reveal">
        <div><span><i class="bi bi-box-seam"></i></span><strong><?= number_format($stats['products'], 0, ',', '.'); ?>+</strong><small>Total Produk</small></div>
        <div><span><i class="bi bi-bag-check"></i></span><strong><?= number_format($stats['orders'], 0, ',', '.'); ?>+</strong><small>Pesanan Online</small></div>
        <div><span><i class="bi bi-people"></i></span><strong><?= number_format($stats['customers'], 0, ',', '.'); ?>+</strong><small>Pelanggan Aktif</small></div>
        <div><span><i class="bi bi-shop"></i></span><strong><?= number_format($stats['branches'], 0, ',', '.'); ?></strong><small>Cabang Toko</small></div>
    </section>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
