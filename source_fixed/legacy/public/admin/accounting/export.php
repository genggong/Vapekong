<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/../../../app/Modules/Accounting/Accounting.php';
require_once __DIR__ . '/../../../app/Modules/DataExchange/DataExchange.php';

Auth::requirePermission('finance.view');
$db = Database::connect();
branch_install($db);

$format = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));
if (!in_array($format, ['xlsx', 'pdf'], true)) {
    http_response_code(400);
    exit('Format export tidak didukung.');
}

$from = accounting_safe_date($_GET['from'] ?? null, date('Y-m-01'));
$to = accounting_safe_date($_GET['to'] ?? null, date('Y-m-d'));
if ($from > $to) [$from, $to] = [$to, $from];

if (branch_is_admin()) {
    $branchId = max(0, (int)($_GET['branch_id'] ?? 0));
} else {
    $branchId = branch_current_id($db);
}

$report = accounting_build_report($db, $from, $to, $branchId);
$summary = $report['summary'];
$storeName = setting('store_name', setting('app_name', 'KasirPro UMKM'));
$periodText = date('d/m/Y', strtotime($from)) . ' - ' . date('d/m/Y', strtotime($to));
$branchText = $report['branch_name'];
$fileBranch = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $branchText));
$fileBranch = trim($fileBranch, '-') ?: 'semua-cabang';
$fileBase = 'laporan-akuntansi-' . $fileBranch . '-' . $from . '-sd-' . $to;

