<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Modules/Marketplace/Marketplace.php';
header('Content-Type: application/json; charset=utf-8');

function delivery_webhook_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::connect();
    marketplace_install($db);
    $secret = trim((string) setting('marketplace_delivery_webhook_token', ''));
    $incoming = trim((string) ($_GET['token'] ?? ''));
    if ($secret === '' || $incoming === '' || !hash_equals($secret, $incoming)) {
        delivery_webhook_json(['success'=>false,'message'=>'Token webhook tidak valid.'], 403);
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;
    $orderNo = trim((string)($payload['order_no'] ?? $payload['order_number'] ?? ''));
    $awb = trim((string)($payload['cnote'] ?? $payload['awb'] ?? $payload['airway_bill'] ?? ''));
    $status = trim((string)($payload['status'] ?? $payload['order_status'] ?? ''));
    if ($orderNo === '') delivery_webhook_json(['success'=>false,'message'=>'order_no tidak tersedia.'], 400);

    $stmt=$db->prepare('SELECT * FROM online_orders WHERE delivery_order_no=? LIMIT 1');
    $stmt->execute([$orderNo]);
    $order=$stmt->fetch();
    if(!$order) delivery_webhook_json(['success'=>false,'message'=>'Order Shipping Delivery tidak ditemukan.'],404);
    $oldStatus=trim((string)($order['delivery_status'] ?? ''));
    $norm=strtolower($status);
    $isDelivered=$status!=='' && (str_contains($norm,'delivered') || str_contains($norm,'diterima') || str_contains($norm,'selesai'));
    $isShipped=$awb!=='' || ($status!=='' && (str_contains($norm,'pickup') || str_contains($norm,'dikirim') || str_contains($norm,'transit') || str_contains($norm,'delivery')));

    $sql="UPDATE online_orders SET delivery_status=CASE WHEN ?<>'' THEN ? ELSE delivery_status END,awb_number=CASE WHEN ?<>'' THEN ? ELSE awb_number END,delivery_last_sync_at=NOW(),updated_at=NOW()";
    $args=[$status,$status,$awb,$awb];
    if($isDelivered){
        $sql.=" ,order_status='completed',fulfillment_status='completed',completed_at=COALESCE(completed_at,NOW()),shipped_at=COALESCE(shipped_at,NOW())";
    } elseif($isShipped && !in_array($order['order_status'],['completed','cancelled','expired'],true)) {
        $sql.=" ,order_status='shipped',fulfillment_status='shipped',shipped_at=COALESCE(shipped_at,NOW())";
    }
    $sql.=' WHERE id=?'; $args[]=(int)$order['id'];
    $db->prepare($sql)->execute($args);
    if($status!=='' && $status!==$oldStatus) marketplace_add_event($db,(int)$order['id'],'delivery_webhook','Update kurir',$status.($awb!==''?' • Resi: '.$awb:''));
    delivery_webhook_json(['success'=>true,'message'=>'Webhook pengiriman diproses.','order_no'=>$orderNo,'awb'=>$awb,'status'=>$status]);
} catch(Throwable $e){
    delivery_webhook_json(['success'=>false,'message'=>$e->getMessage()],422);
}
