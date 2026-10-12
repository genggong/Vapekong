<?php
function debt_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function debt_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function debt_install(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS customer_debts (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        transaction_id BIGINT NULL,
        customer_id BIGINT NOT NULL,
        branch_id BIGINT NULL,
        invoice_number VARCHAR(100) NULL,
        total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        remaining_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        due_date DATE NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'unpaid',
        note TEXT NULL,
        created_by BIGINT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        paid_at DATETIME NULL,
        INDEX idx_customer_debts_customer (customer_id),
        INDEX idx_customer_debts_transaction (transaction_id),
        INDEX idx_customer_debts_status (status),
        INDEX idx_customer_debts_due (due_date),
        INDEX idx_customer_debts_branch (branch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS customer_debt_payments (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        debt_id BIGINT NOT NULL,
        customer_id BIGINT NOT NULL,
        branch_id BIGINT NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(50) NOT NULL DEFAULT 'cash',
        note TEXT NULL,
        created_by BIGINT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_customer_debt_payments_debt (debt_id),
        INDEX idx_customer_debt_payments_customer (customer_id),
        INDEX idx_customer_debt_payments_branch (branch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS customer_debt_qris (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        debt_id BIGINT NOT NULL,
        customer_id BIGINT NOT NULL,
        branch_id BIGINT NULL,
        order_id VARCHAR(120) NOT NULL UNIQUE,
        base_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        unique_code INT NOT NULL DEFAULT 0,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        payment_number TEXT NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        note TEXT NULL,
        raw_response LONGTEXT NULL,
        expired_at DATETIME NULL,
        paid_at DATETIME NULL,
        created_by BIGINT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_customer_debt_qris_debt (debt_id),
        INDEX idx_customer_debt_qris_status (status),
        INDEX idx_customer_debt_qris_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (debt_table_exists($db, 'transactions') && !debt_column_exists($db, 'transactions', 'debt_amount')) {
        $db->exec("ALTER TABLE transactions ADD COLUMN debt_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER change_amount");
    }
    if (debt_table_exists($db, 'transactions') && !debt_column_exists($db, 'transactions', 'debt_status')) {
        $db->exec("ALTER TABLE transactions ADD COLUMN debt_status VARCHAR(30) NULL AFTER debt_amount");
    }
    if (debt_table_exists($db, 'transactions') && !debt_column_exists($db, 'transactions', 'debt_due_date')) {
        $db->exec("ALTER TABLE transactions ADD COLUMN debt_due_date DATE NULL AFTER debt_status");
    }
}

function debt_status_label(string $status): string
{
    return [
        'unpaid' => 'Belum Lunas',
        'partial' => 'Sebagian',
        'paid' => 'Lunas',
        'overdue' => 'Jatuh Tempo',
    ][$status] ?? ucfirst($status);
}

function debt_actual_status(array $debt): string
{
    $remaining = (float)($debt['remaining_amount'] ?? 0);
    if ($remaining <= 0) return 'paid';
    if (!empty($debt['due_date']) && strtotime($debt['due_date']) < strtotime(date('Y-m-d'))) return 'overdue';
    $paid = (float)($debt['paid_amount'] ?? 0);
    return $paid > 0 ? 'partial' : 'unpaid';
}

function debt_sync_status(PDO $db, int $debtId): void
{
    $stmt = $db->prepare("SELECT * FROM customer_debts WHERE id = ? LIMIT 1");
    $stmt->execute([$debtId]);
    $debt = $stmt->fetch();
    if (!$debt) return;

    $status = debt_actual_status($debt);
    $paidAt = $status === 'paid' ? now_datetime() : null;

    $stmt = $db->prepare("UPDATE customer_debts SET status = ?, paid_at = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $paidAt, $debtId]);
}

function debt_create_from_transaction(PDO $db, array $data): int
{
    debt_install($db);
    $remaining = max(0, (float)$data['remaining_amount']);
    if ($remaining <= 0) return 0;

    $paid = max(0, (float)($data['paid_amount'] ?? 0));
    $total = max($remaining + $paid, (float)($data['total_amount'] ?? 0));
    $status = $paid > 0 ? 'partial' : 'unpaid';

    $stmt = $db->prepare("INSERT INTO customer_debts
        (transaction_id, customer_id, branch_id, invoice_number, total_amount, paid_amount, remaining_amount, due_date, status, note, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $data['transaction_id'] ?? null,
        $data['customer_id'],
        $data['branch_id'] ?? null,
        $data['invoice_number'] ?? null,
        $total,
        $paid,
        $remaining,
        $data['due_date'] ?: null,
        $status,
        $data['note'] ?? null,
        Auth::id(),
    ]);

    return (int)$db->lastInsertId();
}

function debt_record_payment(PDO $db, int $debtId, float $amount, string $method = 'cash', string $note = ''): void
{
    // debt_install() jangan dipanggil di sini karena fungsi ini sering dipakai di dalam transaksi.
    // DDL MySQL bisa memicu implicit commit.
    $amount = max(0, $amount);
    if ($amount <= 0) throw new Exception('Nominal pembayaran wajib lebih dari 0.');

    $stmt = $db->prepare("SELECT * FROM customer_debts WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$debtId]);
    $debt = $stmt->fetch();
    if (!$debt) throw new Exception('Data piutang tidak ditemukan.');
    if ((float)$debt['remaining_amount'] <= 0) throw new Exception('Piutang ini sudah lunas.');

    $pay = min($amount, (float)$debt['remaining_amount']);

    $stmt = $db->prepare("INSERT INTO customer_debt_payments (debt_id, customer_id, branch_id, amount, payment_method, note, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $debtId,
        (int)$debt['customer_id'],
        $debt['branch_id'] ?? null,
        $pay,
        $method ?: 'cash',
        $note ?: null,
        Auth::id(),
    ]);

    $newPaid = (float)$debt['paid_amount'] + $pay;
    $newRemaining = max(0, (float)$debt['remaining_amount'] - $pay);
    $status = $newRemaining <= 0 ? 'paid' : 'partial';
    $paidAt = $status === 'paid' ? now_datetime() : null;

    $stmt = $db->prepare("UPDATE customer_debts SET paid_amount = ?, remaining_amount = ?, status = ?, paid_at = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newPaid, $newRemaining, $status, $paidAt, $debtId]);

    if (!empty($debt['transaction_id']) && debt_column_exists($db, 'transactions', 'debt_amount')) {
        $stmt = $db->prepare("UPDATE transactions SET debt_amount = ?, debt_status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newRemaining, $status, (int)$debt['transaction_id']]);
    }
}

function debt_whatsapp_message(array $debt, array $payments = []): string
{
    $store = setting('store_name', setting('app_name', 'Toko'));
    $customer = $debt['customer_name'] ?? $debt['name'] ?? 'Pelanggan';
    $invoice = $debt['invoice_number'] ?? '-';
    $total = rupiah($debt['total_amount'] ?? 0);
    $paid = rupiah($debt['paid_amount'] ?? 0);
    $remaining = rupiah($debt['remaining_amount'] ?? 0);
    $due = !empty($debt['due_date']) ? date('d/m/Y', strtotime($debt['due_date'])) : '-';

    return "Halo {$customer},\n\n".
        "Ini pengingat tagihan dari {$store}.\n".
        "Invoice: {$invoice}\n".
        "Total: {$total}\n".
        "Sudah dibayar: {$paid}\n".
        "Sisa tagihan: {$remaining}\n".
        "Jatuh tempo: {$due}\n\n".
        "Mohon lakukan pembayaran sesuai sisa tagihan. Terima kasih.";
}

function debt_send_whatsapp(PDO $db, int $debtId): void
{
    if (!function_exists('wa_send_fonnte')) {
        throw new Exception('Fitur WhatsApp Fonnte belum tersedia.');
    }

    $stmt = $db->prepare("SELECT d.*, c.name AS customer_name, c.phone AS customer_phone
        FROM customer_debts d
        JOIN customers c ON c.id = d.customer_id
        WHERE d.id = ? LIMIT 1");
    $stmt->execute([$debtId]);
    $debt = $stmt->fetch();
    if (!$debt) throw new Exception('Data piutang tidak ditemukan.');
    if (empty($debt['customer_phone'])) throw new Exception('Nomor WhatsApp pelanggan kosong.');

    wa_send_fonnte($db, (string)$debt['customer_phone'], debt_whatsapp_message($debt), (int)($debt['transaction_id'] ?? 0));
}


function debt_mysql_datetime($value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;

    // Pakasir bisa kirim ISO seperti 2026-06-09T19:19:56.392039125Z.
    // MySQL DATETIME butuh format Y-m-d H:i:s.
    try {
        $value = preg_replace('/\.\d+Z$/', 'Z', $value);
        $dt = new DateTime($value);
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}

function debt_pakasir_config(): array
{
    return [
        'project' => trim((string) setting('pakasir_project', app_config('pakasir_project', ''))),
        'api_key' => trim((string) setting('pakasir_api_key', app_config('pakasir_api_key', ''))),
    ];
}

function debt_pakasir_http_json(string $url, array $payload = null): array
{
    $body = false;
    $httpCode = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];

        if ($payload !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Accept: application/json'];
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new Exception('Tidak bisa konek ke QRIS: ' . ($error ?: 'koneksi gagal'));
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => $payload === null ? 'GET' : 'POST',
                'timeout' => 25,
                'header' => $payload === null ? "Accept: application/json\r\n" : "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $payload === null ? null : json_encode($payload),
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);

        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }

        if ($body === false) {
            throw new Exception('Tidak bisa konek ke QRIS. Aktifkan curl atau allow_url_fopen.');
        }
    }

    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        throw new Exception('Respon QRIS tidak valid: ' . substr((string)$body, 0, 180));
    }

    if ($httpCode >= 400) {
        $msg = $json['message'] ?? $json['error'] ?? ('HTTP ' . $httpCode);
        throw new Exception('Provider QRIS menolak pembayaran: ' . $msg);
    }

    return $json;
}

function debt_qris_unique_code(PDO $db, float $baseAmount): int
{
    for ($i = 0; $i < 20; $i++) {
        $code = random_int(101, 999);
        $amount = (int) round($baseAmount + $code);
        $stmt = $db->prepare("SELECT COUNT(*) FROM customer_debt_qris WHERE amount = ? AND status = 'pending'");
        $stmt->execute([$amount]);
        if ((int)$stmt->fetchColumn() === 0) return $code;
    }
    return random_int(101, 999);
}

function debt_create_qris_payment(PDO $db, int $debtId, float $baseAmount, string $note = ''): int
{
    // debt_install() sudah dipanggil di halaman pemanggil.
    // Jangan panggil DDL di tengah proses pembayaran QRIS.
    $stmt = $db->prepare("SELECT d.*, c.name AS customer_name FROM customer_debts d JOIN customers c ON c.id=d.customer_id WHERE d.id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$debtId]);
    $debt = $stmt->fetch();

    if (!$debt) throw new Exception('Data piutang tidak ditemukan.');
    if ((float)$debt['remaining_amount'] <= 0) throw new Exception('Piutang ini sudah lunas.');

    // Nominal QRIS piutang hanya sebesar nominal yang dibayar untuk sisa piutang.
    // Contoh: total 10.000, bayar cash 5.000, sisa piutang 5.000.
    // Maka QRIS piutang dibuat dari 5.000 + kode unik, bukan total invoice 10.000.
    $remainingDebt = (float)$debt['remaining_amount'];
    $baseAmount = min(max(1, $baseAmount), $remainingDebt);
    $uniqueCode = debt_qris_unique_code($db, $baseAmount);
    $amount = (int) round($baseAmount + $uniqueCode);
    $orderId = 'DEBT-' . $debtId . '-' . date('YmdHis') . '-' . random_int(100, 999);

    $config = debt_pakasir_config();
    if ($config['project'] === '' || $config['api_key'] === '') {
        throw new Exception('Konfigurasi Pakasir belum diisi. Buka menu Pengaturan lalu isi Slug Project Pakasir dan API Key Pakasir.');
    }

    $payload = [
        'project' => $config['project'],
        'order_id' => $orderId,
        'amount' => $amount,
        'api_key' => $config['api_key'],
    ];

    $json = debt_pakasir_http_json('https://app.pakasir.com/api/transactioncreate/qris', $payload);

    if (empty($json['payment']) || empty($json['payment']['payment_number'])) {
        $msg = $json['message'] ?? $json['error'] ?? 'QRIS tidak berhasil dibuat.';
        throw new Exception('Gagal membuat QRIS: ' . $msg);
    }

    $payment = $json['payment'];
    $qr = (string)($payment['payment_number'] ?? '');
    $expiredAt = debt_mysql_datetime($payment['expired_at'] ?? null);
    $raw = json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $db->prepare("INSERT INTO customer_debt_qris
        (debt_id, customer_id, branch_id, order_id, base_amount, unique_code, amount, payment_number, status, note, raw_response, expired_at, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $debtId,
        (int)$debt['customer_id'],
        $debt['branch_id'] ?? null,
        $orderId,
        $baseAmount,
        $uniqueCode,
        $amount,
        $qr,
        $note ?: null,
        $raw,
        $expiredAt ?: null,
        Auth::id(),
    ]);

    return (int)$db->lastInsertId();
}

function debt_qris_normalize_status(?string $status): string
{
    $status = strtolower(trim((string)$status));
    if (in_array($status, ['completed','paid','success','settlement','settled'], true)) return 'success';
    if (in_array($status, ['failed','fail','expired','cancel','canceled','cancelled','deny','denied'], true)) return 'failed';
    return 'pending';
}

function debt_qris_check_and_apply(PDO $db, int $qrisId): array
{
    debt_install($db);

    $stmt = $db->prepare("SELECT q.*, d.remaining_amount FROM customer_debt_qris q JOIN customer_debts d ON d.id=q.debt_id WHERE q.id=? LIMIT 1");
    $stmt->execute([$qrisId]);
    $q = $stmt->fetch();

    if (!$q) throw new Exception('Data QRIS piutang tidak ditemukan.');

    if (in_array($q['status'], ['success','failed'], true)) {
        return ['status' => $q['status'], 'qris' => $q];
    }

    $config = debt_pakasir_config();
    if ($config['project'] === '' || $config['api_key'] === '') {
        throw new Exception('Konfigurasi Pakasir belum lengkap.');
    }

    $url = 'https://app.pakasir.com/api/transactiondetail?' . http_build_query([
        'project' => $config['project'],
        'amount' => (int)$q['amount'],
        'order_id' => $q['order_id'],
        'api_key' => $config['api_key'],
    ]);

    $json = debt_pakasir_http_json($url);
    $remote = $json['transaction'] ?? $json['payment'] ?? $json;
    $rawStatus = is_array($remote) ? (string)($remote['status'] ?? '') : '';
    $status = debt_qris_normalize_status($rawStatus);

    if ($status === 'pending' && !empty($q['expired_at'])) {
        $expired = strtotime((string)$q['expired_at']);
        if ($expired && $expired < time()) $status = 'failed';
    }

    if ($status === 'success') {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT status FROM customer_debt_qris WHERE id=? FOR UPDATE");
            $stmt->execute([$qrisId]);
            $current = (string)$stmt->fetchColumn();

            if ($current !== 'success') {
                debt_record_payment($db, (int)$q['debt_id'], (float)$q['base_amount'], 'qris', 'Pembayaran piutang via QRIS ' . $q['order_id']);
                $stmt = $db->prepare("UPDATE customer_debt_qris SET status='success', paid_at=NOW(), raw_response=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $qrisId]);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    } elseif ($status === 'failed') {
        $stmt = $db->prepare("UPDATE customer_debt_qris SET status='failed', raw_response=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $qrisId]);
    }

    $stmt = $db->prepare("SELECT * FROM customer_debt_qris WHERE id=? LIMIT 1");
    $stmt->execute([$qrisId]);
    $q = $stmt->fetch();

    return ['status' => $status, 'qris' => $q, 'remote' => $remote];
}

?>