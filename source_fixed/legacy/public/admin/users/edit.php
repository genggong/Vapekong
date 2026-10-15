<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin', 'owner']);
require_once __DIR__ . '/../branches/_helpers.php';
$pageTitle = 'Edit User';
$db = Database::connect();
branch_install($db);

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$userEdit = $stmt->fetch();

if (!$userEdit) {
    flash('error', 'User tidak ditemukan.');
    legacy_redirect('admin/users/index.php');
}

$roles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();
$branches = branch_all($db, true);

if (is_post()) {
    if (!CSRF::verify()) {
        flash('error', 'Token keamanan tidak valid.');
        legacy_redirect('admin/users/edit.php?id=' . $id);
    }

    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $roleId = (int)($_POST['role_id'] ?? 0);
    $branchId = (int)($_POST['branch_id'] ?? branch_main_id($db));
    $status = $_POST['status'] ?? 'active';

    if ($name === '' || $username === '' || $roleId <= 0) {
        flash('error', 'Nama, username, dan role wajib diisi.');
        legacy_redirect('admin/users/edit.php?id=' . $id);
    }

    try {
        $stmt = $db->prepare("UPDATE users SET role_id=?, branch_id=?, name=?, username=?, email=?, status=? WHERE id=?");
        $stmt->execute([
            $roleId,
            $branchId ?: branch_main_id($db),
            $name,
            $username,
            $email ?: null,
            in_array($status, ['active', 'inactive'], true) ? $status : 'active',
            $id
        ]);

        if ((int)$id === (int)Auth::id()) {
            $_SESSION['auth_user']['name'] = $name;
            $_SESSION['auth_user']['username'] = $username;
            $_SESSION['auth_user']['email'] = $email;
        }

        Auth::log('update_user', 'Update user ' . $username);
        flash('success', 'User berhasil diperbarui.');
        legacy_redirect('admin/users/index.php');
    } catch (Throwable $e) {
        flash('error', 'Gagal update user: ' . $e->getMessage());
        legacy_redirect('admin/users/edit.php?id=' . $id);
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
            <h1>Edit User</h1>
            <p>Perbarui data akun dan role akses.</p>
        </div>
    </header>

    <form method="POST" class="system-form-card">
        <?= CSRF::input(); ?>
        <div class="system-form-grid">
            <div class="system-field"><label>Nama Lengkap</label><input name="name" required value="<?= e($userEdit['name']); ?>"></div>
            <div class="system-field"><label>Username</label><input name="username" required value="<?= e($userEdit['username']); ?>"></div>
            <div class="system-field"><label>Email</label><input name="email" type="email" value="<?= e($userEdit['email']); ?>"></div>
            <div class="system-field">
                <label>Role</label>
                <select name="role_id" required>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= e($r['id']); ?>" <?= (int)$r['id'] === (int)$userEdit['role_id'] ? 'selected' : ''; ?>><?= e($r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="system-field"><label>Cabang User</label><select name="branch_id"><?php foreach ($branches as $b): ?><option value="<?= e($b['id']); ?>" <?= (int)$b['id'] === (int)($userEdit['branch_id'] ?? 0) ? 'selected' : ''; ?>><?= e($b['name']); ?> (<?= e($b['code']); ?>)</option><?php endforeach; ?></select><small>Kasir otomatis memakai cabang ini.</small></div>
            <div class="system-field">
                <label>Status</label>
                <select name="status">
                    <option value="active" <?= $userEdit['status'] === 'active' ? 'selected' : ''; ?>>Aktif</option>
                    <option value="inactive" <?= $userEdit['status'] === 'inactive' ? 'selected' : ''; ?>>Nonaktif</option>
                </select>
            </div>
        </div>

        <div class="system-form-actions">
            <a class="system-btn soft" href="<?= legacy_base_url('admin/users/index.php'); ?>">Batal</a>
            <button class="system-btn primary" type="submit"><i class="bi bi-save"></i> Update User</button>
        </div>
    </form>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
