<?php
require_once __DIR__.'/_bootstrap.php';$courier=marketplace_courier_require();$courier=marketplace_courier_refresh_session($db);if(!$courier)legacy_redirect('kurir/login');
if(!is_post()||!CSRF::verify()){flash('error','Token keamanan tidak valid.');legacy_redirect('kurir');}
$id=max(0,(int)($_POST['id']??0));$action=trim((string)($_POST['action']??''));
try{marketplace_courier_update_status($db,$id,(int)$courier['id'],$action,$_POST,$_FILES['proof']??null);flash('success','Status paket berhasil diperbarui.');}catch(Throwable $e){flash('error',$e->getMessage());}
legacy_redirect('kurir/paket/'.$id);
