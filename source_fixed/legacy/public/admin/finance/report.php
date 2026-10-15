<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$pageTitle = 'Laporan Keuangan';
$db = Database::connect();

function finance_report_table_exists(PDO $db, string $table): bool
{
    if (!in_array($table, ['finance_transactions', 'finance_categories', 'debts', 'receivables'], true)) return false;
    try {
        $db->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

if (!finance_report_table_exists($db, 'finance_transactions')) {
    flash('error', 'Tabel transaksi keuangan belum tersedia.');
    legacy_redirect('admin/finance/install.php');
}

$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');
$from = trim($_GET['from'] ?? $defaultFrom);
$to = trim($_GET['to'] ?? $today);
$type = trim($_GET['type'] ?? 'all');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || strtotime($from) === false) $from = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || strtotime($to) === false) $to = $today;
if ($from > $to) [$from, $to] = [$to, $from];
if (!in_array($type, ['all', 'income', 'expense'], true)) $type = 'all';

$where = ['ft.transaction_date BETWEEN ? AND ?'];
$params = [$from, $to];
if ($type !== 'all') {
    $where[] = 'ft.type = ?';
    $params[] = $type;
}

$stmt = $db->prepare("\n    SELECT ft.*, fc.name AS category_name, u.name AS user_name\n    FROM finance_transactions ft\n    LEFT JOIN finance_categories fc ON fc.id = ft.category_id\n    LEFT JOIN users u ON u.id = ft.user_id\n    WHERE " . implode(' AND ', $where) . "\n    ORDER BY ft.transaction_date DESC, ft.id DESC\n");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$income = 0.0;
$expense = 0.0;
foreach ($rows as $row) {
    if ($row['type'] === 'income') $income += (float)$row['amount'];
    if ($row['type'] === 'expense') $expense += (float)$row['amount'];
}

$query = http_build_query(['from' => $from, 'to' => $to, 'type' => $type]);
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/finance.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/modules/data-exchange.css?v=' . time()); ?>">

<main class="admin-main finance-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Laporan Keuangan</h1>
            <p>Filter pemasukan dan pengeluaran, lalu unduh laporan dalam format Excel atau PDF.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/finance/index.php'); ?>" class="finance-btn soft"><i class="bi bi-arrow-left"></i> Keuangan</a>
        </div>
    </header>

    <section class="finance-panel mt-4">
        <div class="finance-panel-head">
            <div>
                <h3>Filter Laporan</h3>
                <p class="text-muted mb-0">Periode yang dipilih akan digunakan pada tampilan dan file unduhan.</p>
            </div>
        </div>
        <form method="GET" class="row g-3 align-items-end finance-report-filter">
            <div class="col-md-4">
                <label class="fw-bold text-danger-emphasis mb-2">Dari Tanggal</label>
                <input type="date" name="from" value="<?= e($from); ?>" class="form-control rounded-4 py-3">
            </div>
            <div class="col-md-4">
                <label class="fw-bold text-danger-emphasis mb-2">Sampai Tanggal</label>
                <input type="date" name="to" value="<?= e($to); ?>" class="form-control rounded-4 py-3">
            </div>
            <div class="col-md-4">
                <label class="fw-bold text-danger-emphasis mb-2">Jenis Transaksi</label>
                <select name="type" class="form-select rounded-4 py-3">
                    <option value="all" <?= $type === 'all' ? 'selected' : ''; ?>>Semua Transaksi</option>
                    <option value="income" <?= $type === 'income' ? 'selected' : ''; ?>>Pemasukan</option>
                    <option value="expense" <?= $type === 'expense' ? 'selected' : ''; ?>>Pengeluaran</option>
                </select>
            </div>
            <div class="col-12">
                <div class="finance-actions justify-content-end">
                    <button class="finance-btn primary" type="submit"><i class="bi bi-funnel"></i> Terapkan Filter</button>
                    <a class="finance-btn soft" href="<?= legacy_base_url('admin/data-exchange/finance-export.php?format=xlsx&' . $query); ?>"><i class="bi bi-file-earmark-excel"></i> Unduh Excel</a>
                    <a class="finance-btn soft" href="<?= legacy_base_url('admin/data-exchange/finance-export.php?format=pdf&' . $query); ?>"><i class="bi bi-file-earmark-pdf"></i> Unduh PDF</a>
                </div>
            </div>
        </form>
    </section>

    <section class="finance-grid">
        <div class="finance-card"><div class="mini"><i class="bi bi-arrow-down-circle"></i></div><span>Pemasukan</span><h3><?= rupiah($income); ?></h3><p>Periode terpilih</p></div>
        <div class="finance-card"><div class="mini"><i class="bi bi-arrow-up-circle"></i></div><span>Pengeluaran</span><h3><?= rupiah($expense); ?></h3><p>Periode terpilih</p></div>
        <div class="finance-card"><div class="mini"><i class="bi bi-wallet2"></i></div><span>Saldo Bersih</span><h3><?= rupiah($income - $expense); ?></h3><p>Pemasukan dikurangi pengeluaran</p></div>
        <div class="finance-card"><div class="mini"><i class="bi bi-receipt"></i></div><span>Jumlah Transaksi</span><h3><?= count($rows); ?></h3><p><?= e(date('d/m/Y', strtotime($from))); ?> - <?= e(date('d/m/Y', strtotime($to))); ?></p></div>
    </section>

    <section class="finance-panel">
        <div class="finance-panel-head">
            <div><h3>Rincian Transaksi</h3><p class="text-muted mb-0">Data sesuai filter laporan.</p></div>
        </div>
        <div class="finance-table-wrap">
            <table class="finance-table">
                <thead><tr><th>Tanggal</th><th>Jenis</th><th>Judul</th><th>Kategori</th><th>Nominal</th><th>Admin</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="6" class="finance-empty">Tidak ada transaksi pada periode ini.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e(date('d/m/Y', strtotime($row['transaction_date']))); ?></td>
                        <td><span class="finance-badge <?= e($row['type']); ?>"><?= $row['type'] === 'income' ? 'Pemasukan' : 'Pengeluaran'; ?></span></td>
                        <td><b><?= e($row['title']); ?></b><br><small class="text-muted"><?= e($row['description'] ?: '-'); ?></small></td>
                        <td><?= e($row['category_name'] ?: '-'); ?></td>
                        <td><b><?= rupiah($row['amount']); ?></b></td>
                        <td><?= e($row['user_name'] ?: '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
