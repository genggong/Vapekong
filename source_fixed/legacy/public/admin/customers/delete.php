<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$db = Database::connect();

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    flash('error', 'Pelanggan tidak valid.');
    legacy_redirect('admin/customers/index.php');
}

try {
    $stmt = $db->prepare("DELETE FROM customers WHERE id=?");
    $stmt->execute([$id]);
    Auth::log('delete_customer', 'Menghapus pelanggan ID ' . $id);
    flash('success', 'Pelanggan berhasil dihapus.');
} catch (Throwable $e) {
    flash('error', 'Gagal menghapus pelanggan: ' . $e->getMessage());
}

legacy_redirect('admin/customers/index.php');
