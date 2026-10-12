<?php

require_once __DIR__ . '/../app/bootstrap.php';

Auth::logout();
flash('success', 'Kamu berhasil logout.');
legacy_redirect('login');
