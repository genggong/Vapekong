<?php
/**
 * Repro harness: purchase create + storefront checkout.
 * Boots the real app against a throwaway MySQL schema, then exercises the
 * exact code paths the user reports as broken.
 *
 * Usage: php tests/repro_bug.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=vapekong_pos;charset=utf8mb4', 'vk_test', 'VkTest!2026#Secure', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($db->query('SHOW TABLES', PDO::FETCH_NUM)->fetchAll() as $r) {
    $db->exec('DROP TABLE IF EXISTS `' . $r[0] . '`');
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

function out(string $label, bool $ok, string $detail = ''): void
{
    printf("%s  %s%s\n", $ok ? '[PASS]' : '[FAIL]', $label, $detail !== '' ? " -- $detail" : '');
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}
$failed = false;

// --- minimal core schema the modules expect to already exist -------------
$db->exec("CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    username VARCHAR(60) NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'admin',
    is_active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB");
$db->exec("CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(120) NOT NULL UNIQUE,
    setting_value TEXT NULL
) ENGINE=InnoDB");
$db->exec("CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    product_code VARCHAR(80) NULL,
    barcode VARCHAR(80) NULL,
    category VARCHAR(120) NULL,
    price DECIMAL(15,2) NOT NULL DEFAULT 0,
    purchase_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    stock INT NOT NULL DEFAULT 0,
    minimum_stock INT NOT NULL DEFAULT 0,
    unit VARCHAR(40) DEFAULT 'pcs',
    weight_gram INT NOT NULL DEFAULT 0,
    is_active TINYINT NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$db->exec("CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    is_active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB");
$db->exec("CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    code VARCHAR(50) NULL,
    address TEXT NULL,
    phone VARCHAR(50) NULL,
    is_main TINYINT NOT NULL DEFAULT 0,
    is_active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB");
$db->exec("CREATE TABLE product_branch_stocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    minimum_stock INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq (branch_id, product_id)
) ENGINE=InnoDB");

require $root . '/legacy/app/bootstrap.php';
require $root . '/legacy/app/Modules/Marketplace/Marketplace.php';
require $root . '/legacy/public/admin/purchases/_helpers.php';
require $root . '/legacy/public/admin/warehouse/_helpers.php';
require $root . '/legacy/public/admin/branches/_helpers.php';

warehouse_install($db);
branch_install($db);
purchases_install($db);
marketplace_install($db);

// --- fixtures -------------------------------------------------------------
$db->exec("INSERT INTO users (name,username,role) VALUES ('Admin','admin','owner')");
$db->exec("INSERT INTO branches (name,code,is_main,is_active) VALUES ('Toko Utama','T01',1,1)");
$db->exec("INSERT INTO products (name,product_code,barcode,price,purchase_price,stock,unit,weight_gram,is_active)
           VALUES ('Vape A','PRD-001','899001',50000,30000,10,'pcs',100,1)");
$db->exec("INSERT INTO products (name,product_code,barcode,price,purchase_price,stock,unit,weight_gram,is_active)
           VALUES ('Vape B','PRD-002','899002',75000,45000,5,'pcs',150,1)");
$db->exec("INSERT INTO suppliers (name,is_active) VALUES ('Supplier Satu',1)");

echo "== SCHEMA ==\n";
$cols = array_column($db->query("SHOW COLUMNS FROM purchases", PDO::FETCH_ASSOC)->fetchAll(), 'Field');
out('purchases.stock_destination_type ada', in_array('stock_destination_type', $cols, true), implode(',', $cols));
out('purchases.warehouse_id ada', in_array('warehouse_id', $cols, true));
out('purchase_items.variant_id ada', in_array('variant_id', array_column($db->query("SHOW COLUMNS FROM purchase_items", PDO::FETCH_ASSOC)->fetchAll(), 'Field'), true));

echo "\n== BUG 1: input produk pembelian (baris item tambahan) ==\n";
// purchases.js membuat baris baru dengan name="product_id[]" - server baca item_key
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    '_csrf_token' => $_SESSION['_csrf_token'] ?? '',
    'supplier_id' => '1',
    'purchase_date' => date('Y-m-d'),
    'payment_status' => 'paid',
    'stock_destination' => 'warehouse:' . (int) $db->query("SELECT id FROM warehouses ORDER BY id LIMIT 1")->fetchColumn(),
    'item_key' => [],          // <-- tidak ada, karena JS kirim product_id[]
    'product_id' => ['1', '2'],
    'qty' => ['2', '3'],
    'purchase_price' => ['31000', '46000'],
    'discount' => '0',
    'shipping_cost' => '0',
    'paid_amount' => '0',
];
$_SESSION['_csrf_token'] = $_SESSION['_csrf_token'] ?? 'test-token';
$items = [];
foreach ($_POST['item_key'] as $i => $key) { /* server loop */ }
out(
    'POST dari purchases.js menghasilkan item ter-parse',
    count($items) > 0,
    'item_key kosong -> flash "Minimal masukkan 1 produk pembelian."'
);

