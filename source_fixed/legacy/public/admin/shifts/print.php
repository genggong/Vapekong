<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../branches/_helpers.php';

$db = Database::connect();
branch_install($db);
shift_ensure_tables($db);
$id = (int)($_GET['id'] ?? 0);
$shift = shift_find($db, $id);
if (!$shift) { die('Shift tidak ditemukan.'); }
$calc = shift_calculate($db, $shift);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cetak Shift <?= e($shift['shift_code']); ?></title>
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
.wrap{width:302px;max-width:100%;margin:18px auto;background:#fff;padding:14px;border-radius:14px;box-shadow:0 18px 50px rgba(127,29,29,.10)}@media print{.wrap{width:72mm;box-shadow:none;border:0;border-radius:0;margin:0 auto;padding:0}}</style>
</head>
<body>
<div class="wrap">
    <div class="center">
        <div class="title"><?= e(setting('store_name', 'Toko Demo')); ?></div>
        <div class="muted">Laporan Shift Kasir</div>
        <p><span class="badge"><?= e($shift['shift_code']); ?></span></p>
    </div>
    <div class="row"><span>Kasir</span><b><?= e($shift['user_name'] ?? '-'); ?></b></div>
    <div class="row"><span>Lokasi</span><b><?= e(shift_location_label($shift)); ?></b></div>
    <div class="row"><span>Status</span><b><?= e(strtoupper($shift['status'])); ?></b></div>
    <div class="row"><span>Dibuka</span><b><?= e(date('d/m/Y H:i', strtotime($shift['opened_at']))); ?></b></div>
    <div class="row"><span>Ditutup</span><b><?= $shift['closed_at'] ? e(date('d/m/Y H:i', strtotime($shift['closed_at']))) : '-'; ?></b></div>
    <div class="row"><span>Modal Awal</span><b><?= rupiah($shift['opening_cash']); ?></b></div>
    <div class="row"><span>Penjualan Cash</span><b><?= rupiah($calc['cash_sales']); ?></b></div>
    <div class="row"><span>Non Cash</span><b><?= rupiah($calc['non_cash_sales']); ?></b></div>
    <div class="row"><span>Refund</span><b><?= rupiah($calc['total_refund']); ?></b></div>
    <div class="row"><span>Total Transaksi</span><b><?= e($calc['total_transactions']); ?></b></div>
    <div class="row"><span>Cash Seharusnya</span><b><?= rupiah($calc['expected_cash']); ?></b></div>
    <div class="row"><span>Cash Aktual</span><b><?= rupiah($shift['actual_cash']); ?></b></div>
    <div class="row"><span>Selisih</span><b><?= rupiah($shift['cash_difference']); ?></b></div>
    <p class="muted">Catatan buka: <?= e($shift['opening_note'] ?: '-'); ?></p>
    <p class="muted">Catatan tutup: <?= e($shift['closing_note'] ?: '-'); ?></p>
    <p class="center muted">Dicetak: <?= e(date('d/m/Y H:i')); ?></p>
    <div class="center no-print"><button onclick="window.print()">Print</button></div>
</div>
<script>window.print();</script>
</body>
</html>
