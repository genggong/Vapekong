<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole(['super_admin','owner']);
if (!is_post() || !CSRF::verify()) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Token keamanan tidak valid.']); exit; }
try {
    $token=trim((string)($_POST['api_token'] ?? setting('marketplace_wa_api_token','')));
    $device=(int)($_POST['device_id'] ?? 0);$recipient=marketplace_wa_normalize_phone((string)($_POST['recipient']??''));
    if($token==='')throw new RuntimeException('API Token belum diisi.');if($device<=0)throw new RuntimeException('Pilih device terlebih dahulu.');if(strlen($recipient)<10)throw new RuntimeException('Nomor penerima tidak valid.');
    $store=trim((string)setting('marketplace_site_title',''))?:trim((string)setting('store_name','Toko'));
    $idem='mkt-test-'.date('YmdHis').'-'.substr(bin2hex(random_bytes(4)),0,8);
    $json=marketplace_http_json('https://gateway.teknorilis.com/api/v1/messages/text','POST',['Accept: application/json','Authorization: Bearer '.$token,'Idempotency-Key: '.$idem,'Content-Type: application/json'],['device_id'=>$device,'recipient'=>$recipient,'message'=>"*{$store}*\nTes WhatsApp Marketplace berhasil.\nWaktu: ".date('d-m-Y H:i:s')]);
    echo json_encode(['success'=>true,'message'=>'Pesan tes berhasil dikirim.','data'=>$json],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
