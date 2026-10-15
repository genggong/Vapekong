<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
if (file_exists(__DIR__ . '/../branches/_helpers.php')) require_once __DIR__ . '/../branches/_helpers.php';
Auth::requireRole(['super_admin','owner','admin','cashier']);
$pageTitle = 'Hutang / Piutang Pelanggan';
$db = Database::connect();
debt_install($db);
if (function_exists('branch_install')) branch_install($db);

$status = trim($_GET['status'] ?? '');
$q = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

$where = ["1=1"];
$params = [];
if ($status !== '') { $where[] = "d.status = ?"; $params[] = $status; }
if ($q !== '') {
    $where[] = "(c.name LIKE ? OR c.phone LIKE ? OR d.invoice_number LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($from !== '') { $where[] = "DATE(d.created_at) >= ?"; $params[] = $from; }
if ($to !== '') { $where[] = "DATE(d.created_at) <= ?"; $params[] = $to; }

$sql = "SELECT d.*, c.name AS customer_name, c.phone AS customer_phone".
    (debt_table_exists($db,'branches') ? ", b.name AS branch_name" : ", NULL AS branch_name").
    " FROM customer_debts d
    JOIN customers c ON c.id = d.customer_id ".
    (debt_table_exists($db,'branches') ? "LEFT JOIN branches b ON b.id = d.branch_id " : "").
    " WHERE ".implode(' AND ', $where)." ORDER BY d.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

foreach ($rows as $r) {
    $actual = debt_actual_status($r);
    if ($actual !== $r['status']) {
        $upd = $db->prepare("UPDATE customer_debts SET status=? WHERE id=?");
        $upd->execute([$actual, $r['id']]);
    }
}

$totalRemaining = array_sum(array_map(fn($r)=>(float)$r['remaining_amount'], $rows));
$totalPaid = array_sum(array_map(fn($r)=>(float)$r['paid_amount'], $rows));
$totalAll = array_sum(array_map(fn($r)=>(float)$r['total_amount'], $rows));
$overdue = count(array_filter($rows, fn($r)=>debt_actual_status($r)==='overdue'));

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=' . time()); ?>">
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/debts.css?v=' . time()); ?>">
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar">
    <button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
    <div class="topbar-title"><h1>Hutang / Piutang Pelanggan</h1><p>Catat tagihan pelanggan, pembayaran sebagian, jatuh tempo, dan kirim tagihan WhatsApp.</p></div>
    <div class="topbar-actions"><a href="<?= legacy_base_url('admin/debts/create.php'); ?>" class="system-btn primary"><i class="bi bi-plus-circle"></i> Tambah Piutang</a></div>
</header>

<section class="debt-stats">
    <div class="debt-stat"><div class="debt-stat-icon"><i class="bi bi-receipt"></i></div><span>Total Piutang</span><h3><?= rupiah($totalAll); ?></h3></div>
    <div class="debt-stat"><div class="debt-stat-icon"><i class="bi bi-cash-coin"></i></div><span>Sudah Dibayar</span><h3><?= rupiah($totalPaid); ?></h3></div>
    <div class="debt-stat"><div class="debt-stat-icon"><i class="bi bi-hourglass-split"></i></div><span>Sisa Tagihan</span><h3><?= rupiah($totalRemaining); ?></h3></div>
    <div class="debt-stat"><div class="debt-stat-icon"><i class="bi bi-exclamation-circle"></i></div><span>Jatuh Tempo</span><h3><?= e($overdue); ?></h3></div>
</section>

<section class="system-form-card">
    <form method="GET" class="debt-filter">
        <div class="system-field"><label>Cari</label><input name="q" value="<?= e($q); ?>" placeholder="Nama pelanggan / no HP / invoice"></div>
        <div class="system-field"><label>Status</label><select name="status"><option value="">Semua</option><?php foreach(['unpaid'=>'Belum Lunas','partial'=>'Sebagian','paid'=>'Lunas','overdue'=>'Jatuh Tempo'] as $k=>$v): ?><option value="<?= e($k); ?>" <?= $status===$k?'selected':''; ?>><?= e($v); ?></option><?php endforeach; ?></select></div>
        <div class="system-field"><label>Dari</label><input type="date" name="from" value="<?= e($from); ?>"></div>
        <div class="system-field"><label>Sampai</label><input type="date" name="to" value="<?= e($to); ?>"></div>
        <div class="system-field"><label>&nbsp;</label><button class="system-btn primary"><i class="bi bi-search"></i> Filter</button></div>
    </form>
</section>

<section class="system-form-card">
    <div class="table-responsive">
        <table class="premium-table">
            <thead><tr><th>Invoice</th><th>Pelanggan</th><th>Total</th><th>Dibayar</th><th>Sisa</th><th>Jatuh Tempo</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
            <tbody>
                <?php foreach($rows as $r): $st = debt_actual_status($r); ?>
                <tr>
                    <td><b><?= e($r['invoice_number'] ?: '-'); ?></b><br><small><?= e(date('d/m/Y H:i', strtotime($r['created_at']))); ?><?= !empty($r['branch_name']) ? ' • '.e($r['branch_name']) : ''; ?></small></td>
                    <td><b><?= e($r['customer_name']); ?></b><br><small><?= e($r['customer_phone'] ?: '-'); ?></small></td>
                    <td><?= rupiah($r['total_amount']); ?></td>
                    <td><?= rupiah($r['paid_amount']); ?></td>
                    <td><b><?= rupiah($r['remaining_amount']); ?></b></td>
                    <td><?= $r['due_date'] ? e(date('d/m/Y', strtotime($r['due_date']))) : '-'; ?></td>
                    <td><span class="debt-status <?= e($st); ?>"><?= e(debt_status_label($st)); ?></span></td>
                    <td class="text-end"><div class="debt-actions">
                        <a class="system-btn soft" href="<?= legacy_base_url('admin/debts/detail.php?id=' . $r['id']); ?>">Detail</a>
                        <?php if((float)$r['remaining_amount'] > 0): ?><a class="system-btn primary" href="<?= legacy_base_url('admin/debts/pay.php?id=' . $r['id']); ?>">Bayar</a><?php endif; ?>
                        <?php if(function_exists('wa_send_fonnte') && !empty($r['customer_phone'])): ?><a class="system-btn soft" href="<?= legacy_base_url('admin/debts/send-wa.php?id=' . $r['id']); ?>" onclick="return confirm('Kirim tagihan WhatsApp?')"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
                    </div></td>
                </tr>
                <?php endforeach; ?>
                <?php if(!$rows): ?><tr><td colspan="8" class="text-center">Belum ada data piutang.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>