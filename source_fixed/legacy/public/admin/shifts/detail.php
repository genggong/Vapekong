<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../branches/_helpers.php';

$pageTitle = 'Detail Shift';
$db = Database::connect();
branch_install($db);
shift_ensure_tables($db);

$id = (int)($_GET['id'] ?? 0);
$shift = shift_find($db, $id);
if (!$shift) {
    flash('error', 'Data shift tidak ditemukan.');
    legacy_redirect('admin/shifts/index.php');
}

$calc = shift_calculate($db, $shift);
if ($shift['status'] === 'open') {
    shift_update_totals($db, $shift);
    $shift = shift_find($db, $id);
    $calc = shift_calculate($db, $shift);
}

$end = $shift['closed_at'] ?: now_datetime();
$branchSql = !empty($shift['branch_id']) ? ' AND t.branch_id=?' : '';
$params = [(int)$shift['user_id'], $shift['opened_at'], $end];
if (!empty($shift['branch_id'])) $params[] = (int)$shift['branch_id'];
$stmt = $db->prepare("SELECT t.*, u.name AS cashier_name FROM transactions t LEFT JOIN users u ON u.id=t.cashier_id WHERE t.cashier_id=? AND t.transaction_date BETWEEN ? AND ?{$branchSql} ORDER BY t.id DESC LIMIT 100");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link href="<?= legacy_base_url('assets/css/shifts.css?v=' . time()); ?>" rel="stylesheet">

<main class="admin-main shifts-page">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Detail Shift</h1>
            <p>Ringkasan shift <?= e($shift['shift_code']); ?> dan transaksi kasir.</p>
        </div>
        <div class="topbar-actions">
            <?php if ($shift['status'] === 'open'): ?>
                <a href="<?= legacy_base_url('admin/shifts/close.php?id=' . $shift['id']); ?>" class="shift-btn primary"><i class="bi bi-lock"></i> Tutup Shift</a>
            <?php else: ?>
                <a target="_blank" href="<?= legacy_base_url('admin/shifts/print.php?id=' . $shift['id']); ?>" class="shift-btn primary"><i class="bi bi-printer"></i> Print</a>
            <?php endif; ?>
        </div>
    </header>

    <section class="shift-detail-grid">
        <div class="premium-card shift-summary-card">
            <h3><?= e($shift['shift_code']); ?></h3>
            <p>Kasir: <b><?= e($shift['user_name'] ?? '-'); ?></b> • <?= shift_status_badge($shift['status']); ?></p>
            <div class="shift-summary-list">
                <div><span>Lokasi Shift</span><b><?= e(shift_location_label($shift)); ?></b></div>
                <div><span>Dibuka</span><b><?= e(date('d/m/Y H:i', strtotime($shift['opened_at']))); ?></b></div>
                <div><span>Ditutup</span><b><?= $shift['closed_at'] ? e(date('d/m/Y H:i', strtotime($shift['closed_at']))) : 'Masih aktif'; ?></b></div>
                <div><span>Modal Awal</span><b><?= rupiah($shift['opening_cash']); ?></b></div>
                <div><span>Penjualan Cash</span><b><?= rupiah($calc['cash_sales']); ?></b></div>
                <div><span>Non Cash</span><b><?= rupiah($calc['non_cash_sales']); ?></b></div>
                <div><span>Refund</span><b><?= rupiah($calc['total_refund']); ?></b></div>
                <div class="total"><span>Cash Seharusnya</span><b><?= rupiah($calc['expected_cash']); ?></b></div>
                <div><span>Cash Aktual</span><b><?= rupiah($shift['actual_cash']); ?></b></div>
                <div><span>Selisih</span><b><?= shift_diff_badge($shift['cash_difference']); ?></b></div>
            </div>
        </div>

        <div class="premium-card shift-note-card">
            <h3>Catatan Shift</h3>
            <div class="shift-note-box"><span>Pembukaan</span><p><?= e($shift['opening_note'] ?: '-'); ?></p></div>
            <div class="shift-note-box"><span>Penutupan</span><p><?= e($shift['closing_note'] ?: '-'); ?></p></div>
        </div>
    </section>

    <section class="premium-card mt-4 shift-panel">
        <div class="card-head">
            <div><h3>Transaksi Dalam Shift</h3><p>Transaksi dari kasir ini selama jam shift berjalan.</p></div>
            <span class="badge-soft danger"><?= count($transactions); ?> Transaksi</span>
        </div>
        <div class="table-premium-card dt-premium-wrap">
            <table class="premium-table js-data-table nowrap shift-table" data-title="shift-detail">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Tanggal</th>
                        <th>Metode</th>
                        <th>Total</th>
                        <th>Dibayar</th>
                        <th>Kembali</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $trx): ?>
                        <tr>
                            <td><b><?= e($trx['invoice_number']); ?></b></td>
                            <td><?= e(date('d/m/Y H:i', strtotime($trx['transaction_date'] ?? $trx['created_at']))); ?></td>
                            <td><span class="badge-soft info"><?= e(payment_method_label($trx['payment_method'] ?? '-')); ?></span></td>
                            <td><b><?= rupiah($trx['grand_total']); ?></b></td>
                            <td><?= rupiah($trx['paid_amount']); ?></td>
                            <td><?= rupiah($trx['change_amount']); ?></td>
                            <td><span class="badge-soft success"><?= e($trx['status']); ?></span></td>
                            <td class="text-end table-actions"><a class="btn-icon edit" href="<?= legacy_base_url('admin/transactions/detail.php?id=' . $trx['id']); ?>"><i class="bi bi-eye"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
