<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Modules/Marketplace/Marketplace.php';
header('Content-Type: application/json; charset=utf-8');
$db=Database::connect();marketplace_install($db);
$raw=(string)file_get_contents('php://input');
$received=trim((string)($_SERVER['HTTP_X_OPENWA_SIGNATURE']??''));
$secret=trim((string)setting('marketplace_wa_webhook_secret',''));
if($secret===''||$received===''){http_response_code(401);echo json_encode(['success'=>false,'message'=>'Signature tidak tersedia.']);exit;}
$expected='sha256='.hash_hmac('sha256',$raw,$secret);
if(!hash_equals($expected,$received)){http_response_code(401);echo json_encode(['success'=>false,'message'=>'Signature tidak valid.']);exit;}
$event=json_decode($raw,true);if(!is_array($event))$event=[];
$type=(string)($event['event']??$event['type']??$event['event_type']??'unknown');
$device=(string)($event['device_id']??$event['data']['device_id']??'');
$msg=(string)($event['message_id']??$event['data']['message_id']??$event['data']['id']??'');
try{$st=$db->prepare("INSERT INTO marketplace_wa_webhook_logs(event_type,device_id,external_message_id,payload_json,signature_valid,created_at) VALUES(?,?,?,?,1,NOW())");$st->execute([$type?:null,$device?:null,$msg?:null,$raw]);}catch(Throwable $ignore){}
http_response_code(202);echo json_encode(['success'=>true,'accepted'=>true],JSON_UNESCAPED_SLASHES);
