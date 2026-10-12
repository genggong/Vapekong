<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin', 'owner']);

$backupDir = realpath(__DIR__ . '/../../../storage/backups');
$file = basename($_GET['file'] ?? '');
$path = $backupDir ? realpath($backupDir . '/' . $file) : false;

if (!$backupDir || !$path || strpos($path, $backupDir) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
