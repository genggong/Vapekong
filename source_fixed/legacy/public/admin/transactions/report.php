<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/DataExchange/DataExchange.php';
require_once __DIR__ . '/../branches/_helpers.php';
Auth::requireLogin();

$db = Database::connect();
branch_install($db);
$currentBranch = branch_current($db);
$branchId = (int)$currentBranch['id'];

$format = strtolower(trim($_GET['format'] ?? 'pdf'));
if (!in_array($format, ['pdf','xlsx'], true)) {
    http_response_code(400); exit('Format tidak didukung.');
}
$from = kp_exchange_safe_date($_GET['from'] ?? null, date('Y-m-01'));
$to = kp_exchange_safe_date($_GET['to'] ?? null, date('Y-m-d'));
if ($from > $to) { [$from,$to] = [$to,$from]; }

$stmt = $db->prepare("
    SELECT t.*, u.name AS cashier_name
    FROM transactions t
    LEFT JOIN users u ON u.id=t.cashier_id
    WHERE t.branch_id=? AND DATE(t.created_at) BETWEEN ? AND ?
    ORDER BY t.created_at ASC, t.id ASC
");
$stmt->execute([$branchId,$from,$to]);
$transactions = $stmt->fetchAll();

$itemStmt = $db->prepare("SELECT product_name,qty,unit_price,subtotal FROM transaction_items WHERE transaction_id=? ORDER BY id ASC");
$days = [];
foreach ($transactions as $trx) {
    $day = date('Y-m-d', strtotime($trx['created_at']));
    $itemStmt->execute([(int)$trx['id']]);
    $items = $itemStmt->fetchAll();
    $names = [];
    foreach ($items as $it) {
        $names[] = trim((string)$it['product_name']) . ' x' . (int)$it['qty'];
    }
    $trx['_products'] = implode(', ', $names);
    $days[$day]['rows'][] = $trx;
    $days[$day]['omzet'] = ($days[$day]['omzet'] ?? 0) + (float)($trx['grand_total'] ?? 0);
}
$storeName = setting('store_name', setting('app_name','KasirPro UMKM'));
$stamp = date('Ymd-His');

if ($format === 'xlsx') {
    $rows = [
        [['value'=>'LAPORAN OMZET HARIAN - '.$storeName,'style'=>1,'height'=>28]],
        [['value'=>'Periode '.date('d/m/Y',strtotime($from)).' s/d '.date('d/m/Y',strtotime($to)).' | Cabang '.($currentBranch['name'] ?? '-'),'style'=>5]],
        array_map(static fn($h)=>['value'=>$h,'style'=>2], ['Tanggal / Hari','Jam','Invoice','Produk','Kasir','Metode','Total']),
    ];
    foreach ($days as $day => $group) {
        $rows[] = [
            ['value'=>date('d/m/Y',strtotime($day)).' • Omzet '.kp_exchange_rupiah_number($group['omzet']),'style'=>1],
            ['value'=>'','style'=>1],['value'=>'','style'=>1],['value'=>'','style'=>1],
            ['value'=>'','style'=>1],['value'=>'','style'=>1],['value'=>'','style'=>1],
        ];
        foreach ($group['rows'] as $trx) {
            $rows[] = [
                ['value'=>date('d/m/Y',strtotime($trx['created_at'])),'style'=>5],
                ['value'=>date('H:i',strtotime($trx['created_at'])),'style'=>5],
                ['value'=>$trx['invoice_number'],'style'=>5],
                ['value'=>$trx['_products'] ?: '-','style'=>5],
                ['value'=>$trx['cashier_name'] ?? '-','style'=>5],
                ['value'=>payment_method_label($trx['payment_method'] ?? '-'),'style'=>5],
                ['value'=>(float)$trx['grand_total'],'type'=>'number','style'=>3],
            ];
        }
        $rows[] = array_fill(0,7,['value'=>'','style'=>5]);
    }

    Auth::log('export_daily_turnover','Export omzet harian Excel '.$from.' - '.$to);
    KpXlsx::download('laporan-omzet-harian-'.$stamp.'.xlsx', [[
        'name'=>'Omzet Harian',
        'rows'=>$rows,
        'widths'=>[22,10,24,58,22,18,18],
        'freeze_rows'=>3,
        'auto_filter'=>true,
        'filter_row'=>3,
        'merges'=>['A1:G1','A2:G2'],
    ]]);
}

$pdfRows = [];
foreach ($days as $day => $group) {
    $pdfRows[] = [
        ['value'=>date('d/m/Y',strtotime($day)),'bold'=>true],
        '',
        ['value'=>'OMZET HARIAN','bold'=>true],
        '',
        '',
        '',
        ['value'=>kp_exchange_rupiah_number($group['omzet']),'bold'=>true,'align'=>'right'],
    ];
    foreach ($group['rows'] as $trx) {
        $pdfRows[] = [
            date('d/m/Y',strtotime($trx['created_at'])),
            date('H:i',strtotime($trx['created_at'])),
            $trx['invoice_number'],
            $trx['_products'] ?: '-',
            $trx['cashier_name'] ?? '-',
            payment_method_label($trx['payment_method'] ?? '-'),
            ['value'=>kp_exchange_rupiah_number($trx['grand_total'] ?? 0),'align'=>'right'],
        ];
    }
    $pdfRows[] = ['', '', '', '', '', '', ''];
}

$pdf = new KpPdfDocument('L');
$pdf->setTitle('Laporan Omzet Harian - '.$storeName);
$pdf->addPage();
$pdf->text(28,30,'LAPORAN OMZET HARIAN',17,true);
$pdf->text(28,49,$storeName.' • '.($currentBranch['name'] ?? 'Cabang'),10,true);
$pdf->text(28,65,'Periode '.date('d/m/Y',strtotime($from)).' s/d '.date('d/m/Y',strtotime($to)),9);
$pdf->text($pdf->getWidth()-220,30,'Dicetak: '.date('d/m/Y H:i'),8,false,'right',190);
$pdf->line(28,78,$pdf->getWidth()-28,78,0.78);
$pdf->table(
    ['Tanggal','Jam','Invoice','Produk','Kasir','Metode','Total'],
    $pdfRows,
    [68,42,95,255,95,85,90],
    ['y'=>90,'font_size'=>7.1,'row_height'=>22,'page_title'=>'Laporan Omzet Harian - '.$storeName]
);
Auth::log('export_daily_turnover','Export omzet harian PDF '.$from.' - '.$to);
$pdf->download('laporan-omzet-harian-'.$stamp.'.pdf');
