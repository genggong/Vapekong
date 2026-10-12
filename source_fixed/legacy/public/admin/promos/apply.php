<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';

header('Content-Type: application/json');

Auth::requireLogin();

$db = Database::connect();
ensure_promos_table($db);

function promo_json_response(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}

function promo_table_has_column(PDO $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function promo_cart_product_ids(array $cart): array
{
    $ids = [];

    foreach ($cart as $item) {
        $id = $item['product_id'] ?? $item['id'] ?? null;
        if ($id !== null && $id !== '') {
            $ids[] = (int) $id;
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

function promo_cart_eligible_subtotal(PDO $db, array $promo, array $cart): float
{
    $subtotal = 0;

    $scope = $promo['scope'] ?? $promo['applies_to'] ?? 'all';
    $promoId = (int)($promo['id'] ?? 0);

    if ($scope !== 'specific_products' && $scope !== 'products' && $scope !== 'selected_products') {
        foreach ($cart as $item) {
            $subtotal += (float)($item['price'] ?? 0) * (float)($item['qty'] ?? 0);
        }
        return $subtotal;
    }

    $eligibleIds = [];

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS promo_products (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                promo_id BIGINT NOT NULL,
                product_id BIGINT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY promo_product_unique (promo_id, product_id),
                INDEX idx_promo_products_promo (promo_id),
                INDEX idx_promo_products_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $stmt = $db->prepare("SELECT product_id FROM promo_products WHERE promo_id = ?");
        $stmt->execute([$promoId]);
        $eligibleIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        $eligibleIds = [];
    }

    if (!$eligibleIds) {
        return 0;
    }

    foreach ($cart as $item) {
        $productId = (int)($item['product_id'] ?? $item['id'] ?? 0);
        if (in_array($productId, $eligibleIds, true)) {
            $subtotal += (float)($item['price'] ?? 0) * (float)($item['qty'] ?? 0);
        }
    }

    return $subtotal;
}

function promo_discount_amount(array $promo, float $eligibleSubtotal): float
{
    if ($eligibleSubtotal <= 0) {
        return 0;
    }

    $discountType = $promo['discount_type'] ?? 'fixed';
    $discountValue = (float)($promo['discount_value'] ?? 0);
    $maxDiscount = (float)($promo['max_discount'] ?? 0);

    if ($discountType === 'percent') {
        $discount = $eligibleSubtotal * ($discountValue / 100);

        if ($maxDiscount > 0) {
            $discount = min($discount, $maxDiscount);
        }

        return max(0, min($discount, $eligibleSubtotal));
    }

    return max(0, min($discountValue, $eligibleSubtotal));
}

$subtotal = (float)($_POST['subtotal'] ?? 0);
$code = strtoupper(trim((string)($_POST['code'] ?? $_POST['promo_code'] ?? $_POST['barcode'] ?? '')));
$promoId = (int)($_POST['promo_id'] ?? $_POST['id'] ?? 0);

$cartRaw = $_POST['cart'] ?? $_POST['items'] ?? $_POST['cart_json'] ?? '[]';
$cart = json_decode((string)$cartRaw, true);

if (!is_array($cart)) {
    $cart = [];
}

if (!$cart && $subtotal <= 0) {
    promo_json_response(false, 'Keranjang promo kosong.');
}

$realSubtotal = 0;
foreach ($cart as $item) {
    $realSubtotal += (float)($item['price'] ?? 0) * (float)($item['qty'] ?? 0);
}

if ($realSubtotal > 0) {
    $subtotal = $realSubtotal;
}

$hasBarcode = promo_table_has_column($db, 'promos', 'barcode_code');
$hasScope = promo_table_has_column($db, 'promos', 'scope');

$where = "
    status = 'active'
    AND (start_date IS NULL OR start_date <= CURDATE())
    AND (end_date IS NULL OR end_date >= CURDATE())
    AND (usage_limit = 0 OR used_count < usage_limit)
";

$params = [];

if ($promoId > 0) {
    $where .= " AND id = ?";
    $params[] = $promoId;
} elseif ($code !== '') {
    if ($hasBarcode) {
        $where .= " AND (UPPER(code) = ? OR UPPER(barcode_code) = ?)";
        $params[] = $code;
        $params[] = $code;
    } else {
        $where .= " AND UPPER(code) = ?";
        $params[] = $code;
    }
} else {
    $where .= " AND promo_type = 'auto_discount'";
}

$sql = "SELECT * FROM promos WHERE {$where} ORDER BY discount_value DESC, id DESC LIMIT 1";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$promo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$promo) {
    promo_json_response(false, 'Promo tidak ditemukan, tidak aktif, expired, atau limit sudah habis.');
}

$minPurchase = (float)($promo['min_purchase'] ?? 0);
if ($subtotal < $minPurchase) {
    promo_json_response(false, 'Minimal belanja promo belum terpenuhi.');
}

$eligibleSubtotal = promo_cart_eligible_subtotal($db, $promo, $cart);

if ($eligibleSubtotal <= 0) {
    promo_json_response(false, 'Produk di keranjang tidak sesuai dengan produk promo.');
}

$discount = promo_discount_amount($promo, $eligibleSubtotal);

if ($discount <= 0) {
    promo_json_response(false, 'Diskon promo tidak valid.');
}

promo_json_response(true, 'Promo berhasil diterapkan.', [
    'promo' => [
        'id' => (int)$promo['id'],
        'name' => $promo['name'],
        'code' => $promo['code'] ?? '',
        'barcode' => $promo['barcode_code'] ?? ($promo['code'] ?? ''),
        'promo_type' => $promo['promo_type'] ?? '',
        'scope' => $promo['scope'] ?? 'all',
    ],
    'eligible_subtotal' => $eligibleSubtotal,
    'discount' => $discount,
    'discount_rupiah' => rupiah($discount),
]);
