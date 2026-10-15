<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireLogin();
$db = Database::connect();
sales_returns_install($db);

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT sr.*, t.invoice_number, u.name AS user_name, c.name AS customer_name, c.phone AS customer_phone
    FROM sales_returns sr
    LEFT JOIN transactions t ON t.id = sr.transaction_id
    LEFT JOIN users u ON u.id = sr.user_id
    LEFT JOIN customers c ON c.id = sr.customer_id
    WHERE sr.id = ? LIMIT 1");
$stmt->execute([$id]);
$return = $stmt->fetch();

if (!$return) {
    http_response_code(404);
    exit('Retur tidak ditemukan.');
}

$stmt = $db->prepare("SELECT * FROM sales_return_items WHERE return_id = ? ORDER BY id ASC");
$stmt->execute([$id]);
$items = $stmt->fetchAll();
$auto = isset($_GET['autoprint']);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bukti Retur <?= e($return['return_number']); ?></title>
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
<div class="receipt">
    <div class="center">
        <div class="store"><?= e(setting('store_name', 'KasirPro UMKM')); ?></div>
        <div class="muted">Bukti Retur / Refund</div>
    </div>
    <div class="line"></div>
    <div class="row"><span>No Retur</span><b><?= e($return['return_number']); ?></b></div>
    <div class="row"><span>Invoice</span><b><?= e($return['invoice_number'] ?? '-'); ?></b></div>
    <div class="row"><span>Tanggal</span><b><?= e(date('d/m/Y H:i', strtotime($return['return_date']))); ?></b></div>
    <div class="row"><span>Pelanggan</span><b><?= e($return['customer_name'] ?? '-'); ?></b></div>
    <div class="row"><span>Admin</span><b><?= e($return['user_name'] ?? '-'); ?></b></div>
    <div class="row"><span>Status</span><b><?= e(strtoupper($return['status'])); ?></b></div>
    <div class="line"></div>
    <?php foreach ($items as $item): ?>
        <div class="item">
            <b><?= e($item['product_name']); ?></b>
            <span><em><?= e($item['qty']); ?> x <?= rupiah($item['unit_price']); ?></em><strong><?= rupiah($item['subtotal']); ?></strong></span>
        </div>
    <?php endforeach; ?>
    <div class="line"></div>
    <div class="row total"><span>Total Refund</span><b><?= rupiah($return['total_refund']); ?></b></div>
    <?php if (!empty($return['reason'])): ?><div class="line"></div><div class="muted">Alasan: <?= e($return['reason']); ?></div><?php endif; ?>
    <div class="line"></div>
    <div class="center muted">Simpan bukti ini sebagai arsip retur.</div>
    <div class="btns"><button class="btn soft" onclick="history.back()">Kembali</button><button class="btn primary" onclick="window.print()">Print</button></div>
</div>
<?php if ($auto): ?><script>window.print();</script><?php endif; ?>
</body>
</html>
