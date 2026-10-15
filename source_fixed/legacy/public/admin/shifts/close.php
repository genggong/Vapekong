<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../branches/_helpers.php';

$pageTitle = 'Tutup Shift';
$db = Database::connect();
branch_install($db);
shift_ensure_tables($db);

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$shift = $id > 0 ? shift_find($db, $id) : shift_active($db, (int)Auth::id());

if (!$shift || $shift['status'] !== 'open') {
    flash('error', 'Shift aktif tidak ditemukan atau sudah ditutup.');
    legacy_redirect('admin/shifts/index.php');
}

$calc = shift_calculate($db, $shift);

if (is_post()) {
    if (!CSRF::verify()) {
        flash('error', 'Token keamanan tidak valid.');
        legacy_redirect('admin/shifts/close.php?id=' . (int)$shift['id']);
    }

    $actualCash = (float)($_POST['actual_cash'] ?? 0);
    $note = trim($_POST['closing_note'] ?? '');

    if ($actualCash < 0) {
        flash('error', 'Cash aktual tidak boleh minus.');
        legacy_redirect('admin/shifts/close.php?id=' . (int)$shift['id']);
    }

    try {
        $closedAt = now_datetime();
        $shift['closed_at'] = $closedAt;
        $shift['actual_cash'] = $actualCash;
        $calc = shift_calculate($db, $shift);
        $difference = $actualCash - $calc['expected_cash'];

        $stmt = $db->prepare("UPDATE cashier_shifts SET status='closed', closed_at=?, cash_sales=?, non_cash_sales=?, total_refund=?, expected_cash=?, actual_cash=?, cash_difference=?, total_transactions=?, closing_note=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([
            $closedAt,
            $calc['cash_sales'],
            $calc['non_cash_sales'],
            $calc['total_refund'],
            $calc['expected_cash'],
            $actualCash,
            $difference,
            $calc['total_transactions'],
            $note ?: null,
            (int)$shift['id'],
        ]);

        Auth::log('shift_close', 'Menutup shift ' . $shift['shift_code'] . ' dengan selisih ' . rupiah($difference));
        flash('success', 'Shift berhasil ditutup.');
        legacy_redirect('admin/shifts/detail.php?id=' . (int)$shift['id']);
    } catch (Throwable $e) {
        flash('error', 'Gagal menutup shift: ' . $e->getMessage());
        legacy_redirect('admin/shifts/close.php?id=' . (int)$shift['id']);
    }
}

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link href="<?= legacy_base_url('assets/css/shifts.css?v=' . time()); ?>" rel="stylesheet">

<main class="admin-main shifts-page">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Tutup Shift</h1>
            <p>Hitung cash aktual, cocokkan dengan sistem, dan simpan laporan shift.</p>
        </div>
    </header>

    <section class="shift-close-grid">
        <div class="premium-card shift-summary-card">
            <h3><?= e($shift['shift_code']); ?></h3>
            <p>Kasir: <b><?= e($shift['user_name'] ?? '-'); ?></b> • Lokasi: <b><?= e(shift_location_label($shift)); ?></b></p>
            <div class="shift-summary-list">
                <div><span>Modal Awal</span><b><?= rupiah($calc['opening_cash']); ?></b></div>
                <div><span>Penjualan Cash</span><b><?= rupiah($calc['cash_sales']); ?></b></div>
                <div><span>Retur / Refund</span><b><?= rupiah($calc['total_refund']); ?></b></div>
                <div class="total"><span>Cash Seharusnya</span><b><?= rupiah($calc['expected_cash']); ?></b></div>
                <div><span>Non Cash</span><b><?= rupiah($calc['non_cash_sales']); ?></b></div>
                <div><span>Total Transaksi</span><b><?= e($calc['total_transactions']); ?></b></div>
            </div>
        </div>

        <div class="premium-card shift-form-card">
            <div class="shift-form-head">
                <div class="shift-form-icon"><i class="bi bi-lock-fill"></i></div>
                <div>
                    <h3>Input Cash Aktual</h3>
                    <p>Masukkan uang cash fisik di laci kasir saat shift ditutup.</p>
                </div>
            </div>
            <form method="POST" class="premium-form shift-form">
                <?= CSRF::input(); ?>
                <input type="hidden" name="id" value="<?= e($shift['id']); ?>">
                <div class="form-grid">
                    <div class="form-group form-wide">
                        <label>Cash Aktual</label>
                        <input type="number" min="0" step="100" name="actual_cash" value="<?= e((int)$calc['expected_cash']); ?>" required>
                        <small>Default diisi sesuai cash seharusnya. Ubah kalau uang fisik berbeda.</small>
                    </div>
                    <div class="form-group form-wide">
                        <label>Catatan Penutupan</label>
                        <textarea name="closing_note" rows="4" placeholder="Contoh: Selisih karena pembulatan / uang kembalian"></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <a class="btn-soft" href="<?= legacy_base_url('admin/shifts/index.php'); ?>">Batal</a>
                    <button class="btn-premium"><i class="bi bi-lock"></i> Tutup Shift</button>
                </div>
            </form>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