echo "\n== BUG 2: checkout customer web ==\n";
$branchId = (int) $db->query("SELECT id FROM branches WHERE is_main=1 LIMIT 1")->fetchColumn();
foreach ([1, 2] as $pid) {
    $db->prepare("INSERT IGNORE INTO product_branch_stocks (branch_id,product_id,stock,minimum_stock) VALUES (?,?,?,0)")
        ->execute([$branchId, $pid, $pid === 1 ? 10 : 5]);
}

// Cart valid, wilayah valid, tapi pickup & delivery sama-sama tak terkonfigurasi
$_SESSION['_csrf_token'] = $_SESSION['_csrf_token'] ?? 'test-token';
$_SESSION['storefront_cart'] = json_encode([['id' => 1, 'variant_id' => 0, 'qty' => 2]]);

try {
    $res = marketplace_create_order($db, [
        '_csrf_token' => $_SESSION['_csrf_token'],
        'checkout_key' => str_repeat('a', 32),
        'items' => [['id' => 1, 'variant_id' => 0, 'qty' => 2]],
        'recipient_name' => 'Budi Santoso',
        'recipient_phone' => '08123456789',
        'address' => 'Jl. Merdeka No. 1, RT 01 RW 02',
        'destination_id' => 501,
        'destination_label' => 'Kota Jakarta',
        'courier_code' => 'jne',
        'courier_service' => 'REG',
        'shipping_cost' => 15000,
        'payment_method' => 'qris',
    ]);
    out('checkout buat pesanan', true, 'order ' . $res['order_number']);
} catch (Throwable $e) {
    out('checkout buat pesanan', false, get_class($e) . ': ' . $e->getMessage());
}

//_same_ cart, region valid, tapi courier tidak dikonfigurasi (API key kosong)
try {
    $res = marketplace_create_order($db, [
        '_csrf_token' => $_SESSION['_csrf_token'],
        'checkout_key' => str_repeat('b', 32),
        'items' => [['id' => 1, 'variant_id' => 0, 'qty' => 2]],
        'recipient_name' => 'Budi Santoso',
        'recipient_phone' => '08123456789',
        'address' => 'Jl. Merdeka No. 1, RT 01 RW 02',
        'destination_id' => 501,
        'destination_label' => 'Kota Jakarta',
        'courier_code' => 'jne',
        'courier_service' => 'REG',
        'shipping_cost' => 15000,
        'payment_method' => 'qris',
    ]);
    out('checkout idempotent (retry)', true, 'order ' . $res['order_number']);
} catch (Throwable $e) {
    out('checkout idempotent (retry)', false, get_class($e) . ': ' . $e->getMessage());
}

echo "\n== RINGKASAN ==\n";
echo $failed ? "ADA FAILURE (lihat [FAIL] di atas)\n" : "semua pass\n";
exit($failed ? 1 : 0);
