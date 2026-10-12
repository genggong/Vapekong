<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/../branches/_helpers.php';
$pageTitle = 'Transaksi';
$db = Database::connect();
branch_install($db);
$currentBranch = branch_current($db);
$currentBranchId = (int) $currentBranch['id'];
$branchOptions = branch_is_admin() ? branch_all($db, true) : [$currentBranch];
$canDeleteTransaction = in_array(Auth::role(), ['super_admin', 'owner'], true);

// Filter tanggal Data Transaksi dibuat terpisah dari filter Laporan Omzet Harian.
// Nilai kosong berarti tampilkan seluruh periode agar perilaku lama tetap aman.
$normalizeDate = static function ($value, string $fallback = ''): string {
    $value = trim((string) $value);
    if ($value === '') return $fallback;
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : $fallback;
};

$trxFrom = $normalizeDate($_GET['date_from'] ?? '');
$trxTo = $normalizeDate($_GET['date_to'] ?? '');
if ($trxFrom !== '' && $trxTo !== '' && $trxFrom > $trxTo) {
    [$trxFrom, $trxTo] = [$trxTo, $trxFrom];
}

$transactionWhere = ['t.branch_id = ?'];
$transactionParams = [$currentBranchId];
if ($trxFrom !== '') {
    $transactionWhere[] = 'DATE(COALESCE(t.transaction_date, t.created_at)) >= ?';
    $transactionParams[] = $trxFrom;
}
if ($trxTo !== '') {
    $transactionWhere[] = 'DATE(COALESCE(t.transaction_date, t.created_at)) <= ?';
    $transactionParams[] = $trxTo;
}

