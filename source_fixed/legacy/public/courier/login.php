<?php
require_once __DIR__.'/_bootstrap.php';
if(marketplace_courier_session())legacy_redirect('kurir');
if(is_post()){
    if(!CSRF::verify()){flash('error','Token keamanan tidak valid.');legacy_redirect('kurir/login');}
    $identity=trim((string)($_POST['identity']??''));$password=(string)($_POST['password']??'');
    if(marketplace_courier_login($db,$identity,$password)){flash('success','Login berhasil. Selamat bertugas.');legacy_redirect('kurir');}
    flash('error','Username/nomor/email atau password salah.');legacy_redirect('kurir/login');
}
$courierPageTitle='Login Kurir';$courierUser=null;require __DIR__.'/_header.php';
?>
<main class="courier-login-wrap"><section class="courier-login-card"><div class="courier-login-icon"><i class="bi bi-bicycle"></i></div><span class="courier-eyebrow">PORTAL KURIR TOKO</span><h1>Masuk untuk kelola paket</h1><p>Tugas yang ditampilkan hanya paket yang ditugaskan ke akun kurir Anda.</p><form method="post" class="courier-form"><?= CSRF::input(); ?><label>Username / WhatsApp / Email<input name="identity" required autocomplete="username" placeholder="kurir01 atau 08xxxxxxxxxx"></label><label>Password<input type="password" name="password" required autocomplete="current-password" placeholder="••••••••"></label><button class="courier-btn primary"><i class="bi bi-box-arrow-in-right"></i> Masuk Portal Kurir</button></form><small class="courier-login-help">Akun kurir dibuat oleh admin toko.</small></section></main>
<?php require __DIR__.'/_footer.php'; ?>
