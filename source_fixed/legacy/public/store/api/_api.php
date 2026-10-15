<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';

function marketplace_api_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


function marketplace_api_throttle(string $key, int $milliseconds = 300): void
{
    $milliseconds = max(50, min(5000, $milliseconds));
    $sessionKey = '_marketplace_api_' . preg_replace('/[^a-z0-9_-]/i', '', $key);
    $now = microtime(true);
    $last = (float) ($_SESSION[$sessionKey] ?? 0);
    if ($last > 0 && (($now - $last) * 1000) < $milliseconds) {
        marketplace_api_json(['success' => false, 'message' => 'Permintaan terlalu cepat. Coba lagi sebentar.'], 429);
    }
    $_SESSION[$sessionKey] = $now;
}

function marketplace_api_payload(): array
{
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($type, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

function marketplace_api_require_csrf(array $payload): void
{
    $token = (string) ($payload['_csrf_token'] ?? '');
    $expected = (string) ($_SESSION['_csrf_token'] ?? '');
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        marketplace_api_json(['success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman checkout.'], 419);
    }
}

function marketplace_api_error(Throwable $e, int $defaultStatus = 422): void
{
    $message = trim($e->getMessage()) ?: 'Terjadi kesalahan pada server.';
    $status = $defaultStatus;
    if ($e instanceof InvalidArgumentException) $status = 422;
    elseif ($e instanceof RuntimeException) $status = 422;
    marketplace_api_json(['success' => false, 'message' => $message], $status);
}
