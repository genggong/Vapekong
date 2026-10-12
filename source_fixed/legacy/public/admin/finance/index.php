<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Accounting/Accounting.php';

Auth::requireLogin();
$pageTitle = 'Keuangan';
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

function finance_safe_date(?string $value, string $fallback): string
{
    $value = trim((string)$value);
    if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $ts = strtotime($value . ' 00:00:00');
        if ($ts !== false && date('Y-m-d', $ts) === $value) {
            return $value;
        }
    }
    return $fallback;
}

$today = date('Y-m-d');
$period = strtolower(trim((string)($_GET['period'] ?? 'month')));
$allowedPeriods = ['day', '4days', 'week', 'month', 'custom'];
if (!in_array($period, $allowedPeriods, true)) {
    $period = 'month';
}

// Bila URL lama hanya membawa from/to, tetap hormati sebagai filter custom.
if (!isset($_GET['period']) && (isset($_GET['from']) || isset($_GET['to']))) {
    $period = 'custom';
}

switch ($period) {
    case 'day':
        $from = $today;
        $to = $today;
        $periodLabel = 'Hari Ini';
        break;

    case '4days':
        $from = date('Y-m-d', strtotime($today . ' -3 days'));
        $to = $today;
        $periodLabel = '4 Hari Terakhir';
        break;

    case 'week':
        $from = date('Y-m-d', strtotime($today . ' -6 days'));
        $to = $today;
        $periodLabel = 'Mingguan (7 Hari)';
        break;

    case 'custom':
        $defaultFrom = date('Y-m-01');
        $from = finance_safe_date($_GET['from'] ?? null, $defaultFrom);
        $to = finance_safe_date($_GET['to'] ?? null, $today);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $periodLabel = 'Tanggal Kustom';
        break;

    case 'month':
    default:
        $from = date('Y-m-01');
        $to = $today;
        $periodLabel = 'Bulan Ini';
        break;
}

$financeReady = finance_table_exists($db, 'finance_transactions') && finance_table_exists($db, 'finance_categories');
$debtReady = finance_table_exists($db, 'debts');
$receivableReady = finance_table_exists($db, 'receivables');

$income = 0.0;
$omzet = 0.0;
$otherIncome = 0.0;
$expense = 0.0;
$debt = 0.0;
$receivable = 0.0;
$posTransactionCount = 0;
$transactions = [];
$financeError = '';

