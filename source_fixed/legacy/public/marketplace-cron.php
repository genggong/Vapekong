<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Modules/Marketplace/Marketplace.php';
require_once __DIR__ . '/admin/warehouse/_helpers.php';
header('Content-Type: application/json; charset=utf-8');
$db = Database::connect();marketplace_install($db);
$expected = marketplace_ensure_secret($db, 'marketplace_cron_token');$token = trim((string)($_GET['token'] ?? ''));
if ($token === '' || !hash_equals($expected, $token)) {http_response_code(403);echo json_encode(['success'=>false,'message'=>'Token cron tidak valid.']);exit;}
$started=microtime(true);$startedAt=date('Y-m-d H:i:s');$checked=0;$expired=0;$errors=[];$wa=['checked'=>0,'sent'=>0,'failed'=>0,'skipped'=>0];$autoStockRequest=null;
try{
    $stmt=$db->query("SELECT * FROM online_orders WHERE payment_status='pending' AND payment_expired_at IS NOT NULL AND payment_expired_at < NOW() ORDER BY id ASC LIMIT 200");
    foreach (($stmt->fetchAll() ?: []) as $order) {$checked++;try{$before=(string)$order['payment_status'];$fresh=marketplace_expire_if_needed($db,$order);if($before==='pending'&&($fresh['payment_status']??'')==='expired')$expired++;}catch(Throwable $e){$errors[]=['order'=>$order['order_number'],'message'=>$e->getMessage()];}}
    $wa=function_exists('marketplace_wa_process_queue')?marketplace_wa_process_queue($db,40):$wa;
    if(setting('marketplace_auto_request_recommendation','off')==='on'){
        try{
            warehouse_install($db);
            $branch=marketplace_branch_id($db);
            $pending=$db->prepare("SELECT COUNT(*) FROM branch_stock_requests WHERE branch_id=? AND status IN ('pending','approved') AND note LIKE 'Auto Marketplace V15%'");
            $pending->execute([$branch]);
            if((int)$pending->fetchColumn()===0){
                $threshold=max(0,(int)setting('marketplace_stock_alert_threshold','5'));
                $map=[];
                foreach(marketplace_v15_stock_alerts($db) as $r){
                    $target=max((int)$r['minimum_stock'],$threshold)*2;
                    $suggest=max(0,min((int)$r['warehouse_stock'],$target-(int)$r['branch_stock']));
                    if($suggest>0)$map[(int)$r['id']]=$suggest;
                }
                if($map)$autoStockRequest=marketplace_v15_create_stock_request($db,$map,'Auto Marketplace V15 - rekomendasi stok '.date('Y-m-d H:i'));
            }
        }catch(Throwable $e){$errors[]=['job'=>'auto_stock_request','message'=>$e->getMessage()];}
    }
    $status=$errors?'warning':'success';$duration=(int)round((microtime(true)-$started)*1000);$processed=$checked+(int)($wa['checked']??0);
    try{$st=$db->prepare("INSERT INTO marketplace_cron_logs(job_name,status,processed,duration_ms,error_message,started_at,finished_at,created_at) VALUES('marketplace_cron',?,?,?,?,?,NOW(),NOW())");$st->execute([$status,$processed,$duration,$errors?json_encode($errors,JSON_UNESCAPED_UNICODE):null,$startedAt]);}catch(Throwable $ignore){}
    echo json_encode(['success'=>true,'checked'=>$checked,'expired'=>$expired,'wa'=>$wa,'auto_stock_request'=>$autoStockRequest,'errors'=>$errors,'duration_ms'=>$duration],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){$duration=(int)round((microtime(true)-$started)*1000);try{$st=$db->prepare("INSERT INTO marketplace_cron_logs(job_name,status,processed,duration_ms,error_message,started_at,finished_at,created_at) VALUES('marketplace_cron','failed',0,?,?,?,NOW(),NOW())");$st->execute([$duration,$e->getMessage(),$startedAt]);}catch(Throwable $ignore){}http_response_code(500);echo json_encode(['success'=>false,'message'=>$e->getMessage(),'duration_ms'=>$duration],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
