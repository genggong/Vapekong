<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/../warehouse/_helpers.php';
warehouse_require_request_access();
$pageTitle = 'Detail Request Stock';
$db = Database::connect();
branch_install($db);
warehouse_install($db);
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT r.*,b.name branch_name,b.code branch_code,w.name warehouse_name,u.name requester_name,
    au.name approver_name,fu.name fulfiller_name,ru.name rejecter_name
    FROM branch_stock_requests r
    LEFT JOIN branches b ON b.id=r.branch_id
    LEFT JOIN warehouses w ON w.id=r.warehouse_id
    LEFT JOIN users u ON u.id=r.requested_by
    LEFT JOIN users au ON au.id=r.approved_by
    LEFT JOIN users fu ON fu.id=r.fulfilled_by
    LEFT JOIN users ru ON ru.id=r.rejected_by
    WHERE r.id=? LIMIT 1");
$stmt->execute([$id]);
$request = $stmt->fetch();
if (!$request) { flash('error','Request stok tidak ditemukan.'); legacy_redirect('admin/stock-requests/index.php'); }
$currentBranchId = branch_current_id($db);
$isManager = warehouse_can_manage();
if (!$isManager && (int)$request['branch_id'] !== $currentBranchId) {
    flash('error','Request stok bukan milik cabang kamu.');
    legacy_redirect('admin/stock-requests/index.php');
}

