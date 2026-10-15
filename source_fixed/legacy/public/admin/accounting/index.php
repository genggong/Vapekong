<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/../../../app/Modules/Accounting/Accounting.php';

Auth::requirePermission('finance.view');
$pageTitle = 'Akuntansi';
$db = Database::connect();
branch_install($db);

$today = date('Y-m-d');
$from = accounting_safe_date($_GET['from'] ?? null, date('Y-m-01'));
$to = accounting_safe_date($_GET['to'] ?? null, $today);
if ($from > $to) [$from, $to] = [$to, $from];

$isBranchAdmin = branch_is_admin();
if ($isBranchAdmin) {
    $branchId = max(0, (int)($_GET['branch_id'] ?? 0));
} else {
    $branchId = branch_current_id($db);
}

$report = accounting_build_report($db, $from, $to, $branchId);
$branchId = (int)$report['branch_id'];
$summary = $report['summary'];
$chartRows = accounting_chart_rows($report['daily']);
$query = http_build_query(['from' => $from, 'to' => $to, 'branch_id' => $branchId]);
$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';

$chartPayload = [
    'currency' => 'IDR',
    'trend' => [
        'labels' => array_column($chartRows, 'label'),
        'sales' => array_map('floatval', array_column($chartRows, 'net_sales')),
        'grossProfit' => array_map('floatval', array_column($chartRows, 'gross_profit')),
        'netProfit' => array_map('floatval', array_column($chartRows, 'net_profit')),
        'cashflow' => array_map('floatval', array_column($chartRows, 'net_cashflow')),
    ],
    'branches' => [
        'labels' => array_column($report['branches'], 'branch_name'),
        'sales' => array_map('floatval', array_column($report['branches'], 'net_sales')),
        'grossProfit' => array_map('floatval', array_column($report['branches'], 'gross_profit')),
        'netProfit' => array_map('floatval', array_column($report['branches'], 'net_profit')),
    ],
    'payments' => [
        'labels' => array_column($report['payments'], 'label'),
        'values' => array_map('floatval', array_column($report['payments'], 'amount')),
    ],
    'expenses' => [
        'labels' => array_column($report['expense_categories'], 'label'),
        'values' => array_map('floatval', array_column($report['expense_categories'], 'amount')),
    ],
    'autoPrint' => $autoPrint,
];

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/accounting.css?v=' . time()); ?>">

