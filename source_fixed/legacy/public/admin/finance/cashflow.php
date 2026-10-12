<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$pageTitle = 'Cashflow';
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


if (!finance_table_exists($db, 'finance_transactions')) {
    flash('error', 'Tabel transaksi keuangan belum terbaca.');
    legacy_redirect('admin/finance/install.php');
}

$rows = $db->query("
SELECT transaction_date,
SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income,
SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
FROM finance_transactions
GROUP BY transaction_date
ORDER BY transaction_date DESC
LIMIT 60
")->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/finance.css?v=' . time()); ?>">

<main class="admin-main finance-wrap">
<header class="admin-topbar page-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><h1>Cashflow</h1><p>Ringkasan arus kas masuk dan keluar per hari.</p></div>
</header>

<section class="finance-panel mt-4">
<div class="finance-panel-head">
<h3>Arus Kas Harian</h3>
<a class="finance-btn soft" href="<?= legacy_base_url('admin/finance/index.php'); ?>">Kembali</a>
</div>

<div class="finance-table-wrap">
<table class="finance-table">
<thead><tr><th>Tanggal</th><th>Uang Masuk</th><th>Uang Keluar</th><th>Saldo</th></tr></thead>
<tbody>
<?php foreach($rows as $r): ?>
<tr>
<td><?= e(date('d/m/Y', strtotime($r['transaction_date']))); ?></td>
<td><span class="finance-badge income"><?= rupiah($r['income']); ?></span></td>
<td><span class="finance-badge expense"><?= rupiah($r['expense']); ?></span></td>
<td><b><?= rupiah($r['income']-$r['expense']); ?></b></td>
</tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="4" class="finance-empty">Belum ada data cashflow.</td></tr><?php endif; ?>
</tbody>
</table>
</div>
</section>
</main>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
