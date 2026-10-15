<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin','owner']);
$id = (int)($_GET['id'] ?? 0);
try { $db = Database::connect(); $stmt = $db->prepare("DELETE FROM categories WHERE id=?"); $stmt->execute([$id]); Auth::log('delete_category','Menghapus kategori ID '.$id); flash('success','Kategori berhasil dihapus.'); } catch(Throwable $e){ flash('error','Kategori tidak bisa dihapus karena masih dipakai produk. Nonaktifkan saja jika perlu.'); }
legacy_redirect('admin/categories/index.php');
