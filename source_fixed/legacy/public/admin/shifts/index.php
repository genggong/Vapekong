<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../branches/_helpers.php';

$pageTitle = 'Shift Kasir';
$db = Database::connect();
branch_install($db);
shift_ensure_tables($db);

$userId = (int)Auth::id();
$activeShift = shift_active($db, $userId);

$today = date('Y-m-d');
$summary = [
    'open' => (int)($db->query("SELECT COUNT(*) total FROM cashier_shifts WHERE status='open'")->fetch()['total'] ?? 0),
    'closed_today' => 0,
    'cash_today' => 0,
    'diff_today' => 0,
];

$stmt = $db->prepare("SELECT COUNT(*) total, COALESCE(SUM(cash_sales),0) cash_total, COALESCE(SUM(cash_difference),0) diff_total FROM cashier_shifts WHERE status='closed' AND DATE(closed_at)=?");
$stmt->execute([$today]);
$row = $stmt->fetch() ?: [];
$summary['closed_today'] = (int)($row['total'] ?? 0);
$summary['cash_today'] = (float)($row['cash_total'] ?? 0);
$summary['diff_today'] = (float)($row['diff_total'] ?? 0);

$shifts = $db->query("SELECT cs.*, u.name AS user_name, b.name AS branch_name FROM cashier_shifts cs LEFT JOIN users u ON u.id=cs.user_id LEFT JOIN branches b ON b.id=cs.branch_id ORDER BY cs.id DESC LIMIT 200")->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link href="<?= legacy_base_url('assets/css/shifts.css?v=' . time()); ?>" rel="stylesheet">

<main class="admin-main shifts-page">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Shift Kasir</h1>
            <p>Buka shift, tutup shift, hitung cash drawer, dan cek selisih kasir.</p>
        </div>
        <div class="topbar-actions">
            <?php if ($activeShift): ?>
                <a href="<?= legacy_base_url('admin/shifts/close.php?id=' . $activeShift['id']); ?>" class="shift-btn primary"><i class="bi bi-lock-fill"></i> Tutup Shift</a>
            <?php else: ?>
                <a href="<?= legacy_base_url('admin/shifts/open.php'); ?>" class="shift-btn primary"><i class="bi bi-unlock-fill"></i> Buka Shift</a>
            <?php endif; ?>
        </div>
    </header>

    <section class="shift-hero-card">
        <div class="shift-hero-icon"><i class="bi bi-person-workspace"></i></div>
        <div class="shift-hero-copy">
            <span>Cashier Shift Control</span>
            <h2><?= $activeShift ? 'Shift kamu sedang aktif' : 'Belum ada shift aktif'; ?></h2>
            <p>
                <?php if ($activeShift): ?>
                    Shift <?= e($activeShift['shift_code']); ?> dibuka pada <?= e(date('d/m/Y H:i', strtotime($activeShift['opened_at']))); ?> di <b><?= e(shift_location_label($activeShift)); ?></b> dengan modal awal <?= rupiah($activeShift['opening_cash']); ?>.
                <?php else: ?>
                    Buka shift sebelum mulai kasir supaya laporan cash, transaksi, refund, dan selisih kas tercatat rapi.
                <?php endif; ?>
            </p>
        </div>
        <div class="shift-hero-action">
            <?php if ($activeShift): ?>
                <a href="<?= legacy_base_url('admin/shifts/detail.php?id=' . $activeShift['id']); ?>" class="shift-btn soft"><i class="bi bi-eye"></i> Detail Shift</a>
            <?php else: ?>
                <a href="<?= legacy_base_url('admin/shifts/open.php'); ?>" class="shift-btn primary"><i class="bi bi-cash-coin"></i> Mulai Shift</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="shift-stats-grid">
        <div class="shift-stat red"><div><i class="bi bi-broadcast"></i></div><span>Shift Aktif</span><strong><?= e($summary['open']); ?></strong><small>Sedang berjalan</small></div>
        <div class="shift-stat green"><div><i class="bi bi-check2-circle"></i></div><span>Tutup Hari Ini</span><strong><?= e($summary['closed_today']); ?></strong><small>Shift selesai</small></div>
        <div class="shift-stat blue"><div><i class="bi bi-cash-stack"></i></div><span>Cash Hari Ini</span><strong><?= rupiah($summary['cash_today']); ?></strong><small>Penjualan cash</small></div>
        <div class="shift-stat orange"><div><i class="bi bi-sliders"></i></div><span>Selisih Hari Ini</span><strong><?= rupiah($summary['diff_today']); ?></strong><small>Lebih / kurang</small></div>
    </section>

    <section class="premium-card mt-4 shift-panel">
        <div class="card-head">
            <div>
                <h3>Riwayat Shift Kasir</h3>
                <p>Buka/tutup shift, total transaksi, cash, non-cash, refund, dan selisih kas.</p>
            </div>
            <span class="badge-soft danger"><?= count($shifts); ?> Shift</span>
        </div>

        <div class="dt-hint-card">
            <div class="dt-hint-icon"><i class="bi bi-search"></i></div>
            <div class="dt-hint-content">
                <span class="dt-hint-title">Pencarian Shift</span>
                <p class="dt-hint-text">Cari kode shift, kasir, tanggal buka/tutup, total cash, status, atau selisih shift.</p>
            </div>
        </div>

        <div class="table-premium-card dt-premium-wrap">
            <table class="premium-table js-data-table nowrap shift-table" data-title="shift">
                <thead>
                    <tr>
                        <th>Kode Shift</th>
                        <th>Kasir</th>
                        <th>Lokasi</th>
                        <th>Buka</th>
                        <th>Tutup</th>
                        <th>Modal Awal</th>
                        <th>Cash</th>
                        <th>Non Cash</th>
                        <th>Transaksi</th>
                        <th>Selisih</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shifts as $s): ?>
                        <tr>
                            <td><b><?= e($s['shift_code']); ?></b><br><small>ID #<?= e($s['id']); ?></small></td>
                            <td><?= e($s['user_name'] ?? '-'); ?></td>
                            <td><?= e(shift_location_label($s)); ?></td>
                            <td><?= e(date('d/m/Y H:i', strtotime($s['opened_at']))); ?></td>
                            <td><?= $s['closed_at'] ? e(date('d/m/Y H:i', strtotime($s['closed_at']))) : '<span class="text-muted">Berjalan</span>'; ?></td>
                            <td><b><?= rupiah($s['opening_cash']); ?></b></td>
                            <td><?= rupiah($s['cash_sales']); ?></td>
                            <td><?= rupiah($s['non_cash_sales']); ?></td>
                            <td><?= e($s['total_transactions']); ?></td>
                            <td><?= shift_diff_badge($s['cash_difference']); ?></td>
                            <td><?= shift_status_badge($s['status']); ?></td>
                            <td class="text-end">
                                <div class="table-actions">
                                    <a class="btn-icon edit" href="<?= legacy_base_url('admin/shifts/detail.php?id=' . $s['id']); ?>" title="Detail"><i class="bi bi-eye"></i></a>
                                    <?php if ($s['status'] === 'open'): ?>
                                        <a class="btn-icon danger" href="<?= legacy_base_url('admin/shifts/close.php?id=' . $s['id']); ?>" title="Tutup Shift"><i class="bi bi-lock"></i></a>
                                    <?php else: ?>
                                        <a class="btn-icon" target="_blank" href="<?= legacy_base_url('admin/shifts/print.php?id=' . $s['id']); ?>" title="Print"><i class="bi bi-printer"></i></a>
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

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
