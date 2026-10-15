<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin','owner']);

$pageTitle = 'Cek Keuangan';
$db = Database::connect();


function finance_table_exists(PDO $db, string $table): bool
{
    $allowed = ['finance_categories','finance_transactions','debts','receivables'];
    if (!in_array($table, $allowed, true)) {
        return false;
    }

    try {
        $db->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}


try {
    $databaseName = $db->query("SELECT DATABASE()")->fetchColumn();
} catch (Throwable $e) {
    $databaseName = 'Tidak diketahui';
}

$requiredTables = ['finance_categories','finance_transactions','debts','receivables'];
$tableStatus = [];

foreach ($requiredTables as $table) {
    $tableStatus[$table] = finance_table_exists($db, $table);
}

$allReady = !in_array(false, $tableStatus, true);

if ($allReady) {
    flash('success', 'Semua tabel keuangan sudah terbaca.');
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/finance.css?v=' . time()); ?>">

<main class="admin-main finance-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Cek Keuangan</h1>
            <p>Mengecek tabel keuangan di database aktif script.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/finance/index.php'); ?>" class="finance-btn primary">
                <i class="bi bi-wallet2"></i> Buka Keuangan
            </a>
        </div>
    </header>

    <section class="finance-panel mt-4">
        <div class="finance-panel-head">
            <div>
                <h3>Status Database</h3>
                <p class="text-muted mb-0">Database aktif script: <b><?= e($databaseName); ?></b></p>
            </div>
        </div>

        <?php if ($allReady): ?>
            <div class="alert alert-success rounded-4 fw-bold">
                Semua tabel keuangan sudah ada dan terbaca. Silakan buka halaman Keuangan.
            </div>
        <?php else: ?>
            <div class="alert alert-warning rounded-4 fw-bold">
                Masih ada tabel yang belum terbaca.
            </div>
        <?php endif; ?>

        <div class="finance-table-wrap">
            <table class="finance-table">
                <thead>
                    <tr><th>Tabel</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($tableStatus as $table => $ready): ?>
                        <tr>
                            <td><b><?= e($table); ?></b></td>
                            <td>
                                <?php if ($ready): ?>
                                    <span class="finance-badge income">Ada</span>
                                <?php else: ?>
                                    <span class="finance-badge expense">Belum Ada</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="finance-form-actions">
            <a class="finance-btn soft" href="<?= legacy_base_url('admin/dashboard.php'); ?>">Dashboard</a>
            <a class="finance-btn primary" href="<?= legacy_base_url('admin/finance/index.php'); ?>">Buka Keuangan</a>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
