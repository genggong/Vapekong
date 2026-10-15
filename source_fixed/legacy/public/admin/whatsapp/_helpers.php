<?php
function wa_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function wa_install(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        transaction_id BIGINT NULL,
        target VARCHAR(80) NOT NULL,
        message MEDIUMTEXT NULL,
        provider VARCHAR(50) NOT NULL DEFAULT 'fonnte',
        status ENUM('success','failed') NOT NULL DEFAULT 'failed',
        response MEDIUMTEXT NULL,
        message_id VARCHAR(120) NULL,
        request_id VARCHAR(120) NULL,
        sent_by BIGINT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_whatsapp_logs_transaction (transaction_id),
        INDEX idx_whatsapp_logs_status (status),
        INDEX idx_whatsapp_logs_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function wa_setting(string $key, string $default = ''): string
{
    return trim((string) setting($key, $default));
}

function wa_enabled(): bool
{
    return wa_setting('fonnte_token', '') !== '';
}

function wa_country_code(): string
{
    $code = preg_replace('/\D+/', '', wa_setting('fonnte_country_code', '62'));
    return $code !== '' ? $code : '62';
}

function wa_normalize_target(string $target): string
{
    $target = preg_replace('/[^0-9,]+/', '', $target);
    $parts = array_filter(array_map('trim', explode(',', $target)));
    $country = wa_country_code();
    $out = [];

    foreach ($parts as $number) {
        if ($number === '') continue;
        if (str_starts_with($number, '0')) {
            $number = $country . substr($number, 1);
        } elseif ($country !== '0' && !str_starts_with($number, $country)) {
            if (str_starts_with($number, '8')) {
                $number = $country . $number;
            }
        }
        $out[] = $number;
    }

    return implode(',', array_unique($out));
}

function wa_clean_note(string $note): string
{
    $note = preg_replace('/\[PAKASIR_PAYMENT\].*?\[\/PAKASIR_PAYMENT\]/s', '', $note);
    $note = preg_replace('/\[MEMBER_POINTS\].*?\[\/MEMBER_POINTS\]/s', '', $note);
    $note = preg_replace('/\[MANUAL_QRIS\].*?\[\/MANUAL_QRIS\]/s', '', $note);
    return trim($note);
}

function wa_member_points_meta(string $note): array
{
    if ($note === '' || !preg_match('/\[MEMBER_POINTS\](.*?)\[\/MEMBER_POINTS\]/s', $note, $m)) {
        return [];
    }
    $decoded = json_decode($m[1], true);
    return is_array($decoded) ? $decoded : [];
}

function wa_format_method(?string $method): string
{
    return payment_method_label($method);
}

function wa_invoice_message(array $trx, array $items): string
{
    $storeName = setting('store_name', setting('app_name', 'KasirPro UMKM'));
    $storePhone = setting('store_phone', '');
    $footer = setting('receipt_footer', 'Terima kasih sudah belanja.');
    $trxDate = $trx['transaction_date'] ?? $trx['created_at'] ?? date('Y-m-d H:i:s');
    $lines = [];

    $lines[] = '*' . $storeName . '*';
    if ($storePhone !== '') $lines[] = 'Telp: ' . $storePhone;
    $lines[] = '';
    $lines[] = '*INVOICE ' . ($trx['invoice_number'] ?? '-') . '*';
    $lines[] = 'Tanggal: ' . date('d/m/Y H:i', strtotime($trxDate));
    $lines[] = 'Kasir: ' . ($trx['cashier_name'] ?? '-');
    if (!empty($trx['branch_name'])) $lines[] = 'Cabang: ' . $trx['branch_name'];
    $lines[] = 'Member: ' . ($trx['customer_name'] ?? 'Umum');
    $lines[] = 'Status: ' . strtoupper((string)($trx['status'] ?? '-'));
    $lines[] = 'Metode: ' . wa_format_method($trx['payment_method'] ?? '-');
    $lines[] = '';
    $lines[] = '*Detail Belanja:*';

    foreach ($items as $it) {
        $qty = (int) ($it['qty'] ?? 0);
        $name = $it['product_name'] ?? '-';
        $price = (float) ($it['unit_price'] ?? 0);
        $sub = (float) ($it['subtotal'] ?? ($qty * $price));
        $lines[] = '- ' . $name . ' x' . $qty . ' @ ' . rupiah($price) . ' = ' . rupiah($sub);
    }

    $lines[] = '';
    $lines[] = 'Subtotal: ' . rupiah($trx['subtotal'] ?? 0);
    $lines[] = 'Diskon: ' . rupiah($trx['discount'] ?? 0);
    $lines[] = '*Total: ' . rupiah($trx['grand_total'] ?? 0) . '*';

    if (($trx['payment_method'] ?? '') === 'cash') {
        $lines[] = 'Dibayar: ' . rupiah($trx['paid_amount'] ?? 0);
        $lines[] = 'Kembalian: ' . rupiah($trx['change_amount'] ?? 0);
    }

    $memberPoints = wa_member_points_meta((string)($trx['note'] ?? ''));
    if ($memberPoints) {
        $redeemPoints = max(0, (int)($memberPoints['redeem_points'] ?? 0));
        $earnPoints = max(0, (int)($memberPoints['earn_points'] ?? 0));
        $hasTotalPoints = array_key_exists('customer_points_total', $trx) && $trx['customer_points_total'] !== null;
        $totalPoints = $hasTotalPoints ? max(0, (int)$trx['customer_points_total']) : 0;
        if ($redeemPoints > 0) {
            $lines[] = 'Poin ditukar: -' . $redeemPoints . ' poin';
        }
        if (strtolower((string)($trx['status'] ?? 'success')) === 'success') {
            $lines[] = '*Poin didapat: +' . $earnPoints . ' poin*';
            if ($hasTotalPoints) $lines[] = '*Total poin: ' . $totalPoints . ' poin*';
        } else {
            $lines[] = '*Poin akan didapat: +' . $earnPoints . ' poin setelah pembayaran sukses*';
            if ($hasTotalPoints) $lines[] = '*Total poin saat ini: ' . $totalPoints . ' poin*';
        }
    }

    $note = wa_clean_note((string)($trx['note'] ?? ''));
    if ($note !== '') {
        $lines[] = '';
        $lines[] = 'Catatan: ' . $note;
    }

    $lines[] = '';
    $lines[] = $footer ?: 'Terima kasih.';

    return implode("\n", $lines);
}

function wa_send_fonnte(PDO $db, string $target, string $message, ?int $transactionId = null): array
{
    wa_install($db);

    $token = wa_setting('fonnte_token', '');
    if ($token === '') {
        throw new Exception('Token Fonnte belum diisi di Pengaturan.');
    }

    $target = wa_normalize_target($target);
    if ($target === '') {
        throw new Exception('Nomor WhatsApp tujuan kosong/tidak valid.');
    }

    $postFields = [
        'target' => $target,
        'message' => $message,
        'countryCode' => wa_country_code(),
    ];

    $response = false;
    $curlError = '';

    if (function_exists('curl_init')) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.fonnte.com/send',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $token],
        ]);
        $response = curl_exec($curl);
        if (curl_errno($curl)) $curlError = curl_error($curl);
        curl_close($curl);
    } else {
        $boundary = uniqid('----fonnte');
        $body = '';
        foreach ($postFields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n" . $value . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: {$token}\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\n",
                'content' => $body,
                'timeout' => 30,
            ]
        ]);
        $response = @file_get_contents('https://api.fonnte.com/send', false, $ctx);
        if ($response === false) $curlError = 'Gagal menghubungi API Fonnte.';
    }

    $decoded = is_string($response) ? json_decode($response, true) : null;
    $success = is_array($decoded) && !empty($decoded['status']);
    $messageId = '';
    $requestId = '';
    if (is_array($decoded)) {
        if (!empty($decoded['id'])) {
            $messageId = is_array($decoded['id']) ? implode(',', $decoded['id']) : (string) $decoded['id'];
        }
        if (!empty($decoded['requestid'])) $requestId = (string) $decoded['requestid'];
    }

    $stmt = $db->prepare("INSERT INTO whatsapp_logs (transaction_id, target, message, provider, status, response, message_id, request_id, sent_by, created_at) VALUES (?, ?, ?, 'fonnte', ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $transactionId,
        $target,
        $message,
        $success ? 'success' : 'failed',
        $response !== false ? (string) $response : $curlError,
        $messageId ?: null,
        $requestId ?: null,
        Auth::id(),
    ]);

    if (!$success) {
        $detail = is_array($decoded) ? ($decoded['detail'] ?? $decoded['reason'] ?? json_encode($decoded)) : $curlError;
        throw new Exception('Gagal kirim WhatsApp: ' . ($detail ?: 'Respon Fonnte tidak valid.'));
    }

    return $decoded ?: ['status' => true, 'response' => $response];
}
?>
