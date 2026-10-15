<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$pageTitle = 'Pelanggan / Member';
$db = Database::connect();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS customers (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        phone VARCHAR(40) NULL,
        email VARCHAR(150) NULL,
        address TEXT NULL,
        member_code VARCHAR(80) NULL,
        member_level ENUM('regular','silver','gold','platinum') DEFAULT 'regular',
        points INT NOT NULL DEFAULT 0,
        credit_limit DECIMAL(15,2) NOT NULL DEFAULT 0,
        note TEXT NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY customers_member_code_unique (member_code),
        INDEX idx_customers_phone (phone),
        INDEX idx_customers_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$customers = $db->query("SELECT * FROM customers ORDER BY id DESC")->fetchAll();
$total = count($customers);
$active = count(array_filter($customers, fn($c) => ($c['status'] ?? '') === 'active'));
$totalPoints = array_sum(array_map(fn($c) => (int)$c['points'], $customers));
$totalLimit = array_sum(array_map(fn($c) => (float)$c['credit_limit'], $customers));

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/customers.css?v=' . time()); ?>">

<main class="admin-main customer-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Pelanggan / Member</h1>
            <p>Kelola data pelanggan, kode member, poin belanja, dan limit piutang.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/customers/points.php'); ?>" class="customer-btn soft">
                <i class="bi bi-stars"></i> Riwayat Poin
            </a>
            <a href="<?= legacy_base_url('admin/customers/create.php'); ?>" class="customer-btn primary">
                <i class="bi bi-person-plus"></i> Tambah Pelanggan
            </a>
        </div>
    </header>

    <section class="customer-grid">
        <div class="customer-card"><div class="icon"><i class="bi bi-people"></i></div><span>Total Pelanggan</span><h3><?= e($total); ?></h3><p>Semua data pelanggan</p></div>
        <div class="customer-card"><div class="icon"><i class="bi bi-check-circle"></i></div><span>Aktif</span><h3><?= e($active); ?></h3><p>Pelanggan aktif</p></div>
        <div class="customer-card"><div class="icon"><i class="bi bi-star"></i></div><span>Total Poin</span><h3><?= e($totalPoints); ?></h3><p>Poin member terkumpul</p></div>
        <div class="customer-card"><div class="icon"><i class="bi bi-wallet2"></i></div><span>Limit Piutang</span><h3><?= rupiah($totalLimit); ?></h3><p>Total limit pelanggan</p></div>
    </section>

    <section class="customer-panel">
        <div class="customer-panel-head">
            <div>
                <h3>Daftar Pelanggan</h3>
                <p>Data pelanggan untuk member, piutang, dan histori belanja.</p>
            </div>
        </div>

        <div class="customer-table-wrap">
            <table class="customer-table">
                <thead>
                    <tr>
                        <th>Pelanggan</th>
                        <th>Kontak</th>
                        <th>Kode Member</th>
                        <th>Level</th>
                        <th>Poin</th>
                        <th>Limit Piutang</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td>
                            <div class="customer-user">
                                <div class="customer-avatar"><?= strtoupper(substr($c['name'] ?? 'P', 0, 1)); ?></div>
                                <div>
                                    <b><?= e($c['name']); ?></b>
                                    <small>ID #<?= e($c['id']); ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <b><?= e($c['phone'] ?: '-'); ?></b><br>
                            <small><?= e($c['email'] ?: '-'); ?></small>
                        </td>
                        <td><span class="customer-badge blue"><?= e($c['member_code'] ?: '-'); ?></span></td>
                        <td><span class="customer-badge orange"><?= e(strtoupper($c['member_level'])); ?></span></td>
                        <td><b><?= e($c['points']); ?></b></td>
                        <td><?= rupiah($c['credit_limit']); ?></td>
                        <td>
                            <?php if ($c['status'] === 'active'): ?>
                                <span class="customer-badge green">Aktif</span>
                            <?php else: ?>
                                <span class="customer-badge red">Nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="customer-actions">
                                <a class="customer-icon-btn" href="<?= legacy_base_url('admin/customers/detail.php?id=' . $c['id']); ?>"><i class="bi bi-eye"></i></a>
                                <a class="customer-icon-btn edit" href="<?= legacy_base_url('admin/customers/edit.php?id=' . $c['id']); ?>"><i class="bi bi-pencil"></i></a>
                                <a class="customer-icon-btn delete btn-delete" href="<?= legacy_base_url('admin/customers/delete.php?id=' . $c['id']); ?>"><i class="bi bi-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
