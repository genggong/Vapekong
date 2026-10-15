<?php

require_once __DIR__ . '/Security/security.php';
security_basic_request_guard();
security_boot_session();
security_send_headers();

require_once __DIR__ . '/Core/Database.php';
require_once __DIR__ . '/Core/CSRF.php';
require_once __DIR__ . '/Core/Auth.php';
require_once __DIR__ . '/Helpers/helpers.php';

date_default_timezone_set(app_config('timezone', 'Asia/Jakarta'));

if (app_config('debug', false)) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}


if (function_exists('maintenance_guard')) {
    maintenance_guard();
}

