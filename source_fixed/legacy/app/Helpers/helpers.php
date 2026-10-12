<?php

function app_config(string $key, $default = null)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../Config/app.php';
    }
    return $config[$key] ?? $default;
}

function legacy_base_url(string $path = ''): string
{
    $configured = trim((string) app_config('base_url', ''));

    if ($configured !== '') {
        $baseUrl = rtrim($configured, '/');
    } else {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));

        // Clean storefront routes may be internally rewritten to /public/* by Apache.
        // Always resolve the application root, not the current /store or /admin directory.
        $basePath = '';
        $rootResolved = false;
        $markers = ['/public/', '/store/', '/admin/'];
        foreach ($markers as $marker) {
            $pos = strpos($script, $marker);
            if ($pos !== false) {
                $basePath = substr($script, 0, $pos);
                $rootResolved = true;
                break;
            }
        }

        if (!$rootResolved) {
            if (preg_match('~^(.*?)/public(?:/|$)~', $script, $match)) {
                $basePath = $match[1] ?? '';
            } else {
                $dir = str_replace('\\', '/', dirname($script));
                $basePath = ($dir === '/' || $dir === '.' || $dir === '\\') ? '' : rtrim($dir, '/');
            }
        }

        $baseUrl = rtrim($scheme . '://' . $host . $basePath, '/');
    }

    $path = ltrim($path, '/');
    return $path ? $baseUrl . '/' . $path : $baseUrl;
}

function legacy_redirect(string $path)
{
    header('Location: ' . legacy_base_url($path));
    exit;
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah($number): string
{
    return 'Rp ' . number_format((float) $number, 0, ',', '.');
}


function payment_method_label(?string $method): string
{
    $method = strtolower(trim((string) $method));
    $labels = [
        'cash' => 'Cash',
        'cash_debt' => 'Cash + Piutang',
        'qris' => 'QRIS',
        'qris_pakasir' => 'QRIS Otomatis',
        'qris_manual' => 'QRIS Manual',
        'transfer' => 'Transfer',
    ];
    return $labels[$method] ?? ($method !== '' ? strtoupper(str_replace('_', ' ', $method)) : '-');
}

function now_datetime(): string
{
    return date('Y-m-d H:i:s');
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function legacy_old(string $key, $default = '')
{
    return $_SESSION['_old'][$key] ?? $default;
}

function flash(string $key, $message = null)
{
    if ($message === null) {
        if (!empty($_SESSION['_flash'][$key])) {
            $msg = $_SESSION['_flash'][$key];
            unset($_SESSION['_flash'][$key]);
            return $msg;
        }
        return null;
    }
    $_SESSION['_flash'][$key] = $message;
}

function set_old(array $data): void
{
    $_SESSION['_old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

function setting(string $key, $default = null)
{
    try {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row['setting_value'] ?? $default;
    } catch (Throwable $e) {
        return $default;
    }
}


function maintenance_is_enabled(): bool
{
    return strtolower((string) setting('maintenance_mode', 'off')) === 'on';
}

function maintenance_allowed_role(): bool
{
    if (!class_exists('Auth')) return false;
    $role = Auth::role();
    return in_array($role, ['super_admin', 'owner'], true);
}

function maintenance_should_bypass(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    $allowed = [
        '/maintenance.php',
        '/login.php',
        '/logout.php',
        '/admin/settings/index.php',
        '/courier/',
        '/kurir',
        '/assets/',
        '/uploads/',
        '/pwa/',
        '/manifest',
        '/service-worker',
        '/favicon',
    ];

    foreach ($allowed as $path) {
        if (strpos($uri, $path) !== false || strpos($script, $path) !== false) {
            return true;
        }
    }

    return maintenance_allowed_role();
}

function maintenance_guard(): void
{
    if (!maintenance_is_enabled()) return;
    if (maintenance_should_bypass()) return;

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || strpos($accept, 'application/json') !== false;

    if ($isAjax) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => setting('maintenance_message', 'Sistem sedang maintenance. Silakan coba lagi nanti.'),
            'maintenance' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: ' . legacy_base_url('maintenance.php'));
    exit;
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    $text = trim($text, '-');
    return $text ?: 'item-' . time();
}

function pagination_meta(int $total, int $page, int $perPage): array
{
    $lastPage = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $lastPage));
    $offset = ($page - 1) * $perPage;
    return compact('total', 'page', 'perPage', 'lastPage', 'offset');
}

function upload_product_image(array $file): ?string
{
    if (empty($file['name']) || empty($file['tmp_name'])) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new Exception('Upload gambar gagal.');
    }

    $maxSize = 3 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxSize) {
        throw new Exception('Ukuran gambar maksimal 3MB.');
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        throw new Exception('Format gambar harus JPG, PNG, atau WEBP.');
    }

    $dir = __DIR__ . '/../../public/uploads/products';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $filename = 'product-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new Exception('Gagal menyimpan gambar produk.');
    }

    return 'uploads/products/' . $filename;
}
