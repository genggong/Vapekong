<?php
require_once __DIR__ . '/_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    marketplace_api_json(['success' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

try {
    marketplace_api_throttle('retry_payment', 1200);
    $payload = marketplace_api_payload();
    marketplace_api_require_csrf($payload);
    $orderNumber = trim((string) ($payload['order'] ?? ''));
    $token = trim((string) ($payload['token'] ?? ''));
    $db = Database::connect();
    $order = marketplace_get_order($db, $orderNumber, $token);
    if (!$order) marketplace_api_json(['success' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
    $order = marketplace_retry_payment($db, $order);
    marketplace_api_json([
        'success' => true,
        'message' => 'Instruksi pembayaran berhasil dibuat.',
        'payment_status' => $order['payment_status'],
    ]);
} catch (Throwable $e) {
    marketplace_api_error($e, 502);
}
