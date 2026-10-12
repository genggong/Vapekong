<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
Auth::requirePermission('transactions.view');
$db = Database::connect();
marketplace_install($db);
if (!is_post() || !CSRF::verify()) { flash('error','Token keamanan tidak valid.'); legacy_redirect('admin/marketplace-orders/index.php'); }
$id = (int)($_POST['id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$stmt=$db->prepare("SELECT * FROM online_orders WHERE id=? LIMIT 1"); $stmt->execute([$id]); $order=$stmt->fetch();
if(!$order){ flash('error','Pesanan tidak ditemukan.'); legacy_redirect('admin/marketplace-orders/index.php'); }
try {
    if ($action === 'processing') {
        if ($order['payment_status'] !== 'paid' || $order['order_status'] !== 'paid') throw new RuntimeException('Pesanan belum siap diproses.');
        $db->prepare("UPDATE online_orders SET order_status='processing',fulfillment_status=CASE WHEN fulfillment_status IN ('waiting_payment','waiting_pick') THEN 'waiting_pick' ELSE fulfillment_status END,updated_at=NOW() WHERE id=?")->execute([$id]);
        marketplace_add_event($db,$id,'processing','Pesanan diproses','Barang sedang disiapkan oleh cabang fulfillment.');
        Auth::log('marketplace_order_processing','Marketplace '.$order['order_number']);
        flash('success','Pesanan mulai diproses.');
    } elseif ($action === 'assign_store_courier') {
        $courierId=(int)($_POST['courier_id'] ?? 0);
        if($courierId<=0) throw new RuntimeException('Pilih kurir toko terlebih dahulu.');
        $assignment=marketplace_store_courier_assign($db,$id,$courierId,(int)(Auth::id()??0));
        Auth::log('marketplace_store_courier_assign','Marketplace '.$order['order_number'].' / '.($assignment['courier_name']??('Kurir #'.$courierId)));
        flash('success','Kurir toko berhasil ditugaskan. Paket langsung muncul di Portal Kurir.');
    } elseif ($action === 'delivery_create') {
        if (in_array((string)$order['courier_code'],['store','pickup'],true)) throw new RuntimeException((string)$order['courier_code']==='pickup'?'Pesanan Ambil di Toko tidak menggunakan Shipping Delivery.':'Pesanan Kurir Toko dikelola melalui Portal Kurir, bukan Shipping Delivery.');
        if ($order['payment_status'] !== 'paid' || !in_array($order['order_status'],['paid','processing'],true)) throw new RuntimeException('Pesanan belum siap dibuatkan pengiriman.');
        $fresh = marketplace_delivery_create_order($db,$id);
        Auth::log('marketplace_delivery_created','Marketplace '.$order['order_number'].' / '.($fresh['delivery_order_no'] ?? '-'));
        flash('success','Order Shipping Delivery berhasil dibuat. Lanjutkan request pickup.');
    } elseif ($action === 'delivery_pickup') {
        $date=trim((string)($_POST['pickup_date'] ?? ''));
        $time=trim((string)($_POST['pickup_time'] ?? ''));
        $vehicle=trim((string)($_POST['pickup_vehicle'] ?? 'Motor'));
        if($date==='' || $time==='') throw new RuntimeException('Tanggal dan jam pickup wajib diisi.');
        $fresh=marketplace_delivery_request_pickup($db,$id,$date,$time,$vehicle);
        Auth::log('marketplace_delivery_pickup','Marketplace '.$order['order_number'].' / '.$date.' '.$time);
        flash('success','Pickup berhasil diminta.'.(!empty($fresh['awb_number'])?' Resi: '.$fresh['awb_number']:''));
    } elseif ($action === 'delivery_label') {
        $fresh=marketplace_delivery_print_label($db,$id);
        Auth::log('marketplace_delivery_label','Marketplace '.$order['order_number']);
        flash('success','Label pengiriman berhasil dibuat.');
    } elseif ($action === 'delivery_sync') {
        $fresh=marketplace_get_order($db,(string)$order['order_number']) ?: $order;
        if (!empty($fresh['delivery_order_no'])) { try { marketplace_delivery_detail($db,$fresh); } catch (Throwable $ignore) {} }
        $fresh=marketplace_get_order($db,(string)$order['order_number']) ?: $fresh;
        $track=marketplace_delivery_tracking($db,$fresh,true);
        flash('success','Status pengiriman berhasil disinkronkan'.(!empty($track['last_status'])?': '.$track['last_status']:'.'));
    } elseif ($action === 'shipped') {
        if (in_array((string)$order['courier_code'],['store','pickup'],true)) throw new RuntimeException((string)$order['courier_code']==='pickup'?'Pesanan Ambil di Toko tidak membutuhkan resi.':'Status pengiriman Kurir Toko diperbarui dari Portal Kurir.');
        if ($order['payment_status'] !== 'paid' || !in_array($order['order_status'],['paid','processing'],true)) throw new RuntimeException('Pesanan belum dapat dikirim.');
        $awb=trim((string)($_POST['awb_number'] ?? ''));
        if($awb==='') throw new RuntimeException('Nomor resi wajib diisi.');
        $db->prepare("UPDATE online_orders SET order_status='shipped',fulfillment_status='shipped',awb_number=?,shipped_at=COALESCE(shipped_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$awb,$id]);
        marketplace_add_event($db,$id,'shipped','Pesanan dikirim','Nomor resi manual: '.$awb);
        Auth::log('marketplace_order_shipped','Marketplace '.$order['order_number'].' / '.$awb);
        flash('success','Pesanan ditandai sudah dikirim dengan resi manual.');
    } elseif ($action === 'completed') {
        if ((string)$order['courier_code']==='store') throw new RuntimeException('Pesanan Kurir Toko selesai otomatis setelah kurir mengunggah bukti penerimaan.');
        if ((string)$order['courier_code']==='pickup') throw new RuntimeException('Gunakan panel Ambil di Toko dan verifikasi kode pengambilan.');
        if ($order['order_status'] !== 'shipped') throw new RuntimeException('Pesanan belum berstatus dikirim.');
        $db->prepare("UPDATE online_orders SET order_status='completed',fulfillment_status='completed',completed_at=COALESCE(completed_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$id]);
        marketplace_add_event($db,$id,'completed','Pesanan selesai','Pesanan telah diselesaikan.');
        Auth::log('marketplace_order_completed','Marketplace '.$order['order_number']);
        flash('success','Pesanan selesai.');
    } elseif ($action === 'pickup_ready') {
        marketplace_store_pickup_mark_ready($db,$id);
        Auth::log('marketplace_store_pickup_ready','Marketplace '.$order['order_number']);
        flash('success','Pesanan ditandai Siap Diambil di Toko.');
    } elseif ($action === 'pickup_complete') {
        $code=trim((string)($_POST['pickup_code']??''));
        $recipient=trim((string)($_POST['pickup_recipient']??''));
        $note=trim((string)($_POST['pickup_note']??''));
        marketplace_store_pickup_complete($db,$id,$code,$recipient,$note);
        Auth::log('marketplace_store_pickup_completed','Marketplace '.$order['order_number'].' / '.$recipient);
        flash('success','Pesanan berhasil diserahkan dan diselesaikan.');
    } elseif ($action === 'cancel') {
        if ($order['payment_status'] === 'paid') throw new RuntimeException('Order sudah dibayar. Lakukan proses refund secara terpisah sebelum pembatalan.');
        if (in_array($order['order_status'],['completed','cancelled','expired'],true)) throw new RuntimeException('Status order tidak dapat dibatalkan.');
        $db->prepare("UPDATE online_orders SET order_status='cancelled',payment_status=CASE WHEN payment_status='pending' THEN 'cancelled' ELSE payment_status END,cancelled_at=COALESCE(cancelled_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$id]);
        marketplace_release_stock($db,$id,'Dibatalkan admin marketplace.');
        marketplace_add_event($db,$id,'cancelled','Pesanan dibatalkan','Stok yang di-reserve dikembalikan ke cabang fulfillment.');
        Auth::log('marketplace_order_cancelled','Marketplace '.$order['order_number']);
        flash('success','Pesanan dibatalkan dan stok dikembalikan.');
    } else throw new RuntimeException('Aksi tidak dikenali.');
} catch (Throwable $e) { flash('error',$e->getMessage()); }
legacy_redirect('admin/marketplace-orders/detail.php?id='.$id);
