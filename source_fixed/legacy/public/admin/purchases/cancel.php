<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
Auth::requireRole(['super_admin','owner']);
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/../warehouse/_helpers.php';
$db = Database::connect();
branch_install($db);
purchases_install($db);
warehouse_install($db);
$id = (int)($_GET['id'] ?? 0);
try {
    $db->beginTransaction();
    $stmt = $db->prepare("SELECT * FROM purchases WHERE id=? FOR UPDATE");
    $stmt->execute([$id]);
    $purchase = $stmt->fetch();
    if (!$purchase) throw new Exception('Pembelian tidak ditemukan.');
    if ($purchase['status'] === 'cancelled') throw new Exception('Pembelian sudah dibatalkan.');

    $destinationType = (string)($purchase['stock_destination_type'] ?? 'branch');
    $warehouseId = (int)($purchase['warehouse_id'] ?? 0);
    $branchId = (int)($purchase['branch_id'] ?? 0);
    if ($destinationType === 'warehouse' && $warehouseId <= 0) $warehouseId = warehouse_main_id($db);
    if ($destinationType !== 'warehouse' && $branchId <= 0) $branchId = branch_main_id($db);

    $stmt = $db->prepare("SELECT * FROM purchase_items WHERE purchase_id=? ORDER BY id ASC FOR UPDATE");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

    $targetIsMain = false;
    if ($destinationType !== 'warehouse') {
        $mainStmt = $db->prepare("SELECT is_main FROM branches WHERE id=? LIMIT 1");
        $mainStmt->execute([$branchId]);
        $targetIsMain = (int)$mainStmt->fetchColumn() === 1;
    }

    foreach ($items as $item) {
        $productId = (int)$item['product_id'];
        $qty = (int)$item['qty'];
        if ($destinationType === 'warehouse') {
            $ws = warehouse_stock($db,$warehouseId,$productId,true);
            $before = (int)$ws['stock'];
            if ($before < $qty) {
                throw new Exception('Pembelian tidak dapat dibatalkan karena stok gudang '.$item['product_name'].' sudah terpakai/dikirim. Stok tersedia '.$before.', butuh '.$qty.'.');
            }
            $after = $before - $qty;
            $db->prepare("UPDATE warehouse_stocks SET stock=?,updated_at=NOW() WHERE warehouse_id=? AND product_id=?")
                ->execute([$after,$warehouseId,$productId]);
            warehouse_record_movement($db,$warehouseId,$productId,'supplier_purchase_cancel',$qty,$before,$after,(float)$item['purchase_price'],'purchase_cancel',$id,'Pembatalan pembelian '.$purchase['purchase_number']);
        } else {
            branch_sync_product($db,$productId);
            $bs = branch_stock($db,$branchId,$productId);
            $before = (int)$bs['stock'];
            if ($before < $qty) {
                throw new Exception('Pembelian tidak dapat dibatalkan karena stok cabang '.$item['product_name'].' sudah terjual/terpakai. Stok tersedia '.$before.', butuh '.$qty.'.');
            }
            $after = $before - $qty;
            $db->prepare("UPDATE product_branch_stocks SET stock=?,updated_at=NOW() WHERE branch_id=? AND product_id=?")
                ->execute([$after,$branchId,$productId]);
            if ($targetIsMain) {
                $db->prepare("UPDATE products SET stock=?,updated_at=NOW() WHERE id=?")->execute([$after,$productId]);
            }
            $db->prepare("INSERT INTO stock_movements (product_id,user_id,branch_id,type,qty,stock_before,stock_after,reference_type,reference_id,note) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$productId,Auth::id(),$branchId,'stock_out',$qty,$before,$after,'purchase_cancel',$id,'Pembatalan pembelian '.$purchase['purchase_number']]);
        }
    }
    $db->prepare("UPDATE purchases SET status='cancelled', cancelled_at=NOW(), cancelled_by=?, cancel_note=? WHERE id=?")
        ->execute([Auth::id(), 'Dibatalkan dari halaman pembelian', $id]);
    Auth::log('cancel_purchase', 'Membatalkan pembelian ' . $purchase['purchase_number']);
    $db->commit();
    flash('success','Pembelian berhasil dibatalkan dan stok tujuan dikoreksi.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    flash('error','Gagal membatalkan pembelian: ' . $e->getMessage());
}
legacy_redirect('admin/purchases/index.php');