if ($financeReady) {
    try {
        /*
         * FIX TOTAL PEMASUKAN:
         * Sumber omzet memakai mesin Akuntansi yang sama dengan menu Akuntansi,
         * sehingga penjualan POS otomatis masuk ke kartu Total Pemasukan.
         *
         * Total Pemasukan = Penjualan Bersih POS + Pemasukan Lain.
         * Penjualan Bersih sudah memperhitungkan retur.
         */
        $accountingReport = accounting_build_report($db, $from, $to, 0);
        $accountingSummary = $accountingReport['summary'] ?? [];

        $omzet = (float)($accountingSummary['net_sales'] ?? 0);
        $otherIncome = (float)($accountingSummary['other_income'] ?? 0);
        $posTransactionCount = (int)($accountingSummary['transaction_count'] ?? 0);
        $income = $omzet + $otherIncome;

        // Pengeluaran tetap mengikuti buku Keuangan, tetapi sekarang ikut periode yang dipilih.
        $stmtExpense = $db->prepare("
            SELECT COALESCE(SUM(amount),0) AS total
            FROM finance_transactions
            WHERE type='expense'
              AND transaction_date BETWEEN ? AND ?
        ");
        $stmtExpense->execute([$from, $to]);
        $expense = (float)($stmtExpense->fetch()['total'] ?? 0);

        // Hutang/piutang adalah saldo berjalan saat ini, bukan histori periode.
        if ($debtReady) {
            $debt = (float)($db->query("SELECT COALESCE(SUM(amount-paid_amount),0) AS total FROM debts WHERE status!='paid'")->fetch()['total'] ?? 0);
        }

        if ($receivableReady) {
            $receivable = (float)($db->query("SELECT COALESCE(SUM(amount-paid_amount),0) AS total FROM receivables WHERE status!='paid'")->fetch()['total'] ?? 0);
        }

        // Daftar transaksi Keuangan juga mengikuti filter tanggal.
        $stmtTransactions = $db->prepare("
            SELECT ft.*, fc.name AS category_name, u.name AS user_name
            FROM finance_transactions ft
            LEFT JOIN finance_categories fc ON fc.id = ft.category_id
            LEFT JOIN users u ON u.id = ft.user_id
            WHERE ft.transaction_date BETWEEN ? AND ?
            ORDER BY ft.transaction_date DESC, ft.id DESC
            LIMIT 100
        ");
        $stmtTransactions->execute([$from, $to]);
        $transactions = $stmtTransactions->fetchAll();
    } catch (Throwable $e) {
        $financeReady = false;
        $financeError = $e->getMessage();
    }
}

$balance = $income - $expense;
$rangeText = date('d/m/Y', strtotime($from)) . ' - ' . date('d/m/Y', strtotime($to));

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/finance.css?v=' . time()); ?>">

<main class="admin-main finance-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Keuangan</h1>
            <p>Catat pemasukan, pengeluaran, hutang, piutang, dan cashflow toko.</p>
        </div>
        <div class="topbar-actions">
            <?php if ($financeReady): ?>
                <a href="<?= legacy_base_url('admin/finance/report.php'); ?>" class="finance-btn soft"><i class="bi bi-file-earmark-bar-graph"></i> Laporan</a>
                <a href="<?= legacy_base_url('admin/finance/income.php'); ?>" class="finance-btn primary"><i class="bi bi-plus-circle"></i> Pemasukan</a>
                <a href="<?= legacy_base_url('admin/finance/expense.php'); ?>" class="finance-btn soft"><i class="bi bi-dash-circle"></i> Pengeluaran</a>
            <?php else: ?>
                <a href="<?= legacy_base_url('admin/finance/install.php'); ?>" class="finance-btn primary"><i class="bi bi-tools"></i> Cek Keuangan</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$financeReady): ?>
        <div class="finance-install-box">
            Tabel keuangan belum terbaca oleh script.
            <?php if (!empty($financeError)): ?>
                <br><b>Error:</b> <?= e($financeError); ?>
            <?php endif; ?>
            <br><br>
            <a class="finance-btn primary" href="<?= legacy_base_url('admin/finance/install.php'); ?>">
                <i class="bi bi-tools"></i> Cek Sekarang
            </a>
        </div>
    <?php else: ?>
        <section class="finance-hero">
            <div>
                <h2>Ringkasan Keuangan Toko</h2>
                <p>Pantau omzet POS, pemasukan lain, uang keluar, hutang, piutang, dan saldo bersih dari satu halaman.</p>
            </div>
            <div class="finance-actions">
                <a href="<?= legacy_base_url('admin/finance/debt.php'); ?>" class="finance-btn soft"><i class="bi bi-journal-minus"></i> Hutang</a>
                <a href="<?= legacy_base_url('admin/finance/receivable.php'); ?>" class="finance-btn soft"><i class="bi bi-journal-plus"></i> Piutang</a>
                <a href="<?= legacy_base_url('admin/finance/cashflow.php'); ?>" class="finance-btn primary"><i class="bi bi-graph-up"></i> Cashflow</a>
            </div>
        </section>

        <section class="finance-period-panel">
            <div class="finance-period-head">
                <div>
                    <span class="finance-period-kicker"><i class="bi bi-calendar3"></i> Filter Periode</span>
                    <h3><?= e($periodLabel); ?></h3>
                    <p><?= e($rangeText); ?> &bull; <?= number_format($posTransactionCount, 0, ',', '.'); ?> transaksi POS</p>
                </div>
            </div>

            <div class="finance-period-presets">
                <a href="<?= legacy_base_url('admin/finance/index.php?period=day'); ?>" class="finance-period-chip <?= $period === 'day' ? 'active' : ''; ?>">Daily</a>
                <a href="<?= legacy_base_url('admin/finance/index.php?period=4days'); ?>" class="finance-period-chip <?= $period === '4days' ? 'active' : ''; ?>">4 Hari</a>
                <a href="<?= legacy_base_url('admin/finance/index.php?period=week'); ?>" class="finance-period-chip <?= $period === 'week' ? 'active' : ''; ?>">Weekly</a>
                <a href="<?= legacy_base_url('admin/finance/index.php?period=month'); ?>" class="finance-period-chip <?= $period === 'month' ? 'active' : ''; ?>">Month</a>
            </div>

            <form method="GET" class="finance-custom-filter">
                <input type="hidden" name="period" value="custom">
                <div>
                    <label for="financeFrom">Dari Tanggal</label>
                    <input id="financeFrom" type="date" name="from" value="<?= e($from); ?>" class="form-control">
                </div>
                <div>
                    <label for="financeTo">Sampai Tanggal</label>
                    <input id="financeTo" type="date" name="to" value="<?= e($to); ?>" class="form-control">
                </div>
                <button class="finance-btn primary" type="submit"><i class="bi bi-funnel"></i> Terapkan</button>
            </form>
        </section>

        <section class="finance-grid">
            <div class="finance-card">
                <div class="mini"><i class="bi bi-arrow-down-circle"></i></div>
                <span>Total Pemasukan</span>
                <h3><?= rupiah($income); ?></h3>
                <p>Omzet POS <?= rupiah($omzet); ?> + pemasukan lain <?= rupiah($otherIncome); ?></p>
            </div>
            <div class="finance-card">
                <div class="mini"><i class="bi bi-arrow-up-circle"></i></div>
                <span>Total Pengeluaran</span>
                <h3><?= rupiah($expense); ?></h3>
                <p>Pengeluaran pada periode terpilih</p>
            </div>
            <div class="finance-card">
                <div class="mini"><i class="bi bi-wallet2"></i></div>
                <span>Saldo Bersih</span>
                <h3><?= rupiah($balance); ?></h3>
                <p>Total pemasukan dikurangi pengeluaran</p>
            </div>
            <div class="finance-card">
                <div class="mini"><i class="bi bi-clock-history"></i></div>
                <span>Hutang / Piutang</span>
                <h3><?= rupiah($debt + $receivable); ?></h3>
                <p>Saldo berjalan saat ini</p>
            </div>
        </section>

        <section class="finance-panel">
            <div class="finance-panel-head">
                <div>
                    <h3>Transaksi Keuangan</h3>
                    <p class="text-muted mb-0">Riwayat pemasukan/pengeluaran manual pada <?= e($rangeText); ?>. Omzet POS dihitung otomatis dari transaksi kasir.</p>
                </div>
            </div>

            <div class="finance-table-wrap">
                <table class="finance-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Jenis</th>
                            <th>Judul</th>
                            <th>Kategori</th>
                            <th>Nominal</th>
                            <th>Admin</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$transactions): ?>
                        <tr><td colspan="6" class="finance-empty">Belum ada transaksi keuangan manual pada periode ini.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($transactions as $row): ?>
                        <tr>
                            <td><?= e(date('d/m/Y', strtotime($row['transaction_date']))); ?></td>
                            <td><span class="finance-badge <?= e($row['type']); ?>"><?= $row['type']==='income'?'Pemasukan':'Pengeluaran'; ?></span></td>
                            <td><b><?= e($row['title']); ?></b><br><small class="text-muted"><?= e($row['description'] ?? '-'); ?></small></td>
                            <td><?= e($row['category_name'] ?? '-'); ?></td>
                            <td><b><?= rupiah($row['amount']); ?></b></td>
                            <td><?= e($row['user_name'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
