<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
Auth::requireLogin();
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/../whatsapp/_helpers.php';
require_once __DIR__ . '/../bundles/_helpers.php';
require_once __DIR__ . '/../locations/_helpers.php';
require_once __DIR__ . '/../debts/_helpers.php';
require_once __DIR__ . '/../prices/_helpers.php';
require_once __DIR__ . '/../../../app/Modules/CatalogFast.php';
require_once __DIR__ . '/../shifts/_helpers.php';

$pageTitle = 'Kasir POS';
$db = Database::connect();
marketplace_install($db);
branch_install($db);
wa_install($db);
bundle_install($db);
location_install($db);
debt_install($db);
price_install($db);
$currentBranch = branch_current($db);
$currentBranchId = (int) $currentBranch['id'];
shift_ensure_tables($db);
$activeCashierShift = shift_active($db, (int)Auth::id());
pos_theme_sync_latest_master_to_branch($db, $currentBranchId);
$branchOptions = branch_is_admin() ? branch_all($db, true) : [];
$errorMessage = '';
$posStockExpr = pos_theme_stock_expr('p', 'pbs');
$posMinStockExpr = pos_theme_min_stock_expr('p', 'pbs');

function pos_theme_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function pos_theme_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}




function pos_theme_stock_expr(string $productAlias = 'p', string $branchAlias = 'pbs'): string
{
    return "CASE
        WHEN {$productAlias}.updated_at IS NOT NULL
             AND ({$branchAlias}.updated_at IS NULL OR {$productAlias}.updated_at > {$branchAlias}.updated_at)
             AND {$productAlias}.stock > COALESCE({$branchAlias}.stock, 0)
        THEN {$productAlias}.stock
        ELSE COALESCE({$branchAlias}.stock, {$productAlias}.stock)
    END";
}

function pos_theme_min_stock_expr(string $productAlias = 'p', string $branchAlias = 'pbs'): string
{
    return "CASE
        WHEN {$productAlias}.updated_at IS NOT NULL
             AND ({$branchAlias}.updated_at IS NULL OR {$productAlias}.updated_at > {$branchAlias}.updated_at)
        THEN {$productAlias}.minimum_stock
        ELSE COALESCE({$branchAlias}.minimum_stock, {$productAlias}.minimum_stock)
    END";
}

