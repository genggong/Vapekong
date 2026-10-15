<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Modules/Marketplace/Marketplace.php';
$marketplaceTheme = marketplace_theme_config();
$marketplaceThemeSync = !empty($marketplaceTheme['sync_internal']);

if (Auth::check()) {
    legacy_redirect('admin/dashboard.php');
}

if (is_post()) {
    if (!CSRF::verify()) {
        flash('error', 'Token keamanan tidak valid. Silakan coba lagi.');
        legacy_redirect('login');
    }

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    set_old($_POST);

    if ($username === '' || $password === '') {
        flash('error', 'Username dan password wajib diisi.');
        legacy_redirect('login');
    }

    if (Auth::attempt($username, $password)) {
        clear_old();
        flash('success', 'Login berhasil. Selamat datang!');
        legacy_redirect('admin/dashboard.php');
    }

    flash('error', 'Username atau password salah.');
    legacy_redirect('login');
}

$success = flash('success');
$error = flash('error');
?>
<!DOCTYPE html>
<html lang="id" style="<?= e(marketplace_theme_style_vars($marketplaceTheme)); ?>">
<head>
    <meta charset="UTF-8">
    <title>Login Staff / Admin - <?= e(app_config('app_name')); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="<?= e($marketplaceThemeSync ? $marketplaceTheme['primary'] : "#991b1b"); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="<?= legacy_base_url('assets/css/login.css?v=' . time()); ?>" rel="stylesheet">
    <link href="<?= legacy_base_url('assets/css/marketplace-internal-theme.css?v=15.8.0'); ?>" rel="stylesheet">
</head>
<body class="<?= $marketplaceThemeSync ? 'login-marketplace-sync' : ''; ?>" data-marketplace-theme-sync="<?= $marketplaceThemeSync ? 'on' : 'off'; ?>">

<div class="login-page">
    <div class="login-bg-shape shape-1"></div>
    <div class="login-bg-shape shape-2"></div>
    <div class="login-bg-shape shape-3"></div>

    <div class="login-card">
        <div class="brand-box">
            <div class="brand-icon"><i class="bi bi-shop-window"></i></div>
            <div>
                <h1><?= e(setting('app_name', 'POS Kasir PRO')); ?></h1>
                <p>Portal internal POS &amp; Admin</p>
            </div>
        </div>

        <div class="login-title">
            <h2>Login Staff / Admin</h2>
            <p>Halaman ini khusus operasional toko. Pelanggan dapat berbelanja langsung dari marketplace.</p>
        </div>

        <form method="POST" action="" class="login-form" id="loginForm">
            <?= CSRF::input(); ?>

            <div class="form-group">
                <label>Username / Email</label>
                <div class="input-wrap">
                    <i class="bi bi-person"></i>
                    <input type="text" name="username" value="<?= e(legacy_old('username')); ?>" placeholder="Masukkan username" autocomplete="username">
                </div>
            </div>

            <div class="form-group">
                <label>Password</label>
                <div class="input-wrap">
                    <i class="bi bi-lock"></i>
                    <input type="password" name="password" id="passwordInput" placeholder="Masukkan password" autocomplete="current-password">
                    <button type="button" class="toggle-password" id="togglePassword"><i class="bi bi-eye"></i></button>
                </div>
            </div>

            <button type="submit" class="btn-login" id="btnLogin">
                <span class="btn-text">Masuk Sekarang</span>
                <span class="btn-loader d-none"><span class="spinner-border spinner-border-sm"></span> Memproses...</span>
            </button>
        </form>

        <a class="marketplace-back" href="<?= legacy_base_url(); ?>"><i class="bi bi-arrow-left"></i> Kembali ke Marketplace</a>

        <div class="login-footer">
            <span>Akses internal:</span>
            <b>Hubungi Admin</b>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="<?= legacy_base_url('assets/js/sweetalert.js?v=' . time()); ?>"></script>
<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    const input = document.getElementById('passwordInput');
    const icon = this.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
});

document.getElementById('loginForm').addEventListener('submit', function () {
    const btn = document.getElementById('btnLogin');
    btn.disabled = true;
    btn.querySelector('.btn-text').classList.add('d-none');
    btn.querySelector('.btn-loader').classList.remove('d-none');
});

<?php if ($success): ?>toastSuccess("<?= e($success); ?>");<?php endif; ?>
<?php if ($error): ?>toastError("<?= e($error); ?>");<?php endif; ?>
</script>
</body>
</html>
