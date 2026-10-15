<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Modules/Marketplace/Marketplace.php';
header('Content-Type: application/json; charset=utf-8');

function marketplace_pakasir_webhook_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::connect();
    marketplace_install($db);
    $secret = trim((string) setting('pakasir_webhook_token', ''));
    $incoming = trim((string) ($_GET['token'] ?? ''));
    if ($secret === '' || $incoming === '' || !hash_equals($secret, $incoming)) {
        marketplace_pakasir_webhook_json(['success'=>false,'message'=>'Token webhook tidak valid.'], 403);
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;
    $orderId = trim((string) ($payload['order_id'] ?? ''));
    if ($orderId === '') marketplace_pakasir_webhook_json(['success'=>false,'message'=>'order_id wajib tersedia.'], 400);
    $order = marketplace_get_order($db, $orderId);
    if (!$order) marketplace_pakasir_webhook_json(['success'=>false,'message'=>'Pesanan marketplace tidak ditemukan.'], 404);
    $updated = marketplace_apply_payment_webhook($db, $payload);
    // Best-effort WA delivery after DB update; failures stay queued for cron retry.
    try { if (function_exists('marketplace_wa_process_queue')) marketplace_wa_process_queue($db, 10); } catch (Throwable $ignore) {}
    marketplace_pakasir_webhook_json([
        'success'=>true,
        'message'=>'Webhook marketplace diproses.',
        'order_id'=>$orderId,
        'payment_status'=>$updated['payment_status'] ?? 'pending',
    ]);
} catch (Throwable $e) {
    marketplace_pakasir_webhook_json(['success'=>false,'message'=>$e->getMessage()], 422);
}
