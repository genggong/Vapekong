<?php

/**
 * Marketplace WhatsApp bridge for Teknorilis WA Gateway API.
 * Docs: https://gateway.teknorilis.com/docs/api
 * Uses Bearer auth + Idempotency-Key and DB queue so checkout/webhook is not blocked.
 */

function marketplace_wa_install(PDO $db): void
{
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS marketplace_wa_queue (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT NULL,
            customer_id BIGINT NULL,
            recipient VARCHAR(40) NOT NULL,
            event_key VARCHAR(80) NOT NULL,
            message TEXT NOT NULL,
            idempotency_key VARCHAR(190) NOT NULL,
            status ENUM('pending','sending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            gateway_message_id VARCHAR(190) NULL,
            last_error TEXT NULL,
            next_attempt_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_marketplace_wa_idempotency (idempotency_key),
            INDEX idx_marketplace_wa_status (status,next_attempt_at),
            INDEX idx_marketplace_wa_order (order_id),
            INDEX idx_marketplace_wa_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ignore) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS marketplace_wa_webhook_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(120) NULL,
            device_id VARCHAR(80) NULL,
            external_message_id VARCHAR(190) NULL,
            payload_json LONGTEXT NOT NULL,
            signature_valid TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_marketplace_wa_webhook_created (created_at),
            INDEX idx_marketplace_wa_webhook_event (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ignore) {}
}

function marketplace_wa_config(): array
{
    return [
        'enabled' => setting('marketplace_wa_enabled', 'off') === 'on',
        'base_url' => rtrim((string) setting('marketplace_wa_base_url', 'https://gateway.teknorilis.com/api/v1'), '/'),
        'api_token' => trim((string) setting('marketplace_wa_api_token', '')),
        'device_id' => (int) setting('marketplace_wa_device_id', '0'),
        'device_name' => trim((string) setting('marketplace_wa_device_name', '')),
        'admin_phone' => marketplace_wa_normalize_phone((string) setting('marketplace_wa_admin_phone', '')),
        'webhook_secret' => trim((string) setting('marketplace_wa_webhook_secret', '')),
    ];
}

function marketplace_wa_event_options(): array
{
    return [
        'pending_payment' => 'Pesanan dibuat',
        'payment_created' => 'Instruksi pembayaran',
        'paid' => 'Pembayaran berhasil',
        'processing' => 'Pesanan diproses',
        'ready_pickup' => 'Pesanan siap diambil',
        'shipped' => 'Pesanan dikirim',
        'completed' => 'Pesanan selesai',
        'expired' => 'Pembayaran kedaluwarsa',
        'cancelled' => 'Pesanan dibatalkan',
        'service_case' => 'Komplain / retur / refund',
    ];
}

function marketplace_wa_event_enabled(string $eventKey): bool
{
    if (!marketplace_wa_config()['enabled']) return false;
    $key = 'marketplace_wa_notify_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($eventKey));
    return setting($key, in_array($eventKey, ['pending_payment','paid','processing','ready_pickup','shipped','completed','cancelled','expired','service_case'], true) ? 'on' : 'off') === 'on';
}

function marketplace_wa_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?: '';
    if ($digits === '') return '';
    if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
    if (str_starts_with($digits, '0')) $digits = '62' . substr($digits, 1);
    elseif (str_starts_with($digits, '8')) $digits = '62' . $digits;
    if (!str_starts_with($digits, '62')) return $digits;
    return $digits;
}

function marketplace_wa_api(string $path, string $method = 'GET', ?array $body = null, ?string $idempotencyKey = null): array
{
    $cfg = marketplace_wa_config();
    if ($cfg['api_token'] === '') throw new RuntimeException('API Token Teknorilis WA Gateway belum diisi.');
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $cfg['api_token'],
    ];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if ($idempotencyKey !== null && trim($idempotencyKey) !== '') $headers[] = 'Idempotency-Key: ' . substr(trim($idempotencyKey), 0, 190);
    return marketplace_http_json($cfg['base_url'] . '/' . ltrim($path, '/'), $method, $headers, $body);
}

