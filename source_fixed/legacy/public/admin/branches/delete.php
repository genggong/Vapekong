<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin','owner']);
$db = Database::connect();
branch_install($db);
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM branches WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$branch = $stmt->fetch();
if (!$branch) { flash('error','Cabang tidak ditemukan.'); legacy_redirect('admin/branches/index.php'); }
if ((int)$branch['is_main']) { flash('error','Cabang utama tidak bisa dinonaktifkan.'); legacy_redirect('admin/branches/index.php'); }
$db->prepare("UPDATE branches SET is_active=0, updated_at=NOW() WHERE id=?")->execute([$id]);
Auth::log('disable_branch','Nonaktifkan cabang ' . $branch['name']);
flash('success','Cabang berhasil dinonaktifkan.');
legacy_redirect('admin/branches/index.php');
