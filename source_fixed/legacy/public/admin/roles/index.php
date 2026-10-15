<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

Auth::requirePermission('roles.view');

$pageTitle = 'Role & Hak Akses';
$db = Database::connect();
seed_default_role_permissions($db);

$roles = $db->query("
    SELECT r.*,
        (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS total_users,
        (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id AND rp.is_allowed = 1) AS total_permissions
    FROM roles r
    ORDER BY r.id ASC
")->fetchAll();

$totalRoles = count($roles);
$totalPermissions = count(all_permission_keys());
$totalUsers = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/roles.css?v=' . time()); ?>">

<main class="admin-main roles-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Role & Hak Akses</h1>
            <p>Atur menu yang boleh dibuka oleh Owner, Admin, Kasir, dan role lain.</p>
        </div>
    </header>

    <section class="roles-grid">
        <div class="roles-card">
            <div class="icon"><i class="bi bi-shield-lock"></i></div>
            <span>Total Role</span>
            <h3><?= e($totalRoles); ?></h3>
            <p>Role yang tersedia</p>
        </div>
        <div class="roles-card">
            <div class="icon"><i class="bi bi-key"></i></div>
            <span>Total Permission</span>
            <h3><?= e($totalPermissions); ?></h3>
            <p>Akses menu dan aksi</p>
        </div>
        <div class="roles-card">
            <div class="icon"><i class="bi bi-people"></i></div>
            <span>Total User</span>
            <h3><?= e($totalUsers); ?></h3>
            <p>User aktif/nonaktif</p>
        </div>
    </section>

    <section class="roles-panel">
        <div class="roles-panel-head">
            <div>
                <h3>Daftar Role</h3>
                <p>Pilih role lalu atur checklist akses menu yang diperbolehkan.</p>
            </div>
        </div>

        <div class="roles-table-wrap">
            <table class="roles-table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Slug</th>
                        <th>User</th>
                        <th>Akses Aktif</th>
                        <th>Deskripsi</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td>
                                <div class="role-name">
                                    <div class="role-icon"><i class="bi bi-shield-check"></i></div>
                                    <div>
                                        <b><?= e($role['name']); ?></b>
                                        <small>ID #<?= e($role['id']); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><span class="role-badge"><?= e($role['slug']); ?></span></td>
                            <td><?= e($role['total_users']); ?> user</td>
                            <td><?= e($role['total_permissions']); ?> / <?= e($totalPermissions); ?></td>
                            <td><?= e($role['description'] ?? '-'); ?></td>
                            <td>
                                <a class="roles-btn primary" href="<?= legacy_base_url('admin/roles/permissions.php?id=' . $role['id']); ?>">
                                    <i class="bi bi-sliders"></i> Atur Akses
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$roles): ?>
                        <tr><td colspan="6" class="text-center text-muted fw-bold py-4">Belum ada role.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
