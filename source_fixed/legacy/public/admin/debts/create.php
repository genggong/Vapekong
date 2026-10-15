<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
if (file_exists(__DIR__ . '/../branches/_helpers.php')) require_once __DIR__ . '/../branches/_helpers.php';
Auth::requireRole(['super_admin','owner','admin']);
$pageTitle = 'Tambah Piutang Manual';
$db = Database::connect();
debt_install($db);
if (function_exists('branch_install')) branch_install($db);
$customers = $db->query("SELECT id,name,phone FROM customers WHERE status='active' ORDER BY name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/debts/create.php'); }
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $total = max(0, (float)($_POST['total_amount'] ?? 0));
    $paid = max(0, (float)($_POST['paid_amount'] ?? 0));
    $due = trim($_POST['due_date'] ?? '');
    $note = trim($_POST['note'] ?? '');
    if ($customerId <= 0 || $total <= 0) { flash('error','Pelanggan dan total piutang wajib diisi.'); legacy_redirect('admin/debts/create.php'); }
    $remaining = max(0, $total - $paid);
    if ($remaining <= 0) { flash('error','Sisa piutang harus lebih dari 0.'); legacy_redirect('admin/debts/create.php'); }
    $branchId = function_exists('branch_current_id') ? branch_current_id($db) : null;
    $id = debt_create_from_transaction($db, ['customer_id'=>$customerId,'branch_id'=>$branchId,'invoice_number'=>'MANUAL-'.date('YmdHis'),'total_amount'=>$total,'paid_amount'=>$paid,'remaining_amount'=>$remaining,'due_date'=>$due,'note'=>$note]);
    Auth::log('create_manual_debt','Tambah piutang manual ID '.$id);
    flash('success','Piutang manual berhasil dibuat.');
    legacy_redirect('admin/debts/detail.php?id='.$id);
}
require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>"><link rel="stylesheet" href="<?= legacy_base_url('assets/css/debts.css?v=' . time()); ?>">
<main class="admin-main system-wrap"><header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Tambah Piutang Manual</h1><p>Untuk catatan hutang pelanggan di luar transaksi POS.</p></div></header>
<form method="POST" class="system-form-card"><?= CSRF::input(); ?><div class="system-form-grid"><div class="system-field"><label>Pelanggan</label><select name="customer_id" required><option value="">Pilih pelanggan</option><?php foreach($customers as $c): ?><option value="<?= e($c['id']); ?>"><?= e($c['name']); ?> — <?= e($c['phone'] ?: '-'); ?></option><?php endforeach; ?></select></div><div class="system-field"><label>Total Piutang</label><input type="number" name="total_amount" min="1" required></div><div class="system-field"><label>Sudah Dibayar</label><input type="number" name="paid_amount" min="0" value="0"></div><div class="system-field"><label>Jatuh Tempo</label><input type="date" name="due_date"></div><div class="system-field system-wide"><label>Catatan</label><textarea name="note" rows="3"></textarea></div></div><div class="system-form-actions"><a class="system-btn soft" href="<?= legacy_base_url('admin/debts/index.php'); ?>">Batal</a><button class="system-btn primary"><i class="bi bi-save"></i> Simpan Piutang</button></div></form>
</main><?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>