function pos_theme_sync_latest_master_to_branch(PDO $db, int $branchId): void
{
    // Jika stok produk baru saja diupdate dari menu Produk, tapi stok cabang belum ikut,
    // sinkronkan ke cabang aktif hanya bila products.updated_at lebih baru dari pbs.updated_at.
    if (!pos_theme_table_exists($db, 'product_branch_stocks')) return;

    $db->prepare("
        INSERT IGNORE INTO product_branch_stocks (branch_id, product_id, stock, minimum_stock, created_at)
        SELECT ?, p.id, p.stock, p.minimum_stock, NOW()
        FROM products p
        WHERE p.is_active = 1
    ")->execute([$branchId]);

    $db->prepare("
        UPDATE product_branch_stocks pbs
        JOIN products p ON p.id = pbs.product_id
        SET pbs.stock = p.stock,
            pbs.minimum_stock = p.minimum_stock,
            pbs.updated_at = NOW()
        WHERE pbs.branch_id = ?
          AND p.is_active = 1
          AND p.updated_at IS NOT NULL
          AND (pbs.updated_at IS NULL OR p.updated_at > pbs.updated_at)
          AND p.stock > pbs.stock
    ")->execute([$branchId]);
}

function pos_theme_membership_ensure(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS customers (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        phone VARCHAR(40) NULL,
        email VARCHAR(150) NULL,
        address TEXT NULL,
        member_code VARCHAR(80) NULL,
        member_level ENUM('regular','silver','gold','platinum') DEFAULT 'regular',
        points INT NOT NULL DEFAULT 0,
        credit_limit DECIMAL(15,2) NOT NULL DEFAULT 0,
        note TEXT NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY customers_member_code_unique (member_code),
        INDEX idx_customers_phone (phone),
        INDEX idx_customers_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

    if (pos_theme_table_exists($db, 'transactions') && !pos_theme_column_exists($db, 'transactions', 'customer_id')) {
        $db->exec("ALTER TABLE transactions ADD COLUMN customer_id BIGINT NULL AFTER cashier_id");
        $db->exec("ALTER TABLE transactions ADD INDEX idx_transactions_customer (customer_id)");
    }
}

function pos_theme_point_value(): float
{
    return max(1, (float) setting('member_point_value', '100'));
}

function pos_theme_point_earn_rate(): float
{
    return max(0, (float) setting('member_point_earn_rate', '10000'));
}

function pos_theme_point_min_redeem(): int
{
    return max(1, (int) setting('member_point_min_redeem', '1'));
}

function pos_theme_member_note(array $meta): string
{
    return "\n[MEMBER_POINTS]" . json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "[/MEMBER_POINTS]";
}

function pos_theme_add_customer_points(PDO $db, int $customerId, int $transactionId, string $type, int $points, string $description): void
{
    if ($customerId <= 0 || $points <= 0) return;

    $stmt = $db->prepare("SELECT COUNT(*) FROM customer_points WHERE customer_id = ? AND transaction_id = ? AND type = ?");
    $stmt->execute([$customerId, $transactionId, $type]);
    if ((int) $stmt->fetchColumn() > 0) return;

    $stmt = $db->prepare("INSERT INTO customer_points (customer_id, transaction_id, type, points, description, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$customerId, $transactionId, $type, $points, $description]);

    if ($type === 'in') {
        $stmt = $db->prepare("UPDATE customers SET points = points + ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$points, $customerId]);
    } else {
        $stmt = $db->prepare("UPDATE customers SET points = GREATEST(points - ?, 0), updated_at = NOW() WHERE id = ?");
        $stmt->execute([$points, $customerId]);
    }
}


function pos_theme_invoice_number(PDO $db): string
{
    $prefix = 'INV-' . date('Ymd') . '-';
    $stmt = $db->prepare("SELECT invoice_number FROM transactions WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;

    if ($last) {
        $parts = explode('-', $last);
        $next = ((int) end($parts)) + 1;
    }

    return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

function pos_theme_promo_scope(array $promo): string
{
    if (!empty($promo['promo_scope'])) return (string) $promo['promo_scope'];
    if (!empty($promo['scope'])) return (string) $promo['scope'];
    return 'all';
}

function pos_theme_promo_code(array $promo): string
{
    foreach (['code', 'barcode', 'barcode_code'] as $key) {
        if (!empty($promo[$key])) return (string) $promo[$key];
    }
    return '';
}

function pos_theme_promo_product_ids(PDO $db, int $promoId): array
{
    if (!pos_theme_table_exists($db, 'promo_products')) return [];
    $stmt = $db->prepare("SELECT product_id FROM promo_products WHERE promo_id = ?");
    $stmt->execute([$promoId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function pos_theme_calculate_discount(array $promo, float $eligibleSubtotal): float
{
    if ($eligibleSubtotal <= 0 || $eligibleSubtotal < (float) ($promo['min_purchase'] ?? 0)) {
        return 0;
    }

    if (($promo['discount_type'] ?? 'fixed') === 'percent') {
        $discount = $eligibleSubtotal * ((float) ($promo['discount_value'] ?? 0) / 100);
        if ((float) ($promo['max_discount'] ?? 0) > 0) {
            $discount = min($discount, (float) $promo['max_discount']);
        }
    } else {
        $discount = (float) ($promo['discount_value'] ?? 0);
    }

    return max(0, min($discount, $eligibleSubtotal));
}

function pos_theme_find_promo(PDO $db, int $promoId, string $promoCode, array $items, float $subtotal): ?array
{
    if (!pos_theme_table_exists($db, 'promos')) return null;

    $where = "status = 'active'
        AND (start_date IS NULL OR start_date <= CURDATE())
        AND (end_date IS NULL OR end_date >= CURDATE())
        AND (usage_limit = 0 OR used_count < usage_limit)";
    $params = [];

    if ($promoId > 0) {
        $where .= ' AND id = ?';
        $params[] = $promoId;
    } else {
        $promoCode = strtoupper(trim($promoCode));
        if ($promoCode === '') return null;

        $parts = ['UPPER(code) = ?'];
        $params[] = $promoCode;

        if (pos_theme_column_exists($db, 'promos', 'barcode')) {
            $parts[] = 'UPPER(barcode) = ?';
            $params[] = $promoCode;
        }
        if (pos_theme_column_exists($db, 'promos', 'barcode_code')) {
            $parts[] = 'UPPER(barcode_code) = ?';
            $params[] = $promoCode;
        }

        $where .= ' AND (' . implode(' OR ', $parts) . ')';
    }

    $stmt = $db->prepare("SELECT * FROM promos WHERE {$where} LIMIT 1");
    $stmt->execute($params);
    $promo = $stmt->fetch();

    if (!$promo) return null;

    $eligibleSubtotal = $subtotal;
    if (pos_theme_promo_scope($promo) === 'specific_products') {
        $allowed = pos_theme_promo_product_ids($db, (int) $promo['id']);
        $eligibleSubtotal = 0;
        foreach ($items as $item) {
            if (in_array((int) $item['product']['id'], $allowed, true)) {
                $eligibleSubtotal += (float) $item['subtotal'];
            }
        }
    }

    $discount = pos_theme_calculate_discount($promo, $eligibleSubtotal);
    if ($discount <= 0) return null;

    $promo['computed_discount'] = $discount;
    $promo['eligible_subtotal'] = $eligibleSubtotal;
    return $promo;
}

function pos_theme_redirect_relative(string $url): void
{
    header('Location: ' . $url, true, 303);
    exit;
}


function pos_theme_qris_unique_code(PDO $db, float $baseAmount): int
{
    // Kode unik 3 digit, dicoba agar nominal QRIS pending tidak bentrok.
    for ($i = 0; $i < 20; $i++) {
        $code = random_int(101, 999);
        $targetTotal = (int) round($baseAmount + $code);

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM transactions
            WHERE payment_method = 'qris_pakasir'
              AND status = 'pending'
              AND grand_total = ?
        ");
        $stmt->execute([$targetTotal]);

        if ((int) $stmt->fetchColumn() === 0) {
            return $code;
        }
    }

    return random_int(101, 999);
}



function pos_theme_pakasir_config(): array
{
    return [
        'project' => trim((string) setting('pakasir_project', app_config('pakasir_project', ''))),
        'api_key' => trim((string) setting('pakasir_api_key', app_config('pakasir_api_key', ''))),
    ];
}

function pos_theme_pakasir_create_qris(string $orderId, float $amount): array
{
    $config = pos_theme_pakasir_config();

    if ($config['project'] === '' || $config['api_key'] === '') {
        throw new Exception('Konfigurasi Pakasir belum diisi. Buka menu Pengaturan lalu isi Slug Project Pakasir dan API Key Pakasir.');
    }

    $payload = [
        'project' => $config['project'],
        'order_id' => $orderId,
        'amount' => (int) round($amount),
        'api_key' => $config['api_key'],
    ];

    $url = 'https://app.pakasir.com/api/transactioncreate/qris';
    $responseBody = false;
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new Exception('Gagal konek ke QRIS: ' . ($curlError ?: 'unknown error'));
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => json_encode($payload),
                'timeout' => 25,
                'ignore_errors' => true,
            ],
        ]);
        $responseBody = file_get_contents($url, false, $context);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }
    }

    $json = json_decode((string) $responseBody, true);

    if (!is_array($json)) {
        throw new Exception('Respon QRIS tidak valid: ' . substr((string) $responseBody, 0, 180));
    }

    if ($httpCode >= 400) {
        $msg = $json['message'] ?? $json['error'] ?? 'HTTP ' . $httpCode;
        throw new Exception('Provider QRIS menolak transaksi: ' . $msg);
    }

    if (empty($json['payment']) || empty($json['payment']['payment_number'])) {
        $msg = $json['message'] ?? $json['error'] ?? 'QRIS tidak berhasil dibuat.';
        throw new Exception('Gagal membuat QRIS: ' . $msg);
    }

    return $json['payment'];
}

function pos_theme_pack_pakasir_note(array $payment): string
{
    $safe = [
        'project' => $payment['project'] ?? '',
        'order_id' => $payment['order_id'] ?? '',
        'amount' => (int) ($payment['amount'] ?? 0),
        'original_amount' => (int) ($payment['original_amount'] ?? ($payment['amount'] ?? 0)),
        'unique_code' => (int) ($payment['unique_code'] ?? 0),
        'fee' => (int) ($payment['fee'] ?? 0),
        'total_payment' => (int) ($payment['total_payment'] ?? ($payment['amount'] ?? 0)),
        'payment_method' => $payment['payment_method'] ?? 'qris',
        'payment_number' => $payment['payment_number'] ?? '',
        'expired_at' => $payment['expired_at'] ?? '',
    ];

    return "\n[PAKASIR_PAYMENT]" . json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "[/PAKASIR_PAYMENT]";
}


function pos_theme_manual_qris_config(): array
{
    $image = trim((string)setting('qris_manual_image', ''));
    $enabled = strtolower(trim((string)setting('qris_manual_enabled', 'off'))) === 'on';
    $label = trim((string)setting('qris_manual_label', 'QRIS Toko')) ?: 'QRIS Toko';
    $publicRoot = realpath(__DIR__ . '/../../');
    $fullPath = $publicRoot && $image !== '' ? $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($image, '/')) : '';
    $available = $enabled && $image !== '' && $fullPath !== '' && is_file($fullPath);

    return [
        'enabled' => $enabled,
        'available' => $available,
        'label' => $label,
        'image' => $image,
    ];
}

function pos_theme_pack_manual_qris_note(array $config): string
{
    $safe = [
        'label' => (string)($config['label'] ?? 'QRIS Toko'),
        'image' => (string)($config['image'] ?? ''),
        'confirmed_at' => now_datetime(),
    ];
    return "\n[MANUAL_QRIS]" . json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "[/MANUAL_QRIS]";
}

pos_theme_membership_ensure($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!CSRF::verify()) {
            throw new Exception('Token keamanan tidak valid. Refresh halaman POS lalu coba lagi.');
        }

        $cart = json_decode($_POST['cart_json'] ?? '[]', true);
        if (!is_array($cart) || count($cart) < 1) {
            throw new Exception('Keranjang masih kosong. Pilih produk dulu.');
        }

        $promoId = (int) ($_POST['promo_id'] ?? 0);
        $promoCode = strtoupper(trim($_POST['promo_code'] ?? ''));
        $manualDiscount = max(0, (float) ($_POST['manual_discount'] ?? 0));
        $paidAmount = max(0, (float) ($_POST['paid_amount'] ?? 0));
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $manualQrisConfirmed = (($_POST['manual_qris_confirm'] ?? '') === '1');
        $isDebtMode = $paymentMethod === 'cash_debt';
        if ($isDebtMode) {
            // Simpan transaksi tetap sebagai cash agar laporan lama tidak berubah.
            $paymentMethod = 'cash';
        }
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $redeemPoints = max(0, (int) ($_POST['redeem_points'] ?? 0));
        $note = trim($_POST['note'] ?? '');
        $debtDueDate = trim($_POST['debt_due_date'] ?? '');
        $priceLevel = trim($_POST['price_level'] ?? 'retail');
        if (!array_key_exists($priceLevel, price_levels())) $priceLevel = 'retail';

        $db->beginTransaction();

        $items = [];
        $subtotal = 0;

        foreach ($cart as $row) {
            $itemType = ($row['type'] ?? 'product') === 'bundle' ? 'bundle' : 'product';
            $rowId = (int) ($row['id'] ?? 0);
            $qty = max(1, (int) ($row['qty'] ?? 1));
            $variantId = max(0, (int)($row['variant_id'] ?? 0));
            if ($rowId < 1) continue;

            if ($itemType === 'bundle') {
                $stmt = $db->prepare("SELECT * FROM product_bundles WHERE id = ? AND is_active = 1 LIMIT 1");
                $stmt->execute([$rowId]);
                $bundle = $stmt->fetch();
                if (!$bundle) {
                    throw new Exception('Ada paket yang sudah tidak aktif / tidak ditemukan.');
                }

                $bundleComponents = bundle_items($db, $rowId);
                if (!$bundleComponents) {
                    throw new Exception('Paket ' . $bundle['name'] . ' belum memiliki isi produk.');
                }

                $purchaseTotal = 0;
                $componentSnapshot = [];

                foreach ($bundleComponents as $component) {
                    $needQty = (int)$component['qty'] * $qty;

                    $stmt = $db->prepare("SELECT p.*, {$posStockExpr} AS stock, {$posMinStockExpr} AS minimum_stock
                        FROM products p
                        LEFT JOIN product_branch_stocks pbs ON pbs.product_id = p.id AND pbs.branch_id = ?
                        WHERE p.id = ? AND p.is_active = 1
                        LIMIT 1 FOR UPDATE");
                    $stmt->execute([$currentBranchId, (int)$component['product_id']]);
                    $componentProduct = $stmt->fetch();

                    if (!$componentProduct) {
                        throw new Exception('Produk isi paket ' . $component['product_name'] . ' tidak aktif / tidak ditemukan.');
                    }
                    if ((int)$componentProduct['stock'] < $needQty) {
                        throw new Exception('Stok komponen paket tidak cukup: ' . $componentProduct['name'] . '. Butuh ' . $needQty . ', tersedia ' . (int)$componentProduct['stock']);
                    }

                    $purchaseTotal += ((float)$componentProduct['purchase_price'] * $needQty);
                    $componentSnapshot[] = [
                        'product_id' => (int)$componentProduct['id'],
                        'name' => (string)$componentProduct['name'],
                        'qty_per_bundle' => (int)$component['qty'],
                        'total_qty' => $needQty,
                        'purchase_price' => (float)$componentProduct['purchase_price'],
                    ];
                }

                $lineSubtotal = (float)$bundle['selling_price'] * $qty;
                $subtotal += $lineSubtotal;
                $items[] = [
                    'type' => 'bundle',
                    'bundle' => $bundle,
                    'components' => $componentSnapshot,
                    'product' => [
                        'id' => null,
                        'name' => $bundle['name'],
                        'purchase_price' => $qty > 0 ? ($purchaseTotal / $qty) : 0,
                    ],
                    'qty' => $qty,
                    'unit_price' => (float)$bundle['selling_price'],
                    'purchase_price' => $qty > 0 ? ($purchaseTotal / $qty) : 0,
                    'subtotal' => $lineSubtotal,
                ];
                continue;
            }

            $productId = $rowId;
            $stmt = $db->prepare("SELECT p.*, {$posStockExpr} AS stock, {$posMinStockExpr} AS minimum_stock FROM products p LEFT JOIN product_branch_stocks pbs ON pbs.product_id = p.id AND pbs.branch_id = ? WHERE p.id = ? AND p.is_active = 1 LIMIT 1 FOR UPDATE");
            $stmt->execute([$currentBranchId, $productId]);
            $product = $stmt->fetch();

            if (!$product) throw new Exception('Ada produk yang sudah tidak aktif / tidak ditemukan.');
            $variant=null;
            if(marketplace_v16_product_has_variants($db,$productId,true)){
                if($variantId<=0) throw new Exception('Produk '.$product['name'].' memiliki varian. Pilih varian di POS.');
                $variant=marketplace_v15_variant($db,$variantId,$currentBranchId,true);
                if(!$variant || (int)$variant['product_id']!==$productId || !(int)$variant['is_active']) throw new Exception('Varian produk sudah tidak aktif / tidak ditemukan.');
                $product['stock']=(int)$variant['stock'];
                if((int)$product['stock']<$qty) throw new Exception('Stok varian '.$product['name'].' - '.($variant['display_name']??$variant['name']).' tidak cukup. Tersedia: '.(int)$product['stock']);
                $unitPrice=(float)$variant['selling_price']>0?(float)$variant['selling_price']:price_get($db,$productId,$priceLevel);
                $purchasePrice=(float)$variant['purchase_price']>0?(float)$variant['purchase_price']:(float)$product['purchase_price'];
            }else{
                if((int)$product['stock']<$qty) throw new Exception('Stok produk '.$product['name'].' tidak cukup. Stok tersedia: '.(int)$product['stock']);
                $unitPrice=price_get($db,$productId,$priceLevel);$purchasePrice=(float)$product['purchase_price'];$variantId=0;
            }
            $lineSubtotal=(float)$unitPrice*$qty;$subtotal+=$lineSubtotal;
            $items[]=['type'=>'product','product'=>$product,'variant'=>$variant,'variant_id'=>$variantId,'qty'=>$qty,'unit_price'=>(float)$unitPrice,'price_level'=>$priceLevel,'purchase_price'=>$purchasePrice,'subtotal'=>$lineSubtotal];
        }

        if (!$items) {
            throw new Exception('Keranjang tidak valid. Pilih produk ulang.');
        }

        $promoDiscount = 0;
        $promo = null;

        if ($promoId > 0 || $promoCode !== '') {
            $promo = pos_theme_find_promo($db, $promoId, $promoCode, $items, $subtotal);
            if (!$promo) {
                throw new Exception('Voucher tidak valid / expired / tidak sesuai produk / minimal belanja belum terpenuhi.');
            }
            $promoDiscount = (float) $promo['computed_discount'];
        }

        $customer = null;
        $pointDiscount = 0;
        $earnRate = pos_theme_point_earn_rate();
        $pointValue = pos_theme_point_value();
        $minRedeem = pos_theme_point_min_redeem();

        if ($customerId > 0) {
            $stmt = $db->prepare("SELECT * FROM customers WHERE id = ? AND status = 'active' LIMIT 1 FOR UPDATE");
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch();
            if (!$customer) {
                throw new Exception('Pelanggan/member tidak ditemukan atau nonaktif.');
            }
        }

        if ($redeemPoints > 0) {
            if (!$customer) {
                throw new Exception('Pilih member pelanggan dulu untuk menukar poin.');
            }
            if ($redeemPoints < $minRedeem) {
                throw new Exception('Minimal penukaran poin adalah ' . $minRedeem . ' poin.');
            }
            if ((int) $customer['points'] < $redeemPoints) {
                throw new Exception('Poin pelanggan tidak cukup. Poin tersedia: ' . (int) $customer['points']);
            }

            $maxPointDiscountBase = max(0, $subtotal - $manualDiscount - $promoDiscount);
            $maxRedeemByTotal = (int) floor($maxPointDiscountBase / $pointValue);
            if ($maxRedeemByTotal < 1) {
                throw new Exception('Total belanja tidak cukup untuk menukar poin.');
            }
            if ($redeemPoints > $maxRedeemByTotal) {
                $redeemPoints = $maxRedeemByTotal;
            }
            $pointDiscount = min($maxPointDiscountBase, $redeemPoints * $pointValue);
        }

        $discount = min($subtotal, $manualDiscount + $promoDiscount + $pointDiscount);
        $grandTotal = max(0, $subtotal - $discount);
        $earnBase = $grandTotal;
        $earnPoints = ($customer && $earnRate > 0) ? (int) floor($earnBase / $earnRate) : 0;

        if (!in_array($paymentMethod, ['cash', 'qris_pakasir', 'qris_manual'], true)) {
            throw new Exception('Metode pembayaran tidak valid. Pilih Cash atau QRIS.');
        }
        $manualQrisConfig = pos_theme_manual_qris_config();
        if ($paymentMethod === 'qris_manual') {
            if (!$manualQrisConfig['available']) {
                throw new Exception('QRIS Manual belum aktif atau gambar QRIS toko tidak tersedia. Periksa menu Pengaturan.');
            }
            if (!$manualQrisConfirmed) {
                throw new Exception('Konfirmasi pembayaran QRIS Manual wajib dicentang setelah pembayaran diterima.');
            }
        }

        $invoice = pos_theme_invoice_number($db);
        $changeAmount = 0;
        $transactionStatus = 'success';
        $pakasirPayment = null;

        $debtAmount = 0;
        $debtStatus = null;

        if ($paymentMethod === 'cash') {
            if ($paidAmount < $grandTotal) {
                if (!$isDebtMode) {
                    throw new Exception('Uang diterima kurang. Pilih metode Cash + Piutang untuk menyimpan sisa tagihan.');
                }
                if (!$customer) {
                    throw new Exception('Transaksi piutang wajib memilih member pelanggan.');
                }
                if ($debtDueDate === '') {
                    throw new Exception('Tanggal jatuh tempo piutang wajib dipilih.');
                }
                $dueDateCheck = DateTime::createFromFormat('!Y-m-d', $debtDueDate);
                if (!$dueDateCheck || $dueDateCheck->format('Y-m-d') !== $debtDueDate) {
                    throw new Exception('Format tanggal jatuh tempo piutang tidak valid.');
                }
                if ($debtDueDate < date('Y-m-d')) {
                    throw new Exception('Tanggal jatuh tempo tidak boleh sebelum hari ini.');
                }
                $debtAmount = $grandTotal - $paidAmount;
                $debtStatus = $paidAmount > 0 ? 'partial' : 'unpaid';
                $changeAmount = 0;
            } else {
                $changeAmount = $paidAmount - $grandTotal;
            }
        } elseif ($paymentMethod === 'qris_pakasir') {
            $baseGrandTotal = $grandTotal;
            $uniqueCode = pos_theme_qris_unique_code($db, $baseGrandTotal);
            $grandTotal = $baseGrandTotal + $uniqueCode;

            $paidAmount = $grandTotal;
            $changeAmount = 0;
            $transactionStatus = 'pending';

            $pakasirPayment = pos_theme_pakasir_create_qris($invoice, $grandTotal);
            $pakasirPayment['original_amount'] = (int) round($baseGrandTotal);
            $pakasirPayment['unique_code'] = (int) $uniqueCode;
            $pakasirPayment['amount'] = (int) round($grandTotal);
        } else {
            // QRIS Manual dianggap sukses setelah kasir mencentang konfirmasi pembayaran diterima.
            $paidAmount = $grandTotal;
            $changeAmount = 0;
            $transactionStatus = 'success';
        }

        if ($promo) {
            $note = trim($note . "\n" . 'Voucher: ' . $promo['name'] . ' (' . pos_theme_promo_code($promo) . ') - Diskon ' . rupiah($promoDiscount));
        }

        if ($redeemPoints > 0) {
            $note = trim($note . "\nTukar poin member: " . $redeemPoints . ' poin - Diskon ' . rupiah($pointDiscount));
        }

        if ($customer) {
            $note = trim($note . pos_theme_member_note([
                'customer_id' => (int) $customer['id'],
                'customer_name' => (string) $customer['name'],
                'redeem_points' => (int) $redeemPoints,
                'point_discount' => (int) round($pointDiscount),
                'earn_base' => (int) round($earnBase),
                'earn_rate' => (int) round($earnRate),
                'earn_points' => (int) $earnPoints,
            ]));
        }

        if ($pakasirPayment) {
            $note = trim($note . "\nPembayaran QRIS dibuat. Order ID: " . $invoice . pos_theme_pack_pakasir_note($pakasirPayment));
        } elseif ($paymentMethod === 'qris_manual') {
            $note = trim($note . "\nPembayaran QRIS Manual dikonfirmasi kasir." . pos_theme_pack_manual_qris_note($manualQrisConfig));
        }

        if (!empty($debtAmount) && $debtAmount > 0) {
            $note = trim($note . "\nPiutang pelanggan: " . rupiah($debtAmount) . ($debtDueDate ? " - Jatuh tempo " . $debtDueDate : ""));
        }

        $stmt = $db->prepare("INSERT INTO transactions
            (invoice_number, cashier_id, customer_id, branch_id, subtotal, discount, tax, grand_total, paid_amount, change_amount, debt_amount, debt_status, debt_due_date, payment_method, status, note, transaction_date, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $invoice,
            Auth::id(),
            $customer ? (int) $customer['id'] : null,
            $currentBranchId,
            $subtotal,
            $discount,
            $grandTotal,
            $paidAmount,
            $changeAmount,
            $debtAmount ?? 0,
            $debtStatus,
            $debtDueDate ?: null,
            $paymentMethod,
            $transactionStatus,
            $note,
            now_datetime(),
        ]);
        $transactionId = (int) $db->lastInsertId();

        if ($customer && !empty($debtAmount) && $debtAmount > 0) {
            debt_create_from_transaction($db, [
                'transaction_id' => $transactionId,
                'customer_id' => (int)$customer['id'],
                'branch_id' => $currentBranchId ?? null,
                'invoice_number' => $invoice,
                'total_amount' => $grandTotal,
                'paid_amount' => $paidAmount,
                'remaining_amount' => $debtAmount,
                'due_date' => $debtDueDate ?: null,
                'note' => 'Piutang dari transaksi POS ' . $invoice,
            ]);
        }

        if ($customer && $redeemPoints > 0) {
            pos_theme_add_customer_points($db, (int) $customer['id'], $transactionId, 'out', (int) $redeemPoints, 'Tukar poin untuk invoice ' . $invoice . ' senilai ' . rupiah($pointDiscount));
        }

        if ($customer && $transactionStatus === 'success' && $earnPoints > 0) {
            pos_theme_add_customer_points($db, (int) $customer['id'], $transactionId, 'in', (int) $earnPoints, 'Poin belanja invoice ' . $invoice);
        }

        foreach ($items as $item) {
            if (($item['type'] ?? 'product') === 'bundle') {
                $bundle = $item['bundle'];
                $componentsJson = json_encode($item['components'], JSON_UNESCAPED_UNICODE);

                $stmt = $db->prepare("INSERT INTO transaction_items
                    (transaction_id, item_type, product_id, bundle_id, bundle_components, product_name, qty, unit_price, purchase_price, discount, subtotal, created_at)
                    VALUES (?, 'bundle', NULL, ?, ?, ?, ?, ?, ?, 0, ?, NOW())");
                $stmt->execute([
                    $transactionId,
                    $bundle['id'],
                    $componentsJson,
                    $bundle['name'],
                    $item['qty'],
                    $item['unit_price'],
                    $item['purchase_price'],
                    $item['subtotal'],
                ]);

                foreach ($item['components'] as $component) {
                    $stmt = $db->prepare("SELECT p.*, {$posStockExpr} AS stock
                        FROM products p
                        LEFT JOIN product_branch_stocks pbs ON pbs.product_id = p.id AND pbs.branch_id = ?
                        WHERE p.id = ?
                        LIMIT 1 FOR UPDATE");
                    $stmt->execute([$currentBranchId, (int)$component['product_id']]);
                    $componentProduct = $stmt->fetch();
                    if (!$componentProduct) {
                        throw new Exception('Komponen paket tidak ditemukan saat update stok.');
                    }

                    $stockBefore = (int)$componentProduct['stock'];
                    $stockAfter = $stockBefore - (int)$component['total_qty'];

                    branch_sync_product($db, (int)$component['product_id']);
                    $stmt = $db->prepare("UPDATE product_branch_stocks SET stock = ?, updated_at = NOW() WHERE branch_id = ? AND product_id = ?");
                    $stmt->execute([$stockAfter, $currentBranchId, (int)$component['product_id']]);

                    $stmtMain = $db->prepare("SELECT is_main FROM branches WHERE id = ? LIMIT 1");
                    $stmtMain->execute([$currentBranchId]);
                    if ((int)$stmtMain->fetchColumn() === 1) {
                        $stmt = $db->prepare("UPDATE products SET stock = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$stockAfter, (int)$component['product_id']]);
                    }

                    $stmt = $db->prepare("INSERT INTO stock_movements
                        (product_id, user_id, branch_id, type, qty, stock_before, stock_after, reference_type, reference_id, note, created_at)
                        VALUES (?, ?, ?, 'bundle_sale', ?, ?, ?, 'transaction', ?, ?, NOW())");
                    $stmt->execute([
                        (int)$component['product_id'],
                        Auth::id(),
                        $currentBranchId,
                        (int)$component['total_qty'],
                        $stockBefore,
                        $stockAfter,
                        $transactionId,
                        'Penjualan paket ' . $bundle['name'] . ' - ' . $invoice,
                    ]);
                }

                continue;
            }

            $product=$item['product'];$variant=$item['variant']??null;$variantId=(int)($item['variant_id']??0);$stockBefore=(int)$product['stock'];$stockAfter=$stockBefore-(int)$item['qty'];
            if($variantId>0){
                $variantName=(string)($variant['display_name']??$variant['name']??'Varian');$variantSku=(string)($variant['sku']??'');
                if(marketplace_v16_column_exists($db,'transaction_items','variant_id')){
                    $stmt=$db->prepare("INSERT INTO transaction_items(transaction_id,item_type,product_id,variant_id,product_name,variant_name,variant_sku,qty,unit_price,price_level,purchase_price,discount,subtotal,created_at) VALUES(?,'product',?,?,?,?,?,?,?,?,?,0,?,NOW())");
                    $stmt->execute([$transactionId,$product['id'],$variantId,$product['name'],$variantName,$variantSku,$item['qty'],$item['unit_price'],$item['price_level']??'retail',$item['purchase_price'],$item['subtotal']]);
                }else{
                    $stmt=$db->prepare("INSERT INTO transaction_items(transaction_id,item_type,product_id,product_name,qty,unit_price,price_level,purchase_price,discount,subtotal,created_at) VALUES(?,'product',?,?,?,?,?,?,0,?,NOW())");
                    $stmt->execute([$transactionId,$product['id'],$product['name'].' - '.$variantName,$item['qty'],$item['unit_price'],$item['price_level']??'retail',$item['purchase_price'],$item['subtotal']]);
                }
                marketplace_v16_set_variant_stock($db,$variantId,$currentBranchId,$stockAfter,(int)($variant['minimum_stock']??0));marketplace_v16_sync_parent_stock($db,(int)$product['id'],$currentBranchId);
                $moveNote='Penjualan varian '.$variantName.' - '.$invoice;
            }else{
                $stmt=$db->prepare("INSERT INTO transaction_items(transaction_id,item_type,product_id,product_name,qty,unit_price,price_level,purchase_price,discount,subtotal,created_at) VALUES(?,'product',?,?,?,?,?,?,0,?,NOW())");
                $stmt->execute([$transactionId,$product['id'],$product['name'],$item['qty'],$item['unit_price'],$item['price_level']??'retail',$item['purchase_price'],$item['subtotal']]);
                branch_sync_product($db,(int)$product['id']);$db->prepare('UPDATE product_branch_stocks SET stock=?,updated_at=NOW() WHERE branch_id=? AND product_id=?')->execute([$stockAfter,$currentBranchId,$product['id']]);$m=$db->prepare('SELECT is_main FROM branches WHERE id=? LIMIT 1');$m->execute([$currentBranchId]);if((int)$m->fetchColumn()===1)$db->prepare('UPDATE products SET stock=?,updated_at=NOW() WHERE id=?')->execute([$stockAfter,$product['id']]);$moveNote='Penjualan '.$invoice;
            }
            try{$db->prepare("INSERT INTO stock_movements(product_id,user_id,branch_id,type,qty,stock_before,stock_after,reference_type,reference_id,note,created_at) VALUES(?,?,?,'sale',?,?,?,'transaction',?,?,NOW())")->execute([$product['id'],Auth::id(),$currentBranchId,$item['qty'],$stockBefore,$stockAfter,$transactionId,$moveNote]);}catch(Throwable $e){}
        }

        if ($promo) {
            $stmt = $db->prepare("UPDATE promos SET used_count = used_count + 1 WHERE id = ?");
            $stmt->execute([(int) $promo['id']]);
        }

        try {
            $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'success', NOW())");
            $stmt->execute([Auth::id(), 'Transaksi berhasil', 'Invoice ' . $invoice . ' berhasil dibuat.']);
        } catch (Throwable $ignore) {}

        Auth::log('create_transaction', 'Membuat transaksi ' . $invoice);

        if ($db->inTransaction()) {
            $db->commit();
        }

        // Kirim invoice WhatsApp otomatis jika diaktifkan dan pelanggan punya nomor WA.
        try {
            $customerPointsTotal = null;
            if ($customer) {
                $pointBalanceStmt = $db->prepare("SELECT points FROM customers WHERE id = ? LIMIT 1");
                $pointBalanceStmt->execute([(int)$customer['id']]);
                $pointBalance = $pointBalanceStmt->fetchColumn();
                if ($pointBalance !== false) {
                    $customerPointsTotal = max(0, (int)$pointBalance);
                }
            }
            if (setting('fonnte_auto_invoice', 'manual') === 'success' && $customer && !empty($customer['phone'])) {
                $trxPayload = [
                    'id' => $transactionId,
                    'invoice_number' => $invoice,
                    'cashier_name' => Auth::user()['name'] ?? '-',
                    'branch_name' => $currentBranch['name'] ?? '-',
                    'customer_name' => $customer['name'] ?? 'Umum',
                    'customer_phone' => $customer['phone'] ?? '',
                    'customer_points_total' => $customerPointsTotal,
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'grand_total' => $grandTotal,
                    'paid_amount' => $paidAmount,
                    'change_amount' => $changeAmount,
                    'payment_method' => $paymentMethod,
                    'status' => $transactionStatus,
                    'note' => $note,
                    'transaction_date' => now_datetime(),
                    'created_at' => now_datetime(),
                ];
                $waItems = array_map(function ($item) {
                    return [
                        'product_name' => ($item['type'] ?? 'product') === 'bundle' ? ($item['bundle']['name'] ?? '-') : ($item['product']['name'] ?? '-'),
                        'qty' => $item['qty'] ?? 0,
                        'unit_price' => $item['unit_price'] ?? 0,
                        'subtotal' => $item['subtotal'] ?? 0,
                    ];
                }, $items);
                wa_send_fonnte($db, (string)$customer['phone'], wa_invoice_message($trxPayload, $waItems), $transactionId);
            }
        } catch (Throwable $waError) {
            // Jangan gagalkan transaksi hanya karena WhatsApp gagal.
        }

        $_SESSION['pos_theme_clear_cart'] = true;
        flash('success', 'Transaksi berhasil. Invoice ' . $invoice . ' dibuat.');
        pos_theme_redirect_relative('../transactions/detail.php?id=' . $transactionId . '&pos_success=1');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $errorMessage = $e->getMessage();
    }
}

// POS V17: load only columns needed by the cashier. Images/descriptions are not
// selected and variants are fetched in one bulk query (no N+1 per product).
$stmtProducts = $db->prepare("SELECT p.id,p.category_id,p.product_code,p.barcode,p.name,p.purchase_price,p.selling_price,p.stock,p.minimum_stock,p.unit,p.updated_at,
    c.name AS category_name,c.color AS category_color,{$posStockExpr} AS stock,{$posMinStockExpr} AS minimum_stock,
    pl.area,pl.rack,pl.shelf,pl.bin_box
    FROM products p
    LEFT JOIN categories c ON c.id=p.category_id
    LEFT JOIN product_branch_stocks pbs ON pbs.product_id=p.id AND pbs.branch_id=?
    LEFT JOIN product_locations pl ON pl.product_id=p.id AND pl.branch_id=?
    WHERE p.is_active=1
    ORDER BY CASE WHEN ({$posStockExpr}) <= 0 THEN 1 ELSE 0 END ASC,p.name ASC");
$stmtProducts->execute([$currentBranchId, $currentBranchId]);
$baseProducts = $stmtProducts->fetchAll() ?: [];
$productIds = array_map(fn($row)=>(int)$row['id'], $baseProducts);
$variantsByProduct = catalog_fast_variants_by_product($db, $productIds, $currentBranchId, true);
$posPriceMap = catalog_fast_price_levels($db, $productIds);
$posCatalogProducts = [];
foreach ($baseProducts as $pp) {
    $vars = $variantsByProduct[(int)$pp['id']] ?? [];
    if ($vars) {
        foreach ($vars as $v) {
            $r = $pp;
            $label = (string)($v['display_name'] ?? $v['name'] ?? 'Varian');
            $r['_variant_id'] = (int)$v['id'];
            $r['_variant_name'] = $label;
            $r['_variant_sku'] = (string)($v['sku'] ?? '');
            $r['name'] = $pp['name'] . ' - ' . $label;
            $r['product_code'] = ($v['sku'] ?? '') ?: $pp['product_code'];
            $r['barcode'] = ($v['barcode'] ?? '') ?: $pp['barcode'];
            $r['selling_price'] = (float)($v['selling_price'] ?? 0) > 0 ? (float)$v['selling_price'] : (float)$pp['selling_price'];
            $r['purchase_price'] = (float)($v['purchase_price'] ?? 0) > 0 ? (float)$v['purchase_price'] : (float)$pp['purchase_price'];
            $r['stock'] = (int)($v['stock'] ?? 0);
            $posCatalogProducts[] = $r;
        }
    } else {
        $pp['_variant_id'] = 0;
        $pp['_variant_name'] = '';
        $posCatalogProducts[] = $pp;
    }
}
usort($posCatalogProducts, static function(array $a,array $b): int {
    $az = (int)$a['stock'] <= 0 ? 1 : 0;
    $bz = (int)$b['stock'] <= 0 ? 1 : 0;
    return ($az <=> $bz) ?: strnatcasecmp((string)$a['name'], (string)$b['name']);
});
$products = $posCatalogProducts;

$bundleRows = $db->query("SELECT * FROM product_bundles WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$posBundles = [];
foreach ($bundleRows as $bundleRow) {
    $bundleRow['stock'] = bundle_available_stock($db, (int)$bundleRow['id'], $currentBranchId);
    $bundleRow['category_name'] = 'Paket Produk';
    $posBundles[] = $bundleRow;
}


$customers = [];
try {
    $customers = $db->query("SELECT id, name, phone, member_code, member_level, price_level, points FROM customers WHERE status = 'active' ORDER BY name ASC")->fetchAll();
} catch (Throwable $e) {
    $customers = [];
}

$memberPointValue = pos_theme_point_value();
$memberEarnRate = pos_theme_point_earn_rate();
$memberMinRedeem = pos_theme_point_min_redeem();
$manualQrisConfig = pos_theme_manual_qris_config();

$paymentMethods = [
    ['code' => 'cash', 'name' => 'Cash'],
    ['code' => 'qris_pakasir', 'name' => 'QRIS Otomatis (Pakasir)'],
];
if ($manualQrisConfig['available']) {
    $paymentMethods[] = ['code' => 'qris_manual', 'name' => $manualQrisConfig['label']];
}

$activePromos = [];
if (pos_theme_table_exists($db, 'promos')) {
    $activePromos = $db->query("SELECT * FROM promos
        WHERE status = 'active'
          AND (start_date IS NULL OR start_date <= CURDATE())
          AND (end_date IS NULL OR end_date >= CURDATE())
          AND (usage_limit = 0 OR used_count < usage_limit)
        ORDER BY promo_type ASC, id DESC")->fetchAll();
}

$shouldClearCart = !empty($_SESSION['pos_theme_clear_cart']);
unset($_SESSION['pos_theme_clear_cart']);

require_once __DIR__ . '/../../layouts/admin-header.php';
require_once __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<link rel="stylesheet" href="<?= legacy_base_url('assets/css/locations.css?v=' . time()); ?>">

<style>
.pos-member-add-link,
a.pos-member-add-link {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: fit-content;
    margin: 10px 0 12px;
    padding: 10px 16px;
    border-radius: 999px;
    border: 1px solid #fecaca;
    background: #fff1f2;
    color: #991b1b !important;
    font-weight: 900;
    text-decoration: none !important;
    box-shadow: 0 10px 24px rgba(153, 27, 27, .08);
}
.pos-member-add-link i {
    font-size: 1.05rem;
}
.pos-member-add-link:hover {
    background: linear-gradient(135deg, #ef4444, #991b1b);
    color: #fff !important;
    border-color: transparent;
}
@media (max-width: 560px) {
    .pos-member-add-link,
    a.pos-member-add-link {
        width: 100%;
        min-height: 46px;
    }
}
</style>

<link rel="stylesheet" href="<?= legacy_base_url('assets/css/debts.css?v=' . time()); ?>">
<main class="admin-main pos-main">
    <header class="admin-topbar page-topbar pos-topbar">
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="bi bi-list"></i>
        </button>

        <div class="topbar-title">
            <h1>Kasir POS</h1>
            <p>Cabang aktif: <b><?= e($currentBranch['name'] ?? 'Cabang'); ?></b>. POS memakai stok cabang ini.
                <?php if ($activeCashierShift): ?>
                    <br><span style="font-size:12px;">Shift: <b><?= e($activeCashierShift['shift_code']); ?></b> • <?= e($activeCashierShift['branch_name'] ?? ($currentBranch['name'] ?? '-')); ?><?= !empty($activeCashierShift['location_name']) ? ' • ' . e($activeCashierShift['location_name']) : ''; ?></span>
                <?php endif; ?>
            </p>
        </div>

        <div class="topbar-actions">
            <a href="../transactions/index.php" class="btn-soft">
                <i class="bi bi-receipt"></i> Riwayat
            </a>
        </div>
    </header>

    <?php if ($errorMessage): ?>
        <div class="pos-theme-alert" id="posServerError" data-message="<?= e($errorMessage); ?>">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div><b>Transaksi gagal</b><span><?= e($errorMessage); ?></span></div>
        </div>
    <?php endif; ?>

    <section class="pos-shell">
        <div class="pos-products-panel premium-card">
            <div class="pos-search-row">
                <div class="pos-search-box">
                    <i class="bi bi-search"></i>
                    <input type="search" id="posSearchTheme" placeholder="Cari nama, kode, barcode produk...">
                </div>

                <div class="pos-sort-box" title="Urutkan produk">
                    <i class="bi bi-sort-alpha-down"></i>
                    <select id="posSortTheme" aria-label="Urutkan produk POS">
                        <option value="az">A–Z • stok tersedia dulu</option>
                        <option value="stock_desc">Stok terbanyak • kosong di bawah</option>
                    </select>
                </div>

                <button type="button" class="btn-scan-camera" id="openBarcodeScanner" aria-haspopup="dialog" aria-controls="barcodeModal">
                    <i class="bi bi-upc-scan"></i> <span>Scan Barang</span>
                </button>

                <button type="button" class="btn-soft" id="clearCartTheme">
                    <i class="bi bi-trash3"></i> <span>Reset</span>
                </button>
            </div>

            <div class="pos-products-grid" id="posProductsGridTheme">
                <?php foreach ($products as $product): ?>
                    <?php
                    $posPrices = $posPriceMap[(int)$product['id']] ?? [];
                    foreach (['retail','grosir','reseller','member'] as $lv) {
                        if (!isset($posPrices[$lv]['price']) || (float)$posPrices[$lv]['price'] <= 0) $posPrices[$lv]['price'] = (float)$product['selling_price'];
                    }
                    if ((int)($product['_variant_id']??0) > 0) {
                        foreach (['retail','grosir','reseller','member'] as $lv) $posPrices[$lv]['price'] = (float)$product['selling_price'];
                    }
                    ?>
                    <button
                        type="button"
                        class="pos-product-card"
                        data-product-card
                        data-id="<?= e($product['id']); ?>"
                        data-variant-id="<?= e((int)($product['_variant_id']??0)); ?>"
                        data-name="<?= e($product['name']); ?>"
                        data-code="<?= e($product['product_code'] ?? ''); ?>"
                        data-barcode="<?= e($product['barcode'] ?? ''); ?>"
                        data-price="<?= e((float)($posPrices['retail']['price'] ?? $product['selling_price'])); ?>"
                        data-price-retail="<?= e((float)($posPrices['retail']['price'] ?? $product['selling_price'])); ?>"
                        data-price-grosir="<?= e((float)($posPrices['grosir']['price'] ?? $product['selling_price'])); ?>"
                        data-price-reseller="<?= e((float)($posPrices['reseller']['price'] ?? $product['selling_price'])); ?>"
                        data-price-member="<?= e((float)($posPrices['member']['price'] ?? $product['selling_price'])); ?>"
                        data-stock="<?= e((int) $product['stock']); ?>"
                        data-unit="<?= e($product['unit'] ?? 'pcs'); ?>"
                        data-category="<?= e($product['category_name'] ?? 'Tanpa kategori'); ?>"
                    >
                        <div class="pos-product-img" aria-hidden="true"><i class="bi bi-box-seam"></i></div>

                        <div class="pos-product-info">
                            <b><?= e($product['name']); ?></b>
                            <span><?= e($product['category_name'] ?? 'Tanpa kategori'); ?> • Stok <?= e($product['stock']); ?></span>
                            <strong><?= rupiah($product['selling_price']); ?></strong>
                        </div>

                        <i class="bi bi-plus-circle-fill pos-plus"></i>
                    </button>
                <?php endforeach; ?>

                <?php foreach ($posBundles as $bundle): ?>
                    <button
                        type="button"
                        class="pos-product-card pos-bundle-card"
                        data-product-card
                        data-type="bundle"
                        data-id="<?= e($bundle['id']); ?>"
                        data-name="<?= e($bundle['name']); ?>"
                        data-code="<?= e($bundle['code'] ?? ''); ?>"
                        data-barcode="<?= e($bundle['code'] ?? ''); ?>"
                        data-price="<?= e((float) $bundle['selling_price']); ?>"
                        data-stock="<?= e((int) $bundle['stock']); ?>"
                        data-unit="paket"
                        data-category="Paket Produk"
                    >
                        <div class="pos-product-img">
                            <?php if (!empty($bundle['image']) && bundle_image_exists($bundle['image'])): ?>
                                <i class="bi bi-box2-heart"></i>
                            <?php else: ?>
                                <i class="bi bi-box2-heart"></i>
                            <?php endif; ?>
                        </div>

                        <div class="pos-product-info">
                            <b><?= e($bundle['name']); ?></b>
                            <span>Paket Produk • Bisa dibuat <?= e($bundle['stock']); ?> paket</span>
                            <strong><?= rupiah($bundle['selling_price']); ?></strong>
                        </div>

                        <i class="bi bi-plus-circle-fill pos-plus"></i>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <aside class="pos-cart-panel premium-card">
            <div class="cart-head">
                <div>
                    <h3>Keranjang</h3>
                    <p id="cartCountTheme">Belum ada item</p>
                </div>

                <span class="badge-soft danger">
                    <i class="bi bi-cart3"></i> POS
                </span>
            </div>

            <form method="POST" id="checkoutFormTheme" action="">
                <?= CSRF::input(); ?>
                <input type="hidden" name="cart_json" id="cartJsonTheme" value="[]">
                <input type="hidden" name="promo_id" id="promoIdTheme" value="">
                <input type="hidden" name="promo_code" id="promoCodeHiddenTheme" value="">

                <div class="member-box-theme">
                    <div class="member-label-row-theme">
                        <label class="mini-label">Member Pelanggan</label>
                        <a href="<?= legacy_base_url('admin/customers/create.php'); ?>" class="member-add-link-theme pos-member-add-link"><i class="bi bi-person-plus"></i> Tambah Member</a>
                    </div>
                    <div class="member-search-picker" id="memberSearchPickerTheme">
                        <div class="member-search-input-wrap">
                            <i class="bi bi-search"></i>
                            <input type="search" id="memberSearchTheme" autocomplete="off" placeholder="Cari nama / 4 digit belakang No. HP..." aria-label="Cari member pelanggan" aria-controls="memberSearchResultsTheme">
                            <button type="button" id="memberClearTheme" class="member-clear-btn" title="Pilih umum / non-member"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <div class="member-search-results" id="memberSearchResultsTheme" hidden></div>
                    </div>
                    <select name="customer_id" id="customerTheme" class="member-hidden-select" aria-hidden="true" tabindex="-1">
                        <option value="" data-points="0" data-name="Umum / non-member" data-phone="" data-price-level="retail">Umum / non-member (tanpa poin)</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= e($customer['id']); ?>"
                                data-points="<?= e((int) $customer['points']); ?>"
                                data-name="<?= e($customer['name']); ?>"
                                data-phone="<?= e($customer['phone'] ?? ''); ?>"
                                data-code="<?= e($customer['member_code'] ?? ''); ?>"
                                data-price-level="<?= e($customer['price_level'] ?? 'retail'); ?>">
                                <?= e($customer['name']); ?><?= !empty($customer['phone']) ? ' - ' . e($customer['phone']) : ''; ?> • <?= e((int) $customer['points']); ?> poin
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$customers): ?><small class="member-empty-note">Belum ada member aktif. Tambahkan member baru terlebih dahulu.</small><?php endif; ?>
                    <div class="member-redeem-row">
                        <div>
                            <small id="memberInfoTheme">Pilih member pelanggan untuk mendapat atau menukar poin.</small>
                            <small class="member-rule-theme">Reward: 1 poin / <?= rupiah($memberEarnRate); ?> belanja • Nilai poin: <?= rupiah($memberPointValue); ?>/poin</small>
                        </div>
                        <input type="number" name="redeem_points" id="redeemPointsTheme" min="0" value="0" placeholder="Tukar poin">
                    </div>
                </div>

                <div class="cart-items" id="cartItemsTheme">
                    <div class="cart-empty">
                        <i class="bi bi-cart-x"></i>
                        <b>Keranjang kosong</b>
                        <span>Pilih produk untuk mulai transaksi.</span>
                    </div>
                </div>

                <div class="checkout-box">
                    <div class="checkout-row">
                        <span>Subtotal</span>
                        <b id="subtotalTheme">Rp 0</b>
                    </div>

                    <div class="pos-promo-box">
                        <div class="pos-promo-head">
                            <div>
                                <b><i class="bi bi-ticket-perforated"></i> Promo / Voucher</b>
                                <span>Pilih voucher/promo atau ketik kode barcode.</span>
                            </div>

                            <button type="button" id="clearPromoTheme" class="promo-clear-btn">
                                <i class="bi bi-x-circle"></i>
                            </button>
                        </div>

                        <select id="promoSelectTheme" class="pos-select promo-select">
                            <option value="">Pilih voucher / promo</option>
                            <?php foreach ($activePromos as $promo): ?>
                                <?php
                                    $promoCodeValue = pos_theme_promo_code($promo);
                                    $promoScope = pos_theme_promo_scope($promo);
                                    $productIds = pos_theme_promo_product_ids($db, (int) $promo['id']);
                                    $labelDiscount = ($promo['discount_type'] ?? '') === 'percent'
                                        ? ((float) $promo['discount_value']) . '%'
                                        : rupiah($promo['discount_value']);
                                ?>
                                <option
                                    value="<?= e($promo['id']); ?>"
                                    data-name="<?= e($promo['name']); ?>"
                                    data-code="<?= e($promoCodeValue); ?>"
                                    data-scope="<?= e($promoScope); ?>"
                                    data-product-ids="<?= e(implode(',', $productIds)); ?>"
                                    data-type="<?= e($promo['discount_type'] ?? 'fixed'); ?>"
                                    data-value="<?= e((float) ($promo['discount_value'] ?? 0)); ?>"
                                    data-max="<?= e((float) ($promo['max_discount'] ?? 0)); ?>"
                                    data-min="<?= e((float) ($promo['min_purchase'] ?? 0)); ?>"
                                >
                                    <?= e($promo['name']); ?> - <?= e($labelDiscount); ?><?= $promoCodeValue ? ' (' . e($promoCodeValue) . ')' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="voucher-row">
                            <input type="text" id="promoCodeTheme" class="pos-input" placeholder="Kode / barcode voucher">
                            <button type="button" id="applyPromoCodeTheme" class="btn-promo-apply">
                                <i class="bi bi-check2"></i> Pakai
                            </button>
                        </div>

                        <div class="promo-applied" id="promoAppliedTheme" style="display:none;">
                            <i class="bi bi-check-circle-fill"></i>
                            <span id="promoAppliedTextTheme">Promo diterapkan</span>
                        </div>
                    </div>

                    <div class="checkout-row input-row">
                        <span>Diskon Manual</span>
                        <input type="number" name="manual_discount" id="manualDiscountTheme" min="0" value="0">
                    </div>

                    <div class="checkout-row promo-line">
                        <span>Diskon Promo</span>
                        <b id="promoDiscountTheme">Rp 0</b>
                    </div>

                    <div class="checkout-row member-point-line">
                        <span>Diskon Poin</span>
                        <b id="pointDiscountTheme">Rp 0</b>
                    </div>

                    <div class="checkout-row discount-line">
                        <span>Total Diskon</span>
                        <b id="discountTotalTheme">Rp 0</b>
                    </div>

                    <div class="checkout-row total">
                        <span>Total</span>
                        <b id="grandTotalTheme">Rp 0</b>
                    </div>

                    <label class="mini-label">Metode Pembayaran</label>
                    <select name="payment_method" id="paymentMethodTheme" class="pos-select">
                        <option value="cash">Cash</option>
                        <option value="cash_debt">Cash + Piutang</option>
                        <option value="qris_pakasir">QRIS Otomatis (Pakasir)</option>
                        <?php if ($manualQrisConfig['available']): ?>
                            <option value="qris_manual"><?= e($manualQrisConfig['label']); ?></option>
                        <?php endif; ?>
                    </select>

                    <div id="cashPaidBlockTheme">
                        <label class="mini-label">Uang Diterima</label>
                        <input type="number" name="paid_amount" id="paidTheme" class="pos-input" min="0" value="0" required>

                        <div class="checkout-row change">
                            <span>Kembalian / Sisa Piutang</span>
                            <b id="changeTheme">Rp 0</b>
                        </div>

                        <div id="debtInfoBlockTheme" class="pos-debt-box" style="display:none;">
                            <label class="mini-label">Jatuh Tempo Piutang</label>
                            <div class="pos-debt-presets" aria-label="Pilihan cepat jatuh tempo">
                                <button type="button" data-debt-days="7">7 Hari</button>
                                <button type="button" data-debt-days="14">14 Hari</button>
                                <button type="button" data-debt-days="30">1 Bulan</button>
                            </div>
                            <input type="date" name="debt_due_date" id="debtDueDateTheme" class="pos-input" min="<?= e(date('Y-m-d')); ?>">
                            <small>Pilih tanggal sendiri atau gunakan pilihan cepat. Sisa tagihan otomatis masuk menu Hutang / Piutang.</small>
                        </div>
                    </div>

                    <div id="qrisInfoBlockTheme" class="qris-info-box" style="display:none;">
                        <i class="bi bi-qr-code"></i>
                        <div>
                            <b>QRIS Otomatis (Pakasir)</b>
                            <span>QR pembayaran dibuat otomatis dan tampil di detail invoice setelah klik Bayar &amp; Simpan.</span>
                        </div>
                    </div>

                    <?php if ($manualQrisConfig['available']): ?>
                    <div id="manualQrisBlockTheme" class="manual-qris-box" style="display:none;">
                        <div class="manual-qris-head"><i class="bi bi-qr-code-scan"></i><div><b><?= e($manualQrisConfig['label']); ?></b><span>Scan QRIS toko, lalu konfirmasi setelah pembayaran benar-benar diterima.</span></div></div>
                        <img src="<?= legacy_base_url($manualQrisConfig['image']); ?>" alt="<?= e($manualQrisConfig['label']); ?>">
                        <label class="manual-qris-confirm"><input type="checkbox" name="manual_qris_confirm" id="manualQrisConfirmTheme" value="1"> <span>Saya sudah menerima pembayaran QRIS ini</span></label>
                    </div>
                    <?php endif; ?>

                    <textarea name="note" class="pos-note" rows="2" placeholder="Catatan transaksi opsional" style="margin-top:14px;"></textarea>

                    <button type="submit" class="btn-premium btn-checkout" id="submitTheme">
                        <i class="bi bi-check2-circle"></i> Bayar & Simpan
                    </button>
                </div>
            </form>
        </aside>
    </section>
</main>

<div class="barcode-modal" id="barcodeModal" aria-hidden="true">
    <button type="button" class="barcode-modal-backdrop" id="closeBarcodeBackdrop" aria-label="Tutup scanner"></button>
    <div class="barcode-modal-card" role="dialog" aria-modal="true" aria-labelledby="barcodeModalTitle">
        <div class="barcode-modal-head">
            <div>
                <h3 id="barcodeModalTitle"><i class="bi bi-upc-scan"></i> Scan Barang</h3>
                <p>Arahkan kamera ke barcode produk. Produk akan langsung masuk ke keranjang.</p>
            </div>
            <button type="button" class="barcode-close" id="closeBarcodeScanner" aria-label="Tutup scanner">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="barcode-reader-wrap">
            <div id="barcodeReader"></div>
            <div class="scan-line"></div>
        </div>
        <div class="barcode-hint">
            <i class="bi bi-info-circle"></i>
            <span>Gunakan HTTPS dan izinkan kamera. Scanner USB/Bluetooth juga dapat dipakai dengan mengetik barcode lalu Enter.</span>
        </div>
    </div>
</div>

<style>
.pos-theme-alert{margin:16px 20px;padding:14px 16px;border-radius:16px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;display:flex;gap:12px;align-items:flex-start}.pos-theme-alert i{font-size:22px}.pos-theme-alert b{display:block}.pos-theme-alert span{display:block;color:#7f1d1d}.cart-item-info small{display:block;color:#64748b;margin-top:4px}.pos-product-img img{width:100%;height:100%;object-fit:cover}.pos-product-card{position:relative}.pos-input{width:100%}.qris-info-box{margin:16px 0 18px;border:1px solid #fecaca;background:#fff7f7;border-radius:18px;padding:12px 14px;display:flex;gap:12px;align-items:flex-start;color:#7f1d1d}.qris-info-box i{font-size:24px;color:#991b1b}.qris-info-box b{display:block;color:#991b1b}.qris-info-box span{display:block;color:#7f1d1d;font-size:14px;line-height:1.55;margin-top:4px}.qty-control input::-webkit-outer-spin-button,.qty-control input::-webkit-inner-spin-button{appearance:none;margin:0}.member-box-theme{margin:0 0 16px;padding:14px;border:1px solid #fee2e2;background:#fff7f7;border-radius:20px}.member-redeem-row{display:grid;grid-template-columns:1fr 120px;gap:10px;align-items:center;margin-top:10px}.member-redeem-row small{display:block;color:#7f1d1d;font-weight:700;line-height:1.4}.member-rule-theme{opacity:.78}.member-redeem-row input{width:100%;border:1px solid #fecaca;border-radius:14px;padding:10px 12px;font-weight:800;text-align:right}.member-point-line b{color:#991b1b}.pos-debt-presets{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 10px}.pos-debt-presets button{border:1px solid #fecaca;background:#fff;color:#991b1b;border-radius:999px;padding:7px 12px;font-size:12px;font-weight:800;cursor:pointer}.pos-debt-presets button:hover,.pos-debt-presets button.active{background:#991b1b;color:#fff;border-color:#991b1b}@media(max-width:720px){.member-redeem-row{grid-template-columns:1fr}.member-redeem-row input{text-align:left}.pos-debt-presets button{flex:1}}
.member-hidden-select{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden!important;clip:rect(0 0 0 0)!important;white-space:nowrap!important;border:0!important;padding:0!important;margin:-1px!important}.member-search-picker{position:relative;margin-top:2px}.member-search-input-wrap{display:flex;align-items:center;gap:9px;background:#fff;border:2px solid #e9b8ff;border-radius:18px;padding:0 12px;box-shadow:0 0 0 6px rgba(168,85,247,.08)}.member-search-input-wrap>i{color:#7e22ce;font-size:18px}.member-search-input-wrap input{flex:1;min-width:0;border:0;outline:0;background:transparent;padding:14px 0;font-weight:800;font-size:15px;color:#111827}.member-clear-btn{border:0;background:#fdf2f8;color:#991b1b;width:34px;height:34px;border-radius:50%;display:grid;place-items:center;cursor:pointer}.member-search-results{margin-top:9px;background:#fff;border:1px solid #e9d5ff;border-radius:16px;max-height:280px;overflow:auto;box-shadow:0 18px 44px rgba(88,28,135,.15);padding:6px}.member-result-btn{width:100%;border:0;background:#fff;text-align:left;border-radius:12px;padding:10px 12px;display:flex;justify-content:space-between;gap:12px;align-items:center;cursor:pointer}.member-result-btn:hover,.member-result-btn:focus{background:#faf5ff;outline:2px solid #d8b4fe}.member-result-main{min-width:0}.member-result-main b{display:block;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.member-result-main small{display:block;color:#64748b;margin-top:2px}.member-result-points{white-space:nowrap;color:#7e22ce;font-weight:900;font-size:12px}.member-search-empty,.member-search-more{padding:11px 12px;color:#64748b;font-size:13px}.member-empty-note{display:block;margin-top:8px;color:#64748b}.manual-qris-box{margin:16px 0 18px;border:1px solid #d8b4fe;background:#faf5ff;border-radius:20px;padding:14px}.manual-qris-head{display:flex;gap:10px;align-items:flex-start;color:#581c87}.manual-qris-head i{font-size:24px}.manual-qris-head b{display:block;font-weight:900}.manual-qris-head span{display:block;color:#6b21a8;font-size:13px;line-height:1.45;margin-top:3px}.manual-qris-box img{display:block;width:min(290px,100%);aspect-ratio:1/1;object-fit:contain;margin:14px auto;background:#fff;border:1px solid #e9d5ff;border-radius:18px;padding:10px}.manual-qris-confirm{display:flex;gap:10px;align-items:flex-start;background:#fff;border:1px solid #e9d5ff;border-radius:14px;padding:12px;font-weight:800;color:#581c87;cursor:pointer}.manual-qris-confirm input{width:20px;height:20px;flex:0 0 auto}@media(max-width:720px){.member-search-input-wrap input{font-size:14px}.member-search-results{max-height:235px}.member-result-btn{padding:11px 10px}.manual-qris-box img{width:min(250px,100%)}}

</style>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
if (typeof window.Html5Qrcode === 'undefined') {
    document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"><\/script>');
}
</script>
<script>
(function(){
    'use strict';

    const SHOULD_CLEAR = <?= $shouldClearCart ? 'true' : 'false'; ?>;
    const STORAGE = 'kasirpro_pos_theme_cart_v2';
    let cart = [];
    let promo = null;
    let barcodeScanner = null;
    let barcodeScannerRunning = false;
    let barcodeScannerStarting = false;
    let barcodeScannerSession = 0;
    let barcodeScanLocked = false;
    let hardwareScanBuffer = '';
    let hardwareScanLastKeyAt = 0;
    const MEMBER_POINT_VALUE = <?= json_encode((float) $memberPointValue); ?>;
    const MEMBER_MIN_REDEEM = <?= json_encode((int) $memberMinRedeem); ?>;

    const qs = (s,p=document)=>p.querySelector(s);
    const qsa = (s,p=document)=>Array.from(p.querySelectorAll(s));
    const num = v => { const n = parseFloat(String(v ?? 0).replace(/[^0-9,.-]/g,'').replace(/\./g,'').replace(',','.')); return isNaN(n) ? 0 : n; };
    const rp = v => new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(num(v));
    const esc = s => String(s ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    const digitsOnly = v => String(v ?? '').replace(/\D+/g,'');
    const memberDisplay = opt => {
        if(!opt || !opt.value) return '';
        const phone = opt.dataset.phone || '';
        return (opt.dataset.name || opt.textContent.trim()) + (phone ? ' - ' + phone : '');
    };


    function fireToast(message, icon='info'){
        if(window.Swal){ Swal.fire({toast:true,position:'top-end',icon,title:message,showConfirmButton:false,timer:2200,timerProgressBar:true}); }
        else alert(message);
    }

    function fireAlert(opts){
        if(window.Swal) return Swal.fire(opts);
        alert(opts.text || opts.title || 'Info');
        return Promise.resolve({isConfirmed:true});
    }

    function load(){
        if(SHOULD_CLEAR) localStorage.removeItem(STORAGE);
        try { cart = JSON.parse(localStorage.getItem(STORAGE)||'[]'); if(!Array.isArray(cart)) cart=[]; } catch(e){ cart=[]; }
    }

    function cardType(card){
        return (card?.dataset?.type || 'product') === 'bundle' ? 'bundle' : 'product';
    }

    function cartKey(item){return itemType(item)+':'+String(item?.id||'')+':'+String(item?.variantId||0);}

    function itemType(item){
        if((item?.type || '') === 'bundle') return 'bundle';
        // Kompatibilitas cache versi lama: bundle dulu belum menyimpan field type.
        if(String(item?.category || '').toLowerCase() === 'paket produk') return 'bundle';
        return 'product';
    }

    function findCardForCartItem(item){
        return qsa('.pos-product-card').find(function(card){
            return String(card.dataset.id||'')===String(item.id||'') && String(card.dataset.variantId||'0')===String(item.variantId||0) && cardType(card)===itemType(item);
        }) || null;
    }

    function syncSavedCartWithCatalog(){
        // localStorage hanya cache tampilan, bukan sumber harga. Setiap halaman POS dibuka,
        // nama/harga/stok harus mengikuti data produk terbaru dari server.
        cart = cart.map(function(item){
            const card = findCardForCartItem(item);
            if(!card) return item;
            const fresh = productFromCard(card);
            return {...item, ...fresh, qty: Math.max(1, parseInt(item.qty || 1, 10))};
        });
    }

    function save(){
        localStorage.setItem(STORAGE, JSON.stringify(cart));
        const cartJson = qs('#cartJsonTheme');
        if(cartJson) cartJson.value = JSON.stringify(cart.map(i=>({type:itemType(i),id:i.id,variant_id:i.variantId||0,qty:i.qty})));
        qs('#promoIdTheme').value = promo ? (promo.id || '') : '';
        qs('#promoCodeHiddenTheme').value = promo ? (promo.code || '') : '';
    }

    function subtotal(){ return cart.reduce((s,i)=>s + num(i.price)*num(i.qty), 0); }
    function manualDiscount(){ return Math.max(0,num(qs('#manualDiscountTheme')?.value || 0)); }
    function promoDiscount(){ return promo ? Math.max(0,num(promo.discount||0)) : 0; }
    function selectedCustomerOption(){ const sel = qs('#customerTheme'); return sel ? sel.options[sel.selectedIndex] : null; }
    function selectedCustomerPoints(){ const opt = selectedCustomerOption(); return opt ? Math.max(0, parseInt(opt.dataset.points || '0', 10)) : 0; }
    function redeemPoints(){ return Math.max(0, parseInt(qs('#redeemPointsTheme')?.value || '0', 10)); }
    function pointDiscount(){
        const customerId = qs('#customerTheme')?.value || '';
        if(!customerId) return 0;
        let points = Math.min(redeemPoints(), selectedCustomerPoints());
        if(points < MEMBER_MIN_REDEEM) points = 0;
        const base = Math.max(0, subtotal() - manualDiscount() - promoDiscount());
        const maxByTotal = Math.floor(base / MEMBER_POINT_VALUE);
        points = Math.min(points, maxByTotal);
        return Math.min(base, points * MEMBER_POINT_VALUE);
    }
    function totalDiscount(){ return Math.min(subtotal(), manualDiscount() + promoDiscount() + pointDiscount()); }
    function grandTotal(){ return Math.max(0, subtotal() - totalDiscount()); }
    function isCash(){ return ['cash','cash_debt'].includes(qs('#paymentMethodTheme')?.value || 'cash'); }
    function isDebtMode(){ return (qs('#paymentMethodTheme')?.value || 'cash') === 'cash_debt'; }
    function paid(){ return isCash() ? Math.max(0,num(qs('#paidTheme')?.value || 0)) : grandTotal(); }
    function renderMemberSearchResults(query=''){
        const box = qs('#memberSearchResultsTheme');
        const select = qs('#customerTheme');
        if(!box || !select) return;
        const raw = String(query || '').trim();
        const q = raw.toLowerCase();
        const qDigits = digitsOnly(raw);
        let matches = Array.from(select.options).filter(opt=>opt.value && !opt.disabled).map(opt=>{
            const name = String(opt.dataset.name || '').toLowerCase();
            const code = String(opt.dataset.code || '').toLowerCase();
            const phone = digitsOnly(opt.dataset.phone || '');
            let score = 0;
            let ok = !q;
            if(q){
                if(name === q) { ok=true; score+=100; }
                else if(name.startsWith(q)) { ok=true; score+=70; }
                else if(name.includes(q)) { ok=true; score+=45; }
                if(code && code.includes(q)) { ok=true; score+=35; }
            }
            if(qDigits){
                if(phone.endsWith(qDigits)) { ok=true; score+=qDigits.length >= 4 ? 95 : 55; }
                else if(phone.includes(qDigits)) { ok=true; score+=35; }
            }
            return {opt,score,ok};
        }).filter(x=>x.ok);
        matches.sort((a,b)=>b.score-a.score || String(a.opt.dataset.name||'').localeCompare(String(b.opt.dataset.name||''),'id'));
        const total = matches.length;
        matches = matches.slice(0,12);
        if(!matches.length){
            box.innerHTML = '<div class="member-search-empty">Member tidak ditemukan. Coba nama atau minimal 4 digit belakang nomor HP.</div>';
        } else {
            box.innerHTML = matches.map(({opt})=>{
                const phone = opt.dataset.phone || '-';
                const code = opt.dataset.code ? ' • ' + esc(opt.dataset.code) : '';
                return '<button type="button" class="member-result-btn" data-member-id="'+esc(opt.value)+'"><span class="member-result-main"><b>'+esc(opt.dataset.name || '-')+'</b><small>'+esc(phone)+code+'</small></span><span class="member-result-points">'+esc(opt.dataset.points || '0')+' poin</span></button>';
            }).join('') + (total > matches.length ? '<div class="member-search-more">Menampilkan '+matches.length+' dari '+total+' hasil. Ketik lebih spesifik untuk mempersempit.</div>' : '');
        }
        box.hidden = false;
    }

    function chooseMember(id){
        const select = qs('#customerTheme');
        const input = qs('#memberSearchTheme');
        const box = qs('#memberSearchResultsTheme');
        if(!select) return;
        select.value = String(id || '');
        const opt = selectedCustomerOption();
        if(input){
            input.value = memberDisplay(opt);
            input.dataset.selectedId = select.value || '';
        }
        if(box) box.hidden = true;
        select.dispatchEvent(new Event('change',{bubbles:true}));
    }

    function clearMemberSelection(){
        chooseMember('');
        const input = qs('#memberSearchTheme');
        if(input){ input.value=''; input.focus(); }
    }

    function updateMemberInfo(){
        const opt = selectedCustomerOption();
        const info = qs('#memberInfoTheme');
        const redeem = qs('#redeemPointsTheme');
        if(!info || !redeem) return;
        const customerId = qs('#customerTheme')?.value || '';
        const priceSelect = qs('#priceLevelTheme');
        const selectedOpt = selectedCustomerOption();
        if(customerId && priceSelect && selectedOpt?.dataset.priceLevel){
            priceSelect.value = selectedOpt.dataset.priceLevel || 'retail';
            applyPriceLevelToCart();
        }
        const points = selectedCustomerPoints();
        if(!customerId){
            info.textContent = 'Pilih member pelanggan untuk mendapat atau menukar poin.';
            redeem.value = 0;
            redeem.disabled = true;
        } else {
            info.textContent = (opt.dataset.name || 'Member') + ' memiliki ' + points + ' poin.';
            redeem.disabled = points < MEMBER_MIN_REDEEM;
            redeem.max = points;
            if(parseInt(redeem.value || '0', 10) > points) redeem.value = points;
        }
    }

    function formatLocalDate(date){
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function setDebtDueDate(days, markButton=true){
        const input = qs('#debtDueDateTheme');
        if(!input) return;
        const date = new Date();
        date.setHours(12, 0, 0, 0);
        date.setDate(date.getDate() + Math.max(0, parseInt(days || 0, 10)));
        input.value = formatLocalDate(date);
        qsa('[data-debt-days]').forEach(btn=>btn.classList.toggle('active', markButton && String(btn.dataset.debtDays) === String(days)));
    }

    function togglePaymentFields(){
        const cashBlock = qs('#cashPaidBlockTheme');
        const qrisBlock = qs('#qrisInfoBlockTheme');
        const manualQrisBlock = qs('#manualQrisBlockTheme');
        const paidInput = qs('#paidTheme');
        const debtBlock = qs('#debtInfoBlockTheme');
        const dueInput = qs('#debtDueDateTheme');
        const method = qs('#paymentMethodTheme')?.value || 'cash';
        const debtHasBalance = isDebtMode() && paid() < grandTotal();
        if(isCash()){
            if(cashBlock) cashBlock.style.display = '';
            if(paidInput) paidInput.required = true;
        } else {
            if(cashBlock) cashBlock.style.display = 'none';
            if(paidInput){ paidInput.required = false; paidInput.value = grandTotal(); }
        }
        if(qrisBlock) qrisBlock.style.display = method === 'qris_pakasir' ? '' : 'none';
        if(manualQrisBlock) manualQrisBlock.style.display = method === 'qris_manual' ? '' : 'none';
        if(debtBlock) debtBlock.style.display = isDebtMode() ? '' : 'none';
        if(dueInput){
            dueInput.required = debtHasBalance;
            if(isDebtMode() && !dueInput.value) setDebtDueDate(7);
        }
    }

    function currentPriceLevel(){
        return qs('#priceLevelTheme')?.value || 'retail';
    }

    function priceByLevel(card, level){
        const map = {
            retail: card.dataset.priceRetail || card.dataset.price,
            grosir: card.dataset.priceGrosir || card.dataset.price,
            reseller: card.dataset.priceReseller || card.dataset.price,
            member: card.dataset.priceMember || card.dataset.price
        };
        return num(map[level] || card.dataset.price || 0);
    }

    function applyPriceLevelToCart(){
        const level = currentPriceLevel();
        cart = cart.map(function(item){
            if(item.type === 'bundle') return item;
            if(item.prices && item.prices[level] !== undefined){
                item.price = num(item.prices[level]);
                item.priceLevel = level;
            }
            return item;
        });
    }

    function productFromCard(card){
        return {
            type: cardType(card),
            id: num(card.dataset.id),
            variantId: num(card.dataset.variantId||0),
            name: card.dataset.name || 'Produk',
            code: card.dataset.code || '',
            barcode: card.dataset.barcode || '',
            price: priceByLevel(card, currentPriceLevel()),
            prices: {
                retail: num(card.dataset.priceRetail || card.dataset.price),
                grosir: num(card.dataset.priceGrosir || card.dataset.price),
                reseller: num(card.dataset.priceReseller || card.dataset.price),
                member: num(card.dataset.priceMember || card.dataset.price)
            },
            stock: num(card.dataset.stock),
            unit: card.dataset.unit || 'pcs',
            category: card.dataset.category || 'Tanpa kategori',
            priceLevel: currentPriceLevel()
        };
    }

    function addProduct(product){
        if(!product || !product.id) return;
        if(product.stock <= 0){ fireToast('Stok produk kosong.','warning'); return; }
        const item=cart.find(i=>cartKey(i)===cartKey(product));
        if(item){
            // Refresh data cached item dulu supaya edit nama/harga langsung terlihat
            // walau produk sudah ada di keranjang sebelum diedit.
            const previousQty = Math.max(1, parseInt(item.qty || 1, 10));
            Object.assign(item, product);
            if(previousQty + 1 > item.stock){ fireToast('Qty melebihi stok tersedia.','warning'); item.qty = previousQty; return; }
            item.qty = previousQty + 1;
        } else {
            cart.push({...product, qty:1});
        }
        render();
        refreshPromoAfterCartChange();
        fireToast(product.name + ' masuk keranjang','success');
    }

    function resetProductFilter(){
        const search = qs('#posSearchTheme');
        if(search) search.value = '';
        qsa('.pos-product-card').forEach(card=>{ card.style.display = ''; });
    }

    function sortProductCards(mode){
        const grid = qs('#posProductsGridTheme');
        if(!grid) return;
        const cards = Array.from(grid.querySelectorAll('.pos-product-card'));
        const selectedMode = mode === 'stock_desc' ? 'stock_desc' : 'az';

        cards.sort(function(a,b){
            const stockA = num(a.dataset.stock);
            const stockB = num(b.dataset.stock);
            const emptyA = stockA <= 0 ? 1 : 0;
            const emptyB = stockB <= 0 ? 1 : 0;

            // Stok kosong selalu dipindah ke paling bawah. Di kelompok kosong tetap A-Z.
            if(emptyA !== emptyB) return emptyA - emptyB;

            const nameA = String(a.dataset.name || '').trim();
            const nameB = String(b.dataset.name || '').trim();
            if(selectedMode === 'stock_desc' && !emptyA && stockA !== stockB){
                return stockB - stockA;
            }
            return nameA.localeCompare(nameB, 'id', {sensitivity:'base', numeric:true});
        });

        cards.forEach(function(card){ grid.appendChild(card); });
    }

    function findProductCardByCode(code){
        const wanted = String(code || '').trim().toLowerCase();
        if(!wanted) return null;
        return qsa('.pos-product-card').find(function(card){
            const barcode = String(card.dataset.barcode || '').trim().toLowerCase();
            const productCode = String(card.dataset.code || '').trim().toLowerCase();
            return (barcode && barcode === wanted) || (productCode && productCode === wanted);
        }) || null;
    }

    function addProductByScannedCode(code){
        const cleanCode = String(code || '').trim();
        if(!cleanCode) return false;
        const card = findProductCardByCode(cleanCode);
        if(!card){
            fireToast('Barcode "' + cleanCode + '" tidak ditemukan.','warning');
            return false;
        }
        addProduct(productFromCard(card));
        resetProductFilter();
        return true;
    }

    function barcodePreflightError(){
        if(typeof window.Html5Qrcode === 'undefined'){
            return 'Library scanner gagal dimuat. Periksa koneksi internet atau firewall hosting.';
        }
        if(!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1'){
            return 'Kamera hanya dapat digunakan melalui HTTPS. Buka POS memakai https://.';
        }
        if(!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function'){
            return 'Browser atau WebView ini tidak menyediakan akses kamera. Coba Chrome terbaru atau aktifkan izin kamera WebView.';
        }
        return '';
    }

    function barcodeCameraErrorMessage(err){
        const text = String((err && (err.name || err.message)) || err || '').toLowerCase();
        if(text.includes('notallowed') || text.includes('permission') || text.includes('denied')){
            return 'Izin kamera ditolak. Buka pengaturan situs browser lalu izinkan Kamera.';
        }
        if(text.includes('notfound') || text.includes('devicesnotfound') || text.includes('no cameras')){
            return 'Kamera tidak ditemukan pada perangkat ini.';
        }
        if(text.includes('notreadable') || text.includes('trackstart') || text.includes('could not start')){
            return 'Kamera sedang dipakai aplikasi lain atau tidak dapat dibuka.';
        }
        if(text.includes('security') || text.includes('secure')){
            return 'Akses kamera diblokir karena halaman tidak memakai HTTPS.';
        }
        return 'Kamera tidak bisa dibuka. Pastikan HTTPS aktif dan izin kamera diberikan.';
    }

    function clearBarcodeReader(){
        const reader = qs('#barcodeReader');
        if(reader) reader.innerHTML = '';
    }

    function openBarcodeScanner(){
        const preflight = barcodePreflightError();
        if(preflight){
            fireAlert({icon:'error',title:'Scanner tidak dapat dibuka',text:preflight});
            return;
        }
        const modal = qs('#barcodeModal');
        if(!modal) return;
        modal.classList.add('show');
        modal.setAttribute('aria-hidden','false');
        document.body.classList.add('barcode-camera-open');
        barcodeScanLocked = false;
        const session = ++barcodeScannerSession;
        setTimeout(function(){ startBarcodeScanner(session); }, 140);
    }

    function startBarcodeScanner(session){
        if(session !== barcodeScannerSession || barcodeScannerRunning || barcodeScannerStarting) return;
        clearBarcodeReader();
        const instance = new window.Html5Qrcode('barcodeReader');
        barcodeScanner = instance;
        barcodeScannerStarting = true;
        instance.start(
            {facingMode:'environment'},
            {fps:10, qrbox:{width:240,height:140}, aspectRatio:1.777778},
            function(decodedText){
                if(barcodeScanLocked) return;
                barcodeScanLocked = true;
                addProductByScannedCode(decodedText);
                closeBarcodeScanner();
            },
            function(){}
        ).then(function(){
            barcodeScannerStarting = false;
            if(session !== barcodeScannerSession){
                return instance.stop().catch(function(){}).then(function(){
                    try{ instance.clear(); }catch(e){}
                });
            }
            barcodeScannerRunning = true;
        }).catch(function(err){
            barcodeScannerStarting = false;
            barcodeScannerRunning = false;
            if(barcodeScanner === instance) barcodeScanner = null;
            if(session !== barcodeScannerSession){
                try{ instance.clear(); }catch(e){}
                clearBarcodeReader();
                return;
            }
            console.error('POS barcode camera error:', err);
            try{ instance.clear(); }catch(e){}
            clearBarcodeReader();
            const modal = qs('#barcodeModal');
            if(modal){ modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); }
            document.body.classList.remove('barcode-camera-open');
            fireAlert({icon:'error',title:'Kamera tidak dapat dibuka',text:barcodeCameraErrorMessage(err)});
        });
    }

    function closeBarcodeScanner(){
        barcodeScannerSession++;
        const instance = barcodeScanner;
        const wasRunning = barcodeScannerRunning;
        const wasStarting = barcodeScannerStarting;
        barcodeScanner = null;
        barcodeScannerRunning = false;
        barcodeScannerStarting = false;
        const modal = qs('#barcodeModal');
        if(modal){ modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); }
        document.body.classList.remove('barcode-camera-open');
        if(!instance){ clearBarcodeReader(); return; }
        const finish = function(){
            try{ instance.clear(); }catch(e){}
            clearBarcodeReader();
        };
        if(wasRunning) instance.stop().then(finish).catch(finish);
        else if(!wasStarting) finish();
    }

    function setQty(key, qty){
        const item=cart.find(i=>cartKey(i)===String(key));if(!item)return;qty=Math.max(0,parseInt(qty||0,10));if(qty<=0){cart=cart.filter(i=>cartKey(i)!==String(key));}else{if(qty>item.stock){fireToast('Qty melebihi stok tersedia.','warning');qty=item.stock;}item.qty=qty;}if(!cart.length)promo=null;render();refreshPromoAfterCartChange();
    }

    function renderCart(){
        const box = qs('#cartItemsTheme');
        if(!box) return;
        if(!cart.length){
            box.innerHTML = '<div class="cart-empty"><i class="bi bi-cart-x"></i><b>Keranjang kosong</b><span>Pilih produk untuk mulai transaksi.</span></div>';
            qs('#cartCountTheme').textContent = 'Belum ada item';
            return;
        }
        box.innerHTML = cart.map(item=>`
            <div class="cart-item" data-cart-id="${esc(cartKey(item))}">
                <div class="cart-item-info">
                    <b>${esc(item.name)}</b>
                    <small>${esc(item.category)} • ${rp(item.price)} × ${item.qty} = ${rp(item.price*item.qty)}</small>
                </div>
                <div class="qty-control">
                    <button type="button" data-minus="${esc(cartKey(item))}">−</button>
                    <input type="number" min="1" value="${item.qty}" data-qty="${esc(cartKey(item))}">
                    <button type="button" data-plus="${esc(cartKey(item))}">+</button>
                </div>
                <button type="button" class="cart-remove" data-remove="${esc(cartKey(item))}"><i class="bi bi-trash"></i></button>
            </div>`).join('');
        qs('#cartCountTheme').textContent = cart.reduce((s,i)=>s+i.qty,0) + ' item';
    }

    function renderPromo(){
        const box = qs('#promoAppliedTheme');
        const text = qs('#promoAppliedTextTheme');
        if(!box || !text) return;
        if(!promo){ box.style.display='none'; text.textContent='Promo diterapkan'; }
        else { box.style.display=''; text.textContent = (promo.name || 'Promo') + ' - ' + rp(promo.discount); }
    }

    function updateTotals(){
        qs('#subtotalTheme').textContent = rp(subtotal());
        qs('#promoDiscountTheme').textContent = rp(promoDiscount());
        qs('#pointDiscountTheme').textContent = rp(pointDiscount());
        qs('#discountTotalTheme').textContent = rp(totalDiscount());
        qs('#grandTotalTheme').textContent = rp(grandTotal());
        qs('#changeTheme').textContent = rp(isDebtMode() ? Math.max(0, grandTotal()-paid()) : (isCash() ? Math.max(0, paid()-grandTotal()) : 0));
        togglePaymentFields();
        updateMemberInfo();
        save();
    }

    function render(){ renderCart(); renderPromo(); updateTotals(); }

    function promoFromOption(option){
        if(!option || !option.value) return null;
        return {
            id: option.value,
            name: option.dataset.name || option.textContent.trim(),
            code: option.dataset.code || '',
            scope: option.dataset.scope || 'all',
            productIds: String(option.dataset.productIds || '').split(',').map(x=>parseInt(x,10)).filter(Boolean),
            type: option.dataset.type || 'fixed',
            value: num(option.dataset.value),
            max: num(option.dataset.max),
            min: num(option.dataset.min)
        };
    }

    function calculatePromo(p){
        if(!p || !cart.length) return 0;
        let eligible = subtotal();
        if(p.scope === 'specific_products'){
            eligible = cart.reduce((s,item)=>p.productIds.includes(parseInt(item.id,10)) ? s + item.price*item.qty : s, 0);
        }
        if(eligible <= 0 || eligible < p.min) return 0;
        if(p.type === 'percent'){
            let d = eligible * (p.value/100);
            if(p.max > 0) d = Math.min(d, p.max);
            return Math.min(d, eligible);
        }
        return Math.min(p.value, eligible);
    }

    function applySelectedPromo(showMessage=true){
        const select = qs('#promoSelectTheme');
        const p = promoFromOption(select.options[select.selectedIndex]);
        if(!p){ promo=null; render(); return; }
        const discount = calculatePromo(p);
        if(discount <= 0){
            promo=null;
            render();
            if(showMessage) fireToast('Promo belum memenuhi syarat / produk tidak sesuai.','warning');
            return;
        }
        promo = {id:p.id, name:p.name, code:p.code, discount};
        qs('#promoCodeTheme').value = p.code || '';
        render();
        if(showMessage) fireToast('Promo berhasil diterapkan.','success');
    }

    function applyCodePromo(){
        const code = (qs('#promoCodeTheme').value || '').trim().toUpperCase();
        if(!code){ fireToast('Masukkan kode voucher dulu.','warning'); return; }
        const options = qsa('#promoSelectTheme option');
        const found = options.find(opt => opt.value && String(opt.dataset.code || '').toUpperCase() === code);
        if(found){
            qs('#promoSelectTheme').value = found.value;
            applySelectedPromo(true);
        } else {
            promo = {id:'', name:'Voucher ' + code, code:code, discount:0};
            qs('#promoSelectTheme').value = '';
            render();
            fireToast('Kode akan divalidasi server saat transaksi disimpan.','info');
        }
    }

    function refreshPromoAfterCartChange(){
        const select = qs('#promoSelectTheme');
        if(select && select.value) applySelectedPromo(false);
        else render();
    }

    document.addEventListener('click', function(e){
        const memberResult = e.target.closest('[data-member-id]');
        if(memberResult){ e.preventDefault(); chooseMember(memberResult.dataset.memberId); return; }
        if(e.target.closest('#memberClearTheme')){ e.preventDefault(); clearMemberSelection(); return; }
        const picker = qs('#memberSearchPickerTheme');
        const memberBox = qs('#memberSearchResultsTheme');
        if(memberBox && picker && !picker.contains(e.target)) memberBox.hidden = true;
        const debtPreset = e.target.closest('[data-debt-days]');
        if(debtPreset){ e.preventDefault(); setDebtDueDate(debtPreset.dataset.debtDays); return; }
        const card = e.target.closest('.pos-product-card');
        if(card){ e.preventDefault(); addProduct(productFromCard(card)); return; }
        const plus=e.target.closest('[data-plus]');if(plus){const item=cart.find(i=>cartKey(i)===plus.dataset.plus);if(item)setQty(cartKey(item),item.qty+1);return;}
        const minus=e.target.closest('[data-minus]');if(minus){const item=cart.find(i=>cartKey(i)===minus.dataset.minus);if(item)setQty(cartKey(item),item.qty-1);return;}
        const remove = e.target.closest('[data-remove]');
        if(remove){ setQty(remove.dataset.remove,0); return; }
        if(e.target.closest('#clearCartTheme')){
            e.preventDefault();
            fireAlert({title:'Reset keranjang?',icon:'question',showCancelButton:true,confirmButtonText:'Reset',cancelButtonText:'Batal',confirmButtonColor:'#dc2626'}).then(r=>{
                if(r.isConfirmed){ cart=[]; promo=null; qs('#promoSelectTheme').value=''; qs('#promoCodeTheme').value=''; render(); }
            });
            return;
        }
        if(e.target.closest('#clearPromoTheme')){ e.preventDefault(); promo=null; qs('#promoSelectTheme').value=''; qs('#promoCodeTheme').value=''; render(); return; }
        if(e.target.closest('#applyPromoCodeTheme')){ e.preventDefault(); applyCodePromo(); return; }
        if(e.target.closest('#openBarcodeScanner')){ e.preventDefault(); openBarcodeScanner(); return; }
        if(e.target.closest('#closeBarcodeScanner, #closeBarcodeBackdrop')){ e.preventDefault(); closeBarcodeScanner(); return; }
    });

    document.addEventListener('input', function(e){
        if(e.target.matches('#memberSearchTheme')){
            const select = qs('#customerTheme');
            if(select && e.target.dataset.selectedId){
                select.value = '';
                e.target.dataset.selectedId = '';
                updateTotals();
            }
            renderMemberSearchResults(e.target.value);
        }
        if(e.target.matches('#posSearchTheme')){
            const key = e.target.value.toLowerCase();
            qsa('.pos-product-card').forEach(card=>{
                const text = (card.textContent + ' ' + card.dataset.code + ' ' + card.dataset.barcode).toLowerCase();
                card.style.display = !key || text.includes(key) ? '' : 'none';
            });
        }
        if(e.target.matches('[data-qty]')) setQty(e.target.dataset.qty, e.target.value);
        if(e.target.matches('#manualDiscountTheme,#paidTheme,#redeemPointsTheme')) updateTotals();
        if(e.target.matches('#debtDueDateTheme')) qsa('[data-debt-days]').forEach(btn=>btn.classList.remove('active'));
    });

    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape' && qs('#barcodeModal')?.classList.contains('show')){
            e.preventDefault();
            closeBarcodeScanner();
            return;
        }

        if(e.target && e.target.matches('#memberSearchTheme')){
            if(e.key === 'Enter'){
                const first = qs('#memberSearchResultsTheme [data-member-id]');
                if(first){ e.preventDefault(); chooseMember(first.dataset.memberId); }
            } else if(e.key === 'Escape'){
                const box = qs('#memberSearchResultsTheme');
                if(box) box.hidden = true;
            }
            return;
        }

        if(e.target && e.target.matches('#posSearchTheme') && e.key === 'Enter'){
            const code = e.target.value.trim();
            if(code){
                e.preventDefault();
                addProductByScannedCode(code);
            }
            return;
        }

        const active = document.activeElement;
        const editable = active && (
            active.matches('input, textarea, select') ||
            active.isContentEditable
        );
        if(editable || e.ctrlKey || e.altKey || e.metaKey) return;

        const now = Date.now();
        if(e.key === 'Enter'){
            if(hardwareScanBuffer.length >= 3){
                e.preventDefault();
                const code = hardwareScanBuffer;
                hardwareScanBuffer = '';
                addProductByScannedCode(code);
            } else {
                hardwareScanBuffer = '';
            }
            return;
        }
        if(e.key.length !== 1) return;
        if(now - hardwareScanLastKeyAt > 120) hardwareScanBuffer = '';
        hardwareScanLastKeyAt = now;
        hardwareScanBuffer += e.key;
    });

    document.addEventListener('focusin', function(e){
        if(e.target.matches('#memberSearchTheme')) renderMemberSearchResults(e.target.value);
    });

    document.addEventListener('change', function(e){
        if(e.target.matches('#promoSelectTheme')) applySelectedPromo(true);
        if(e.target.matches('#paymentMethodTheme,#customerTheme')) updateTotals();
        if(e.target.matches('#priceLevelTheme')){ applyPriceLevelToCart(); render(); }
        if(e.target.matches('#posSortTheme')) sortProductCards(e.target.value);
    });

    qs('#checkoutFormTheme').addEventListener('submit', function(e){
        render();
        const codeInput = (qs('#promoCodeTheme').value || '').trim().toUpperCase();
        if(codeInput && !promo){ qs('#promoCodeHiddenTheme').value = codeInput; }
        if(!cart.length){ e.preventDefault(); fireAlert({icon:'warning',title:'Keranjang kosong',text:'Pilih produk dulu sebelum bayar.'}); return; }
        if(redeemPoints() > 0 && !(qs('#customerTheme')?.value || '')){
            e.preventDefault();
            fireAlert({icon:'warning',title:'Pilih member pelanggan',text:'Pilih member pelanggan dulu untuk menukar poin.'});
            return;
        }
        if(redeemPoints() > selectedCustomerPoints()){
            e.preventDefault();
            fireAlert({icon:'warning',title:'Poin tidak cukup',text:'Poin yang ditukar melebihi poin member.'});
            return;
        }
        if(isCash() && paid() < grandTotal()){
            if(!isDebtMode()){
                e.preventDefault();
                fireAlert({icon:'warning',title:'Uang diterima kurang',text:'Pilih metode Cash + Piutang untuk menyimpan sisa tagihan.'});
                return;
            }
            if(!(qs('#customerTheme')?.value || '')){
                e.preventDefault();
                fireAlert({icon:'warning',title:'Pilih member pelanggan',text:'Transaksi piutang wajib memilih member pelanggan.'});
                return;
            }
            if(!(qs('#debtDueDateTheme')?.value || '')){
                e.preventDefault();
                fireAlert({icon:'warning',title:'Pilih jatuh tempo',text:'Tentukan tanggal jatuh tempo piutang terlebih dahulu.'});
                return;
            }
        }
        if((qs('#paymentMethodTheme')?.value || '') === 'qris_manual' && !qs('#manualQrisConfirmTheme')?.checked){
            e.preventDefault();
            fireAlert({icon:'warning',title:'Konfirmasi pembayaran QRIS',text:'Centang konfirmasi setelah pembayaran QRIS benar-benar sudah diterima.'});
            return;
        }
        if(!isCash()) qs('#paidTheme').value = grandTotal();
        const btn = qs('#submitTheme');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Memproses transaksi...';
        if(window.Swal){
            const method = qs('#paymentMethodTheme')?.value || 'cash';
            const loadingText = isDebtMode() ? 'Menyimpan transaksi piutang.' : (isCash() ? 'Menyimpan transaksi cash.' : (method === 'qris_manual' ? 'Menyimpan pembayaran QRIS manual.' : 'Membuat QRIS, mohon tunggu.'));
            Swal.fire({title:'Memproses transaksi...',text:loadingText,allowOutsideClick:false,allowEscapeKey:false,didOpen:()=>Swal.showLoading()});
        }
    });

    sortProductCards(qs('#posSortTheme')?.value || 'az');
    load();
    syncSavedCartWithCatalog();
    render();
    chooseMember(qs('#customerTheme')?.value || '');

    const errorBox = qs('#posServerError');
    if(errorBox && window.Swal){
        Swal.fire({icon:'error',title:'Transaksi gagal',text:errorBox.dataset.message || 'Transaksi gagal diproses.'});
    }
})();
</script>

<?php require_once __DIR__ . '/../../layouts/admin-footer.php'; ?>
