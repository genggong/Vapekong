<?php
/**
 * Marketplace Store Pickup / Click & Collect (V15.5)
 * - Customer can pick paid marketplace orders up at an enabled branch.
 * - No shipping fee / external courier required.
 * - Uses the selected pickup branch as the actual stock/fulfillment branch.
 * - Picking/Packing remains mandatory before the order becomes Ready for Pickup.
 * - A 6 digit pickup code is used when handing the package to the customer.
 */

function marketplace_store_pickup_install(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [
        'pickup_code' => "VARCHAR(12) NULL",
        'pickup_ready_at' => "DATETIME NULL",
        'picked_up_at' => "DATETIME NULL",
        'pickup_recipient' => "VARCHAR(160) NULL",
        'pickup_note' => "TEXT NULL",
    ];
    foreach ($columns as $column => $definition) {
        try {
            if (function_exists('marketplace_column_exists') && !marketplace_column_exists($db, 'online_orders', $column)) {
                $db->exec("ALTER TABLE online_orders ADD COLUMN `{$column}` {$definition}");
            }
        } catch (Throwable $ignore) {}
    }

    $defaults = [
        'marketplace_store_pickup_enabled' => 'off',
        'marketplace_store_pickup_name' => 'Ambil di Toko',
        'marketplace_store_pickup_hours' => '09:00 - 21:00 WIB',
        'marketplace_store_pickup_instructions' => 'Tunjukkan kode pengambilan kepada petugas toko saat mengambil pesanan.',
        'marketplace_store_pickup_branch_ids' => '',
    ];
    foreach ($defaults as $key => $value) {
        try {
            $stmt = $db->prepare("INSERT IGNORE INTO settings(setting_key,setting_value) VALUES(?,?)");
            $stmt->execute([$key, $value]);
        } catch (Throwable $ignore) {}
    }
}

