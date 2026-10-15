<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();
$db = Database::connect();

$stmt = $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id IS NULL OR user_id=?");
$stmt->execute([Auth::id()]);
flash('success', 'Semua notifikasi ditandai sudah dibaca.');

legacy_redirect('admin/notifications/index.php');
