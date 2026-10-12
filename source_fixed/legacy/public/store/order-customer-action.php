<?php
require_once __DIR__.'/_data.php';
$db=storefront_db();$customer=$db?marketplace_customer($db):null;if(!$customer)legacy_redirect('masuk');
if(!is_post()||!CSRF::verify()){flash('error','Token keamanan tidak valid.');legacy_redirect('pesanan-saya');}
$cid=(int)$customer['id'];$orderId=(int)($_POST['order_id']??0);$action=trim((string)($_POST['action']??''));
$st=$db->prepare("SELECT * FROM online_orders WHERE id=? AND customer_id=? LIMIT 1");$st->execute([$orderId,$cid]);$o=$st->fetch();if(!$o){flash('error','Pesanan tidak ditemukan.');legacy_redirect('pesanan-saya');}
$url='pesanan/'.rawurlencode((string)$o['order_number']).'?token='.rawurlencode((string)$o['public_token']);
try{
 if($action==='confirm_received'){marketplace_confirm_received($db,$cid,$orderId);flash('success','Terima kasih. Pesanan ditandai sudah diterima.');}
 elseif($action==='cancel_request'){
   if((string)$o['payment_status']!=='paid'&&in_array((string)$o['order_status'],['pending_payment','paid','processing'],true)){
      $db->prepare("UPDATE online_orders SET order_status='cancelled',payment_status=CASE WHEN payment_status='pending' THEN 'cancelled' ELSE payment_status END,cancelled_at=COALESCE(cancelled_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$orderId]);
      marketplace_release_stock($db,$orderId,'Dibatalkan pelanggan sebelum pembayaran.');marketplace_add_event($db,$orderId,'cancelled','Pesanan dibatalkan','Pembatalan dilakukan pelanggan sebelum pembayaran.');flash('success','Pesanan dibatalkan dan stok dikembalikan.');
   } else {
      $case=marketplace_service_create($db,$cid,$orderId,'cancel_refund','Pembatalan oleh pelanggan','Mohon batalkan pesanan dan proses refund jika diperlukan.');flash('success','Permintaan pembatalan dikirim ke admin.');legacy_redirect('bantuan-pesanan/'.(int)$case['id']);
   }
 } else throw new RuntimeException('Aksi tidak dikenali.');
}catch(Throwable $e){flash('error',$e->getMessage());}
legacy_redirect($url);
