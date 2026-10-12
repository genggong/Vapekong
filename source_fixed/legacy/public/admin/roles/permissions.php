<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

Auth::requirePermission('roles.manage');

$pageTitle = 'Atur Hak Akses';
$db = Database::connect();
seed_default_role_permissions($db);

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM roles WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$role = $stmt->fetch();

if (!$role) {
    flash('error', 'Role tidak ditemukan.');
    legacy_redirect('admin/roles/index.php');
}

if ($role['slug'] === 'super_admin' && Auth::role() !== 'super_admin') {
    flash('error', 'Hanya Super Admin yang boleh mengubah akses Super Admin.');
    legacy_redirect('admin/roles/index.php');
}

$groups = permission_groups();
$selected = role_permission_keys($db, $id);

if (is_post()) {
    if (!CSRF::verify()) {
        flash('error', 'Token keamanan tidak valid.');
        legacy_redirect('admin/roles/permissions.php?id=' . $id);
    }

    $permissions = $_POST['permissions'] ?? [];
    if (!is_array($permissions)) {
        $permissions = [];
    }

    $valid = all_permission_keys();
    $permissions = array_values(array_intersect($permissions, $valid));

    if ($role['slug'] === 'super_admin') {
        $permissions = $valid;
    }

    try {
        $db->beginTransaction();

        $del = $db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $del->execute([$id]);

        $ins = $db->prepare("INSERT INTO role_permissions (role_id, permission_key, is_allowed) VALUES (?, ?, 1)");
        foreach ($permissions as $permission) {
            $ins->execute([$id, $permission]);
        }

        $db->commit();

        Auth::log('update_role_permissions', 'Update hak akses role ' . $role['name']);

        if ((int)Auth::roleId() === (int)$id) {
            Auth::refreshPermissions();
        }

        flash('success', 'Hak akses role berhasil diperbarui.');
        legacy_redirect('admin/roles/permissions.php?id=' . $id);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        flash('error', 'Gagal menyimpan hak akses: ' . $e->getMessage());
        legacy_redirect('admin/roles/permissions.php?id=' . $id);
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/roles.css?v=' . time()); ?>">

<main class="admin-main roles-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Atur Hak Akses</h1>
            <p>Role: <?= e($role['name']); ?> — checklist menu dan aksi yang boleh dibuka.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/roles/index.php'); ?>" class="roles-btn soft">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </div>
    </header>

    <form method="POST" class="roles-form-card">
        <?= CSRF::input(); ?>

        <?php foreach ($groups as $groupName => $group): ?>
            <section class="permission-group">
                <div class="permission-group-head">
                    <div class="permission-group-title">
                        <div class="icon"><i class="bi <?= e($group['icon']); ?>"></i></div>
                        <div>
                            <h4><?= e($groupName); ?></h4>
                            <p><?= e($group['description']); ?></p>
                        </div>
                    </div>
                </div>

                <div class="permission-check-grid">
                    <?php foreach ($group['items'] as $key => $meta): ?>
                        <?php
                            $checked = in_array($key, $selected, true) || $role['slug'] === 'super_admin';
                            $disabled = $role['slug'] === 'super_admin' ? 'disabled' : '';
                        ?>
                        <label class="permission-check">
                            <input type="checkbox" name="permissions[]" value="<?= e($key); ?>" <?= $checked ? 'checked' : ''; ?> <?= $disabled; ?>>
                            <span>
                                <b><?= e($meta[0]); ?></b>
                                <small><?= e($meta[1]); ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <?php if ($role['slug'] === 'super_admin'): ?>
            <div class="alert alert-warning rounded-4 fw-bold">
                <i class="bi bi-shield-lock"></i>
                Super Admin selalu memiliki semua akses dan tidak bisa dibatasi.
            </div>
        <?php endif; ?>

        <div class="roles-form-actions">
            <a href="<?= legacy_base_url('admin/roles/index.php'); ?>" class="roles-btn soft">Batal</a>
            <button type="submit" class="roles-btn primary" <?= $role['slug'] === 'super_admin' ? 'disabled' : ''; ?>>
                <i class="bi bi-save"></i> Simpan Hak Akses
            </button>
        </div>
    </form>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