<main class="admin-main accounting-wrap">
    <header class="admin-topbar page-topbar accounting-no-print">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Akuntansi</h1>
            <p>Laporan laba rugi, arus kas, dan performa setiap cabang berdasarkan data transaksi asli.</p>
        </div>
        <div class="topbar-actions">
            <button type="button" class="accounting-btn soft" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
            <a class="accounting-btn soft" href="<?= legacy_base_url('admin/accounting/export.php?format=xlsx&' . $query); ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
            <a class="accounting-btn primary" href="<?= legacy_base_url('admin/accounting/export.php?format=pdf&' . $query); ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
        </div>
    </header>

    <section class="accounting-report-title">
        <div>
            <span class="accounting-kicker">Laporan Manajemen</span>
            <h2><?= e(setting('store_name', 'KasirPro UMKM')); ?></h2>
            <p><?= e($report['branch_name']); ?> &bull; <?= e(date('d/m/Y', strtotime($from))); ?> - <?= e(date('d/m/Y', strtotime($to))); ?></p>
        </div>
        <div class="accounting-report-stamp">
            <small>Dibuat</small>
            <strong><?= e(date('d/m/Y H:i')); ?></strong>
        </div>
    </section>

    <section class="accounting-filter accounting-no-print">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label>Dari Tanggal</label>
                <input type="date" name="from" value="<?= e($from); ?>" class="form-control">
            </div>
            <div class="col-md-3">
                <label>Sampai Tanggal</label>
                <input type="date" name="to" value="<?= e($to); ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label>Cabang</label>
                <?php if ($isBranchAdmin): ?>
                    <select name="branch_id" class="form-select">
                        <option value="0" <?= $branchId === 0 ? 'selected' : ''; ?>>Semua Cabang</option>
                        <?php foreach ($report['all_branches'] as $branch): ?>
                            <option value="<?= (int)$branch['id']; ?>" <?= $branchId === (int)$branch['id'] ? 'selected' : ''; ?>>
                                <?= e($branch['name']); ?><?= empty($branch['is_active']) ? ' (Nonaktif)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="branch_id" value="<?= $branchId; ?>">
                    <input type="text" class="form-control" value="<?= e($report['branch_name']); ?>" readonly>
                <?php endif; ?>
            </div>
            <div class="col-md-2 d-grid">
                <button class="accounting-btn primary" type="submit"><i class="bi bi-funnel"></i> Tampilkan</button>
            </div>
        </form>
    </section>

    <section class="accounting-basis">
        <i class="bi bi-info-circle-fill"></i>
        <div>
            <strong>Dasar perhitungan laporan</strong>
            <p>Penjualan diambil otomatis dari POS, retur mengurangi penjualan dan HPP, HPP memakai harga beli pada item transaksi, sedangkan biaya operasional memakai menu Keuangan. Pembelian stok tidak dihitung dua kali sebagai beban karena nilainya masuk melalui HPP saat barang terjual.</p>
        </div>
    </section>

    <section class="accounting-kpi-grid">
        <article class="accounting-kpi">
            <div class="icon"><i class="bi bi-cart-check"></i></div>
            <span>Penjualan Bersih</span>
            <h3><?= rupiah($summary['net_sales']); ?></h3>
            <small>Bruto <?= rupiah($summary['gross_sales']); ?> - retur <?= rupiah($summary['refunds']); ?></small>
        </article>
        <article class="accounting-kpi">
            <div class="icon"><i class="bi bi-boxes"></i></div>
            <span>Harga Pokok (HPP)</span>
            <h3><?= rupiah($summary['cogs']); ?></h3>
            <small>Biaya barang yang benar-benar terjual</small>
        </article>
        <article class="accounting-kpi">
            <div class="icon"><i class="bi bi-graph-up-arrow"></i></div>
            <span>Laba Kotor</span>
            <h3><?= rupiah($summary['gross_profit']); ?></h3>
            <small>Penjualan bersih dikurangi HPP</small>
        </article>
        <article class="accounting-kpi featured">
            <div class="icon"><i class="bi bi-piggy-bank"></i></div>
            <span>Laba Bersih</span>
            <h3><?= rupiah($summary['net_profit']); ?></h3>
            <small>Margin <?= number_format((float)$summary['margin'], 1, ',', '.'); ?>%</small>
        </article>
        <article class="accounting-kpi">
            <div class="icon"><i class="bi bi-arrow-down-left-circle"></i></div>
            <span>Kas Masuk</span>
            <h3><?= rupiah($summary['cash_in']); ?></h3>
            <small>Penjualan dibayar, pelunasan, pemasukan lain</small>
        </article>
        <article class="accounting-kpi">
            <div class="icon"><i class="bi bi-arrow-up-right-circle"></i></div>
            <span>Kas Keluar</span>
            <h3><?= rupiah($summary['cash_out']); ?></h3>
            <small>Pembelian dibayar, retur, operasional</small>
        </article>
        <article class="accounting-kpi">
            <div class="icon"><i class="bi bi-wallet2"></i></div>
            <span>Arus Kas Bersih</span>
            <h3><?= rupiah($summary['net_cashflow']); ?></h3>
            <small>Kas masuk dikurangi kas keluar</small>
        </article>
        <article class="accounting-kpi">
            <div class="icon"><i class="bi bi-receipt"></i></div>
            <span>Transaksi / Rata-rata</span>
            <h3><?= number_format((int)$summary['transaction_count'], 0, ',', '.'); ?></h3>
            <small>Rata-rata <?= rupiah($summary['average_transaction']); ?></small>
        </article>
    </section>

    <section class="accounting-snapshot-grid">
        <article><span>Piutang Berjalan</span><strong><?= rupiah($summary['receivables']); ?></strong><small>Saldo pelanggan yang belum lunas saat ini</small></article>
        <article><span>Hutang Berjalan</span><strong><?= rupiah($summary['payables']); ?></strong><small>Saldo hutang yang belum lunas saat ini</small></article>
        <article><span>Nilai Persediaan</span><strong><?= rupiah($summary['inventory_value']); ?></strong><small>Stok saat ini x harga beli produk</small></article>
        <article><span>Pemasukan Lain</span><strong><?= rupiah($summary['other_income']); ?></strong><small>Di luar penjualan POS</small></article>
    </section>

    <section class="accounting-chart-grid">
        <article class="accounting-panel accounting-chart-wide">
            <div class="accounting-panel-head"><div><h3>Tren Penjualan & Laba</h3><p>Pergerakan nilai bersih pada periode terpilih.</p></div></div>
            <div class="accounting-chart-box"><canvas id="accountingTrendChart"></canvas></div>
        </article>
        <article class="accounting-panel">
            <div class="accounting-panel-head"><div><h3>Performa Cabang</h3><p>Perbandingan penjualan dan laba.</p></div></div>
            <div class="accounting-chart-box"><canvas id="accountingBranchChart"></canvas></div>
        </article>
        <article class="accounting-panel">
            <div class="accounting-panel-head"><div><h3>Metode Pembayaran</h3><p>Komposisi kas masuk dari pelanggan.</p></div></div>
            <div class="accounting-chart-box compact"><canvas id="accountingPaymentChart"></canvas></div>
        </article>
        <article class="accounting-panel">
            <div class="accounting-panel-head"><div><h3>Beban Operasional</h3><p>Komposisi pengeluaran selain pembelian stok.</p></div></div>
            <div class="accounting-chart-box compact"><canvas id="accountingExpenseChart"></canvas></div>
        </article>
    </section>

    <section class="accounting-panel">
        <div class="accounting-panel-head">
            <div><h3>Ringkasan Per Cabang</h3><p>Setiap cabang dapat dicetak atau diekspor terpisah untuk bahan meeting.</p></div>
        </div>
        <div class="accounting-table-wrap">
            <table class="accounting-table">
                <thead>
                    <tr>
                        <th>Cabang</th><th>Penjualan Bersih</th><th>Retur</th><th>HPP</th><th>Laba Kotor</th><th>Beban</th><th>Laba Bersih</th><th>Arus Kas</th><th>Margin</th><th class="accounting-no-print">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($report['branches'] as $branch):
                    $branchQuery = http_build_query(['from' => $from, 'to' => $to, 'branch_id' => (int)$branch['branch_id']]);
                ?>
                    <tr>
                        <td><b><?= e($branch['branch_name']); ?></b><br><small><?= e($branch['branch_code']); ?><?= empty($branch['is_active']) ? ' - Nonaktif' : ''; ?></small></td>
                        <td><?= rupiah($branch['net_sales']); ?></td>
                        <td><?= rupiah($branch['refunds']); ?></td>
                        <td><?= rupiah($branch['cogs']); ?></td>
                        <td><?= rupiah($branch['gross_profit']); ?></td>
                        <td><?= rupiah($branch['operating_expense']); ?></td>
                        <td><b><?= rupiah($branch['net_profit']); ?></b></td>
                        <td><b><?= rupiah($branch['net_cashflow']); ?></b></td>
                        <td><?= number_format((float)$branch['margin'], 1, ',', '.'); ?>%</td>
                        <td class="accounting-no-print">
                            <div class="accounting-row-actions">
                                <a target="_blank" title="Print cabang" href="<?= legacy_base_url('admin/accounting/index.php?' . $branchQuery . '&print=1'); ?>"><i class="bi bi-printer"></i></a>
                                <a title="Excel cabang" href="<?= legacy_base_url('admin/accounting/export.php?format=xlsx&' . $branchQuery); ?>"><i class="bi bi-file-earmark-excel"></i></a>
                                <a title="PDF cabang" href="<?= legacy_base_url('admin/accounting/export.php?format=pdf&' . $branchQuery); ?>"><i class="bi bi-file-earmark-pdf"></i></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$report['branches']): ?><tr><td colspan="10" class="accounting-empty">Belum ada cabang untuk ditampilkan.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="accounting-panel">
        <div class="accounting-panel-head">
            <div><h3>Rekap Harian</h3><p>Rincian angka utama agar perubahan laporan mudah ditelusuri.</p></div>
        </div>
        <div class="accounting-table-wrap">
            <table class="accounting-table accounting-daily-table">
                <thead><tr><th>Tanggal</th><th>Transaksi</th><th>Penjualan</th><th>Retur</th><th>HPP</th><th>Laba Kotor</th><th>Beban</th><th>Laba Bersih</th><th>Arus Kas</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($report['daily']) as $day): ?>
                    <tr>
                        <td><?= e(date('d/m/Y', strtotime($day['date']))); ?></td>
                        <td><?= number_format((int)$day['transaction_count'], 0, ',', '.'); ?></td>
                        <td><?= rupiah($day['net_sales']); ?></td>
                        <td><?= rupiah($day['refunds']); ?></td>
                        <td><?= rupiah($day['cogs']); ?></td>
                        <td><?= rupiah($day['gross_profit']); ?></td>
                        <td><?= rupiah($day['operating_expense']); ?></td>
                        <td><b><?= rupiah($day['net_profit']); ?></b></td>
                        <td><b><?= rupiah($day['net_cashflow']); ?></b></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer class="accounting-print-footer">
        <span>KasirPro UMKM - Laporan Akuntansi</span>
        <span><?= e($report['branch_name']); ?> | <?= e(date('d/m/Y', strtotime($from))); ?> - <?= e(date('d/m/Y', strtotime($to))); ?></span>
    </footer>
</main>

<script type="application/json" id="accountingChartData"><?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script src="<?= legacy_base_url('assets/js/accounting.js?v=' . time()); ?>"></script>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