function marketplace_wa_devices(): array
{
    $json = marketplace_wa_api('/devices');
    $rows = is_array($json['data'] ?? null) ? $json['data'] : [];
    $out = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) continue;
        $out[] = [
            'id' => $id,
            'name' => trim((string)($row['name'] ?? ('Device #' . $id))),
            'phone_number' => trim((string)($row['phone_number'] ?? '')),
            'status' => strtolower(trim((string)($row['status'] ?? 'unknown'))),
        ];
    }
    return $out;
}

function marketplace_wa_usage(): array
{
    return marketplace_wa_api('/account/usage');
}

function marketplace_wa_send_text(string $recipient, string $message, string $idempotencyKey): array
{
    $cfg = marketplace_wa_config();
    if (!$cfg['enabled']) throw new RuntimeException('WhatsApp Marketplace sedang nonaktif.');
    if ($cfg['device_id'] <= 0) throw new RuntimeException('Device WhatsApp Marketplace belum dipilih.');
    $recipient = marketplace_wa_normalize_phone($recipient);
    if ($recipient === '' || strlen($recipient) < 10) throw new RuntimeException('Nomor WhatsApp penerima tidak valid.');
    $message = trim($message);
    if ($message === '') throw new RuntimeException('Isi pesan WhatsApp kosong.');
    return marketplace_wa_api('/messages/text', 'POST', [
        'device_id' => $cfg['device_id'],
        'recipient' => $recipient,
        'message' => $message,
    ], $idempotencyKey);
}

function marketplace_wa_order_message(PDO $db, int $orderId, string $eventKey, string $title = '', string $description = ''): ?array
{
    if (!marketplace_wa_event_enabled($eventKey)) return null;
    $stmt = $db->prepare("SELECT o.*,c.name AS customer_name,c.phone AS customer_phone FROM online_orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE o.id=? LIMIT 1");
    $stmt->execute([$orderId]);
    $o = $stmt->fetch();
    if (!$o) return null;
    $recipient = marketplace_wa_normalize_phone((string)($o['recipient_phone'] ?: $o['customer_phone'] ?? ''));
    if ($recipient === '') return null;
    $store = trim((string)setting('marketplace_site_title', '')) ?: trim((string)setting('store_name', 'Toko'));
    $name = trim((string)($o['recipient_name'] ?: $o['customer_name'] ?: 'Pelanggan'));
    $lines = [
        '*' . $store . '*',
        'Halo ' . $name . ',',
        '',
        '*' . ($title !== '' ? $title : marketplace_order_status_label($eventKey)) . '*',
        'No. Pesanan: ' . $o['order_number'],
    ];
    if ($eventKey === 'pending_payment' || $eventKey === 'payment_created') {
        $lines[] = 'Total: ' . rupiah((float)($o['payment_total'] ?: $o['grand_total']));
        if (!empty($o['payment_expired_at'])) $lines[] = 'Batas bayar: ' . date('d-m-Y H:i', strtotime((string)$o['payment_expired_at']));
    }
    if ($eventKey === 'shipped') {
        $lines[] = 'Kurir: ' . ((string)$o['courier_code']==='store' ? ((string)$o['courier_name'] ?: 'Kurir Toko') : (strtoupper((string)$o['courier_code']) . ' ' . (string)$o['courier_service']));
        if ((string)$o['courier_code']!=='store' && !empty($o['awb_number'])) $lines[] = 'Resi: ' . $o['awb_number'];
    }
    if ($description !== '') $lines[] = $description;
    $lines[] = '';
    $lines[] = 'Cek pesanan: ' . legacy_base_url('store/order.php') . '?order=' . rawurlencode((string)$o['order_number']) . '&token=' . rawurlencode((string)$o['public_token']);
    return ['recipient'=>$recipient,'message'=>implode("\n", $lines),'customer_id'=>(int)($o['customer_id'] ?? 0)];
}

