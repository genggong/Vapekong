<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin', 'owner']);
$pageTitle = 'Activity Log';
$db = Database::connect();

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS activity_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(150) NOT NULL,
            description TEXT NULL,
            ip_address VARCHAR(80) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity_user (user_id),
            INDEX idx_activity_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {}

$keyword = trim($_GET['q'] ?? '');
$where = "WHERE 1=1";
$params = [];

if ($keyword !== '') {
    $where .= " AND (a.action LIKE ? OR a.description LIKE ? OR u.name LIKE ? OR u.username LIKE ?)";
    $like = '%' . $keyword . '%';
    $params = [$like, $like, $like, $like];
}

$stmt = $db->prepare("
    SELECT a.*, u.name AS user_name, u.username
    FROM activity_logs a
    LEFT JOIN users u ON u.id = a.user_id
    {$where}
    ORDER BY a.id DESC
    LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total = (int)$db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
$today = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$users = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM activity_logs WHERE user_id IS NOT NULL")->fetchColumn();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/audit.css?v=' . time()); ?>">

<main class="admin-main audit-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Activity Log</h1>
            <p>Riwayat aktivitas admin dan kasir untuk audit keamanan sistem.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/notifications/index.php'); ?>" class="audit-btn soft"><i class="bi bi-bell"></i> Notifikasi</a>
        </div>
    </header>

    <section class="audit-grid">
        <div class="audit-card"><div class="icon"><i class="bi bi-clock-history"></i></div><span>Total Log</span><h3><?= e($total); ?></h3><p>Semua aktivitas</p></div>
        <div class="audit-card"><div class="icon"><i class="bi bi-calendar-day"></i></div><span>Hari Ini</span><h3><?= e($today); ?></h3><p>Aktivitas terbaru</p></div>
        <div class="audit-card"><div class="icon"><i class="bi bi-people"></i></div><span>User Aktif</span><h3><?= e($users); ?></h3><p>Pernah tercatat</p></div>
    </section>

    <section class="audit-panel">
        <div class="audit-panel-head">
            <div>
                <h3>Riwayat Aktivitas</h3>
                <p>Cari berdasarkan user, aksi, atau deskripsi.</p>
            </div>
        </div>

        <form class="audit-filter" method="GET">
            <div>
                <label>Cari Log</label>
                <input type="text" name="q" value="<?= e($keyword); ?>" placeholder="Contoh: login, produk, user">
            </div>
            <button class="audit-btn primary" type="submit"><i class="bi bi-search"></i> Cari</button>
            <a class="audit-btn soft" href="<?= legacy_base_url('admin/logs/index.php'); ?>">Reset</a>
        </form>

        <div class="audit-table-wrap">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>User</th>
                        <th>Aksi</th>
                        <th>Deskripsi</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))); ?></td>
                            <td>
                                <div class="audit-user">
                                    <div class="audit-avatar"><?= strtoupper(substr($r['user_name'] ?? 'S', 0, 1)); ?></div>
                                    <div>
                                        <b><?= e($r['user_name'] ?? 'System'); ?></b><br>
                                        <small><?= e($r['username'] ?? '-'); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><span class="audit-badge blue"><?= e($r['action']); ?></span></td>
                            <td><?= e($r['description'] ?? '-'); ?></td>
                            <td><?= e($r['ip_address'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$rows): ?>
                        <tr><td colspan="5" class="audit-empty">Belum ada activity log.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
