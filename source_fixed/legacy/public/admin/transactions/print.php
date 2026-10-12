<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$db = Database::connect();
$id = (int) ($_GET['id'] ?? 0);
$autoPrint = (int) ($_GET['autoprint'] ?? 0);

$stmt = $db->prepare("SELECT t.*, u.name AS cashier_name, c.name AS customer_name, c.phone AS customer_phone, c.points AS customer_points_total FROM transactions t LEFT JOIN users u ON u.id = t.cashier_id LEFT JOIN customers c ON c.id = t.customer_id WHERE t.id = ? LIMIT 1");
$stmt->execute([$id]);
$trx = $stmt->fetch();
if (!$trx) { die('Transaksi tidak ditemukan.'); }

$stmt = $db->prepare("SELECT * FROM transaction_items WHERE transaction_id = ? ORDER BY id ASC");
$stmt->execute([$id]);
$items = $stmt->fetchAll();
$trxDate = $trx['transaction_date'] ?? $trx['created_at'] ?? date('Y-m-d H:i:s');
$memberPoints = [];
if (!empty($trx['note']) && preg_match('/\[MEMBER_POINTS\](.*?)\[\/MEMBER_POINTS\]/s', (string)$trx['note'], $m)) {
    $decoded = json_decode($m[1], true);
    if (is_array($decoded)) $memberPoints = $decoded;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cetak Struk <?= e($trx['invoice_number']); ?></title>
<style>
*{box-sizing:border-box}
:root{--red:#991b1b;--red2:#dc2626;--soft:#fff1f2;--line:#e5e7eb;--text:#111827;--muted:#64748b}
body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#fff7f7;color:var(--text)}
.print-toolbar{position:sticky;top:0;z-index:20;display:flex;gap:10px;justify-content:center;padding:12px;background:rgba(255,255,255,.94);border-bottom:1px solid #fee2e2;backdrop-filter:blur(12px)}
.print-toolbar button,.print-toolbar a{border:0;border-radius:999px;padding:11px 16px;font-weight:900;text-decoration:none;font-size:14px;cursor:pointer}
.btn-print{background:linear-gradient(135deg,#ef4444,#991b1b);color:#fff;box-shadow:0 12px 28px rgba(153,27,27,.18)}
.btn-back{background:#fff;color:var(--red);border:1px solid #fecaca!important}
.receipt-wrap{padding:18px}
.receipt{width:302px;max-width:100%;margin:0 auto;background:#fff;padding:14px;border-radius:14px;box-shadow:0 18px 50px rgba(127,29,29,.10)}
.center{text-align:center}.store{font-size:17px;font-weight:900;line-height:1.2}.muted{font-size:11px;color:#64748b;line-height:1.35}.line{border-top:1px dashed #94a3b8;margin:10px 0}.row{display:flex;justify-content:space-between;gap:10px;font-size:12px;margin:6px 0}.row span{max-width:58%;word-break:break-word}.row b{text-align:right;word-break:break-word}.item{font-size:12px;margin:9px 0}.item-name{font-weight:900;display:block;word-break:break-word}.item-meta{display:flex;justify-content:space-between;gap:8px;margin-top:4px;color:#475569}.total{font-size:14px;font-weight:900}.grand{font-size:16px}.badge{display:inline-block;padding:4px 9px;border-radius:999px;background:#fee2e2;color:#991b1b;font-size:10px;font-weight:900}.thanks{font-size:12px;font-weight:900}.signature{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:20px;font-size:11px;text-align:center}.sign-box{padding-top:45px;border-top:1px solid transparent}.sign-line{border-top:1px solid #111;padding-top:5px}
@page{size:80mm auto;margin:4mm}
@media print{body{background:#fff}.print-toolbar{display:none}.receipt-wrap{padding:0}.receipt{width:72mm;box-shadow:none;border:0;border-radius:0;margin:0 auto;padding:0}.no-print{display:none!important}}
</style>
</head>
<body>
<div class="print-toolbar no-print">
    <button class="btn-print" onclick="window.print()">Cetak</button>
    <a class="btn-back" href="<?= legacy_base_url('admin/transactions/detail.php?id=' . $trx['id']); ?>">Kembali</a>
</div>
<div class="receipt-wrap">
<div class="receipt">
    <div class="center">
        <div class="store"><?= e(setting('store_name', 'Toko Demo')); ?></div>
        <div class="muted"><?= e(setting('store_address', '')); ?></div>
        <div class="muted"><?= e(setting('store_phone', '')); ?></div>
        <div class="line"></div>
        <span class="badge">STRUK PENJUALAN</span>
    </div>
    <div class="line"></div>
    <div class="row"><span>Invoice</span><b><?= e($trx['invoice_number']); ?></b></div>
    <div class="row"><span>Tanggal</span><b><?= e(date('d/m/Y H:i', strtotime($trxDate))); ?></b></div>
    <div class="row"><span>Kasir</span><b><?= e($trx['cashier_name'] ?? '-'); ?></b></div>
    <?php if(!empty($trx['customer_name'])): ?><div class="row"><span>Pelanggan</span><b><?= e($trx['customer_name']); ?></b></div><?php endif; ?>
    <div class="line"></div>
    <?php foreach($items as $it): ?>
        <div class="item">
            <span class="item-name"><?= e($it['product_name']); ?></span>
            <?php if(!empty($it['price_level'])): ?><span class="muted">Level: <?= e($it['price_level']); ?></span><?php endif; ?>
            <div class="item-meta"><span><?= e($it['qty']); ?> x <?= rupiah($it['unit_price']); ?></span><b><?= rupiah($it['subtotal']); ?></b></div>
        </div>
    <?php endforeach; ?>
    <div class="line"></div>
    <div class="row"><span>Subtotal</span><b><?= rupiah($trx['subtotal']); ?></b></div>
    <div class="row"><span>Diskon</span><b><?= rupiah($trx['discount'] ?? 0); ?></b></div>
    <?php if(!empty($trx['debt_amount']) && (float)$trx['debt_amount'] > 0): ?><div class="row"><span>Piutang</span><b><?= rupiah($trx['debt_amount']); ?></b></div><?php endif; ?>
    <div class="row total grand"><span>Total</span><b><?= rupiah($trx['grand_total']); ?></b></div>
    <div class="row"><span>Bayar</span><b><?= rupiah($trx['paid_amount']); ?></b></div>
    <div class="row"><span>Kembali</span><b><?= rupiah($trx['change_amount']); ?></b></div>
    <div class="row"><span>Metode</span><b><?= e(payment_method_label($trx['payment_method'])); ?></b></div>
    <?php if($memberPoints): ?>
        <?php $redeemPoints = max(0, (int)($memberPoints['redeem_points'] ?? 0)); $earnPoints = max(0, (int)($memberPoints['earn_points'] ?? 0)); $totalPoints = max(0, (int)($trx['customer_points_total'] ?? 0)); ?>
        <?php if($redeemPoints > 0): ?><div class="row"><span>Poin ditukar</span><b>-<?= e($redeemPoints); ?> poin</b></div><?php endif; ?>
        <?php if(strtolower((string)($trx['status'] ?? 'success')) === 'success'): ?>
            <div class="row total"><span>Poin didapat</span><b>+<?= e($earnPoints); ?> poin</b></div>
            <div class="row total"><span>Total poin</span><b><?= e($totalPoints); ?> poin</b></div>
        <?php else: ?>
            <div class="row"><span>Poin setelah lunas</span><b>+<?= e($earnPoints); ?> poin</b></div>
            <div class="row total"><span>Total poin saat ini</span><b><?= e($totalPoints); ?> poin</b></div>
        <?php endif; ?>
    <?php endif; ?>
    <div class="line"></div>
    <div class="center thanks">Terima kasih sudah berbelanja</div>
</div>
</div>
<?php if($autoPrint): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),350));</script><?php endif; ?>
</body>
</html>