if ($format === 'xlsx') {
    $summaryRows = [
        [['value' => 'LAPORAN AKUNTANSI - ' . $storeName, 'style' => 1, 'height' => 30], '', '', ''],
        [['value' => 'Cabang', 'style' => 7], ['value' => $branchText, 'style' => 5], '', ''],
        [['value' => 'Periode', 'style' => 7], ['value' => $periodText, 'style' => 5], '', ''],
        [['value' => 'Dibuat', 'style' => 7], ['value' => date('d/m/Y H:i'), 'style' => 5], '', ''],
        ['', '', '', ''],
        [['value' => 'Penjualan Bruto', 'style' => 7], ['value' => $summary['gross_sales'], 'type' => 'number', 'style' => 8], ['value' => 'Retur Penjualan', 'style' => 7], ['value' => $summary['refunds'], 'type' => 'number', 'style' => 8]],
        [['value' => 'Penjualan Bersih', 'style' => 7], ['value' => $summary['net_sales'], 'type' => 'number', 'style' => 8], ['value' => 'Harga Pokok (HPP)', 'style' => 7], ['value' => $summary['cogs'], 'type' => 'number', 'style' => 8]],
        [['value' => 'Laba Kotor', 'style' => 7], ['value' => $summary['gross_profit'], 'type' => 'number', 'style' => 8], ['value' => 'Beban Operasional', 'style' => 7], ['value' => $summary['operating_expense'], 'type' => 'number', 'style' => 8]],
        [['value' => 'Pemasukan Lain', 'style' => 7], ['value' => $summary['other_income'], 'type' => 'number', 'style' => 8], ['value' => 'Laba Bersih', 'style' => 7], ['value' => $summary['net_profit'], 'type' => 'number', 'style' => 8]],
        [['value' => 'Kas Masuk', 'style' => 7], ['value' => $summary['cash_in'], 'type' => 'number', 'style' => 8], ['value' => 'Kas Keluar', 'style' => 7], ['value' => $summary['cash_out'], 'type' => 'number', 'style' => 8]],
        [['value' => 'Arus Kas Bersih', 'style' => 7], ['value' => $summary['net_cashflow'], 'type' => 'number', 'style' => 8], ['value' => 'Margin Bersih', 'style' => 7], ['value' => round((float)$summary['margin'], 2), 'type' => 'number', 'style' => 4]],
        [['value' => 'Jumlah Transaksi', 'style' => 7], ['value' => (int)$summary['transaction_count'], 'type' => 'number', 'style' => 4], ['value' => 'Rata-rata Transaksi', 'style' => 7], ['value' => $summary['average_transaction'], 'type' => 'number', 'style' => 8]],
        ['', '', '', ''],
        [['value' => 'Piutang Berjalan', 'style' => 7], ['value' => $summary['receivables'], 'type' => 'number', 'style' => 8], ['value' => 'Hutang Berjalan', 'style' => 7], ['value' => $summary['payables'], 'type' => 'number', 'style' => 8]],
        [['value' => 'Nilai Persediaan', 'style' => 7], ['value' => $summary['inventory_value'], 'type' => 'number', 'style' => 8], '', ''],
        ['', '', '', ''],
        [['value' => 'Catatan Perhitungan', 'style' => 7], ['value' => 'Penjualan berasal dari POS. Retur mengurangi penjualan dan HPP. Pembelian stok tidak dihitung sebagai beban operasional agar tidak terjadi perhitungan ganda.', 'style' => 5], '', ''],
    ];

    $branchRows = [
        [['value' => 'RINGKASAN PER CABANG', 'style' => 1, 'height' => 28]],
        [['value' => 'Periode ' . $periodText, 'style' => 5]],
        array_map(static fn($h) => ['value' => $h, 'style' => 2], [
            'Kode', 'Cabang', 'Penjualan Bruto', 'Retur', 'Penjualan Bersih', 'HPP', 'Laba Kotor',
            'Pemasukan Lain', 'Beban Operasional', 'Laba Bersih', 'Kas Masuk', 'Kas Keluar',
            'Arus Kas Bersih', 'Transaksi', 'Rata-rata', 'Margin %', 'Piutang', 'Hutang', 'Nilai Persediaan'
        ]),
    ];
    foreach ($report['branches'] as $branch) {
        $branchRows[] = [
            ['value' => $branch['branch_code'], 'style' => 5],
            ['value' => $branch['branch_name'], 'style' => 5],
            ['value' => $branch['gross_sales'], 'type' => 'number', 'style' => 3],
            ['value' => $branch['refunds'], 'type' => 'number', 'style' => 3],
            ['value' => $branch['net_sales'], 'type' => 'number', 'style' => 3],
            ['value' => $branch['cogs'], 'type' => 'number', 'style' => 3],
            ['value' => $branch['gross_profit'], 'type' => 'number', 'style' => 3],
            ['value' => $branch['other_income'], 'type' => 'number', 'style' => 3],
            ['value' => $branch['operating_expense'], 'type' => 'number', 'style' => 3],
            ['value' => $branch['net_profit'], 'type' => 'number', 'style' => 3],
            ['value' => $branch['cash_in'], 'type' => 'number', 'style' => 3],
            ['value' => $branch['cash_out'], 'type' => 'number', 'style' => 3],
            ['value' => $branch['net_cashflow'], 'type' => 'number', 'style' => 3],
            ['value' => (int)$branch['transaction_count'], 'type' => 'number', 'style' => 4],
            ['value' => $branch['average_transaction'], 'type' => 'number', 'style' => 3],
            ['value' => round((float)$branch['margin'], 2), 'type' => 'number', 'style' => 4],
            ['value' => $branch['receivables'], 'type' => 'number', 'style' => 3],
            ['value' => $branch['payables'], 'type' => 'number', 'style' => 3],
            ['value' => $branch['inventory_value'], 'type' => 'number', 'style' => 3],
        ];
    }

    $dailyRows = [
        [['value' => 'REKAP HARIAN', 'style' => 1, 'height' => 28]],
        [['value' => $branchText . ' | ' . $periodText, 'style' => 5]],
        array_map(static fn($h) => ['value' => $h, 'style' => 2], [
            'Tanggal', 'Transaksi', 'Penjualan Bruto', 'Retur', 'Penjualan Bersih', 'HPP',
            'Laba Kotor', 'Pemasukan Lain', 'Beban Operasional', 'Laba Bersih', 'Kas Masuk', 'Kas Keluar', 'Arus Kas Bersih'
        ]),
    ];
    foreach ($report['daily'] as $day) {
        $dailyRows[] = [
            ['value' => date('d/m/Y', strtotime($day['date'])), 'style' => 10],
            ['value' => (int)$day['transaction_count'], 'type' => 'number', 'style' => 4],
            ['value' => $day['gross_sales'], 'type' => 'number', 'style' => 3],
            ['value' => $day['refunds'], 'type' => 'number', 'style' => 3],
            ['value' => $day['net_sales'], 'type' => 'number', 'style' => 3],
            ['value' => $day['cogs'], 'type' => 'number', 'style' => 3],
            ['value' => $day['gross_profit'], 'type' => 'number', 'style' => 3],
            ['value' => $day['other_income'], 'type' => 'number', 'style' => 3],
            ['value' => $day['operating_expense'], 'type' => 'number', 'style' => 3],
            ['value' => $day['net_profit'], 'type' => 'number', 'style' => 3],
            ['value' => $day['cash_in'], 'type' => 'number', 'style' => 3],
            ['value' => $day['cash_out'], 'type' => 'number', 'style' => 3],
            ['value' => $day['net_cashflow'], 'type' => 'number', 'style' => 3],
        ];
    }

    $paymentRows = [
        [['value' => 'KOMPOSISI PEMBAYARAN', 'style' => 1, 'height' => 28]],
        [['value' => 'Metode', 'style' => 2], ['value' => 'Nominal', 'style' => 2]],
    ];
    foreach ($report['payments'] as $payment) {
        $paymentRows[] = [
            ['value' => $payment['label'], 'style' => 5],
            ['value' => $payment['amount'], 'type' => 'number', 'style' => 3],
        ];
    }

    Auth::log('export_accounting', 'Export laporan akuntansi Excel ' . $branchText . ' periode ' . $from . ' s/d ' . $to);
    KpXlsx::download($fileBase . '.xlsx', [
        ['name' => 'Ringkasan', 'rows' => $summaryRows, 'widths' => [25, 34, 25, 34], 'merges' => ['A1:D1', 'B17:D17']],
        ['name' => 'Per Cabang', 'rows' => $branchRows, 'widths' => [12, 25, 18, 16, 18, 18, 18, 18, 20, 18, 18, 18, 18, 12, 18, 12, 18, 18, 20], 'freeze_rows' => 3, 'auto_filter' => true, 'filter_row' => 3, 'merges' => ['A1:S1', 'A2:S2']],
        ['name' => 'Harian', 'rows' => $dailyRows, 'widths' => [14, 12, 18, 16, 18, 18, 18, 18, 20, 18, 18, 18, 18], 'freeze_rows' => 3, 'auto_filter' => true, 'filter_row' => 3, 'merges' => ['A1:M1', 'A2:M2']],
        ['name' => 'Pembayaran', 'rows' => $paymentRows, 'widths' => [30, 22], 'merges' => ['A1:B1']],
    ]);
}

