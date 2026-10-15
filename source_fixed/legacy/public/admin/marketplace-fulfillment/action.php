<?php
require_once __DIR__.'/../../../app/bootstrap.php';
require_once __DIR__.'/../../../app/Modules/Marketplace/Marketplace.php';
Auth::requireLogin();
if(!is_post()||!CSRF::verify()){
    flash('error','Token keamanan tidak valid.');
    legacy_redirect('admin/marketplace-fulfillment/index.php');
}
$db=Database::connect();
marketplace_install($db);
$id=(int)($_POST['order_id']??0);
try{
    $s=$db->prepare("SELECT * FROM online_orders WHERE id=? AND payment_status='paid' LIMIT 1");
    $s->execute([$id]);
    $o=$s->fetch();
    if(!$o) throw new RuntimeException('Order tidak ditemukan.');
    $items=marketplace_order_items($db,$id);
    $picked=(array)($_POST['picked']??[]);
    $packed=(array)($_POST['packed']??[]);
    $status=(string)($_POST['fulfillment_status']??'picking');
    if(!in_array($status,['picking','packing','ready_ship'],true)) $status='picking';

    $db->beginTransaction();
    foreach($items as $it){
        $p=max(0,min((int)$it['qty'],(int)($picked[$it['id']]??$it['picked_qty'])));
        $pk=max(0,min((int)$it['qty'],(int)($packed[$it['id']]??$it['packed_qty'])));
        if($pk>$p) throw new RuntimeException('Qty packed tidak boleh melebihi picked untuk '.$it['product_name'].'.');
        $db->prepare("UPDATE online_order_items SET picked_qty=?,packed_qty=? WHERE id=? AND order_id=?")->execute([$p,$pk,$it['id'],$id]);
    }
    if(in_array($status,['packing','ready_ship'],true)){
        foreach($items as $it){
            $p=(int)($picked[$it['id']]??$it['picked_qty']);
            if($p<(int)$it['qty']) throw new RuntimeException('Semua item harus selesai picking sebelum lanjut packing.');
        }
    }
    if($status==='ready_ship'){
        foreach($items as $it){
            $pk=(int)($packed[$it['id']]??$it['packed_qty']);
            if($pk<(int)$it['qty']) throw new RuntimeException('Semua item harus selesai packing sebelum siap dikirim/diambil.');
        }
    }

    $proof=(string)($o['packing_proof']??'');
    if(!empty($_FILES['packing_proof']['name'])){
        $f=$_FILES['packing_proof'];
        if(($f['error']??0)!==UPLOAD_ERR_OK||($f['size']??0)>5*1024*1024) throw new RuntimeException('Upload bukti packing gagal / maksimal 5MB.');
        $info=@getimagesize($f['tmp_name']);
        $ext=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp'][$info[2]??0]??null;
        if(!$ext) throw new RuntimeException('Bukti packing harus gambar.');
        $dir=__DIR__.'/../../uploads/storefront/packing';
        if(!is_dir($dir)) mkdir($dir,0775,true);
        $n='packing-'.$id.'-'.date('YmdHis').'.'.$ext;
        if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$n)) throw new RuntimeException('Bukti packing gagal disimpan.');
        $proof='uploads/storefront/packing/'.$n;
    }

    $set=['fulfillment_status=?'];$args=[$status];
    if($status==='picking') $set[]='picked_at=COALESCE(picked_at,NOW())';
    if($status==='packing'){
        $set[]='picked_at=COALESCE(picked_at,NOW())';
        $set[]='packed_at=COALESCE(packed_at,NOW())';
    }
    if($status==='ready_ship'){
        $set[]='picked_at=COALESCE(picked_at,NOW())';
        $set[]='packed_at=COALESCE(packed_at,NOW())';
        $set[]='ready_ship_at=COALESCE(ready_ship_at,NOW())';
    }
    $set[]='packing_proof=?';$args[]=$proof?:null;
    $set[]='updated_at=NOW()';$args[]=$id;
    $db->prepare("UPDATE online_orders SET ".implode(',',$set)." WHERE id=?")->execute($args);
    $db->commit();

    marketplace_add_event($db,$id,$status,'Fulfillment: '.ucwords(str_replace('_',' ',$status)),'Status picking/packing diperbarui admin.');
    marketplace_v15_log($db,'fulfillment.update','order',$id,$status);
    if($status==='ready_ship' && (string)$o['courier_code']==='pickup'){
        marketplace_store_pickup_mark_ready($db,$id);
        flash('success','Packing selesai. Pesanan sekarang Siap Diambil di Toko.');
    }else{
        flash('success','Fulfillment diperbarui.');
    }
}catch(Throwable $e){
    if($db->inTransaction()) $db->rollBack();
    flash('error',$e->getMessage());
}
legacy_redirect('admin/marketplace-fulfillment/detail.php?id='.$id);
