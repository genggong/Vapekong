<?php
require_once __DIR__ . '/../../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/branches/_helpers.php';
$pageTitle = 'Dashboard';
$db = Database::connect();
branch_install($db);
$currentBranch = branch_current($db);
$currentBranchId = (int) ($currentBranch['id'] ?? 0);

function safe_count(PDO $db, string $table, string $where = '1=1'): int {
    try {
        return (int)($db->query("SELECT COUNT(*) AS total FROM {$table} WHERE {$where}")->fetch()['total'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function safe_sum(PDO $db, string $table, string $field, string $where = '1=1'): float {
    try {
        return (float)($db->query("SELECT COALESCE(SUM({$field}),0) AS total FROM {$table} WHERE {$where}")->fetch()['total'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function dashboard_daily_summary(PDO $db, int $branchId): array
{
    $default = [
        'turnover_today' => 0.0,
        'cash_today' => 0.0,
        'qris_today' => 0.0,
        'invoice_today' => 0,
    ];

    try {
        $stmt = $db->prepare("
            SELECT
                COALESCE(SUM(CASE
                    WHEN status = 'success' THEN grand_total
                    ELSE 0
                END), 0) AS turnover_today,
                COALESCE(SUM(CASE
                    WHEN status = 'success' AND payment_method = 'cash' THEN grand_total
                    WHEN status = 'success' AND payment_method = 'cash_debt'
                        THEN GREATEST(grand_total - COALESCE(debt_amount, 0), 0)
                    ELSE 0
                END), 0) AS cash_today,
                COALESCE(SUM(CASE
                    WHEN status = 'success' AND payment_method IN ('qris', 'qris_pakasir', 'qris_manual') THEN grand_total
                    ELSE 0
                END), 0) AS qris_today,
                COUNT(*) AS invoice_today
            FROM transactions
            WHERE branch_id = ?
              AND DATE(COALESCE(transaction_date, created_at)) = CURDATE()
        ");
        $stmt->execute([$branchId]);
        $row = $stmt->fetch() ?: [];

        return [
            'turnover_today' => (float) ($row['turnover_today'] ?? 0),
            'cash_today' => (float) ($row['cash_today'] ?? 0),
            'qris_today' => (float) ($row['qris_today'] ?? 0),
            'invoice_today' => (int) ($row['invoice_today'] ?? 0),
        ];
    } catch (Throwable $e) {
        return $default;
    }
}

$totalProducts = safe_count($db, 'products');
$totalCategories = safe_count($db, 'categories');
$totalSuppliers = safe_count($db, 'suppliers');
$lowStock = safe_count($db, 'products', 'stock <= minimum_stock AND is_active = 1');
$stockValue = safe_sum($db, 'products', 'stock * purchase_price');
$potentialValue = safe_sum($db, 'products', 'stock * selling_price');
$dailySummary = dashboard_daily_summary($db, $currentBranchId);

require_once __DIR__ . '/../layouts/admin-header.php';
require_once __DIR__ . '/../layouts/admin-sidebar.php';
?>

<main class="admin-main">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Dashboard</h1>
            <p>Selamat datang, <?= e(Auth::user()['name']); ?> 👋 Pantau toko dari satu tempat.</p>
        </div>
    </header>

    <section class="dashboard-hero theme-hero">
        <div>
            <span class="hero-badge"><i class="bi bi-fire"></i> POS Premium</span>
            <h2>Kasir, stok, supplier, dan keuangan  dibuat lebih rapi dan siap dikembangkan.</h2>
            <p>Tampilan modern dengan tema custom, menu tertata, footer, bottom navigation Android, dan data toko mudah dipantau.</p>
        </div>
        <div class="hero-icon"><i class="bi bi-shop"></i></div>
    </section>

    <section class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
            <div>
                <span>Omset Hari Ini</span>
                <h3><?= rupiah($dailySummary['turnover_today']); ?></h3>
                <p>Total penjualan sukses cabang <?= e($currentBranch['name'] ?? 'aktif'); ?></p>
            </div>
        </div>

        <div class="stat-card success">
            <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
            <div>
                <span>Tunai Hari Ini</span>
                <h3><?= rupiah($dailySummary['cash_today']); ?></h3>
                <p>Pembayaran tunai yang masuk hari ini</p>
            </div>
        </div>

        <div class="stat-card warning">
            <div class="stat-icon"><i class="bi bi-qr-code-scan"></i></div>
            <div>
                <span>QRIS Hari Ini</span>
                <h3><?= rupiah($dailySummary['qris_today']); ?></h3>
                <p>Pembayaran QRIS sukses hari ini</p>
            </div>
        </div>

        <div class="stat-card danger">
            <div class="stat-icon"><i class="bi bi-receipt-cutoff"></i></div>
            <div>
                <span>Invoice Hari Ini</span>
                <h3><?= e($dailySummary['invoice_today']); ?></h3>
                <p>Jumlah invoice yang dibuat hari ini</p>
            </div>
        </div>
    </section>

    <section class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-icon"><i class="bi bi-box-seam"></i></div>
            <div>
                <span>Total Produk</span>
                <h3><?= e($totalProducts); ?></h3>
                <p>Data barang aktif dan nonaktif</p>
            </div>
        </div>

        <div class="stat-card success">
            <div class="stat-icon"><i class="bi bi-wallet2"></i></div>
            <div>
                <span>Nilai Modal Stok</span>
                <h3><?= rupiah($stockValue); ?></h3>
                <p>Total harga beli stok tersedia</p>
            </div>
        </div>

        <div class="stat-card warning">
            <div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <span>Stok Menipis</span>
                <h3><?= e($lowStock); ?></h3>
                <p>Produk di bawah minimum</p>
            </div>
        </div>

        <div class="stat-card danger">
            <div class="stat-icon"><i class="bi bi-tags"></i></div>
            <div>
                <span>Potensi Nilai Jual</span>
                <h3><?= rupiah($potentialValue); ?></h3>
                <p>Total harga jual stok tersedia</p>
            </div>
        </div>

    </section>

    <section class="content-grid">
        <div class="premium-card">
            <div class="card-head">
                <div>
                    <h3>Ringkasan Master Data</h3>
                    <p>Data awal untuk menjalankan POS dan stok.</p>
                </div>
                <span class="badge-soft success"><i class="bi bi-check-circle"></i> Ready</span>
            </div>

            <div class="progress-list">
                <div class="progress-item done"><i class="bi bi-check-circle-fill"></i><span><?= e($totalCategories); ?> kategori produk tersedia</span></div>
                <div class="progress-item done"><i class="bi bi-check-circle-fill"></i><span><?= e($totalSuppliers); ?> supplier tersedia</span></div>
                <div class="progress-item done"><i class="bi bi-check-circle-fill"></i><span><?= e($totalProducts); ?> produk siap dikelola</span></div>
                <div class="progress-item done"><i class="bi bi-check-circle-fill"></i><span>POS, stok, transaksi, keuangan, laporan, user, dan backup sudah tersedia</span></div>
            </div>
        </div>

        <div class="premium-card">
            <div class="card-head">
                <div>
                    <h3>Shortcut Cepat</h3>
                    <p>Akses menu penting.</p>
                </div>
            </div>

            <div class="shortcut-grid">
                <a class="shortcut-btn" href="<?= legacy_base_url('admin/pos/index.php'); ?>"><i class="bi bi-cart-check"></i><span>POS Kasir</span></a>
                <a class="shortcut-btn" href="<?= legacy_base_url('admin/products/index.php'); ?>"><i class="bi bi-box-seam"></i><span>Produk</span></a>
                <a class="shortcut-btn" href="<?= legacy_base_url('admin/stock/index.php'); ?>"><i class="bi bi-inboxes"></i><span>Stok</span></a>
                <a class="shortcut-btn" href="<?= legacy_base_url('admin/transactions/index.php'); ?>"><i class="bi bi-receipt"></i><span>Transaksi</span></a>
            </div>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../layouts/admin-footer.php'; ?>
