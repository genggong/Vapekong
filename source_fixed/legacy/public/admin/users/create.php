<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin', 'owner']);
require_once __DIR__ . '/../branches/_helpers.php';
$pageTitle = 'Tambah User';
$db = Database::connect();
branch_install($db);

$roles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();
$branches = branch_all($db, true);

if (is_post()) {
    if (!CSRF::verify()) {
        flash('error', 'Token keamanan tidak valid.');
        legacy_redirect('admin/users/create.php');
    }

    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $roleId = (int)($_POST['role_id'] ?? 0);
    $branchId = (int)($_POST['branch_id'] ?? branch_main_id($db));
    $status = $_POST['status'] ?? 'active';
    $password = $_POST['password'] ?? '';

    if ($name === '' || $username === '' || $roleId <= 0 || strlen($password) < 6) {
        flash('error', 'Nama, username, role, dan password minimal 6 karakter wajib diisi.');
        legacy_redirect('admin/users/create.php');
    }

    try {
        $stmt = $db->prepare("INSERT INTO users (role_id, branch_id, name, username, email, password, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $roleId,
            $branchId ?: branch_main_id($db),
            $name,
            $username,
            $email ?: null,
            password_hash($password, PASSWORD_DEFAULT),
            in_array($status, ['active', 'inactive'], true) ? $status : 'active',
            now_datetime()
        ]);

        Auth::log('create_user', 'Menambah user ' . $username);
        flash('success', 'User berhasil ditambahkan.');
        legacy_redirect('admin/users/index.php');
    } catch (Throwable $e) {
        flash('error', 'Gagal menambah user: ' . $e->getMessage());
        legacy_redirect('admin/users/create.php');
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">

<main class="admin-main system-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Tambah User</h1>
            <p>Buat akun baru untuk admin, owner, atau kasir.</p>
        </div>
    </header>

    <form method="POST" class="system-form-card">
        <?= CSRF::input(); ?>
        <div class="system-form-grid">
            <div class="system-field"><label>Nama Lengkap</label><input name="name" required placeholder="Contoh: Kasir Toko"></div>
            <div class="system-field"><label>Username</label><input name="username" required placeholder="username"></div>
            <div class="system-field"><label>Email</label><input name="email" type="email" placeholder="email optional"></div>
            <div class="system-field">
                <label>Role</label>
                <select name="role_id" required>
                    <option value="">Pilih role</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= e($r['id']); ?>"><?= e($r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="system-field"><label>Cabang User</label><select name="branch_id"><?php foreach ($branches as $b): ?><option value="<?= e($b['id']); ?>"><?= e($b['name']); ?> (<?= e($b['code']); ?>)</option><?php endforeach; ?></select><small>Kasir otomatis memakai cabang ini.</small></div><div class="system-field"><label>Password</label><input type="password" name="password" required placeholder="Minimal 6 karakter"></div>
            <div class="system-field">
                <label>Status</label>
                <select name="status">
                    <option value="active">Aktif</option>
                    <option value="inactive">Nonaktif</option>
                </select>
            </div>
        </div>

        <div class="system-form-actions">
            <a class="system-btn soft" href="<?= legacy_base_url('admin/users/index.php'); ?>">Batal</a>
            <button class="system-btn primary" type="submit"><i class="bi bi-save"></i> Simpan User</button>
        </div>
    </form>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
