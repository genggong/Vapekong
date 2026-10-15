<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin', 'owner']);
$db = Database::connect();

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    flash('error', 'User tidak valid.');
    legacy_redirect('admin/users/index.php');
}

if ((int)$id === (int)Auth::id()) {
    flash('error', 'Tidak bisa menghapus akun sendiri.');
    legacy_redirect('admin/users/index.php');
}

try {
    $stmt = $db->prepare("DELETE FROM users WHERE id=?");
    $stmt->execute([$id]);
    Auth::log('delete_user', 'Menghapus user ID ' . $id);
    flash('success', 'User berhasil dihapus.');
} catch (Throwable $e) {
    flash('error', 'Gagal menghapus user: ' . $e->getMessage());
}

legacy_redirect('admin/users/index.php');
