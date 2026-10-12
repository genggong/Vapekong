<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin', 'owner']);
$pageTitle = 'User';
$db = Database::connect();

$users = [];
try {
    $users = $db->query("
        SELECT u.*, r.name AS role_name, r.slug AS role_slug
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        ORDER BY u.id DESC
    ")->fetchAll();
} catch (Throwable $e) {
    flash('error', 'Gagal mengambil data user: ' . $e->getMessage());
}

$totalUsers = count($users);
$activeUsers = count(array_filter($users, fn($u) => ($u['status'] ?? '') === 'active'));
$inactiveUsers = $totalUsers - $activeUsers;

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">

<main class="admin-main system-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>User</h1>
            <p>Kelola akun admin, kasir, role, status login, dan reset password.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/users/create.php'); ?>" class="system-btn primary">
                <i class="bi bi-person-plus"></i> Tambah User
            </a>
        </div>
    </header>

    <section class="system-grid">
        <div class="system-card"><div class="icon"><i class="bi bi-people"></i></div><span>Total User</span><h3><?= e($totalUsers); ?></h3><p>Akun tercatat</p></div>
        <div class="system-card"><div class="icon"><i class="bi bi-check-circle"></i></div><span>Aktif</span><h3><?= e($activeUsers); ?></h3><p>Bisa login</p></div>
        <div class="system-card"><div class="icon"><i class="bi bi-x-circle"></i></div><span>Nonaktif</span><h3><?= e($inactiveUsers); ?></h3><p>Tidak bisa login</p></div>
    </section>

    <section class="system-panel">
        <div class="system-panel-head">
            <div>
                <h3>Data User</h3>
                <p>Daftar akun yang memiliki akses ke dashboard kasir.</p>
            </div>
        </div>

        <div class="system-table-wrap">
            <table class="system-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Login Terakhir</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <div class="system-user">
                                <div class="system-avatar"><?= strtoupper(substr($u['name'] ?? 'U', 0, 1)); ?></div>
                                <div>
                                    <b><?= e($u['name']); ?></b>
                                    <small>ID #<?= e($u['id']); ?></small>
                                </div>
                            </div>
                        </td>
                        <td><?= e($u['username']); ?></td>
                        <td><?= e($u['email'] ?? '-'); ?></td>
                        <td><span class="system-badge blue"><?= e($u['role_name'] ?? '-'); ?></span></td>
                        <td>
                            <?php if (($u['status'] ?? '') === 'active'): ?>
                                <span class="system-badge green">Aktif</span>
                            <?php else: ?>
                                <span class="system-badge red">Nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($u['last_login_at']) ? e(date('d/m/Y H:i', strtotime($u['last_login_at']))) : '-'; ?></td>
                        <td>
                            <div class="system-actions">
                                <a class="system-icon-btn edit" href="<?= legacy_base_url('admin/users/edit.php?id=' . $u['id']); ?>"><i class="bi bi-pencil"></i></a>
                                <a class="system-icon-btn" href="<?= legacy_base_url('admin/users/password.php?id=' . $u['id']); ?>"><i class="bi bi-key"></i></a>
                                <?php if ((int)$u['id'] !== (int)Auth::id()): ?>
                                    <a class="system-icon-btn delete btn-delete" href="<?= legacy_base_url('admin/users/delete.php?id=' . $u['id']); ?>"><i class="bi bi-trash"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$users): ?>
                    <tr><td colspan="7" class="text-center text-muted fw-bold py-4">Belum ada user.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
