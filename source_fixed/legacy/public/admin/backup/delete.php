<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin', 'owner']);

$backupDir = realpath(__DIR__ . '/../../../storage/backups');
$file = basename($_GET['file'] ?? '');
$path = $backupDir ? realpath($backupDir . '/' . $file) : false;

if ($backupDir && $path && strpos($path, $backupDir) === 0 && is_file($path)) {
    unlink($path);
    Auth::log('delete_backup', 'Menghapus backup ' . $file);
    flash('success', 'File backup berhasil dihapus.');
} else {
    flash('error', 'File backup tidak ditemukan.');
}

legacy_redirect('admin/backup/index.php');
