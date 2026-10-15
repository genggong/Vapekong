<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin','owner']);
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/../warehouse/_helpers.php';
$db = Database::connect();
branch_install($db);
purchases_install($db);
warehouse_install($db);
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT p.*, s.name supplier_name, s.phone supplier_phone, s.address supplier_address, u.name user_name, w.name warehouse_name, b.name branch_name FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id LEFT JOIN users u ON u.id=p.user_id LEFT JOIN warehouses w ON w.id=p.warehouse_id LEFT JOIN branches b ON b.id=p.branch_id WHERE p.id=? LIMIT 1");
$stmt->execute([$id]);
$purchase = $stmt->fetch();
if (!$purchase) die('Pembelian tidak ditemukan.');
$stmt = $db->prepare("SELECT * FROM purchase_items WHERE purchase_id=? ORDER BY id ASC");
$stmt->execute([$id]);
$items = $stmt->fetchAll();
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cetak Pembelian <?= e($purchase['purchase_number']); ?></title><style>
*{box-sizing:border-box}
:root{--red:#991b1b;--red2:#dc2626;--soft:#fff1f2;--line:#e5e7eb;--text:#111827;--muted:#64748b}
body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#f8fafc;color:var(--text)}
.print-toolbar{position:sticky;top:0;z-index:20;display:flex;gap:10px;justify-content:center;padding:12px;background:rgba(255,255,255,.94);border-bottom:1px solid #fee2e2;backdrop-filter:blur(12px)}
.print-toolbar button,.print-toolbar a{border:0;border-radius:999px;padding:11px 16px;font-weight:900;text-decoration:none;font-size:14px;cursor:pointer}
.btn-print{background:linear-gradient(135deg,#ef4444,#991b1b);color:#fff;box-shadow:0 12px 28px rgba(153,27,27,.18)}
.btn-back{background:#fff;color:var(--red);border:1px solid #fecaca!important}
.sheet{max-width:960px;margin:22px auto;background:#fff;border:1px solid #fee2e2;border-radius:22px;padding:28px;box-shadow:0 18px 60px rgba(127,29,29,.08)}
.header{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;border-bottom:3px solid var(--red);padding-bottom:16px;margin-bottom:18px}
.brand h1{margin:0;color:var(--red);font-size:28px}.brand p,.doc-meta p{margin:4px 0;color:var(--muted);font-size:13px}.doc-title{text-align:right}.doc-title h2{margin:0;color:#111827;font-size:24px}.badge{display:inline-block;padding:6px 12px;border-radius:999px;background:#fee2e2;color:var(--red);font-size:12px;font-weight:900}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:16px 0}.card{border:1px solid #fee2e2;border-radius:16px;padding:14px;background:#fffafa}.card h3{margin:0 0 10px;color:var(--red);font-size:15px}.row{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px dashed #fecaca;font-size:13px}.row:last-child{border-bottom:0}.row b{text-align:right}
table{width:100%;border-collapse:collapse;margin-top:16px;font-size:12px}th{background:linear-gradient(135deg,#dc2626,#991b1b);color:#fff;text-align:left;padding:10px;border:1px solid #991b1b}td{border:1px solid #e5e7eb;padding:9px;vertical-align:top}tfoot th,tfoot td{background:#fff1f2;color:#7f1d1d;font-weight:900}.right{text-align:right}.center{text-align:center}.plus{color:#15803d;font-weight:900}.minus{color:#dc2626;font-weight:900}.note{margin-top:16px;border:1px dashed #fecaca;border-radius:14px;padding:12px;background:#fff7f7;font-size:13px}.signature{display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;margin-top:36px;text-align:center;font-size:12px}.sign-line{padding-top:60px;border-bottom:1px solid #111}
@page{size:A4;margin:12mm}
@media print{body{background:#fff}.print-toolbar{display:none}.sheet{box-shadow:none;border:0;border-radius:0;margin:0;max-width:none;padding:0}.header{break-inside:avoid}table{page-break-inside:auto}tr{page-break-inside:avoid;page-break-after:auto}.card{break-inside:avoid}}
@media(max-width:700px){.sheet{margin:12px;padding:18px;border-radius:18px}.header,.grid{display:block}.doc-title{text-align:left;margin-top:12px}.signature{grid-template-columns:1fr}}
</style></head><body><div class="sheet"><button onclick="window.print()">Print</button><div class="head"><div><h1><?= e(setting('store_name','Toko Demo')); ?></h1><p><?= e(setting('store_address','Indonesia')); ?><br><?= e(setting('store_phone','')); ?></p></div><div><h2>INVOICE PEMBELIAN</h2><b><?= e($purchase['purchase_number']); ?></b><br><?= e(date('d/m/Y', strtotime($purchase['purchase_date']))); ?></div></div><div class="box"><b>Supplier:</b> <?= e($purchase['supplier_name'] ?: 'Supplier Umum'); ?><br><b>Invoice Supplier:</b> <?= e($purchase['supplier_invoice'] ?: '-'); ?><br><b>Status:</b> <?= e(purchases_status_label($purchase['payment_status'])); ?><br><b>Tujuan Stok:</b> <?= e(($purchase['stock_destination_type'] ?? 'branch') === 'warehouse' ? ($purchase['warehouse_name'] ?: 'Gudang') : ($purchase['branch_name'] ?: 'Cabang Utama')); ?></div><table><thead><tr><th>Produk</th><th>Qty</th><th>Harga</th><th>Total</th></tr></thead><tbody><?php foreach($items as $it): ?><tr><td><?= e($it['product_name']); ?><?= !empty($it['variant_name']) ? ' - '.e($it['variant_name']) : ''; ?><br><small><?= e($it['variant_sku'] ?: $it['product_code']); ?></small></td><td><?= e($it['qty']); ?> <?= e($it['unit']); ?></td><td><?= purchases_money($it['purchase_price']); ?></td><td><?= purchases_money($it['line_total']); ?></td></tr><?php endforeach; ?></tbody></table><div class="totals"><div><span>Subtotal</span><b><?= purchases_money($purchase['subtotal']); ?></b></div><div><span>Diskon</span><b><?= purchases_money($purchase['discount']); ?></b></div><div><span>Biaya</span><b><?= purchases_money($purchase['shipping_cost']); ?></b></div><div class="grand"><span>Total</span><b><?= purchases_money($purchase['grand_total']); ?></b></div><div><span>Dibayar</span><b><?= purchases_money($purchase['paid_amount']); ?></b></div><div><span>Hutang</span><b><?= purchases_money($purchase['debt_amount']); ?></b></div></div><p><b>Catatan:</b> <?= e($purchase['note'] ?: '-'); ?></p></div></body></html>
