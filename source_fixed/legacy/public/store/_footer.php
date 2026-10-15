<?php
$footerStore = storefront_settings();
$footerLogo = storefront_visual_url($footerStore['marketplace_logo'] ?? '', 'logo-vapekong.svg');
$footerWhatsapp = storefront_whatsapp_url($footerStore);
$footerInstagram = storefront_external_url($footerStore['marketplace_instagram'] ?? '');
$footerTiktok = storefront_external_url($footerStore['marketplace_tiktok'] ?? '');
$footerFacebook = storefront_external_url($footerStore['marketplace_facebook'] ?? '');
?>
<footer class="store-footer" id="contact">
    <div class="store-container footer-grid">
        <div class="footer-brand-col">
            <a class="store-brand footer-logo" href="<?= legacy_base_url(); ?>">
                <img class="store-logo-image footer-logo-image" src="<?= e($footerLogo); ?>" alt="<?= e($footerStore['store_name']); ?>" onerror="this.onerror=null;this.src='<?= e(storefront_asset_url('logo-vapekong.svg')); ?>';">
            </a>
            <p><?= e($footerStore['marketplace_footer_description']); ?></p>
            <?php if ($footerInstagram || $footerTiktok || $footerFacebook || $footerWhatsapp): ?>
            <div class="social-row">
                <?php if ($footerInstagram): ?><a href="<?= e($footerInstagram); ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram"><i class="bi bi-instagram"></i></a><?php endif; ?>
                <?php if ($footerTiktok): ?><a href="<?= e($footerTiktok); ?>" target="_blank" rel="noopener noreferrer" aria-label="TikTok"><i class="bi bi-tiktok"></i></a><?php endif; ?>
                <?php if ($footerFacebook): ?><a href="<?= e($footerFacebook); ?>" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><i class="bi bi-facebook"></i></a><?php endif; ?>
                <?php if ($footerWhatsapp): ?><a href="<?= e($footerWhatsapp); ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div><h4>Belanja</h4><a href="<?= legacy_base_url('produk'); ?>">Semua Produk</a><a href="<?= legacy_base_url() . '#categories'; ?>">Kategori</a><a href="<?= legacy_base_url() . '#promo'; ?>">Promo</a><a href="<?= legacy_base_url('keranjang'); ?>">Keranjang</a></div>
        <div><h4>Informasi</h4><a href="<?= legacy_base_url() . '#cara-belanja'; ?>">Cara Belanja</a><a href="<?= legacy_base_url() . '#faq'; ?>">FAQ</a><a href="<?= legacy_base_url('tracking'); ?>">Lacak Pesanan</a></div>
        <div><h4>Bantuan</h4><a href="<?= legacy_base_url('akun'); ?>">Akun Saya</a><a href="<?= legacy_base_url('wishlist'); ?>">Wishlist</a><a href="<?= legacy_base_url('checkout'); ?>">Panduan Checkout</a><a href="<?= legacy_base_url('tracking'); ?>">Status Pesanan</a><a href="<?= legacy_base_url() . '#contact'; ?>">Kontak Toko</a><?php if ($footerWhatsapp): ?><a href="<?= e($footerWhatsapp); ?>" target="_blank" rel="noopener noreferrer">Chat WhatsApp</a><?php endif; ?></div>
        <div class="footer-contact"><h4>Hubungi Kami</h4><p><i class="bi bi-telephone"></i><?= e($footerStore['store_phone'] ?: '-'); ?></p><?php if (!empty($footerStore['store_email'])): ?><p><i class="bi bi-envelope"></i><?= e($footerStore['store_email']); ?></p><?php endif; ?><p><i class="bi bi-geo-alt"></i><?= e($footerStore['store_address'] ?: 'Alamat toko belum diatur'); ?></p><p><i class="bi bi-clock"></i><?= e($footerStore['marketplace_opening_hours']); ?></p></div>
    </div>
    <div class="store-container footer-bottom">
        <span>© <?= date('Y'); ?> <?= e($footerStore['store_name']); ?>. All rights reserved. • Stok terintegrasi POS.</span>
    </div>
</footer>

<?php if ($footerWhatsapp): ?>
<a class="floating-whatsapp" href="<?= e($footerWhatsapp); ?>" target="_blank" rel="noopener noreferrer" aria-label="Chat WhatsApp <?= e($footerStore['store_name']); ?>"><i class="bi bi-whatsapp"></i><span>Chat</span></a>
<?php endif; ?>

<div class="cart-drawer-backdrop" id="cartBackdrop"></div>
<aside class="cart-drawer" id="cartDrawer" aria-label="Keranjang belanja">
    <div class="cart-drawer-head"><div><span class="eyebrow">KERANJANG</span><h3>Belanja Anda</h3></div><button type="button" class="drawer-close" id="cartClose"><i class="bi bi-x-lg"></i></button></div>
    <div class="cart-drawer-body" id="cartDrawerItems"></div>
    <div class="cart-drawer-foot">
        <div class="drawer-total"><span>Subtotal</span><strong data-cart-subtotal>Rp 0</strong></div>
        <p>Ongkir dihitung saat checkout.</p>
        <a class="btn-primary-store full" href="<?= legacy_base_url('checkout'); ?>"><i class="bi bi-bag-check"></i> Checkout Sekarang</a>
        <a class="drawer-link" href="<?= legacy_base_url('keranjang'); ?>">Lihat keranjang lengkap</a>
    </div>
</aside>
<div class="store-toast-wrap" id="storeToastWrap"></div>
<nav class="mobile-bottom-nav" aria-label="Navigasi mobile">
    <a class="<?= $activePage === 'home' ? 'active' : ''; ?>" href="<?= legacy_base_url(); ?>"><i class="bi bi-house-door-fill"></i><span>Home</span></a>
    <a class="<?= $activePage === 'products' ? 'active' : ''; ?>" href="<?= legacy_base_url('produk'); ?>"><i class="bi bi-grid-fill"></i><span>Produk</span></a>
    <button class="cart-open-btn mobile-cart-button" type="button"><i class="bi bi-bag-fill"></i><span>Cart</span><b data-cart-count>0</b></button>
    <a class="<?= $activePage === 'tracking' ? 'active' : ''; ?>" href="<?= legacy_base_url('tracking'); ?>"><i class="bi bi-truck"></i><span>Tracking</span></a>
    <a class="<?= $activePage === 'account' ? 'active' : ''; ?>" href="<?= legacy_base_url(storefront_customer() ? 'akun' : 'masuk'); ?>"><i class="bi bi-person-fill"></i><span>Akun</span></a>
</nav>
<script src="<?= legacy_base_url('assets/js/storefront.js?v=17.0.1'); ?>" onload="window.VapekongHideStoreLoader&&window.VapekongHideStoreLoader()" onerror="window.VapekongHideStoreLoader&&window.VapekongHideStoreLoader()"></script>
</body>
</html>
