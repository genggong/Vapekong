<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$db = Database::connect();
branch_install($db);

$id = (int)($_GET['id'] ?? 0);
$back = $_GET['back'] ?? 'admin/branches/index.php';
$allowedBack = preg_match('/^[a-zA-Z0-9_\-\/\.\?=&]+$/', $back) ? $back : 'admin/branches/index.php';

if ($id <= 0) {
    unset($_SESSION['active_branch_id']);
    flash('success', 'Cabang aktif direset.');
    legacy_redirect($allowedBack);
}

$stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$id]);
$branch = $stmt->fetch();

if (!$branch) {
    flash('error', 'Cabang tidak ditemukan atau nonaktif.');
    legacy_redirect('admin/branches/index.php');
}

$_SESSION['active_branch_id'] = (int)$branch['id'];
flash('success', 'Cabang aktif diganti ke ' . $branch['name'] . '.');
legacy_redirect($allowedBack);