$pdf = new KpPdfDocument('L');
$pdf->setTitle('Laporan Akuntansi - ' . $storeName);
$pdf->addPage();
$pdf->text(28, 28, 'LAPORAN AKUNTANSI', 18, true);
$pdf->text(28, 48, $storeName, 11, true);
$pdf->text(28, 65, 'Cabang: ' . $branchText . ' | Periode: ' . $periodText, 9);
$pdf->text($pdf->getWidth() - 220, 28, 'Dicetak: ' . date('d/m/Y H:i'), 8, false, 'right', 190);

$summaryItems = [
    ['Penjualan Bersih', $summary['net_sales']],
    ['HPP', $summary['cogs']],
    ['Laba Kotor', $summary['gross_profit']],
    ['Laba Bersih', $summary['net_profit']],
    ['Arus Kas Bersih', $summary['net_cashflow']],
    ['Nilai Persediaan', $summary['inventory_value']],
];
$boxWidth = 126;
foreach ($summaryItems as $i => $item) {
    $x = 28 + ($i * ($boxWidth + 4));
    $pdf->fillRect($x, 80, $boxWidth, 46, 1, 0.96, 0.96);
    $pdf->strokeRect($x, 80, $boxWidth, 46, 0.82);
    $pdf->text($x + 7, 96, $item[0], 7.5, true);
    $pdf->text($x + 7, 115, kp_exchange_rupiah_number($item[1]), 8.5, true);
}

