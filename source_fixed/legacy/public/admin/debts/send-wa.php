<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
if (file_exists(__DIR__ . '/../whatsapp/_helpers.php')) require_once __DIR__ . '/../whatsapp/_helpers.php';
Auth::requireRole(['super_admin','owner','admin','cashier']);
$db = Database::connect();
debt_install($db);
$id = (int)($_GET['id'] ?? 0);
try {
    debt_send_whatsapp($db, $id);
    flash('success','Tagihan WhatsApp berhasil dikirim.');
} catch (Throwable $e) {
    flash('error','Gagal kirim WhatsApp: ' . $e->getMessage());
}
legacy_redirect('admin/debts/detail.php?id=' . $id);
?>