if (is_post() && ($_POST['action'] ?? '') === 'cancel') {
    if (!CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/stock-requests/detail.php?id='.$id); }
    if (!in_array($request['status'], ['pending'], true)) {
        flash('error','Request yang sudah diproses gudang tidak dapat dibatalkan.');
    } else {
        $stmt = $db->prepare("UPDATE branch_stock_requests SET status='cancelled',cancelled_by=?,cancelled_at=NOW(),updated_at=NOW() WHERE id=? AND status='pending'");
        $stmt->execute([Auth::id(),$id]);
        Auth::log('cancel_stock_request','Membatalkan request stok '.$request['request_number']);
        flash('success','Request stok dibatalkan.');
    }
    legacy_redirect('admin/stock-requests/detail.php?id='.$id);
}

$stmt = $db->prepare("SELECT i.*,CASE WHEN COALESCE(i.variant_id,0)>0 THEN COALESCE(wvs.stock,0) ELSE COALESCE(ws.stock,0) END current_warehouse_stock FROM branch_stock_request_items i LEFT JOIN warehouse_stocks ws ON ws.product_id=i.product_id AND ws.warehouse_id=? LEFT JOIN warehouse_variant_stocks wvs ON wvs.variant_id=i.variant_id AND wvs.warehouse_id=? WHERE i.request_id=? ORDER BY i.id ASC");
$stmt->execute([(int)$request['warehouse_id'],(int)$request['warehouse_id'],$id]);
$items = $stmt->fetchAll();

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/warehouse.css?v=' . time()); ?>">
<main class="admin-main warehouse-wrap">
<header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>Detail Request Stock</h1><p><?= e($request['request_number']); ?> • <?= e($request['branch_name']); ?></p></div><div class="topbar-actions"><a class="btn-premium outline" href="<?= legacy_base_url('admin/stock-requests/index.php'); ?>"><i class="bi bi-arrow-left"></i> Kembali</a></div></header>

<section class="request-detail-hero"><div><small>NOMOR REQUEST</small><h2><?= e($request['request_number']); ?></h2><p><?= e(date('d F Y H:i',strtotime($request['created_at']))); ?></p></div><span class="request-status large <?= e(warehouse_request_status_class($request['status'])); ?>"><?= e(warehouse_request_status_label($request['status'])); ?></span></section>

<section class="request-detail-grid">
<article><small>Cabang</small><h3><?= e($request['branch_name']); ?></h3><p><?= e($request['branch_code']); ?></p></article>
<article><small>Gudang</small><h3><?= e($request['warehouse_name']); ?></h3><p>Sumber stok</p></article>
<article><small>Peminta</small><h3><?= e($request['requester_name'] ?: '-'); ?></h3><p><?= e($request['note'] ?: 'Tanpa catatan'); ?></p></article>
<article><small>Total Modal</small><h3><?= rupiah($request['total_cost']); ?></h3><p>Tanpa margin</p></article>
</section>

<section class="premium-card mt-4"><div class="card-head"><div><h3>Item Request</h3><p>Harga modal dikunci saat request dibuat dari harga beli gudang.</p></div></div><div class="table-premium-card dt-premium-wrap"><table class="premium-table nowrap"><thead><tr><th>Produk</th><th>Qty</th><th>Stok Gudang Saat Ini</th><th>Harga Modal</th><th>Total</th><th>Pergerakan Stok</th></tr></thead><tbody>
<?php foreach($items as $it): ?>
<tr><td><b><?= e($it['product_name']); ?><?= !empty($it['variant_name']) ? ' - '.e($it['variant_name']) : ''; ?></b><br><small><?= e($it['variant_sku'] ?: $it['product_code']); ?></small></td><td><?= e($it['qty_requested']); ?> <?= e($it['unit']); ?></td><td><?= e($it['current_warehouse_stock']); ?> <?= e($it['unit']); ?></td><td><?= rupiah($it['unit_cost']); ?></td><td><b><?= rupiah($it['line_total']); ?></b></td><td><?php if($request['status']==='fulfilled'): ?>Gudang <?= e($it['warehouse_stock_before']); ?> → <?= e($it['warehouse_stock_after']); ?><br><small>Cabang <?= e($it['branch_stock_before']); ?> → <?= e($it['branch_stock_after']); ?></small><?php else: ?>-<?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>

<?php if (!empty($request['action_note'])): ?><section class="request-action-note"><i class="bi bi-chat-left-text"></i><div><b>Catatan Gudang</b><p><?= nl2br(e($request['action_note'])); ?></p></div></section><?php endif; ?>

<section class="request-timeline">
<div class="done"><i class="bi bi-send-check"></i><span><b>Request Dibuat</b><small><?= e($request['requester_name'] ?: '-'); ?> • <?= e(date('d/m/Y H:i',strtotime($request['created_at']))); ?></small></span></div>
<div class="<?= $request['approved_at']?'done':''; ?>"><i class="bi bi-check2-circle"></i><span><b>Disetujui Gudang</b><small><?= $request['approved_at'] ? e(($request['approver_name'] ?: '-').' • '.date('d/m/Y H:i',strtotime($request['approved_at']))) : 'Menunggu'; ?></small></span></div>
<div class="<?= $request['fulfilled_at']?'done':''; ?>"><i class="bi bi-truck"></i><span><b>Stok Masuk Cabang</b><small><?= $request['fulfilled_at'] ? e(($request['fulfiller_name'] ?: '-').' • '.date('d/m/Y H:i',strtotime($request['fulfilled_at']))) : 'Belum dikirim'; ?></small></span></div>
</section>

<?php if ($isManager && in_array($request['status'],['pending','approved'],true)): ?>
<section class="request-action-panel"><div><h3>Proses Request Gudang</h3><p>Fulfill akan mengurangi stok gudang dan menambah stok cabang secara atomik.</p></div><div class="request-action-buttons">
<?php if($request['status']==='pending'): ?><form method="POST" action="<?= legacy_base_url('admin/stock-requests/action.php'); ?>"><?= CSRF::input(); ?><input type="hidden" name="request_id" value="<?= e($id); ?>"><input type="hidden" name="action" value="approve"><button class="btn-premium outline"><i class="bi bi-check2"></i> Setujui</button></form><?php endif; ?>
<form method="POST" action="<?= legacy_base_url('admin/stock-requests/action.php'); ?>" onsubmit="return confirm('Kirim stok ke cabang sekarang? Stok gudang akan berkurang.');"><?= CSRF::input(); ?><input type="hidden" name="request_id" value="<?= e($id); ?>"><input type="hidden" name="action" value="fulfill"><button class="btn-premium"><i class="bi bi-truck"></i> Kirim / Fulfill</button></form>
<form method="POST" action="<?= legacy_base_url('admin/stock-requests/action.php'); ?>" class="reject-form"><?= CSRF::input(); ?><input type="hidden" name="request_id" value="<?= e($id); ?>"><input type="hidden" name="action" value="reject"><input name="action_note" placeholder="Alasan penolakan" required><button class="btn-premium danger"><i class="bi bi-x-circle"></i> Tolak</button></form>
</div></section>
<?php elseif (!$isManager && $request['status']==='pending'): ?>
<section class="request-action-panel"><div><h3>Request masih menunggu</h3><p>Kamu masih bisa membatalkan sebelum diproses gudang.</p></div><form method="POST" onsubmit="return confirm('Batalkan request stok ini?');"><?= CSRF::input(); ?><input type="hidden" name="action" value="cancel"><button class="btn-premium danger"><i class="bi bi-x-circle"></i> Batalkan Request</button></form></section>
<?php endif; ?>
</main>
<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