$branchPdfRows = [];
foreach ($report['branches'] as $branch) {
    $branchPdfRows[] = [
        ['value' => $branch['branch_name'], 'bold' => true],
        ['value' => kp_exchange_rupiah_number($branch['net_sales']), 'align' => 'right'],
        ['value' => kp_exchange_rupiah_number($branch['refunds']), 'align' => 'right'],
        ['value' => kp_exchange_rupiah_number($branch['cogs']), 'align' => 'right'],
        ['value' => kp_exchange_rupiah_number($branch['operating_expense']), 'align' => 'right'],
        ['value' => kp_exchange_rupiah_number($branch['net_profit']), 'align' => 'right', 'bold' => true],
        ['value' => kp_exchange_rupiah_number($branch['net_cashflow']), 'align' => 'right'],
        ['value' => number_format((float)$branch['margin'], 1, ',', '.') . '%', 'align' => 'right'],
    ];
}
$pdf->table(
    ['Cabang', 'Penjualan', 'Retur', 'HPP', 'Beban', 'Laba Bersih', 'Arus Kas', 'Margin'],
    $branchPdfRows,
    [126, 105, 82, 96, 90, 105, 100, 70],
    ['y' => 145, 'font_size' => 7.2, 'row_height' => 23, 'page_title' => 'Ringkasan Cabang - ' . $storeName]
);

$pdf->addPage();
$pdf->text(28, 28, 'REKAP HARIAN', 16, true);
$pdf->text(28, 48, $branchText . ' | ' . $periodText, 9);
$dailyPdfRows = [];
foreach ($report['daily'] as $day) {
    $dailyPdfRows[] = [
        date('d/m/Y', strtotime($day['date'])),
        (string)(int)$day['transaction_count'],
        ['value' => kp_exchange_rupiah_number($day['net_sales']), 'align' => 'right'],
        ['value' => kp_exchange_rupiah_number($day['refunds']), 'align' => 'right'],
        ['value' => kp_exchange_rupiah_number($day['cogs']), 'align' => 'right'],
        ['value' => kp_exchange_rupiah_number($day['gross_profit']), 'align' => 'right'],
        ['value' => kp_exchange_rupiah_number($day['operating_expense']), 'align' => 'right'],
        ['value' => kp_exchange_rupiah_number($day['net_profit']), 'align' => 'right', 'bold' => true],
        ['value' => kp_exchange_rupiah_number($day['net_cashflow']), 'align' => 'right'],
    ];
}
$pdf->table(
    ['Tanggal', 'Trx', 'Penjualan', 'Retur', 'HPP', 'Laba Kotor', 'Beban', 'Laba Bersih', 'Arus Kas'],
    $dailyPdfRows,
    [72, 40, 95, 82, 90, 95, 88, 100, 95],
    ['y' => 68, 'font_size' => 6.8, 'row_height' => 21, 'page_title' => 'Rekap Harian - ' . $storeName]
);

if ($report['payments']) {
    $pdf->addPage();
    $pdf->text(28, 28, 'KOMPOSISI PEMBAYARAN', 16, true);
    $pdf->text(28, 48, $branchText . ' | ' . $periodText, 9);
    $paymentPdfRows = [];
    foreach ($report['payments'] as $payment) {
        $paymentPdfRows[] = [
            ['value' => $payment['label'], 'bold' => true],
            ['value' => kp_exchange_rupiah_number($payment['amount']), 'align' => 'right'],
        ];
    }
    $pdf->table(['Metode Pembayaran', 'Nominal'], $paymentPdfRows, [360, 220], [
        'y' => 70, 'font_size' => 8, 'row_height' => 24, 'page_title' => 'Komposisi Pembayaran - ' . $storeName
    ]);
}

Auth::log('export_accounting', 'Export laporan akuntansi PDF ' . $branchText . ' periode ' . $from . ' s/d ' . $to);
$pdf->download($fileBase . '.pdf');
