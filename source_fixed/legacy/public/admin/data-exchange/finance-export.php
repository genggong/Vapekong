<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/DataExchange/DataExchange.php';
Auth::requireLogin();

$db = Database::connect();
try {
    $db->query("SELECT 1 FROM finance_transactions LIMIT 1");
} catch (Throwable $e) {
    flash('error', 'Tabel transaksi keuangan belum tersedia.');
    legacy_redirect('admin/finance/install.php');
}
$format = strtolower(trim($_GET['format'] ?? 'xlsx'));
if (!in_array($format, ['xlsx', 'pdf'], true)) {
    http_response_code(400);
    exit('Format export tidak didukung.');
}

$today = date('Y-m-d');
$from = kp_exchange_safe_date($_GET['from'] ?? null, date('Y-m-01'));
$to = kp_exchange_safe_date($_GET['to'] ?? null, $today);
if ($from > $to) [$from, $to] = [$to, $from];
$type = trim($_GET['type'] ?? 'all');
if (!in_array($type, ['all', 'income', 'expense'], true)) $type = 'all';

$where = ['ft.transaction_date BETWEEN ? AND ?'];
$params = [$from, $to];
if ($type !== 'all') {
    $where[] = 'ft.type = ?';
    $params[] = $type;
}

$stmt = $db->prepare("\n    SELECT ft.*, fc.name AS category_name, u.name AS user_name\n    FROM finance_transactions ft\n    LEFT JOIN finance_categories fc ON fc.id = ft.category_id\n    LEFT JOIN users u ON u.id = ft.user_id\n    WHERE " . implode(' AND ', $where) . "\n    ORDER BY ft.transaction_date ASC, ft.id ASC\n");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$income = 0.0;
$expense = 0.0;
foreach ($transactions as $transaction) {
    if ($transaction['type'] === 'income') $income += (float)$transaction['amount'];
    if ($transaction['type'] === 'expense') $expense += (float)$transaction['amount'];
}

$debt = 0.0;
$receivable = 0.0;
try { $debt = (float)$db->query("SELECT COALESCE(SUM(amount-paid_amount),0) FROM debts WHERE status!='paid'")->fetchColumn(); } catch (Throwable $e) {}
try { $receivable = (float)$db->query("SELECT COALESCE(SUM(amount-paid_amount),0) FROM receivables WHERE status!='paid'")->fetchColumn(); } catch (Throwable $e) {}

$storeName = setting('store_name', setting('app_name', 'KasirPro UMKM'));
$periodText = date('d/m/Y', strtotime($from)) . ' - ' . date('d/m/Y', strtotime($to));
$typeText = $type === 'income' ? 'Pemasukan' : ($type === 'expense' ? 'Pengeluaran' : 'Semua Transaksi');
$fileBase = 'laporan-keuangan-' . $from . '-sd-' . $to;

