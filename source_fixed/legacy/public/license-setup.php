<?php
require_once __DIR__ . '/../app/bootstrap.php';

// Modul lisensi telah dinonaktifkan. URL lama tetap aman dan dialihkan
// agar bookmark/cache lama tidak menampilkan halaman aktivasi lagi.
legacy_redirect(Auth::check() ? 'admin/dashboard.php' : 'login.php');
