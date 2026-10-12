<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$pageTitle = 'Hutang';
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


if (!finance_table_exists($db, 'debts')) {
    flash('error', 'Tabel hutang belum terbaca.');
    legacy_redirect('admin/finance/install.php');
}

if (is_post()) {
    if (!CSRF::verify()) {
        flash('error','Token keamanan tidak valid.');
        legacy_redirect('admin/finance/debt.php');
    }

    $title = trim($_POST['title'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $due = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $note = trim($_POST['note'] ?? '');

    if ($title === '' || $amount <= 0) {
        flash('error','Judul dan nominal wajib diisi.');
        legacy_redirect('admin/finance/debt.php');
    }

    $stmt = $db->prepare("INSERT INTO debts (user_id,title,amount,due_date,note,status) VALUES (?,?,?,?,?,'unpaid')");
    $stmt->execute([Auth::id(),$title,$amount,$due,$note]);

    flash('success','Hutang berhasil ditambahkan.');
    legacy_redirect('admin/finance/debt.php');
}

$rows = $db->query("SELECT * FROM debts ORDER BY id DESC")->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/finance.css?v=' . time()); ?>">

<main class="admin-main finance-wrap">
<header class="admin-topbar page-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><h1>Hutang</h1><p>Catat hutang toko ke supplier atau pihak lain.</p></div>
</header>

<form method="POST" class="finance-form-card">
<?= CSRF::input(); ?>
<div class="row g-3">
    <div class="col-md-4"><label>Judul Hutang</label><input class="form-control" name="title" required></div>
    <div class="col-md-4"><label>Nominal</label><input class="form-control" type="number" name="amount" required></div>
    <div class="col-md-4"><label>Jatuh Tempo</label><input class="form-control" type="date" name="due_date"></div>
    <div class="col-12"><label>Catatan</label><textarea class="form-control" name="note" rows="3"></textarea></div>
</div>
<div class="finance-form-actions">
    <a class="finance-btn soft" href="<?= legacy_base_url('admin/finance/index.php'); ?>">Kembali</a>
    <button class="finance-btn primary"><i class="bi bi-save"></i> Simpan Hutang</button>
</div>
</form>

<section class="finance-panel">
<div class="finance-panel-head"><h3>Daftar Hutang</h3></div>
<div class="finance-table-wrap">
<table class="finance-table">
<thead><tr><th>Judul</th><th>Nominal</th><th>Dibayar</th><th>Sisa</th><th>Jatuh Tempo</th><th>Status</th></tr></thead>
<tbody>
<?php foreach($rows as $r): ?>
<tr>
<td><b><?= e($r['title']); ?></b><br><small><?= e($r['note']); ?></small></td>
<td><?= rupiah($r['amount']); ?></td>
<td><?= rupiah($r['paid_amount']); ?></td>
<td><b><?= rupiah($r['amount']-$r['paid_amount']); ?></b></td>
<td><?= e($r['due_date'] ?: '-'); ?></td>
<td><span class="finance-badge <?= e($r['status']); ?>"><?= e($r['status']); ?></span></td>
</tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="6" class="finance-empty">Belum ada hutang.</td></tr><?php endif; ?>
</tbody>
</table>
</div>
</section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
