<?php
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$db = Database::connect();

function pakasir_webhook_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pakasir_webhook_config(): array
{
    return [
        'project' => trim((string) setting('pakasir_project', app_config('pakasir_project', ''))),
        'api_key' => trim((string) setting('pakasir_api_key', app_config('pakasir_api_key', ''))),
    ];
}

function pakasir_webhook_meta(array $trx): array
{
    if (empty($trx['note'])) return [];
    if (!preg_match('/\[PAKASIR_PAYMENT\](.*?)\[\/PAKASIR_PAYMENT\]/s', (string) $trx['note'], $m)) return [];
    $decoded = json_decode($m[1], true);
    return is_array($decoded) ? $decoded : [];
}


function pakasir_webhook_member_meta(array $trx): array
{
    if (empty($trx['note'])) return [];
    if (!preg_match('/\[MEMBER_POINTS\](.*?)\[\/MEMBER_POINTS\]/s', (string) $trx['note'], $m)) return [];
    $decoded = json_decode($m[1], true);
    return is_array($decoded) ? $decoded : [];
}

function pakasir_webhook_apply_member_points(PDO $db, array $trx, string $finalStatus): void
{
    $meta = pakasir_webhook_member_meta($trx);
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


function pakasir_webhook_normalize(?string $status): string
{
    $status = strtolower(trim((string) $status));
    if (in_array($status, ['completed', 'paid', 'success', 'settlement', 'settled'], true)) return 'success';
    if (in_array($status, ['failed', 'fail', 'expired', 'cancel', 'canceled', 'cancelled', 'deny', 'denied'], true)) return 'failed';
    return 'pending';
}

// Endpoint ini khusus pembayaran POS. Marketplace memakai marketplace-pakasir-webhook.php.
$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
if (!is_array($payload)) $payload = $_POST;
$orderId = trim((string) ($payload['order_id'] ?? ''));
$amount = (int) ($payload['amount'] ?? 0);
$project = trim((string) ($payload['project'] ?? ''));
$statusRaw = trim((string) ($payload['status'] ?? ''));
$method = trim((string) ($payload['payment_method'] ?? 'qris'));
if ($orderId === '' || $amount <= 0) {
    pakasir_webhook_json(['success' => false, 'message' => 'Payload webhook tidak lengkap.'], 400);
}

$config = pakasir_webhook_config();
if ($config['project'] !== '' && $project !== '' && $project !== $config['project']) {
    pakasir_webhook_json(['success' => false, 'message' => 'Project webhook tidak sesuai.'], 403);
}

$stmt = $db->prepare("SELECT * FROM transactions WHERE invoice_number = ? LIMIT 1");
$stmt->execute([$orderId]);
$trx = $stmt->fetch();

if (!$trx) {
    pakasir_webhook_json(['success' => false, 'message' => 'Transaksi tidak ditemukan.'], 404);
}

$meta = pakasir_webhook_meta($trx);
$expectedAmount = (int) ($meta['amount'] ?? $trx['grand_total'] ?? 0);

if ($expectedAmount !== $amount) {
    pakasir_webhook_json(['success' => false, 'message' => 'Amount webhook tidak sesuai.'], 422);
}

$status = pakasir_webhook_normalize($statusRaw);

if ($status === 'success') {
    try {
        $stmt = $db->prepare("UPDATE transactions SET status = 'success', paid_amount = ?, change_amount = 0, updated_at = NOW() WHERE id = ?");
        $stmt->execute([(float) ($trx['grand_total'] ?? 0), (int) $trx['id']]);
    } catch (Throwable $e) {
        $stmt = $db->prepare("UPDATE transactions SET status = 'success', paid_amount = ?, change_amount = 0 WHERE id = ?");
        $stmt->execute([(float) ($trx['grand_total'] ?? 0), (int) $trx['id']]);
    }
    pakasir_webhook_apply_member_points($db, $trx, 'success');
} elseif ($status === 'failed' && strtolower((string) $trx['status']) !== 'success') {
    $stmt = $db->prepare("UPDATE transactions SET status = 'failed' WHERE id = ?");
    $stmt->execute([(int) $trx['id']]);
    pakasir_webhook_apply_member_points($db, $trx, 'failed');
}

try {
    $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([
        $trx['cashier_id'] ?? null,
        $status === 'success' ? 'QRIS berhasil' : 'Update QRIS',
        'Invoice ' . $trx['invoice_number'] . ' status ' . $status . '.',
        $status === 'success' ? 'success' : ($status === 'failed' ? 'danger' : 'info'),
    ]);
} catch (Throwable $ignore) {}

pakasir_webhook_json([
    'success' => true,
    'message' => 'Webhook diproses.',
    'status' => $status,
    'invoice' => $trx['invoice_number'],
]);
