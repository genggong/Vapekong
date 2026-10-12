<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../branches/_helpers.php';

$pageTitle = 'Buka Shift';
$db = Database::connect();
branch_install($db);
shift_ensure_tables($db);

$userId = (int)Auth::id();
$currentBranch = branch_current($db);
$branchOptions = branch_is_admin() ? branch_all($db, true) : [$currentBranch];
$activeShift = shift_active($db, $userId);

if ($activeShift) {
    flash('warning', 'Kamu masih punya shift aktif. Tutup shift dulu sebelum membuka shift baru.');
    legacy_redirect('admin/shifts/index.php');
}

if (is_post()) {
    if (!CSRF::verify()) {
        flash('error', 'Token keamanan tidak valid.');
        legacy_redirect('admin/shifts/open.php');
    }

    $openingCash = (float)($_POST['opening_cash'] ?? 0);
    $note = trim($_POST['opening_note'] ?? '');
    $locationName = trim((string)($_POST['location_name'] ?? ''));
    $postedBranchId = (int)($_POST['branch_id'] ?? 0);
    $branchId = branch_is_admin() ? $postedBranchId : (int)($currentBranch['id'] ?? 0);

    $stmtBranch = $db->prepare("SELECT id, name FROM branches WHERE id=? AND is_active=1 LIMIT 1");
    $stmtBranch->execute([$branchId]);
    $selectedBranch = $stmtBranch->fetch();
    if (!$selectedBranch) {
        flash('error', 'Cabang/lokasi shift tidak valid atau sudah nonaktif.');
        legacy_redirect('admin/shifts/open.php');
    }
    if (mb_strlen($locationName) > 150) {
        flash('error', 'Nama posisi/counter maksimal 150 karakter.');
        legacy_redirect('admin/shifts/open.php');
    }

    if ($openingCash < 0) {
        flash('error', 'Modal awal tidak boleh minus.');
        legacy_redirect('admin/shifts/open.php');
    }

    try {
        $code = shift_generate_code();
        $stmt = $db->prepare("INSERT INTO cashier_shifts (shift_code,user_id,branch_id,location_name,opening_cash,expected_cash,status,opened_at,opening_note) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$code, $userId, $branchId, $locationName ?: null, $openingCash, $openingCash, 'open', now_datetime(), $note ?: null]);
        if (branch_is_admin()) $_SESSION['active_branch_id'] = $branchId;
        Auth::log('shift_open', 'Membuka shift ' . $code . ' di ' . ($selectedBranch['name'] ?? '-') . ($locationName ? ' / ' . $locationName : '') . ' dengan modal awal ' . rupiah($openingCash));
        flash('success', 'Shift berhasil dibuka.');
        legacy_redirect('admin/shifts/index.php');
    } catch (Throwable $e) {
        flash('error', 'Gagal membuka shift: ' . $e->getMessage());
        legacy_redirect('admin/shifts/open.php');
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
            <h1>Buka Shift</h1>
            <p>Masukkan modal awal kas sebelum mulai transaksi POS.</p>
        </div>
    </header>

    <section class="premium-card mt-4 shift-form-card">
        <div class="shift-form-head">
            <div class="shift-form-icon"><i class="bi bi-unlock-fill"></i></div>
            <div>
                <h3>Mulai Shift Kasir</h3>
                <p>Shift akan mencatat transaksi dari kasir login saat ini: <b><?= e(Auth::user()['name'] ?? 'User'); ?></b>.</p>
            </div>
        </div>

        <form method="POST" class="premium-form shift-form">
            <?= CSRF::input(); ?>
            <div class="form-grid">
                <div class="form-group">
                    <label>Cabang / Lokasi Shift</label>
                    <select name="branch_id" required <?= branch_is_admin() ? '' : 'disabled'; ?>>
                        <?php foreach ($branchOptions as $branch): ?>
                            <option value="<?= e($branch['id']); ?>" <?= (int)$branch['id'] === (int)($currentBranch['id'] ?? 0) ? 'selected' : ''; ?>><?= e($branch['name']); ?><?= !empty($branch['code']) ? ' (' . e($branch['code']) . ')' : ''; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!branch_is_admin()): ?><input type="hidden" name="branch_id" value="<?= e($currentBranch['id']); ?>"><?php endif; ?>
                    <small>Shift dan transaksi akan dihitung berdasarkan cabang ini.</small>
                </div>
                <div class="form-group">
                    <label>Posisi / Counter Kasir</label>
                    <input type="text" maxlength="150" name="location_name" placeholder="Contoh: Kasir Depan, Counter A, Lantai 1">
                    <small>Opsional. Berguna jika satu cabang memiliki beberapa meja/pos kasir.</small>
                </div>

                <div class="form-group">
                    <label>Modal Awal Kas</label>
                    <input type="number" min="0" step="100" name="opening_cash" value="0" required placeholder="Contoh: 200000">
                    <small>Isi uang cash awal di laci kasir.</small>
                </div>
                <div class="form-group form-wide">
                    <label>Catatan Pembukaan</label>
                    <textarea name="opening_note" rows="4" placeholder="Contoh: Modal awal shift pagi"></textarea>
                </div>
            </div>

            <div class="form-actions">
                <a class="btn-soft" href="<?= legacy_base_url('admin/shifts/index.php'); ?>">Batal</a>
                <button class="btn-premium"><i class="bi bi-play-circle"></i> Buka Shift</button>
            </div>
        </form>
    </section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
