<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$pageTitle = 'Tambah Pemasukan';
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


if (!finance_table_exists($db, 'finance_transactions') || !finance_table_exists($db, 'finance_categories')) {
    flash('error', 'Tabel keuangan belum terbaca. Buka menu Cek Keuangan dulu.');
    legacy_redirect('admin/finance/install.php');
}

if (is_post()) {
    if (!CSRF::verify()) {
        flash('error', 'Token keamanan tidak valid.');
        legacy_redirect('admin/finance/income.php');
    }

    $category_id = (int)($_POST['category_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $titleInput = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $date = $_POST['transaction_date'] ?? date('Y-m-d');

    if ($amount <= 0 || $titleInput === '') {
        flash('error', 'Judul dan nominal wajib diisi.');
        legacy_redirect('admin/finance/income.php');
    }

    $stmt = $db->prepare("INSERT INTO finance_transactions (category_id,user_id,type,amount,title,description,transaction_date) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$category_id ?: null, Auth::id(), 'income', $amount, $titleInput, $description, $date]);

    flash('success', 'Data berhasil disimpan.');
    legacy_redirect('admin/finance/index.php');
}

$stmt = $db->prepare("SELECT * FROM finance_categories WHERE type=? AND is_active=1 ORDER BY name ASC");
$stmt->execute(['income']);
$categories = $stmt->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/finance.css?v=' . time()); ?>">

<main class="admin-main finance-wrap">
    <header class="admin-topbar page-topbar">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <h1>Tambah Pemasukan</h1>
            <p>Catat uang masuk toko, modal tambahan, atau pendapatan lain.</p>
        </div>
    </header>

    <form method="POST" class="finance-form-card">
        <?= CSRF::input(); ?>

        <div class="row g-3">
            <div class="col-md-6">
                <label>Kategori</label>
                <select name="category_id" class="form-select">
                    <option value="">Pilih kategori</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat['id']); ?>"><?= e($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label>Tanggal</label>
                <input type="date" name="transaction_date" value="<?= date('Y-m-d'); ?>" class="form-control">
            </div>

            <div class="col-md-6">
                <label>Judul</label>
                <input type="text" name="title" class="form-control" placeholder="Contoh: Penjualan offline / Belanja stok" required>
            </div>

            <div class="col-md-6">
                <label>Nominal</label>
                <input type="number" name="amount" class="form-control" placeholder="0" min="0" required>
            </div>

            <div class="col-12">
                <label>Catatan</label>
                <textarea name="description" rows="4" class="form-control" placeholder="Catatan optional"></textarea>
            </div>
        </div>

        <div class="finance-form-actions">
            <a href="<?= legacy_base_url('admin/finance/index.php'); ?>" class="finance-btn soft">Batal</a>
            <button class="finance-btn primary" type="submit"><i class="bi bi-arrow-down-circle"></i> Simpan Pemasukan</button>
        </div>
    </form>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
