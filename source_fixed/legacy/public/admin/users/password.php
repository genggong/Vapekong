<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin', 'owner']);
$pageTitle = 'Reset Password';
$db = Database::connect();

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$userEdit = $stmt->fetch();

if (!$userEdit) {
    flash('error', 'User tidak ditemukan.');
    legacy_redirect('admin/users/index.php');
}

if (is_post()) {
    if (!CSRF::verify()) {
        flash('error', 'Token keamanan tidak valid.');
        legacy_redirect('admin/users/password.php?id=' . $id);
    }

    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6 || $password !== $confirm) {
        flash('error', 'Password minimal 6 karakter dan konfirmasi harus sama.');
        legacy_redirect('admin/users/password.php?id=' . $id);
    }

    $stmt = $db->prepare("UPDATE users SET password=? WHERE id=?");
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);

    Auth::log('reset_password', 'Reset password user ' . $userEdit['username']);
    flash('success', 'Password berhasil direset.');
    legacy_redirect('admin/users/index.php');
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">

<main class="admin-main system-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Reset Password</h1>
            <p>Reset password akun: <?= e($userEdit['name']); ?></p>
        </div>
    </header>

    <form method="POST" class="system-form-card">
        <?= CSRF::input(); ?>
        <div class="system-form-grid">
            <div class="system-field"><label>Password Baru</label><input type="password" name="password" required placeholder="Minimal 6 karakter"></div>
            <div class="system-field"><label>Konfirmasi Password</label><input type="password" name="confirm_password" required placeholder="Ulangi password"></div>
        </div>

        <div class="system-form-actions">
            <a class="system-btn soft" href="<?= legacy_base_url('admin/users/index.php'); ?>">Batal</a>
            <button class="system-btn primary" type="submit"><i class="bi bi-key"></i> Reset Password</button>
        </div>
    </form>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
