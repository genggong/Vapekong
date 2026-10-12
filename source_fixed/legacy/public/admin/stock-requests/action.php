<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
require_once __DIR__ . '/../branches/_helpers.php';
require_once __DIR__ . '/../warehouse/_helpers.php';
warehouse_require_manage();
$db = Database::connect();
branch_install($db);
warehouse_install($db);

if (!is_post() || !CSRF::verify()) {
    flash('error','Token keamanan tidak valid.');
    legacy_redirect('admin/stock-requests/index.php');
}
$id = (int)($_POST['request_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$actionNote = trim((string)($_POST['action_note'] ?? ''));
if ($id <= 0 || !in_array($action,['approve','fulfill','reject'],true)) {
    flash('error','Aksi request tidak valid.');
    legacy_redirect('admin/stock-requests/index.php');
}

try {
    $db->beginTransaction();
    $stmt = $db->prepare("SELECT * FROM branch_stock_requests WHERE id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$id]);
    $request = $stmt->fetch();
    if (!$request) throw new Exception('Request stok tidak ditemukan.');

    if ($action === 'approve') {
        if ($request['status'] !== 'pending') throw new Exception('Request tidak lagi berstatus menunggu.');
        $db->prepare("UPDATE branch_stock_requests SET status='approved',approved_by=?,approved_at=NOW(),action_note=?,updated_at=NOW() WHERE id=?")
            ->execute([Auth::id(),$actionNote ?: null,$id]);
        warehouse_notify($db,(int)$request['requested_by'],'Request stok disetujui','Request '.$request['request_number'].' telah disetujui gudang.','success');
        Auth::log('approve_stock_request','Menyetujui request stok '.$request['request_number']);
        $db->commit();
        flash('success','Request stok disetujui.');
        legacy_redirect('admin/stock-requests/detail.php?id='.$id);
    }

    if ($action === 'reject') {
        if (!in_array($request['status'],['pending','approved'],true)) throw new Exception('Request tidak dapat ditolak pada status ini.');
        if ($actionNote === '') throw new Exception('Alasan penolakan wajib diisi.');
        $db->prepare("UPDATE branch_stock_requests SET status='rejected',rejected_by=?,rejected_at=NOW(),action_note=?,updated_at=NOW() WHERE id=?")
            ->execute([Auth::id(),$actionNote,$id]);
        warehouse_notify($db,(int)$request['requested_by'],'Request stok ditolak','Request '.$request['request_number'].' ditolak: '.$actionNote,'danger');
        Auth::log('reject_stock_request','Menolak request stok '.$request['request_number']);
        $db->commit();
        flash('success','Request stok ditolak.');
        legacy_redirect('admin/stock-requests/detail.php?id='.$id);
    }

    if (!in_array($request['status'],['pending','approved'],true)) throw new Exception('Request tidak dapat dikirim pada status ini.');
    $stmt = $db->prepare("SELECT * FROM branch_stock_request_items WHERE request_id=? ORDER BY id ASC FOR UPDATE");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
    if (!$items) throw new Exception('Item request kosong.');

    $isMainStmt = $db->prepare("SELECT is_main FROM branches WHERE id=? LIMIT 1");
    $isMainStmt->execute([(int)$request['branch_id']]);
    $targetIsMain = (int)$isMainStmt->fetchColumn() === 1;

    foreach ($items as $item) {
        $productId=(int)$item['product_id'];$variantId=(int)($item['variant_id']??0);$qty=(int)$item['qty_requested'];
        $ws=$variantId>0?warehouse_variant_stock($db,(int)$request['warehouse_id'],$productId,$variantId,true):warehouse_stock($db,(int)$request['warehouse_id'],$productId,true);
        $warehouseBefore=(int)$ws['stock'];
        if ($warehouseBefore < $qty) throw new Exception('Stok gudang untuk '.$item['product_name'].' tidak cukup. Tersedia '.$warehouseBefore.' '.$item['unit'].'.');
        $warehouseAfter = $warehouseBefore - $qty;

        branch_sync_product($db,$productId);
        $bs = branch_stock($db,(int)$request['branch_id'],$productId);
        $branchBefore = (int)$bs['stock'];
        $branchAfter = $branchBefore + $qty;

        if($variantId>0){
            warehouse_set_variant_stock($db,(int)$request['warehouse_id'],$productId,$variantId,$warehouseAfter,(float)$item['unit_cost'],(int)($ws['minimum_stock']??0));
            $branchBefore=marketplace_v16_variant_stock($db,$variantId,(int)$request['branch_id'],true);$branchAfter=$branchBefore+$qty;
            marketplace_v16_set_variant_stock($db,$variantId,(int)$request['branch_id'],$branchAfter,0);marketplace_v16_sync_parent_stock($db,$productId,(int)$request['branch_id']);
        }else{
            $db->prepare("UPDATE warehouse_stocks SET stock=?,updated_at=NOW() WHERE warehouse_id=? AND product_id=?")->execute([$warehouseAfter,(int)$request['warehouse_id'],$productId]);
            $db->prepare("UPDATE product_branch_stocks SET stock=?,updated_at=NOW() WHERE branch_id=? AND product_id=?")->execute([$branchAfter,(int)$request['branch_id'],$productId]);
            if($targetIsMain)$db->prepare("UPDATE products SET stock=?,updated_at=NOW() WHERE id=?")->execute([$branchAfter,$productId]);
        }

        warehouse_record_movement($db,(int)$request['warehouse_id'],$productId,'branch_request_out',$qty,$warehouseBefore,$warehouseAfter,(float)$item['unit_cost'],'stock_request',$id,'Kirim ke '.branch_name($db,(int)$request['branch_id']).' • '.$request['request_number']);
        $sm = $db->prepare("INSERT INTO stock_movements (product_id,user_id,branch_id,type,qty,stock_before,stock_after,reference_type,reference_id,note,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
        $sm->execute([$productId,Auth::id(),(int)$request['branch_id'],'warehouse_request_in',$qty,$branchBefore,$branchAfter,'stock_request',$id,'Stok dari gudang • '.$request['request_number']]);
        $db->prepare("UPDATE branch_stock_request_items SET qty_fulfilled=?,warehouse_stock_before=?,warehouse_stock_after=?,branch_stock_before=?,branch_stock_after=? WHERE id=?")
            ->execute([$qty,$warehouseBefore,$warehouseAfter,$branchBefore,$branchAfter,(int)$item['id']]);
    }

    $approvedBy = $request['approved_by'] ?: Auth::id();
    $approvedAtSql = $request['approved_at'] ? 'approved_at' : 'NOW()';
    $sql = "UPDATE branch_stock_requests SET status='fulfilled',approved_by=?,approved_at={$approvedAtSql},fulfilled_by=?,fulfilled_at=NOW(),action_note=?,updated_at=NOW() WHERE id=?";
    $db->prepare($sql)->execute([$approvedBy,Auth::id(),$actionNote ?: $request['action_note'],$id]);
    warehouse_notify($db,(int)$request['requested_by'],'Stok cabang sudah masuk','Request '.$request['request_number'].' telah dipenuhi. Stok otomatis masuk ke cabang.','success');
    Auth::log('fulfill_stock_request','Memenuhi request stok '.$request['request_number']);
    $db->commit();
    flash('success','Request berhasil dipenuhi. Stok gudang berkurang dan stok cabang bertambah.');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    flash('error','Gagal memproses request: '.$e->getMessage());
}
legacy_redirect('admin/stock-requests/detail.php?id='.$id);