function marketplace_wa_enqueue(PDO $db, string $recipient, string $eventKey, string $message, string $idempotencyKey, ?int $orderId = null, ?int $customerId = null): bool
{
    marketplace_wa_install($db);
    $recipient = marketplace_wa_normalize_phone($recipient);
    if ($recipient === '' || trim($message) === '' || trim($idempotencyKey) === '') return false;
    try {
        $stmt = $db->prepare("INSERT IGNORE INTO marketplace_wa_queue(order_id,customer_id,recipient,event_key,message,idempotency_key,status,attempts,created_at) VALUES(?,?,?,?,?,?,'pending',0,NOW())");
        $stmt->execute([$orderId ?: null,$customerId ?: null,$recipient,$eventKey,$message,substr($idempotencyKey,0,190)]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) { return false; }
}

function marketplace_wa_enqueue_order_event(PDO $db, int $orderId, string $eventKey, string $title = '', string $description = '', ?int $eventId = null): bool
{
    $payload = marketplace_wa_order_message($db, $orderId, $eventKey, $title, $description);
    if (!$payload) return false;
    $idem = 'mkt-order-' . $orderId . '-event-' . ($eventId ?: substr(hash('sha256',$eventKey.'|'.$title.'|'.$description),0,24));
    return marketplace_wa_enqueue($db,$payload['recipient'],$eventKey,$payload['message'],$idem,$orderId,(int)$payload['customer_id']);
}

function marketplace_wa_enqueue_admin(PDO $db, string $eventKey, string $message, string $idempotencyKey, ?int $orderId = null): bool
{
    $cfg = marketplace_wa_config();
    if (!$cfg['enabled'] || $cfg['admin_phone'] === '') return false;
    return marketplace_wa_enqueue($db,$cfg['admin_phone'],$eventKey,$message,$idempotencyKey,$orderId,null);
}

function marketplace_wa_process_queue(PDO $db, int $limit = 30): array
{
    marketplace_wa_install($db);
    $cfg = marketplace_wa_config();
    if (!$cfg['enabled'] || $cfg['api_token'] === '' || $cfg['device_id'] <= 0) return ['checked'=>0,'sent'=>0,'failed'=>0,'skipped'=>0];
    $limit = max(1,min(100,$limit));
    try { $db->exec("UPDATE marketplace_wa_queue SET status='pending',next_attempt_at=NOW() WHERE status='sending' AND updated_at < (NOW() - INTERVAL 10 MINUTE)"); } catch (Throwable $ignore) {}
    $rows = $db->query("SELECT * FROM marketplace_wa_queue WHERE status='pending' AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) ORDER BY id ASC LIMIT {$limit}")->fetchAll() ?: [];
    $out=['checked'=>0,'sent'=>0,'failed'=>0,'skipped'=>0];
    foreach ($rows as $row) {
        $out['checked']++;
        $id=(int)$row['id'];
        try {
            $db->prepare("UPDATE marketplace_wa_queue SET status='sending',attempts=attempts+1,updated_at=NOW() WHERE id=? AND status='pending'")->execute([$id]);
            $json = marketplace_wa_send_text((string)$row['recipient'],(string)$row['message'],(string)$row['idempotency_key']);
            $messageId = (string)($json['data']['id'] ?? $json['data']['message_id'] ?? $json['message_id'] ?? $json['id'] ?? '');
            $db->prepare("UPDATE marketplace_wa_queue SET status='sent',gateway_message_id=?,last_error=NULL,sent_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$messageId ?: null,$id]);
            $out['sent']++;
        } catch (Throwable $e) {
            $attempts=(int)$row['attempts']+1;
            $final=$attempts>=5;
            $delay=min(60, max(2, (int)pow(2,min(5,$attempts))));
            $next=date('Y-m-d H:i:s',time()+$delay*60);
            $db->prepare("UPDATE marketplace_wa_queue SET status=?,last_error=?,next_attempt_at=?,updated_at=NOW() WHERE id=?")->execute([$final?'failed':'pending',substr($e->getMessage(),0,1000),$final?null:$next,$id]);
            $out['failed']++;
        }
    }
    return $out;
}

function marketplace_wa_test(PDO $db, string $recipient): array
{
    marketplace_wa_install($db);
    $store = trim((string)setting('marketplace_site_title','')) ?: trim((string)setting('store_name','Toko'));
    $idem = 'mkt-test-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)),0,8);
    return marketplace_wa_send_text($recipient, "*{$store}*\nTes koneksi WhatsApp Marketplace berhasil.\nWaktu: " . date('d-m-Y H:i:s'), $idem);
}
