<?php
require_once __DIR__ . '/_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    marketplace_api_json(['success' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

try {
    marketplace_api_throttle('create_order', 800);
    $payload = marketplace_api_payload();
    marketplace_api_require_csrf($payload);
    $db = Database::connect();
    marketplace_install($db);
    $result = marketplace_create_order($db, $payload);
    $url = legacy_base_url('pesanan/' . rawurlencode($result['order_number'])) . '?token=' . rawurlencode($result['public_token']);
    marketplace_api_json([
        'success' => true,
        'message' => $result['existing'] ? 'Pesanan sudah dibuat sebelumnya.' : 'Pesanan berhasil dibuat.',
        'order_number' => $result['order_number'],
        'redirect_url' => $url,
        'clear_cart' => true,
    ]);
} catch (Throwable $e) {
    marketplace_api_error($e);
}
