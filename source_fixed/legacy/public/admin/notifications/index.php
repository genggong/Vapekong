<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$pageTitle = 'Notifikasi';
$db = Database::connect();

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            title VARCHAR(180) NOT NULL,
            message TEXT NULL,
            type VARCHAR(40) DEFAULT 'info',
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifications_user (user_id),
            INDEX idx_notifications_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {}

$filter = $_GET['filter'] ?? 'all';
$where = "WHERE (user_id IS NULL OR user_id = ?)";
$params = [Auth::id()];

if ($filter === 'unread') {
    $where .= " AND is_read=0";
}

$stmt = $db->prepare("SELECT * FROM notifications {$where} ORDER BY id DESC LIMIT 100");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
$unread = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn();
$read = $total - $unread;

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/audit.css?v=' . time()); ?>">

<main class="admin-main audit-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Notifikasi</h1>
            <p>Pusat informasi transaksi, stok minimum, sistem, dan aktivitas penting toko.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/notifications/read-all.php'); ?>" class="audit-btn primary"><i class="bi bi-check2-all"></i> Baca Semua</a>
        </div>
    </header>

    <section class="audit-grid">
        <div class="audit-card"><div class="icon"><i class="bi bi-bell"></i></div><span>Total</span><h3><?= e($total); ?></h3><p>Semua notifikasi</p></div>
        <div class="audit-card"><div class="icon"><i class="bi bi-bell-fill"></i></div><span>Belum Dibaca</span><h3><?= e($unread); ?></h3><p>Butuh perhatian</p></div>
        <div class="audit-card"><div class="icon"><i class="bi bi-check-circle"></i></div><span>Sudah Dibaca</span><h3><?= e($read); ?></h3><p>Telah dilihat</p></div>
    </section>

    <section class="audit-panel">
        <div class="audit-panel-head">
            <div>
                <h3>Daftar Notifikasi</h3>
                <p>Gunakan filter untuk melihat semua atau yang belum dibaca.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a class="audit-btn <?= $filter === 'all' ? 'primary' : 'soft'; ?>" href="<?= legacy_base_url('admin/notifications/index.php'); ?>">Semua</a>
                <a class="audit-btn <?= $filter === 'unread' ? 'primary' : 'soft'; ?>" href="<?= legacy_base_url('admin/notifications/index.php?filter=unread'); ?>">Belum Dibaca</a>
            </div>
        </div>

        <div class="notification-list">
            <?php foreach ($rows as $n): 
                $type = $n['type'] ?? 'info';
                $icon = 'bi-info-circle';
                if ($type === 'success') $icon = 'bi-check-circle';
                if ($type === 'warning') $icon = 'bi-exclamation-triangle';
                if ($type === 'danger') $icon = 'bi-x-circle';
                if ($type === 'stock') $icon = 'bi-inboxes';
                if ($type === 'transaction') $icon = 'bi-receipt';
            ?>
                <div class="notification-item <?= (int)$n['is_read'] === 0 ? 'unread' : ''; ?>">
                    <div class="notification-icon"><i class="bi <?= e($icon); ?>"></i></div>
                    <div class="notification-content">
                        <h4><?= e($n['title']); ?></h4>
                        <p><?= e($n['message'] ?? '-'); ?></p>
                        <small><i class="bi bi-clock"></i>&nbsp;<?= e(date('d/m/Y H:i', strtotime($n['created_at']))); ?></small>
                    </div>
                    <div class="notification-actions">
                        <?php if ((int)$n['is_read'] === 0): ?>
                            <a class="audit-icon-btn green" href="<?= legacy_base_url('admin/notifications/read.php?id=' . $n['id']); ?>"><i class="bi bi-check"></i></a>
                        <?php endif; ?>
                        <a class="audit-icon-btn delete btn-delete" href="<?= legacy_base_url('admin/notifications/delete.php?id=' . $n['id']); ?>"><i class="bi bi-trash"></i></a>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!$rows): ?>
                <div class="audit-empty">Belum ada notifikasi.</div>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
