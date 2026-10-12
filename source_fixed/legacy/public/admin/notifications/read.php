<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$db = Database::connect();

$id = (int)($_GET['id'] ?? 0);

if ($id > 0) {
    $stmt = $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND (user_id IS NULL OR user_id=?)");
    $stmt->execute([$id, Auth::id()]);
    flash('success', 'Notifikasi ditandai sudah dibaca.');
}

legacy_redirect('admin/notifications/index.php');