$stmtTransactions = $db->prepare("SELECT t.*, u.name AS cashier_name, b.name AS branch_name, c.name AS customer_name
    FROM transactions t
    LEFT JOIN users u ON u.id = t.cashier_id
    LEFT JOIN branches b ON b.id = t.branch_id
    LEFT JOIN customers c ON c.id = t.customer_id
    WHERE " . implode(' AND ', $transactionWhere) . "
    ORDER BY COALESCE(t.transaction_date, t.created_at) DESC, t.id DESC");
$stmtTransactions->execute($transactionParams);
$transactions = $stmtTransactions->fetchAll();

$reportFrom = $normalizeDate($_GET['report_from'] ?? date('Y-m-01'), date('Y-m-01'));
$reportTo = $normalizeDate($_GET['report_to'] ?? date('Y-m-d'), date('Y-m-d'));
if ($reportFrom > $reportTo) { [$reportFrom, $reportTo] = [$reportTo, $reportFrom]; }

$stmtDaily = $db->prepare("SELECT DATE(COALESCE(t.transaction_date, t.created_at)) AS sale_day, COUNT(*) AS trx_count, COALESCE(SUM(t.grand_total),0) AS omzet
    FROM transactions t
    WHERE t.branch_id=? AND DATE(COALESCE(t.transaction_date, t.created_at)) BETWEEN ? AND ?
    GROUP BY DATE(COALESCE(t.transaction_date, t.created_at))
    ORDER BY sale_day DESC");
$stmtDaily->execute([$currentBranchId, $reportFrom, $reportTo]);
$dailyOmzet = $stmtDaily->fetchAll();

$stmtReportTrx = $db->prepare("SELECT t.*, u.name AS cashier_name
    FROM transactions t
    LEFT JOIN users u ON u.id=t.cashier_id
    WHERE t.branch_id=? AND DATE(COALESCE(t.transaction_date, t.created_at)) BETWEEN ? AND ?
    ORDER BY COALESCE(t.transaction_date, t.created_at) DESC, t.id DESC");
$stmtReportTrx->execute([$currentBranchId, $reportFrom, $reportTo]);
$reportTransactions = $stmtReportTrx->fetchAll();
$reportGrouped = [];
foreach ($reportTransactions as $reportTrx) {
    $reportDate = $reportTrx['transaction_date'] ?? $reportTrx['created_at'] ?? null;
    if (!$reportDate) continue;
    $dayKey = date('Y-m-d', strtotime($reportDate));
    $reportGrouped[$dayKey][] = $reportTrx;
}
$periodOmzet = array_sum(array_map(static fn($row) => (float)$row['omzet'], $dailyOmzet));
$periodTrxCount = array_sum(array_map(static fn($row) => (int)$row['trx_count'], $dailyOmzet));

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
.transaction-filter-bar{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:0 0 16px;padding:14px 16px;border:1px solid rgba(153,27,27,.12);background:linear-gradient(180deg,#fff,#fffafa);border-radius:18px}
.transaction-filter-copy{display:flex;gap:10px;align-items:flex-start;min-width:0}.transaction-filter-copy i{color:#991b1b;font-size:21px}.transaction-filter-copy b{display:block;color:#111827}.transaction-filter-copy small{display:block;color:#64748b;margin-top:2px}
.transaction-branch-filter{display:grid;grid-template-columns:minmax(190px,1.35fr) repeat(2,minmax(145px,.8fr)) auto;align-items:end;gap:9px;flex:1;min-width:min(100%,650px)}.transaction-filter-field{display:grid;gap:5px;min-width:0}.transaction-filter-field label{font-size:11px;font-weight:900;color:#7f1d1d}.transaction-filter-field select,.transaction-filter-field input{width:100%;min-width:0;border:1px solid #fecaca;border-radius:14px;background:#fff;padding:10px 12px;font-weight:800;color:#111827;outline:0;min-height:44px}.transaction-filter-field select{padding-right:34px}.transaction-filter-field select:focus,.transaction-filter-field input:focus{border-color:#a855f7;box-shadow:0 0 0 4px rgba(168,85,247,.1)}.transaction-filter-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.transaction-filter-actions .btn-soft{min-height:44px;display:inline-flex;align-items:center;justify-content:center}.transaction-filter-active{display:inline-flex;align-items:center;gap:6px;margin-top:6px;color:#7f1d1d;font-size:11px;font-weight:900}
.transaction-delete-form{display:inline-flex;margin:0}.transaction-delete-btn{border:0}.transaction-delete-btn:disabled,.transaction-delete-locked{opacity:.45;cursor:not-allowed!important;filter:grayscale(.25)}
.transaction-table .trx-invoice b{display:block}.transaction-table .trx-invoice small{display:block;color:#64748b;margin-top:3px;line-height:1.35}.transaction-table .trx-meta b{display:block}.transaction-table .trx-meta small{display:block;color:#64748b;margin-top:2px}.transaction-table .trx-total{font-weight:900;white-space:nowrap}
@media(max-width:720px){
  .transaction-filter-bar{padding:12px;border-radius:16px;align-items:stretch}.transaction-filter-copy{width:100%}.transaction-branch-filter{width:100%;min-width:0;grid-template-columns:1fr 1fr}.transaction-filter-field:first-child{grid-column:1 / -1}.transaction-filter-field select,.transaction-filter-field input{font-size:14px}.transaction-filter-actions{grid-column:1 / -1}.transaction-filter-actions .btn-soft{flex:1}
  .transaction-datatable-card .dataTables_length,.transaction-datatable-card .dataTables_filter{width:100%;text-align:left!important;margin:0!important}.transaction-datatable-card .dataTables_length label,.transaction-datatable-card .dataTables_filter label{width:100%;display:flex;align-items:center;gap:8px;flex-wrap:nowrap}.transaction-datatable-card .dataTables_filter input{width:100%!important;min-width:0!important;margin-left:0!important}.transaction-datatable-card .dataTables_length select{min-width:82px}
  .transaction-table td,.transaction-table th{padding-left:10px!important;padding-right:10px!important}.transaction-table .trx-action-buttons{gap:6px!important}
}
@media(max-width:640px){
  .transaction-datatable-card{background:transparent!important;border:0!important;box-shadow:none!important;padding:0!important;overflow:visible!important}
  .transaction-datatable-card .dataTables_scroll,.transaction-datatable-card .dataTables_scrollBody{overflow:visible!important;border:0!important;background:transparent!important}
  .transaction-datatable-card .dataTables_scrollHead{display:none!important}
  .transaction-datatable-card table.transaction-table,.transaction-datatable-card .dataTables_scrollBody table{width:100%!important;min-width:0!important;max-width:100%!important;table-layout:auto!important;border-collapse:separate!important;border-spacing:0!important}
  .transaction-table tbody{display:grid!important;gap:11px!important;width:100%!important}
  .transaction-table tbody tr{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:0 10px!important;width:100%!important;background:#fff!important;border:1px solid rgba(153,27,27,.12)!important;border-radius:18px!important;padding:10px!important;box-shadow:0 10px 30px rgba(127,29,29,.06)!important}
  .transaction-table tbody td{display:block!important;width:auto!important;min-width:0!important;max-width:none!important;white-space:normal!important;text-align:left!important;border:0!important;border-bottom:1px dashed #f1e4e4!important;padding:9px 7px!important;font-size:12px!important}
  .transaction-table tbody td::before{content:attr(data-label);display:block;margin-bottom:4px;color:#991b1b;font-size:10px;font-weight:950;text-transform:uppercase;letter-spacing:.04em}
  .transaction-table tbody td.trx-mobile-invoice{grid-column:1 / -1!important;padding-top:4px!important}
  .transaction-table tbody td.trx-mobile-actions{grid-column:1 / -1!important;border-bottom:0!important;padding:10px 5px 2px!important}
  .transaction-table tbody td.trx-mobile-actions::before{display:none!important}
  .transaction-table .trx-invoice b{font-size:14px!important;overflow-wrap:anywhere}.transaction-table .trx-invoice small{font-size:11px!important}
  .transaction-table .trx-action-buttons{display:flex!important;justify-content:flex-end!important;flex-wrap:wrap!important;width:100%!important}
  .transaction-table .trx-action-buttons .btn-icon{width:40px!important;height:40px!important;min-width:40px!important}
}
@media(max-width:460px){.transaction-branch-filter{grid-template-columns:1fr}.transaction-filter-field:first-child,.transaction-filter-actions{grid-column:auto}.transaction-filter-actions{display:grid;grid-template-columns:1fr 1fr}}

.daily-report-card{margin:18px 0;padding:18px;border:1px solid rgba(153,27,27,.12);border-radius:22px;background:linear-gradient(180deg,#fff,#fffafa)}
.daily-report-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-end;flex-wrap:wrap}.daily-report-head h3{margin:0;color:#111827;font-weight:900}.daily-report-head p{margin:4px 0 0;color:#64748b}
.daily-report-filter{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}.daily-report-filter label{font-size:11px;font-weight:900;color:#7f1d1d;display:grid;gap:4px}.daily-report-filter input{border:1px solid #fecaca;border-radius:12px;padding:9px 11px;background:#fff}
.daily-report-actions{display:flex;gap:8px;flex-wrap:wrap}.daily-summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:14px 0}.daily-summary-box{padding:13px;border:1px solid #fee2e2;border-radius:16px;background:#fff}.daily-summary-box span{display:block;font-size:11px;color:#64748b;font-weight:800}.daily-summary-box b{display:block;margin-top:3px;font-size:18px;color:#7f1d1d}
.daily-day{margin-top:12px;border:1px solid #f3dddd;border-radius:18px;overflow:hidden;background:#fff}.daily-day-head{display:flex;justify-content:space-between;gap:12px;padding:11px 13px;background:#fff5f5;color:#7f1d1d;font-weight:900}.daily-day-rows{display:grid}.daily-trx-row{display:grid;grid-template-columns:90px minmax(0,1.2fr) minmax(0,.8fr) minmax(0,.8fr) auto;gap:10px;padding:11px 13px;border-top:1px dashed #f3dddd;align-items:center;font-size:12px}.daily-trx-row .daily-total{font-weight:900;white-space:nowrap;color:#111827}
@media(max-width:720px){.daily-summary-grid{grid-template-columns:1fr}.daily-report-filter{width:100%}.daily-report-filter label{flex:1;min-width:130px}.daily-report-filter input{width:100%}.daily-trx-row{grid-template-columns:70px 1fr auto}.daily-trx-row .daily-hide-mobile{display:none}.daily-day-head{font-size:12px}}

</style>

<main class="admin-main">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Transaksi</h1>
            <p>Riwayat penjualan cabang aktif: <b><?= e($currentBranch['name'] ?? 'Cabang'); ?></b>.</p>
        </div>
        <div class="topbar-actions">
            <a href="<?= legacy_base_url('admin/returns/create.php'); ?>" class="btn-soft"><i class="bi bi-arrow-counterclockwise"></i> Buat Retur</a>
            <a href="<?= legacy_base_url('admin/pos/index.php'); ?>" class="btn-premium"><i class="bi bi-cart-plus"></i> Buka POS</a>
        </div>
    </header>

    <section class="daily-report-card">
        <div class="daily-report-head">
            <div>
                <h3>Laporan Omzet Harian</h3>
                <p>Transaksi dikelompokkan dan diberi jarak per hari. Export tidak menampilkan kode produk atau barcode.</p>
            </div>
            <form method="GET" class="daily-report-filter">
                <input type="hidden" name="branch_id" value="<?= e($currentBranchId); ?>">
                <?php if ($trxFrom !== ''): ?><input type="hidden" name="date_from" value="<?= e($trxFrom); ?>"><?php endif; ?>
                <?php if ($trxTo !== ''): ?><input type="hidden" name="date_to" value="<?= e($trxTo); ?>"><?php endif; ?>
                <label>Dari<input type="date" name="report_from" value="<?= e($reportFrom); ?>"></label>
                <label>Sampai<input type="date" name="report_to" value="<?= e($reportTo); ?>"></label>
                <button class="btn-soft" type="submit"><i class="bi bi-funnel"></i> Tampilkan</button>
            </form>
        </div>
        <div class="daily-summary-grid">
            <div class="daily-summary-box"><span>Omzet Periode</span><b><?= rupiah($periodOmzet); ?></b></div>
            <div class="daily-summary-box"><span>Total Transaksi</span><b><?= e($periodTrxCount); ?></b></div>
            <div class="daily-summary-box"><span>Hari Penjualan</span><b><?= e(count($dailyOmzet)); ?></b></div>
        </div>
        <div class="daily-report-actions">
            <a class="btn-soft" target="_blank" href="<?= legacy_base_url('admin/transactions/report.php?format=pdf&from=' . urlencode($reportFrom) . '&to=' . urlencode($reportTo)); ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
            <a class="btn-soft" href="<?= legacy_base_url('admin/transactions/report.php?format=xlsx&from=' . urlencode($reportFrom) . '&to=' . urlencode($reportTo)); ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        </div>
        <?php foreach ($reportGrouped as $day => $dayRows): ?>
            <?php $dayOmzet = array_sum(array_map(static fn($x) => (float)($x['grand_total'] ?? 0), $dayRows)); ?>
            <div class="daily-day">
                <div class="daily-day-head">
                    <span><?= e(date('d/m/Y', strtotime($day))); ?> • <?= e(count($dayRows)); ?> transaksi</span>
                    <span><?= rupiah($dayOmzet); ?></span>
                </div>
                <div class="daily-day-rows">
                    <?php foreach ($dayRows as $dayTrx): ?>
                        <div class="daily-trx-row">
                            <span><?= e(date('H:i', strtotime($dayTrx['transaction_date'] ?? $dayTrx['created_at']))); ?></span>
                            <b><?= e($dayTrx['invoice_number']); ?></b>
                            <span class="daily-hide-mobile"><?= e($dayTrx['cashier_name'] ?? '-'); ?></span>
                            <span class="daily-hide-mobile"><?= e(payment_method_label($dayTrx['payment_method'] ?? '-')); ?></span>
                            <span class="daily-total"><?= rupiah($dayTrx['grand_total'] ?? 0); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$reportGrouped): ?><div class="text-muted mt-3">Tidak ada transaksi pada periode ini.</div><?php endif; ?>
    </section>

    <section class="premium-card mt-4">
        <div class="card-head">
            <div>
                <h3>Data Transaksi</h3>
                <p>Kelola riwayat transaksi, detail invoice, cetak struk, dan hapus transaksi oleh Admin/Owner.</p>
            </div>
            <span class="badge-soft danger"><?= count($transactions); ?> Transaksi</span>
        </div>

        <div class="transaction-filter-bar">
            <div class="transaction-filter-copy">
                <i class="bi bi-funnel-fill"></i>
                <div>
                    <b>Filter Data Transaksi</b>
                    <small>Filter berdasarkan cabang dan rentang tanggal transaksi. Data terbaru selalu ditampilkan lebih dulu.</small>
                    <?php if ($trxFrom !== '' || $trxTo !== ''): ?>
                        <span class="transaction-filter-active"><i class="bi bi-calendar-check"></i> Periode: <?= e($trxFrom !== '' ? date('d/m/Y', strtotime($trxFrom)) : 'awal'); ?> – <?= e($trxTo !== '' ? date('d/m/Y', strtotime($trxTo)) : 'sekarang'); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <form method="GET" class="transaction-branch-filter" id="transactionBranchFilter">
                <input type="hidden" name="report_from" value="<?= e($reportFrom); ?>">
                <input type="hidden" name="report_to" value="<?= e($reportTo); ?>">
                <div class="transaction-filter-field">
                    <label for="transactionBranchSelect">Cabang</label>
                    <select name="branch_id" id="transactionBranchSelect" <?= branch_is_admin() ? '' : 'disabled'; ?>>
                        <?php foreach ($branchOptions as $branch): ?>
                            <option value="<?= e($branch['id']); ?>" <?= (int)$branch['id'] === $currentBranchId ? 'selected' : ''; ?>><?= e($branch['name']); ?><?= !empty($branch['code']) ? ' • ' . e($branch['code']) : ''; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!branch_is_admin()): ?><input type="hidden" name="branch_id" value="<?= e($currentBranchId); ?>"><?php endif; ?>
                </div>
                <div class="transaction-filter-field">
                    <label for="transactionDateFrom">Dari Tanggal</label>
                    <input type="date" name="date_from" id="transactionDateFrom" value="<?= e($trxFrom); ?>">
                </div>
                <div class="transaction-filter-field">
                    <label for="transactionDateTo">Sampai Tanggal</label>
                    <input type="date" name="date_to" id="transactionDateTo" value="<?= e($trxTo); ?>">
                </div>
                <div class="transaction-filter-actions">
                    <button class="btn-soft" type="submit"><i class="bi bi-funnel"></i> Terapkan</button>
                    <a class="btn-soft" href="<?= legacy_base_url('admin/transactions/index.php?branch_id=' . $currentBranchId . '&report_from=' . urlencode($reportFrom) . '&report_to=' . urlencode($reportTo)); ?>"><i class="bi bi-x-circle"></i> Reset</a>
                </div>
            </form>
        </div>

        <div class="table-toolbar-note">
            <div class="dt-hint-card">
                <div class="dt-hint-icon"><i class="bi bi-receipt-cutoff"></i></div>
                <div class="dt-hint-content">
                    <span class="dt-hint-title">Pencarian Transaksi</span>
                    <p class="dt-hint-text">Cari invoice, tipe customer/member, nama member, kasir, metode pembayaran, atau total belanja. Status tidak lagi ditampilkan sebagai kolom agar tabel lebih responsif.</p>
                </div>
            </div>
        </div>

        <div class="table-premium-card dt-premium-wrap transaction-datatable-card">
            <table class="premium-table js-data-table transaction-table" data-title="transaksi" data-table-width="840" data-mobile-cards="true">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Tanggal</th>
                        <th>Kasir</th>
                        <th>Metode</th>
                        <th>Total</th>
                        <th class="text-end no-sort">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $trx): ?>
                        <?php
                            $trxDate = $trx['transaction_date'] ?? $trx['created_at'] ?? null;
                            $isMember = !empty($trx['customer_id']);
                            $customerName = trim((string) ($trx['customer_name'] ?? ''));
                            $customerLabel = $isMember
                                ? 'Member: ' . ($customerName !== '' ? $customerName : 'Pelanggan #' . (int) $trx['customer_id'])
                                : 'Umum';
                            $trxPaymentMethod = strtolower((string)($trx['payment_method'] ?? ''));
                            $trxStatus = strtolower((string)($trx['status'] ?? ''));
                            $deleteProtectedQris = $trxPaymentMethod === 'qris_pakasir'
                                && !in_array($trxStatus, ['failed','cancelled','canceled','expired','refunded'], true);
                        ?>
                        <tr>
                            <td class="trx-mobile-invoice" data-label="Invoice" data-order="<?= e((int)$trx['id']); ?>">
                                <div class="trx-invoice">
                                    <b><?= e($trx['invoice_number']); ?></b>
                                    <small><?= e($customerLabel); ?> • ID #<?= e($trx['id']); ?></small>
                                </div>
                            </td>
                            <td data-label="Tanggal" data-order="<?= e($trxDate ? strtotime($trxDate) : 0); ?>">
                                <div class="trx-meta">
                                    <b><?= e($trxDate ? date('d/m/Y', strtotime($trxDate)) : '-'); ?></b>
                                    <small><?= e($trxDate ? date('H:i', strtotime($trxDate)) : '-'); ?></small>
                                </div>
                            </td>
                            <td data-label="Kasir"><?= e($trx['cashier_name'] ?? '-'); ?></td>
                            <td data-label="Metode"><span class="badge-soft info"><?= e(payment_method_label($trx['payment_method'] ?? '-')); ?></span></td>
                            <td data-label="Total"><span class="trx-total"><?= rupiah($trx['grand_total'] ?? 0); ?></span></td>
                            <td class="text-end trx-mobile-actions" data-label="Aksi">
                                <div class="trx-action-buttons table-actions">
                                    <a class="btn-icon edit" href="<?= legacy_base_url('admin/transactions/detail.php?id=' . $trx['id']); ?>" title="Detail"><i class="bi bi-eye"></i></a>
                                    <a class="btn-icon" href="<?= legacy_base_url('admin/transactions/print.php?id=' . $trx['id']); ?>" target="_blank" title="Cetak Struk"><i class="bi bi-printer"></i></a>
                                    <a class="btn-icon" href="<?= legacy_base_url('admin/returns/create.php?transaction_id=' . $trx['id']); ?>" title="Retur"><i class="bi bi-arrow-counterclockwise"></i></a>
                                    <?php if ($canDeleteTransaction): ?>
                                        <?php if ($deleteProtectedQris): ?>
                                            <button type="button" class="btn-icon transaction-delete-locked" disabled title="QRIS Otomatis pending/sukses harus dibatalkan atau direfund di Pakasir terlebih dahulu" aria-label="Transaksi QRIS Otomatis terlindungi"><i class="bi bi-shield-lock"></i></button>
                                        <?php else: ?>
                                            <form method="POST" action="<?= legacy_base_url('admin/transactions/delete.php'); ?>" class="transaction-delete-form" data-invoice="<?= e($trx['invoice_number']); ?>">
                                                <?= CSRF::input(); ?>
                                                <input type="hidden" name="id" value="<?= e($trx['id']); ?>">
                                                <button type="button" class="btn-icon delete transaction-delete-btn" title="Hapus Transaksi" aria-label="Hapus transaksi <?= e($trx['invoice_number']); ?>"><i class="bi bi-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
(function(){
    'use strict';
    document.addEventListener('click', function(event){
        var btn = event.target.closest('.transaction-delete-btn');
        if (!btn) return;
        event.preventDefault();
        var form = btn.closest('.transaction-delete-form');
        if (!form) return;
        var invoice = form.dataset.invoice || 'transaksi ini';
        var submitDelete = function(){ form.submit(); };

        if (!window.Swal) {
            if (confirm('Hapus ' + invoice + '? Stok penjualan akan dikembalikan. Pembayaran QRIS eksternal tidak otomatis direfund.')) submitDelete();
            return;
        }

        Swal.fire({
            icon: 'warning',
            title: 'Hapus transaksi?',
            html: '<b>' + String(invoice).replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];}) + '</b><br><br>Stok penjualan akan dikembalikan dan catatan lokal terkait transaksi akan dibersihkan.<br><small style="display:block;margin-top:8px;color:#64748b">Pembayaran QRIS eksternal tidak otomatis direfund. Transaksi yang sudah punya retur atau pembayaran piutang akan ditolak demi keamanan data.</small>',
            showCancelButton: true,
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#dc2626',
            reverseButtons: true
        }).then(function(result){ if (result.isConfirmed) submitDelete(); });
    });
})();
</script>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
