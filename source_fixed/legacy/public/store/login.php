<?php
require_once __DIR__ . '/_data.php';
$db = storefront_db();
if (!$db) { http_response_code(503); exit('Database tidak tersedia.'); }
if (marketplace_customer($db)) legacy_redirect('akun');
$error='';
$next=trim((string)($_GET['next'] ?? $_POST['next'] ?? 'akun'));
if (!preg_match('~^[a-zA-Z0-9_/?=&.-]+$~',$next)) $next='akun';
if (is_post()) {
    if (!CSRF::verify()) $error='Sesi formulir kedaluwarsa. Silakan coba lagi.';
    else {
        $identity=trim((string)($_POST['identity'] ?? '')); $password=(string)($_POST['password'] ?? '');
        if (marketplace_customer_login($db,$identity,$password)) legacy_redirect($next);
        $error='Email/nomor WhatsApp atau password tidak sesuai.';
    }
}
$pageTitle='Masuk Akun'; $activePage='account'; require __DIR__ . '/_header.php';
?>
<main class="inner-page customer-auth-page"><section class="store-container auth-shell"><div class="auth-copy"><span class="hero-label"><i class="bi bi-person-check-fill"></i> AKUN PELANGGAN</span><h1>Masuk ke <span>Akun Belanja</span></h1><p>Pantau pesanan, simpan alamat, kelola wishlist, dan lihat notifikasi order dalam satu akun yang terhubung dengan data pelanggan POS.</p><div class="auth-benefits"><span><i class="bi bi-bag-check"></i>Riwayat pesanan online</span><span><i class="bi bi-geo-alt"></i>Alamat tersimpan</span><span><i class="bi bi-heart"></i>Wishlist</span><span><i class="bi bi-bell"></i>Notifikasi status</span></div></div><section class="checkout-box auth-card"><div class="checkout-box-title"><span><i class="bi bi-box-arrow-in-right"></i></span><div><small>SELAMAT DATANG</small><h2>Masuk</h2></div></div><?php if($error): ?><div class="checkout-live-alert error"><i class="bi bi-exclamation-triangle-fill"></i><span><?= e($error); ?></span></div><?php endif; ?><form method="post" class="account-form"><?= CSRF::input(); ?><input type="hidden" name="next" value="<?= e($next); ?>"><label>Email / Nomor WhatsApp<input name="identity" required autocomplete="username" value="<?= e($_POST['identity'] ?? ''); ?>" placeholder="nama@email.com atau 08xxxx"></label><label>Password<input type="password" name="password" required autocomplete="current-password" minlength="6" placeholder="Masukkan password"></label><button class="btn-primary-store full" type="submit"><i class="bi bi-box-arrow-in-right"></i> Masuk</button></form><div class="auth-foot">Belum punya akun? <a href="<?= legacy_base_url('daftar'); ?>">Daftar sekarang</a></div></section></section></main>
<?php require __DIR__ . '/_footer.php'; ?>
