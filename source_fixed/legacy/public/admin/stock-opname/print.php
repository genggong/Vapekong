<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$db = Database::connect();
opname_ensure_tables($db);
$id = (int)($_GET['id'] ?? 0);
$opname = opname_get($db, $id);
if (!$opname) { die('Sesi opname tidak ditemukan.'); }
$items = opname_items($db, $id);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cetak Stock Opname <?= e($opname['code']); ?></title>
<style>
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
</style>
</head>
<body>
<div class="print-toolbar no-print"><button class="btn-print" onclick="window.print()">Cetak</button><a class="btn-back" href="<?= legacy_base_url('admin/stock-opname/detail.php?id=' . $opname['id']); ?>">Kembali</a></div>
<div class="sheet">
    <div class="header">
        <div class="brand"><h1><?= e(setting('store_name','Toko Demo')); ?></h1><p><?= e(setting('store_address','')); ?></p><p><?= e(setting('store_phone','')); ?></p></div>
        <div class="doc-title"><h2>Laporan Stock Opname</h2><p><span class="badge"><?= e($opname['code']); ?></span></p><p><?= e(date('d/m/Y H:i', strtotime($opname['created_at']))); ?></p></div>
    </div>
    <div class="grid">
        <div class="card"><h3>Informasi Sesi</h3><div class="row"><span>Judul</span><b><?= e($opname['title']); ?></b></div><div class="row"><span>Status</span><b><?= e(strtoupper($opname['status'])); ?></b></div><div class="row"><span>Total Produk</span><b><?= e($opname['total_items']); ?></b></div><div class="row"><span>Dicek</span><b><?= e($opname['total_checked']); ?></b></div></div>
        <div class="card"><h3>Ringkasan Selisih</h3><div class="row"><span>Selisih Plus</span><b class="plus">+<?= e($opname['total_plus']); ?> / <?= rupiah($opname['total_plus_value'] ?? 0); ?></b></div><div class="row"><span>Selisih Minus</span><b class="minus">-<?= e($opname['total_minus']); ?> / <?= rupiah($opname['total_minus_value'] ?? 0); ?></b></div></div>
    </div>
    <table>
        <thead><tr><th>Produk</th><th>Kode</th><th class="right">Sistem</th><th class="right">Fisik</th><th class="right">Selisih</th><th class="right">Nilai</th><th>Alasan</th><th>Catatan</th></tr></thead>
        <tbody><?php foreach($items as $it): $diff=(int)$it['difference']; ?><tr><td><?= e($it['product_name']); ?></td><td><?= e($it['product_code']); ?></td><td class="right"><?= e($it['system_stock']); ?></td><td class="right"><?= e($it['physical_stock']); ?></td><td class="right <?= $diff>=0?'plus':'minus'; ?>"><?= $diff>0?'+':''; ?><?= e($diff); ?></td><td class="right"><?= ($diff>0?'+':($diff<0?'-':'')); ?><?= rupiah(abs((float)($it['difference_value'] ?? 0))); ?></td><td><?= e($it['reason'] ?: '-'); ?></td><td><?= e($it['note'] ?: '-'); ?></td></tr><?php endforeach; ?></tbody>
    </table>
    <div class="signature"><div><div class="sign-line"></div>Admin</div><div><div class="sign-line"></div>Checker</div><div><div class="sign-line"></div>Owner</div></div>
</div>
</body></html>