<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/Modules/Marketplace/Marketplace.php';
header('Content-Type: application/json; charset=utf-8');
Auth::requireRole(['super_admin','owner']);
if (!is_post() || !CSRF::verify()) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Token keamanan tidak valid.']); exit; }
try {
    $token=trim((string)($_POST['api_token'] ?? setting('marketplace_wa_api_token','')));
    if($token==='') throw new RuntimeException('Isi API Token Teknorilis WA Gateway terlebih dahulu.');
    $json=marketplace_http_json('https://gateway.teknorilis.com/api/v1/devices','GET',['Accept: application/json','Authorization: Bearer '.$token]);
    $rows=is_array($json['data']??null)?$json['data']:[];$out=[];
    foreach($rows as $r){$id=(int)($r['id']??0);if($id<=0)continue;$out[]=['id'=>$id,'name'=>(string)($r['name']??('Device #'.$id)),'phone_number'=>(string)($r['phone_number']??''),'status'=>(string)($r['status']??'unknown')];}
    echo json_encode(['success'=>true,'data'=>$out],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