function marketplace_store_pickup_config(): array
{
    $ids = [];
    foreach (preg_split('/[,:;\s]+/', (string) setting('marketplace_store_pickup_branch_ids', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $id) {
        $id = (int) $id;
        if ($id > 0) $ids[$id] = $id;
    }
    return [
        'enabled' => setting('marketplace_store_pickup_enabled', 'off') === 'on',
        'name' => trim((string) setting('marketplace_store_pickup_name', 'Ambil di Toko')) ?: 'Ambil di Toko',
        'hours' => trim((string) setting('marketplace_store_pickup_hours', '09:00 - 21:00 WIB')) ?: '09:00 - 21:00 WIB',
        'instructions' => trim((string) setting('marketplace_store_pickup_instructions', 'Tunjukkan kode pengambilan kepada petugas toko saat mengambil pesanan.')),
        'branch_ids' => array_values($ids),
    ];
}

function marketplace_store_pickup_allowed_branch_ids(PDO $db): array
{
    $cfg = marketplace_store_pickup_config();
    if (!$cfg['enabled']) return [];
    $configured = array_map('intval', $cfg['branch_ids']);
    if (!$configured) {
        try {
            $default = marketplace_branch_id($db);
            return $default > 0 ? [$default] : [];
        } catch (Throwable $e) {
            return [];
        }
    }
    $placeholders = implode(',', array_fill(0, count($configured), '?'));
    try {
        $stmt = $db->prepare("SELECT id FROM branches WHERE is_active=1 AND id IN ({$placeholders}) ORDER BY is_main DESC,name ASC");
        $stmt->execute($configured);
        return array_map('intval', array_column($stmt->fetchAll() ?: [], 'id'));
    } catch (Throwable $e) {
        return [];
    }
}

function marketplace_store_pickup_branches(PDO $db, array $rawItems = []): array
{
    marketplace_store_pickup_install($db);
    $ids = marketplace_store_pickup_allowed_branch_ids($db);
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id,code,name,phone,address,is_main FROM branches WHERE is_active=1 AND id IN ({$placeholders}) ORDER BY is_main DESC,name ASC");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll() ?: [];
    $cfg = marketplace_store_pickup_config();
    $out = [];
    foreach ($rows as $row) {
        if ($rawItems) {
            try {
                $cart = marketplace_cart($db, $rawItems, (int)$row['id']);
            } catch (Throwable $e) {
                continue; // Don't offer a branch that cannot fulfill the whole cart.
            }
        } else {
            $cart = null;
        }
        $address = trim((string)($row['address'] ?? ''));
        if ($address === '') $address = trim((string)setting('store_address', ''));
        if ($address === '') continue; // Click & Collect must always show a physical pickup address.
        $out[] = [
            'branch_id' => (int)$row['id'],
            'code' => (string)($row['code'] ?? ''),
            'name' => (string)$row['name'],
            'phone' => (string)($row['phone'] ?? ''),
            'address' => $address,
            'hours' => $cfg['hours'],
            'instructions' => $cfg['instructions'],
            'subtotal' => $cart ? (float)$cart['subtotal'] : null,
            'weight_gram' => $cart ? (int)$cart['weight_gram'] : null,
        ];
    }
    return $out;
}

function marketplace_store_pickup_branch(PDO $db, int $branchId, array $rawItems = []): ?array
{
    if ($branchId <= 0) return null;
    foreach (marketplace_store_pickup_branches($db, $rawItems) as $branch) {
        if ((int)$branch['branch_id'] === $branchId) return $branch;
    }
    return null;
}

function marketplace_store_pickup_option(array $branch): array
{
    $cfg = marketplace_store_pickup_config();
    return [
        'code' => 'pickup',
        'name' => $cfg['name'],
        'service' => 'PICKUP',
        'description' => 'Ambil sendiri di ' . ($branch['name'] ?? 'toko'),
        'cost' => 0.0,
        'etd' => $cfg['hours'],
        'pickup_branch_id' => (int)($branch['branch_id'] ?? 0),
        'is_store_pickup' => true,
    ];
}

function marketplace_store_pickup_generate_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function marketplace_store_pickup_status_label(array $order): string
{
    if ((string)($order['order_status'] ?? '') === 'completed') return 'Sudah Diambil';
    return match ((string)($order['fulfillment_status'] ?? '')) {
        'ready_pickup' => 'Siap Diambil',
        'packing' => 'Sedang Packing',
        'picking' => 'Sedang Picking',
        'waiting_pick' => 'Menunggu Picking',
        default => ((string)($order['payment_status'] ?? '') === 'paid' ? 'Sedang Diproses' : 'Menunggu Pembayaran'),
    };
}

function marketplace_store_pickup_mark_ready(PDO $db, int $orderId): array
{
    $stmt = $db->prepare("SELECT * FROM online_orders WHERE id=? LIMIT 1");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) throw new RuntimeException('Pesanan tidak ditemukan.');
    if ((string)$order['courier_code'] !== 'pickup') throw new RuntimeException('Pesanan ini bukan Ambil di Toko.');
    if ((string)$order['payment_status'] !== 'paid') throw new RuntimeException('Pesanan belum dibayar.');
    if (!in_array((string)$order['fulfillment_status'], ['ready_ship','ready_pickup'], true)) throw new RuntimeException('Selesaikan picking dan packing terlebih dahulu.');
    if (empty($order['pickup_code'])) {
        $code = marketplace_store_pickup_generate_code();
        $db->prepare("UPDATE online_orders SET pickup_code=? WHERE id=?")->execute([$code,$orderId]);
    }
    $db->prepare("UPDATE online_orders SET order_status=CASE WHEN order_status='paid' THEN 'processing' ELSE order_status END,fulfillment_status='ready_pickup',pickup_ready_at=COALESCE(pickup_ready_at,NOW()),delivery_status='Siap diambil di toko',updated_at=NOW() WHERE id=?")->execute([$orderId]);
    if (function_exists('marketplace_add_event')) marketplace_add_event($db,$orderId,'ready_pickup','Pesanan siap diambil','Pesanan sudah selesai dipacking dan dapat diambil di toko.');
    if (function_exists('marketplace_v15_log')) { try { marketplace_v15_log($db,'store_pickup.ready','online_order',$orderId,'Pesanan siap diambil di toko'); } catch (Throwable $ignore) {} }
    $stmt->execute([$orderId]);
    return $stmt->fetch() ?: $order;
}

function marketplace_store_pickup_complete(PDO $db, int $orderId, string $pickupCode, string $recipient, string $note = ''): array
{
    $stmt = $db->prepare("SELECT * FROM online_orders WHERE id=? LIMIT 1 FOR UPDATE");
    $db->beginTransaction();
    try {
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) throw new RuntimeException('Pesanan tidak ditemukan.');
        if ((string)$order['courier_code'] !== 'pickup') throw new RuntimeException('Pesanan ini bukan Ambil di Toko.');
        if ((string)$order['payment_status'] !== 'paid') throw new RuntimeException('Pesanan belum dibayar.');
        if ((string)$order['fulfillment_status'] !== 'ready_pickup') throw new RuntimeException('Pesanan belum berstatus Siap Diambil.');
        $expected = preg_replace('/\D+/', '', (string)($order['pickup_code'] ?? ''));
        $provided = preg_replace('/\D+/', '', $pickupCode);
        if ($expected === '' || !hash_equals($expected, $provided)) throw new RuntimeException('Kode pengambilan tidak cocok.');
        $recipient = trim($recipient);
        if ($recipient === '') throw new RuntimeException('Nama penerima barang wajib diisi.');
        $db->prepare("UPDATE online_orders SET order_status='completed',fulfillment_status='completed',pickup_recipient=?,pickup_note=?,picked_up_at=COALESCE(picked_up_at,NOW()),completed_at=COALESCE(completed_at,NOW()),delivery_status='Sudah diambil di toko',updated_at=NOW() WHERE id=?")
            ->execute([$recipient,$note !== '' ? $note : null,$orderId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    if (function_exists('marketplace_add_event')) marketplace_add_event($db,$orderId,'completed','Pesanan diambil di toko','Pesanan diserahkan kepada '.$recipient.'.');
    if (function_exists('marketplace_v15_log')) { try { marketplace_v15_log($db,'store_pickup.completed','online_order',$orderId,'Diserahkan kepada '.$recipient); } catch (Throwable $ignore) {} }
    $fresh=$db->prepare("SELECT * FROM online_orders WHERE id=? LIMIT 1");$fresh->execute([$orderId]);return $fresh->fetch() ?: [];
}
