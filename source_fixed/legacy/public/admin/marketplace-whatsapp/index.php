<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
Auth::requireRole(['super_admin','owner']);
$pageTitle='WhatsApp Marketplace';$db=Database::connect();marketplace_install($db);
if(is_post()){
    if(!CSRF::verify()){flash('error','Token keamanan tidak valid.');legacy_redirect('admin/marketplace-whatsapp/index.php');}
    $action=(string)($_POST['action']??'');
    if($action==='retry_failed'){$db->exec("UPDATE marketplace_wa_queue SET status='pending',next_attempt_at=NULL,last_error=NULL WHERE status='failed'");flash('success','Pesan gagal dimasukkan kembali ke antrean.');}
    elseif($action==='process_now'){try{$r=marketplace_wa_process_queue($db,50);flash('success','Proses antrean: terkirim '.$r['sent'].', gagal '.$r['failed'].'.');}catch(Throwable $e){flash('error',$e->getMessage());}}
    legacy_redirect('admin/marketplace-whatsapp/index.php');
}
$page=max(1,(int)($_GET['page']??1));$per=25;$off=($page-1)*$per;
$total=(int)$db->query("SELECT COUNT(*) FROM marketplace_wa_queue")->fetchColumn();
$stats=[];foreach($db->query("SELECT status,COUNT(*) c FROM marketplace_wa_queue GROUP BY status")->fetchAll()?:[] as $r)$stats[$r['status']]=$r['c'];
$rows=$db->query("SELECT q.*,o.order_number,c.name customer_name FROM marketplace_wa_queue q LEFT JOIN online_orders o ON o.id=q.order_id LEFT JOIN customers c ON c.id=q.customer_id ORDER BY q.id DESC LIMIT {$per} OFFSET {$off}")->fetchAll()?:[];
$webhooks=$db->query("SELECT * FROM marketplace_wa_webhook_logs ORDER BY id DESC LIMIT 10")->fetchAll()?:[];
$pages=max(1,(int)ceil($total/$per));
require_once __DIR__.'/../../layouts/admin-header.php';require_once __DIR__.'/../../layouts/admin-sidebar.php';
?>
<link rel="stylesheet" href="<?= legacy_base_url('assets/css/system.css?v=14.0.0'); ?>">
<style>.wa-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.wa-pill{padding:5px 10px;border-radius:999px;font-size:12px;font-weight:800;background:#f1f5f9}.wa-pill.sent{background:#dcfce7;color:#166534}.wa-pill.failed{background:#fee2e2;color:#991b1b}.wa-pill.pending,.wa-pill.sending{background:#fef3c7;color:#92400e}.wa-msg{max-width:420px;white-space:pre-line}.wa-error{max-width:320px;color:#b91c1c;font-size:12px}.wa-pager{display:flex;gap:6px;flex-wrap:wrap;margin-top:14px}.wa-pager a{padding:8px 11px;border:1px solid #e2e8f0;border-radius:9px;text-decoration:none}.wa-pager a.active{background:#111827;color:#fff}@media(max-width:900px){.wa-stat-grid{grid-template-columns:1fr 1fr}.system-table-wrap{overflow:auto}}</style>
<main class="admin-main system-wrap">
<header class="admin-topbar page-topbar"><button class="sidebar-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button><div class="topbar-title"><h1>WhatsApp Marketplace</h1><p>Antrean notifikasi Teknorilis WA Gateway, retry, status pengiriman, dan log webhook.</p></div><div class="topbar-actions"><a class="btn-soft" href="<?= legacy_base_url('admin/marketplace-settings/index.php'); ?>"><i class="bi bi-gear"></i> Pengaturan</a></div></header>
<section class="wa-stat-grid system-stats"><div class="system-card"><span>Total</span><h3><?= $total; ?></h3></div><div class="system-card"><span>Pending</span><h3><?= (int)($stats['pending']??0); ?></h3></div><div class="system-card"><span>Terkirim</span><h3><?= (int)($stats['sent']??0); ?></h3></div><div class="system-card"><span>Gagal</span><h3><?= (int)($stats['failed']??0); ?></h3></div></section>
<section class="system-form-card"><div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:space-between;align-items:center"><div><h3 style="margin:0">Antrean WhatsApp</h3><p style="margin:5px 0 0;color:#64748b">Cron marketplace mengirim antrean otomatis. Bisa diproses manual untuk pengujian.</p></div><div style="display:flex;gap:8px"><form method="post"><?= CSRF::input(); ?><input type="hidden" name="action" value="process_now"><button class="system-btn primary"><i class="bi bi-send"></i> Proses Sekarang</button></form><form method="post"><?= CSRF::input(); ?><input type="hidden" name="action" value="retry_failed"><button class="system-btn soft"><i class="bi bi-arrow-repeat"></i> Retry Gagal</button></form></div></div>
<div class="system-table-wrap" style="margin-top:14px"><table class="system-table"><thead><tr><th>Waktu</th><th>Order/Customer</th><th>Tujuan</th><th>Event</th><th>Pesan</th><th>Status</th><th>Error</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="7" style="text-align:center;padding:28px">Belum ada antrean WhatsApp.</td></tr><?php endif; ?><?php foreach($rows as $r): ?><tr><td><?= e(date('d/m/Y H:i',strtotime((string)$r['created_at']))); ?></td><td><b><?= e($r['order_number']?:'-'); ?></b><br><small><?= e($r['customer_name']?:'-'); ?></small></td><td><?= e($r['recipient']); ?></td><td><?= e($r['event_key']); ?></td><td><div class="wa-msg"><?= e($r['message']); ?></div></td><td><span class="wa-pill <?= e($r['status']); ?>"><?= e(strtoupper($r['status'])); ?></span><br><small>Attempt <?= (int)$r['attempts']; ?></small></td><td><div class="wa-error"><?= e($r['last_error']?:'-'); ?></div></td></tr><?php endforeach; ?></tbody></table></div>
<?php if($pages>1): ?><div class="wa-pager"><?php for($i=1;$i<=$pages;$i++): ?><a class="<?= $i===$page?'active':''; ?>" href="?page=<?= $i; ?>"><?= $i; ?></a><?php endfor; ?></div><?php endif; ?></section>
<section class="system-form-card"><h3 style="margin-top:0">Webhook Incoming Terakhir</h3><div class="system-table-wrap"><table class="system-table"><thead><tr><th>Waktu</th><th>Event</th><th>Device</th><th>Message ID</th></tr></thead><tbody><?php if(!$webhooks): ?><tr><td colspan="4" style="text-align:center;padding:20px">Belum ada event webhook.</td></tr><?php endif; ?><?php foreach($webhooks as $w): ?><tr><td><?= e(date('d/m/Y H:i:s',strtotime((string)$w['created_at']))); ?></td><td><?= e($w['event_type']?:'-'); ?></td><td><?= e($w['device_id']?:'-'); ?></td><td><?= e($w['external_message_id']?:'-'); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</main>
<?php require_once __DIR__.'/../../layouts/admin-footer.php'; ?>
