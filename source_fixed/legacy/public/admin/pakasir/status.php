<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$db = Database::connect();

function pakasir_status_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pakasir_status_config(): array
{
    return [
        'project' => trim((string) setting('pakasir_project', app_config('pakasir_project', ''))),
        'api_key' => trim((string) setting('pakasir_api_key', app_config('pakasir_api_key', ''))),
    ];
}

function pakasir_status_meta(array $trx): array
{
    if (empty($trx['note'])) {
        return [];
    }

    if (!preg_match('/\[PAKASIR_PAYMENT\](.*?)\[\/PAKASIR_PAYMENT\]/s', (string) $trx['note'], $m)) {
        return [];
    }

    $decoded = json_decode($m[1], true);
    return is_array($decoded) ? $decoded : [];
}


function pakasir_status_member_meta(array $trx): array
{
    if (empty($trx['note'])) return [];
    if (!preg_match('/\[MEMBER_POINTS\](.*?)\[\/MEMBER_POINTS\]/s', (string) $trx['note'], $m)) return [];
    $decoded = json_decode($m[1], true);
    return is_array($decoded) ? $decoded : [];
}

function pakasir_status_apply_member_points(PDO $db, array $trx, string $finalStatus): void
{
    $meta = pakasir_status_member_meta($trx);
    $customerId = (int) ($meta['customer_id'] ?? $trx['customer_id'] ?? 0);
    if ($customerId <= 0) return;

    $transactionId = (int) ($trx['id'] ?? 0);
    if ($transactionId <= 0) return;

    $db->exec("CREATE TABLE IF NOT EXISTS customer_points (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        customer_id BIGINT NOT NULL,
        transaction_id BIGINT NULL,
        type ENUM('in','out') NOT NULL DEFAULT 'in',
        points INT NOT NULL DEFAULT 0,
        description TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_customer_points_customer (customer_id),
        INDEX idx_customer_points_transaction (transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($finalStatus === 'success') {
        $points = (int) ($meta['earn_points'] ?? 0);
        if ($points <= 0) return;
        $stmt = $db->prepare("SELECT COUNT(*) FROM customer_points WHERE customer_id = ? AND transaction_id = ? AND type = 'in'");
        $stmt->execute([$customerId, $transactionId]);
        if ((int) $stmt->fetchColumn() > 0) return;

        $stmt = $db->prepare("INSERT INTO customer_points (customer_id, transaction_id, type, points, description, created_at) VALUES (?, ?, 'in', ?, ?, NOW())");
        $stmt->execute([$customerId, $transactionId, $points, 'Poin belanja invoice ' . ($trx['invoice_number'] ?? '-')]);
        $stmt = $db->prepare("UPDATE customers SET points = points + ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$points, $customerId]);
        return;
    }

    if ($finalStatus === 'failed') {
        $redeem = (int) ($meta['redeem_points'] ?? 0);
        if ($redeem <= 0) return;
        $stmt = $db->prepare("SELECT COUNT(*) FROM customer_points WHERE customer_id = ? AND transaction_id = ? AND type = 'in'");
        $stmt->execute([$customerId, $transactionId]);
        if ((int) $stmt->fetchColumn() > 0) return;

        $stmt = $db->prepare("INSERT INTO customer_points (customer_id, transaction_id, type, points, description, created_at) VALUES (?, ?, 'in', ?, ?, NOW())");
        $stmt->execute([$customerId, $transactionId, $redeem, 'Refund poin karena QRIS gagal/expired invoice ' . ($trx['invoice_number'] ?? '-')]);
        $stmt = $db->prepare("UPDATE customers SET points = points + ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$redeem, $customerId]);
    }
}


function pakasir_http_get_json(string $url): array
{
    $body = false;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new Exception('Tidak bisa mengecek status Pakasir: ' . ($error ?: 'koneksi gagal'));
        }

        if ($code >= 400) {
            throw new Exception('Pakasir mengembalikan HTTP ' . $code . ': ' . substr((string) $body, 0, 180));
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'header' => "Accept: application/json\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);

        if ($body === false) {
            throw new Exception('Tidak bisa mengecek status QRIS. Aktifkan curl atau allow_url_fopen.');
        }
    }

    $json = json_decode((string) $body, true);

    if (!is_array($json)) {
        throw new Exception('Respon status Pakasir bukan JSON: ' . substr((string) $body, 0, 180));
    }

    return $json;
}

function pakasir_normalize_status(?string $status): string
{
    $status = strtolower(trim((string) $status));

    if (in_array($status, ['completed', 'paid', 'success', 'settlement', 'settled'], true)) {
        return 'success';
    }

    if (in_array($status, ['failed', 'fail', 'expired', 'cancel', 'canceled', 'cancelled', 'deny', 'denied'], true)) {
        return 'failed';
    }

    return 'pending';
}

function pakasir_update_transaction(PDO $db, array $trx, array $meta, string $status, array $remote = []): void
{
    $current = strtolower((string) ($trx['status'] ?? ''));

    if ($status === 'success') {
        $paidAmount = (float) ($trx['grand_total'] ?? 0);
        $stmt = $db->prepare("UPDATE transactions SET status = 'success', paid_amount = ?, change_amount = 0, updated_at = NOW() WHERE id = ?");
        try {
            $stmt->execute([$paidAmount, (int) $trx['id']]);
        } catch (Throwable $e) {
            $stmt = $db->prepare("UPDATE transactions SET status = 'success', paid_amount = ?, change_amount = 0 WHERE id = ?");
            $stmt->execute([$paidAmount, (int) $trx['id']]);
        }
        pakasir_status_apply_member_points($db, $trx, 'success');
        return;
    }

    if ($status === 'failed' && $current !== 'success') {
        $stmt = $db->prepare("UPDATE transactions SET status = 'failed' WHERE id = ?");
        $stmt->execute([(int) $trx['id']]);
        pakasir_status_apply_member_points($db, $trx, 'failed');
    }
}

$id = (int) ($_GET['id'] ?? $_GET['transaction_id'] ?? 0);

if ($id <= 0) {
    pakasir_status_json(['success' => false, 'message' => 'ID transaksi tidak valid.'], 400);
}

$stmt = $db->prepare("SELECT * FROM transactions WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$trx = $stmt->fetch();

if (!$trx) {
    pakasir_status_json(['success' => false, 'message' => 'Transaksi tidak ditemukan.'], 404);
}

$meta = pakasir_status_meta($trx);

if (($trx['payment_method'] ?? '') !== 'qris_pakasir' || !$meta) {
    pakasir_status_json([
        'success' => true,
        'status' => strtolower((string) ($trx['status'] ?? 'success')),
        'message' => 'Transaksi bukan QRIS.',
        'transaction' => [
            'id' => (int) $trx['id'],
            'invoice' => $trx['invoice_number'],
            'status' => strtolower((string) ($trx['status'] ?? 'success')),
        ],
    ]);
}

$config = pakasir_status_config();

if ($config['project'] === '' || $config['api_key'] === '') {
    pakasir_status_json(['success' => false, 'message' => 'Slug Project atau API Key QRIS belum diisi di Pengaturan.'], 400);
}

$amount = (int) ($meta['amount'] ?? $trx['grand_total'] ?? 0);
$orderId = (string) ($meta['order_id'] ?? $trx['invoice_number']);

try {
    $url = 'https://app.pakasir.com/api/transactiondetail?' . http_build_query([
        'project' => $config['project'],
        'amount' => $amount,
        'order_id' => $orderId,
        'api_key' => $config['api_key'],
    ]);

    $json = pakasir_http_get_json($url);
    $remote = $json['transaction'] ?? $json['payment'] ?? $json;
    $remoteStatusRaw = is_array($remote) ? (string) ($remote['status'] ?? '') : '';
    $status = pakasir_normalize_status($remoteStatusRaw);

    if ($status === 'pending' && !empty($meta['expired_at'])) {
        $expiredAt = strtotime((string) $meta['expired_at']);
        if ($expiredAt && $expiredAt < time()) {
            $status = 'failed';
            $remoteStatusRaw = $remoteStatusRaw ?: 'expired';
        }
    }

    pakasir_update_transaction($db, $trx, $meta, $status, is_array($remote) ? $remote : []);

    pakasir_status_json([
        'success' => true,
        'status' => $status,
        'remote_status' => $remoteStatusRaw ?: $status,
        'message' => $status === 'success'
            ? 'Pembayaran QRIS berhasil dikonfirmasi.'
            : ($status === 'failed' ? 'Pembayaran QRIS gagal atau expired.' : 'Menunggu pembayaran QRIS.'),
        'transaction' => [
            'id' => (int) $trx['id'],
            'invoice' => $trx['invoice_number'],
            'status' => $status,
            'payment_method' => $trx['payment_method'],
            'amount' => $amount,
            'order_id' => $orderId,
        ],
        'remote' => is_array($remote) ? $remote : [],
    ]);
} catch (Throwable $e) {
    pakasir_status_json([
        'success' => false,
        'status' => strtolower((string) ($trx['status'] ?? 'pending')),
        'message' => $e->getMessage(),
    ], 500);
}
