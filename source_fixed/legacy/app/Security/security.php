<?php

function security_send_headers(): void
{
    if (headers_sent()) return;

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');

    // Cache halaman admin sebaiknya tidak disimpan browser.
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/admin/') !== false) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}

function security_boot_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    // Sesi admin/kasir tetap aktif dan diperpanjang setiap kali aplikasi dibuka.
    // Logout hanya dilakukan melalui tombol logout atau ketika cookie browser dihapus.
    $sessionLifetime = 365 * 24 * 60 * 60;

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_lifetime', (string) $sessionLifetime);
    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $sessionLifetime,
            'path' => '/',
            'domain' => '',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params($sessionLifetime, '/', '', $https, true);
    }

    session_start();

    // Rolling session: masa aktif cookie diperbarui pada setiap request.
    if (!headers_sent() && session_id() !== '') {
        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), session_id(), [
                'expires' => time() + $sessionLifetime,
                'path' => '/',
                'domain' => '',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie(session_name(), session_id(), time() + $sessionLifetime, '/', '', $https, true);
        }
    }
}

function security_basic_request_guard(): void
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';

    // Blokir path traversal sederhana.
    if (strpos($uri, '..') !== false || strpos(rawurldecode($uri), '..') !== false) {
        http_response_code(400);
        exit('Bad request.');
    }
}
