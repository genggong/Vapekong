<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

Auth::requireRole(['super_admin', 'owner']);

$db = Database::connect();
seed_default_role_permissions($db);

flash('success', 'Default hak akses role berhasil dibuat.');
legacy_redirect('admin/roles/index.php');