if ($format === 'xlsx') {
    $summaryRows = [
        [['value' => 'LAPORAN KEUANGAN - ' . $storeName, 'style' => 1, 'height' => 28], '', ''],
        [['value' => 'Periode', 'style' => 7], ['value' => $periodText, 'style' => 5], ''],
        [['value' => 'Jenis Laporan', 'style' => 7], ['value' => $typeText, 'style' => 5], ''],
        [['value' => 'Total Pemasukan', 'style' => 7], ['value' => $income, 'type' => 'number', 'style' => 8], ''],
        [['value' => 'Total Pengeluaran', 'style' => 7], ['value' => $expense, 'type' => 'number', 'style' => 8], ''],
        [['value' => 'Saldo Bersih', 'style' => 7], ['value' => $income - $expense, 'type' => 'number', 'style' => 8], ''],
        [['value' => 'Sisa Hutang', 'style' => 7], ['value' => $debt, 'type' => 'number', 'style' => 8], ''],
        [['value' => 'Sisa Piutang', 'style' => 7], ['value' => $receivable, 'type' => 'number', 'style' => 8], ''],
        [['value' => 'Jumlah Transaksi', 'style' => 7], ['value' => count($transactions), 'type' => 'number', 'style' => 4], ''],
        [['value' => 'Diekspor', 'style' => 7], ['value' => date('d/m/Y H:i'), 'style' => 5], ''],
    ];

    $detailRows = [
        [['value' => 'RINCIAN TRANSAKSI KEUANGAN', 'style' => 1, 'height' => 28]],
        [['value' => 'Periode ' . $periodText . ' | ' . $typeText, 'style' => 5]],
        array_map(static fn($h) => ['value' => $h, 'style' => 2], ['Tanggal', 'Jenis', 'Judul', 'Kategori', 'Deskripsi', 'Nominal', 'Admin', 'Referensi']),
    ];
    foreach ($transactions as $transaction) {
        $reference = trim((string)($transaction['reference_type'] ?? ''));
        if ($reference !== '' && !empty($transaction['reference_id'])) $reference .= ' #' . $transaction['reference_id'];
        $detailRows[] = [
            ['value' => date('d/m/Y', strtotime($transaction['transaction_date'])), 'style' => 10],
            ['value' => $transaction['type'] === 'income' ? 'Pemasukan' : 'Pengeluaran', 'style' => 10],
            ['value' => $transaction['title'], 'style' => 5],
            ['value' => $transaction['category_name'] ?? '-', 'style' => 5],
            ['value' => $transaction['description'] ?? '', 'style' => 5],
            ['value' => (float)$transaction['amount'], 'type' => 'number', 'style' => 3],
            ['value' => $transaction['user_name'] ?? '-', 'style' => 5],
            ['value' => $reference ?: '-', 'style' => 5],
        ];
    }

    Auth::log('export_finance', 'Export laporan keuangan Excel periode ' . $from . ' s/d ' . $to);
    KpXlsx::download($fileBase . '.xlsx', [
        ['name' => 'Ringkasan', 'rows' => $summaryRows, 'widths' => [24, 28, 12], 'merges' => ['A1:C1']],
        ['name' => 'Transaksi', 'rows' => $detailRows, 'widths' => [14, 16, 30, 22, 38, 18, 20, 18], 'freeze_rows' => 3, 'auto_filter' => true, 'filter_row' => 3, 'merges' => ['A1:H1', 'A2:H2']],
    ]);
}

$pdfRows = [];
foreach ($transactions as $index => $transaction) {
    $pdfRows[] = [
        date('d/m/Y', strtotime($transaction['transaction_date'])),
        $transaction['type'] === 'income' ? 'Pemasukan' : 'Pengeluaran',
        ['value' => $transaction['title'], 'bold' => true],
        $transaction['category_name'] ?? '-',
        ['value' => kp_exchange_rupiah_number($transaction['amount']), 'align' => 'right'],
        $transaction['user_name'] ?? '-',
    ];
}

$pdf = new KpPdfDocument('L');
$pdf->setTitle('Laporan Keuangan - ' . $storeName);
$pdf->addPage();
$pdf->text(28, 28, 'LAPORAN KEUANGAN', 18, true);
$pdf->text(28, 47, $storeName, 10, true);
$pdf->text(28, 64, 'Periode: ' . $periodText . ' | Jenis: ' . $typeText, 9);
$pdf->text($pdf->getWidth() - 220, 28, 'Dicetak: ' . date('d/m/Y H:i'), 8, false, 'right', 190);

$summaryY = 78;
$summary = [
    ['Pemasukan', $income], ['Pengeluaran', $expense], ['Saldo Bersih', $income - $expense],
    ['Sisa Hutang', $debt], ['Sisa Piutang', $receivable], ['Transaksi', count($transactions)],
];
$boxWidth = 126;
foreach ($summary as $i => $item) {
    $x = 28 + ($i * ($boxWidth + 4));
    $pdf->fillRect($x, $summaryY, $boxWidth, 44, 1, 0.95, 0.95);
    $pdf->strokeRect($x, $summaryY, $boxWidth, 44, 0.82);
    $pdf->text($x + 7, $summaryY + 15, $item[0], 7.5, true);
    $value = $item[0] === 'Transaksi' ? (string)$item[1] : kp_exchange_rupiah_number($item[1]);
    $pdf->text($x + 7, $summaryY + 33, $value, 9, true);
}

$pdf->table(
    ['Tanggal', 'Jenis', 'Judul', 'Kategori', 'Nominal', 'Admin'],
    $pdfRows,
    [80, 90, 245, 130, 130, 110],
    ['y' => 136, 'font_size' => 7.3, 'row_height' => 22, 'page_title' => 'Laporan Keuangan - ' . $storeName]
);
Auth::log('export_finance', 'Export laporan keuangan PDF periode ' . $from . ' s/d ' . $to);
$pdf->download($fileBase . '.pdf');
