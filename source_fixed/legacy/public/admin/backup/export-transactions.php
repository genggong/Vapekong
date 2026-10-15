<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin', 'owner']);
$db = Database::connect();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="transaksi_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Invoice', 'Tanggal', 'Kasir', 'Metode Bayar', 'Subtotal', 'Diskon', 'Total', 'Dibayar', 'Kembali', 'Status']);

$stmt = $db->query("
    SELECT t.*, u.name AS cashier_name
    FROM transactions t
    LEFT JOIN users u ON u.id = t.cashier_id
    ORDER BY t.id DESC
");

while ($row = $stmt->fetch()) {
    fputcsv($out, [
        $row['id'],
        $row['invoice_number'],
        $row['created_at'],
        $row['cashier_name'] ?? '',
        $row['payment_method'] ?? '',
        $row['subtotal'] ?? 0,
        $row['discount'] ?? 0,
        $row['grand_total'] ?? 0,
        $row['paid_amount'] ?? 0,
        $row['change_amount'] ?? 0,
        $row['status'] ?? ''
    ]);
}

fclose($out);
exit;
