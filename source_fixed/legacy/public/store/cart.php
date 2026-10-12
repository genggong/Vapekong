<?php
require_once __DIR__ . '/_data.php';
$pageTitle = 'Keranjang';
$activePage = 'cart';
require __DIR__ . '/_header.php';
?>
<main class="inner-page">
    <section class="store-container page-hero small"><div><span class="hero-label"><i class="bi bi-bag-fill"></i> KERANJANG BELANJA</span><h1>Periksa sebelum <span>Checkout</span></h1><p>Ubah jumlah barang atau hapus produk sebelum melanjutkan ke pembayaran.</p></div><div class="page-hero-icon"><i class="bi bi-cart-check-fill"></i></div></section>
    <section class="store-container cart-page-grid">
        <div class="cart-page-panel"><div class="panel-title"><div><span class="eyebrow">ITEM PESANAN</span><h2>Keranjang Anda</h2></div><span class="cart-page-count"><b data-cart-count>0</b> item</span></div><div id="cartPageItems" class="cart-page-items"></div></div>
        <aside class="order-summary-card"><span class="eyebrow">RINGKASAN</span><h2>Total Belanja</h2><div class="summary-lines"><div><span>Subtotal</span><b data-cart-subtotal>Rp 0</b></div><div><span>Estimasi Ongkir</span><b>Di checkout</b></div><div class="grand"><span>Total sementara</span><strong data-cart-subtotal>Rp 0</strong></div></div><a href="<?= legacy_base_url('checkout'); ?>" class="btn-primary-store full"><i class="bi bi-arrow-right-circle-fill"></i> Lanjut Checkout</a><a href="<?= legacy_base_url('produk'); ?>" class="continue-shopping"><i class="bi bi-arrow-left"></i> Lanjut belanja</a></aside>
    </section>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
