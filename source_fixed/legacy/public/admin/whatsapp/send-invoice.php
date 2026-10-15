<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireLogin();
$db = Database::connect();
wa_install($db);

if (!is_post()) {
    legacy_redirect('admin/transactions/index.php');
}

$id = (int)($_POST['transaction_id'] ?? 0);
$target = trim((string)($_POST['target'] ?? ''));

if (!CSRF::verify()) {
    flash('error', 'Token keamanan tidak valid.');
    legacy_redirect('admin/transactions/detail.php?id=' . $id);
}

try {
    $stmt = $db->prepare("SELECT t.*, u.name AS cashier_name, c.name AS customer_name, c.phone AS customer_phone, c.member_code AS customer_member_code, c.points AS customer_points_total, b.name AS branch_name
        FROM transactions t
        LEFT JOIN users u ON u.id = t.cashier_id
        LEFT JOIN customers c ON c.id = t.customer_id
        LEFT JOIN branches b ON b.id = t.branch_id
        WHERE t.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $trx = $stmt->fetch();

    if (!$trx) {
        throw new Exception('Transaksi tidak ditemukan.');
    }

    if ($target === '') {
        $target = (string)($trx['customer_phone'] ?? '');
    }

    if ($target === '') {
        throw new Exception('Nomor WhatsApp pelanggan belum diisi. Masukkan nomor tujuan dulu.');
    }

    $stmt = $db->prepare("SELECT * FROM transaction_items WHERE transaction_id = ? ORDER BY id ASC");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

    $message = wa_invoice_message($trx, $items);
    wa_send_fonnte($db, $target, $message, $id);

    flash('success', 'Invoice berhasil dikirim ke WhatsApp.');
} catch (Throwable $e) {
    flash('error', $e->getMessage());
}

legacy_redirect('admin/transactions/detail.php?id=' . $id);
