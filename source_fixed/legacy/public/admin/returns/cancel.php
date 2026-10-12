<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/_helpers.php';
Auth::requireRole(['super_admin', 'owner']);
$db = Database::connect();
sales_returns_install($db);

$id = (int)($_GET['id'] ?? 0);

try {
    $stmt = $db->prepare("SELECT * FROM sales_returns WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $return = $stmt->fetch();

    if (!$return) {
        flash('error', 'Data retur tidak ditemukan.');
        legacy_redirect('admin/returns/index.php');
    }

    if ($return['status'] !== 'completed') {
        flash('error', 'Retur sudah dibatalkan.');
        legacy_redirect('admin/returns/detail.php?id=' . $id);
    }

    $db->beginTransaction();

    $stmt = $db->prepare("SELECT * FROM sales_return_items WHERE return_id = ?");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

    foreach ($items as $item) {
        if ((int)$item['restock'] !== 1 || empty($item['product_id'])) {
            continue;
        }

        $stmt = $db->prepare("SELECT stock FROM products WHERE id = ? FOR UPDATE");
        $stmt->execute([(int)$item['product_id']]);
        $stockBefore = (int)($stmt->fetchColumn() ?: 0);
        $stockAfter = max(0, $stockBefore - (int)$item['qty']);

        $up = $db->prepare("UPDATE products SET stock = ? WHERE id = ?");
        $up->execute([$stockAfter, (int)$item['product_id']]);

        $sm = $db->prepare("INSERT INTO stock_movements (product_id, user_id, type, qty, stock_before, stock_after, reference_type, reference_id, note, created_at)
            VALUES (?, ?, 'return_cancel', ?, ?, ?, 'sales_return', ?, ?, ?)");
        $sm->execute([
            (int)$item['product_id'],
            Auth::id(),
            (int)$item['qty'],
            $stockBefore,
            $stockAfter,
            $id,
            'Pembatalan retur ' . $return['return_number'],
            now_datetime(),
        ]);
    }

    $up = $db->prepare("UPDATE sales_returns SET status = 'cancelled' WHERE id = ?");
    $up->execute([$id]);

    $trxUp = $db->prepare("UPDATE transactions SET status = 'success' WHERE id = ? AND status = 'refunded'");
    $trxUp->execute([(int)$return['transaction_id']]);

    Auth::log('cancel_sales_return', 'Membatalkan retur ' . $return['return_number']);
    $db->commit();

    flash('success', 'Retur berhasil dibatalkan dan stok dikoreksi.');
    legacy_redirect('admin/returns/detail.php?id=' . $id);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    flash('error', 'Gagal membatalkan retur: ' . $e->getMessage());
    legacy_redirect('admin/returns/detail.php?id=' . $id);
}
