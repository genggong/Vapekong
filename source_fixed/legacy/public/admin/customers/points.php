<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$pageTitle = 'Riwayat Poin Member';
$db = Database::connect();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS customer_points (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        customer_id BIGINT NOT NULL,
        transaction_id BIGINT NULL,
        type ENUM('in','out') NOT NULL DEFAULT 'in',
        points INT NOT NULL DEFAULT 0,
        description TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_customer_points_customer (customer_id),
        INDEX idx_customer_points_transaction (transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$type = $_GET['type'] ?? '';
$where = [];
$params = [];
if (in_array($type, ['in', 'out'], true)) {
    $where[] = 'cp.type = ?';
    $params[] = $type;
}
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $db->prepare("SELECT cp.*, c.name AS customer_name, c.phone AS customer_phone, c.member_code, t.invoice_number
    FROM customer_points cp
    LEFT JOIN customers c ON c.id = cp.customer_id
    LEFT JOIN transactions t ON t.id = cp.transaction_id
    {$sqlWhere}
    ORDER BY cp.id DESC
    LIMIT 300");
$stmt->execute($params);
$history = $stmt->fetchAll();

$totalIn = (int) $db->query("SELECT COALESCE(SUM(points),0) FROM customer_points WHERE type='in'")->fetchColumn();
$totalOut = (int) $db->query("SELECT COALESCE(SUM(points),0) FROM customer_points WHERE type='out'")->fetchColumn();
$totalActive = (int) $db->query("SELECT COALESCE(SUM(points),0) FROM customers WHERE status='active'")->fetchColumn();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/customers.css?v=' . time()); ?>">

<main class="admin-main customer-wrap member-points-page">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Riwayat Poin Member</h1>
            <p>Monitoring poin masuk dari transaksi dan poin keluar dari penukaran diskon.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/customers/index.php'); ?>" class="customer-btn soft"><i class="bi bi-arrow-left"></i> Pelanggan</a>
        </div>
    </header>

    <section class="customer-grid">
        <div class="customer-card"><div class="icon"><i class="bi bi-arrow-down-circle"></i></div><span>Poin Masuk</span><h3><?= e($totalIn); ?></h3><p>Total reward diberikan</p></div>
        <div class="customer-card"><div class="icon"><i class="bi bi-arrow-up-circle"></i></div><span>Poin Ditukar</span><h3><?= e($totalOut); ?></h3><p>Total poin dipakai diskon</p></div>
        <div class="customer-card"><div class="icon"><i class="bi bi-stars"></i></div><span>Poin Aktif</span><h3><?= e($totalActive); ?></h3><p>Saldo poin pelanggan aktif</p></div>
        <div class="customer-card"><div class="icon"><i class="bi bi-filter"></i></div><span>Filter</span><h3><?= e($type ? strtoupper($type) : 'SEMUA'); ?></h3><p>Riwayat yang sedang tampil</p></div>
    </section>

    <section class="customer-panel">
        <div class="customer-panel-head">
            <div>
                <h3>Daftar Riwayat</h3>
                <p>Gunakan filter untuk melihat poin masuk atau keluar.</p>
            </div>
            <div class="customer-actions">
                <a class="customer-btn soft" href="<?= legacy_base_url('admin/customers/points.php'); ?>">Semua</a>
                <a class="customer-btn soft" href="<?= legacy_base_url('admin/customers/points.php?type=in'); ?>">Masuk</a>
                <a class="customer-btn soft" href="<?= legacy_base_url('admin/customers/points.php?type=out'); ?>">Keluar</a>
            </div>
        </div>

        <div class="customer-table-wrap">
            <table class="customer-table no-datatable member-points-table">
                <thead><tr><th>Tanggal</th><th>Pelanggan</th><th>Invoice</th><th>Tipe</th><th>Poin</th><th>Keterangan</th></tr></thead>
                <tbody>
                <?php foreach ($history as $row): ?>
                    <tr>
                        <td><?= e(date('d/m/Y H:i', strtotime($row['created_at']))); ?></td>
                        <td><b><?= e($row['customer_name'] ?? '-'); ?></b><br><small><?= e($row['customer_phone'] ?? $row['member_code'] ?? '-'); ?></small></td>
                        <td><b><?= e($row['invoice_number'] ?? '-'); ?></b></td>
                        <td><?= ($row['type'] ?? '') === 'in' ? '<span class="customer-badge green">Masuk</span>' : '<span class="customer-badge red">Keluar</span>'; ?></td>
                        <td><b><?= (($row['type'] ?? '') === 'in' ? '+' : '-') . e((int) $row['points']); ?></b></td>
                        <td><?= e($row['description'] ?? '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$history): ?>
                    <tr><td colspan="6" class="text-center text-muted fw-bold py-4">Belum ada riwayat poin.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
